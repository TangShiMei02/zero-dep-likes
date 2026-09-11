<?php
/**
 * 博客点赞系统 — API 入口
 *
 * 接口一览：
 *   GET    ?article_id=xxx&visitor_id=yyy        查单篇
 *   GET    ?article_ids=a,b,c&visitor_id=yyy     批量查
 *   POST   {article_id, visitor_id}              点赞
 *   POST   {article_id, visitor_id, action:"unlike"}  取消点赞
 *   DELETE {article_id, visitor_id}              取消点赞（别名）
 *   OPTIONS                                      CORS 预检
 *
 * @package BlogLikes
 */

define('LIKES_APP', true);

require __DIR__ . '/config.php';
require __DIR__ . '/response.php';
require __DIR__ . '/db.php';

// ---------------- CORS ----------------

$originAllowed = apply_cors($ALLOWED_ORIGINS);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

// 预检请求：被拒的域名直接 403，正确的域名回 204
if ($method === 'OPTIONS') {
    if (!$originAllowed) {
        http_response_code(403);
        exit;
    }
    http_response_code(204);
    exit;
}

// 非预检请求来自白名单外的域名：拒绝。
// 用 400 而不是 403，是为了让错误码始终落在契约表内（0/400/429/1001/500）。
if (!$originAllowed) {
    send_json(CODE_BAD_REQUEST, 'origin not allowed');
}

// ---------------- 路由 ----------------

try {
    if ($method === 'GET') {
        handle_get();
    } elseif ($method === 'POST' || $method === 'DELETE') {
        handle_write($method);
    } else {
        send_json(CODE_BAD_REQUEST, 'method not allowed, use GET / POST / DELETE');
    }
} catch (Throwable $e) {
    likes_log('unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    // $DEBUG = true 时把 MySQL / PHP 的原话带回去，方便对照排查。
    // 生产环境务必保持 false，否则会泄露数据库与路径信息。
    $debugData = null;
    if ($DEBUG) {
        $debugData = array(
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
        );
    }

    send_json(CODE_SERVER_ERROR, 'server error', $debugData);
}

/* ============================================================
 * GET
 * ============================================================ */

function handle_get()
{
    global $MAX_BATCH_IDS;

    // ---- 批量模式 ----
    if (isset($_GET['article_ids'])) {
        $raw = $_GET['article_ids'];

        if (!is_string($raw)) {
            send_json(CODE_BAD_REQUEST, 'article_ids must be a comma-separated string');
        }

        $ids = array();
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $ids[] = $piece;
            }
        }
        $ids = array_values(array_unique($ids));

        if (count($ids) === 0) {
            send_json(CODE_BAD_REQUEST, 'article_ids is empty');
        }
        if (count($ids) > $MAX_BATCH_IDS) {
            send_json(CODE_BAD_REQUEST, 'too many article_ids, max is ' . (int) $MAX_BATCH_IDS);
        }
        foreach ($ids as $id) {
            validate_article_id($id); // 非法立刻 400，不静默跳过
        }

        $visitorId = read_visitor_id_from_query();

        // 一次 SQL 取回所有计数，避免 N 次查询
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = likes_db()->prepare(
            'SELECT article_id, like_count FROM article_likes WHERE article_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);

        $countMap = array();
        foreach ($stmt->fetchAll() as $row) {
            $countMap[$row['article_id']] = (int) $row['like_count'];
        }

        // 带了 visitor_id 就顺便算 liked，前端能一次拿到按钮该显示什么状态
        $likedMap = array();
        if ($visitorId !== null) {
            $likedMap = fetch_liked_map($ids, $visitorId);
        }

        $items = array();
        foreach ($ids as $id) {
            $item = array(
                'article_id' => $id,
                'like_count' => isset($countMap[$id]) ? $countMap[$id] : 0,
            );
            if ($visitorId !== null) {
                $item['liked'] = !empty($likedMap[$id]);
            }
            $items[] = $item;
        }

        send_ok(array('items' => $items));
    }

    // ---- 单篇模式 ----
    if (!isset($_GET['article_id'])) {
        send_json(CODE_BAD_REQUEST, 'article_id is required');
    }
    if (!is_string($_GET['article_id'])) {
        send_json(CODE_BAD_REQUEST, 'article_id must be a string');
    }

    $articleId = validate_article_id(trim($_GET['article_id']));
    $visitorId = read_visitor_id_from_query();

    $liked = false;
    if ($visitorId !== null) {
        $likedMap = fetch_liked_map(array($articleId), $visitorId);
        $liked = !empty($likedMap[$articleId]);
    }

    send_ok(array(
        'article_id' => $articleId,
        'like_count' => get_like_count($articleId),
        'liked'      => $liked,
    ));
}

/* ============================================================
 * POST / DELETE —— 写操作
 *
 * 点赞：POST  { "article_id": "…", "visitor_id": "…" }
 * 取消：POST  { "article_id": "…", "visitor_id": "…", "action": "unlike" }
 *
 * 也接受 DELETE 方法，等价于 action=unlike，方便用工具直接调。
 *
 * 为什么取消点赞用 POST + action 字段，而不是单独一个 DELETE 接口：
 *   部分虚拟主机 / CDN / WAF 会拦掉带请求体的 DELETE，症状是"本地 curl 好用、
 *   线上莫名其妙失败"，非常难查。POST 到处都能过。
 *   DELETE 只是顺手提供的别名，正式前端走 POST。
 * ============================================================ */

function handle_write($method)
{
    global $RATE_LIMIT_ENABLED, $RATE_LIMIT_MAX, $RATE_LIMIT_WINDOW, $SALT;

    $body = read_json_body();

    // ---- 动作 ----
    // 默认 like，保持向后兼容：老客户端不带 action 字段时行为不变。
    $action = 'like';
    if ($method === 'DELETE') {
        $action = 'unlike';
    } elseif (isset($body['action'])) {
        $action = strtolower(trim((string) $body['action']));
    }
    if ($action !== 'like' && $action !== 'unlike') {
        send_json(CODE_BAD_REQUEST, 'action must be "like" or "unlike"');
    }

    if (!isset($body['article_id'])) {
        send_json(CODE_BAD_REQUEST, 'article_id is required');
    }
    if (!isset($body['visitor_id'])) {
        send_json(CODE_BAD_REQUEST, 'visitor_id is required');
    }

    $articleId = validate_article_id(is_string($body['article_id']) ? trim($body['article_id']) : $body['article_id']);
    $visitorId = validate_visitor_id($body['visitor_id']);

    // 前端传来的只是原始标识，落库前一律换成不可逆哈希
    $visitorHash = hash('sha256', $visitorId . $SALT);
    $ipHash      = ip_hash();
    $uaHash      = user_agent_hash();

    $pdo = likes_db();

    // ---- 取消点赞 ----
    if ($action === 'unlike') {
        $result = perform_unlike($pdo, $articleId, $visitorHash);

        // 注意：从没赞过也算成功（幂等），不报错。
        // 只是 removed=false，前端据此知道"本来就没赞"。
        send_ok(array(
            'article_id' => $articleId,
            'like_count' => $result['like_count'],
            'liked'      => false,
            'removed'    => $result['removed'],
        ));
    }

    // ---- 限流（只针对点赞）----
    // 取消点赞不会新增明细，没有刷的价值；而且误伤正常用户得不偿失。
    if ($RATE_LIMIT_ENABLED && $ipHash !== null) {
        $window = (int) $RATE_LIMIT_WINDOW; // 强转 int 后拼进 SQL，不存在注入
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM like_records
             WHERE ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL ' . $window . ' SECOND)'
        );
        $stmt->execute(array($ipHash));
        $recent = (int) $stmt->fetchColumn();

        if ($recent >= (int) $RATE_LIMIT_MAX) {
            send_json(CODE_RATE_LIMITED, 'too many requests, slow down');
        }
    }

    // ---- 事务：写入明细 + 累加计数 ----
    $result = perform_like($pdo, $articleId, $visitorHash, $ipHash, $uaHash);

    $data = array(
        'article_id' => $articleId,
        'like_count' => $result['like_count'],
        'liked'      => true,
    );

    if (!$result['first_time']) {
        // 早赞过了。不是错误，把最新计数带回去让前端同步状态即可。
        send_json(CODE_ALREADY_LIKED, 'already liked', $data);
    }

    send_ok($data);
}

/**
 * 真正执行点赞的事务。内建死锁重试。
 *
 * 并发安全的关键有两点：
 *   1. like_records 上有 (article_id, visitor_hash) 唯一索引，
 *      INSERT IGNORE 让"同一人同一篇"最多只写进一行 —— 再多并发也只 +1 一次。
 *   2. article_likes 用 ON DUPLICATE KEY UPDATE like_count = like_count + 1，
 *      自增在 MySQL 内部完成，读-改-写之间没有空隙，不会互相覆盖。
 *
 * @return array{first_time:bool, like_count:int}
 */
function perform_like(PDO $pdo, $articleId, $visitorHash, $ipHash, $uaHash)
{
    $maxAttempts = 3;
    $attempt = 0;

    while (true) {
        $attempt++;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO like_records (article_id, visitor_hash, ip_hash, user_agent_hash)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute(array($articleId, $visitorHash, $ipHash, $uaHash));
            $firstTime = $stmt->rowCount() > 0;

            if ($firstTime) {
                $stmt = $pdo->prepare(
                    'INSERT INTO article_likes (article_id, like_count) VALUES (?, 1)
                     ON DUPLICATE KEY UPDATE like_count = like_count + 1'
                );
                $stmt->execute(array($articleId));
            }

            // 在事务内读计数。刚 UPDATE 过的事务已持有该行排他锁，
            // 读到的一定是自己写完的最新值，不会被并发请求读到中间态。
            $stmt = $pdo->prepare('SELECT like_count FROM article_likes WHERE article_id = ?');
            $stmt->execute(array($articleId));
            $count = $stmt->fetchColumn();

            $pdo->commit();

            return array(
                'first_time' => $firstTime,
                'like_count' => $count === false ? 0 : (int) $count,
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // 1213 = 死锁，1205 = 锁等待超时。这种是并发撞车，退一步重试即可。
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if (in_array($driverCode, array(1213, 1205), true) && $attempt < $maxAttempts) {
                usleep(50000 * $attempt); // 50ms、100ms 递进
                continue;
            }

            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * 取消点赞。内建与点赞相同的死锁重试。
 *
 * 顺序很重要：**先删明细，删掉了才减计数。**
 * 这样"计数 = 明细条数"这个不变量始终成立 —— 即使中途出错，
 * 也不会出现"计数减了但明细还在"的错位。
 *
 * 反过来做（先减计数再删明细）的话，一旦删明细失败，
 * 就会出现：计数少了 1，但那个人仍然被记为"已赞过"，
 * 于是他既赞不了也取消不掉，得手工修数据库。
 *
 * @return array{removed:bool, like_count:int}
 */
function perform_unlike(PDO $pdo, $articleId, $visitorHash)
{
    $maxAttempts = 3;
    $attempt = 0;

    while (true) {
        $attempt++;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'DELETE FROM like_records WHERE article_id = ? AND visitor_hash = ?'
            );
            $stmt->execute(array($articleId, $visitorHash));
            $removed = $stmt->rowCount() > 0;

            if ($removed) {
                // GREATEST 兜底，防止任何异常情况下把计数减成负数。
                // 正常运行不该触发，但数据库层面加一道保险不亏。
                $stmt = $pdo->prepare(
                    'UPDATE article_likes SET like_count = GREATEST(like_count - 1, 0)
                     WHERE article_id = ?'
                );
                $stmt->execute(array($articleId));
            }

            $stmt = $pdo->prepare('SELECT like_count FROM article_likes WHERE article_id = ?');
            $stmt->execute(array($articleId));
            $count = $stmt->fetchColumn();

            $pdo->commit();

            return array(
                'removed'    => $removed,
                'like_count' => $count === false ? 0 : (int) $count,
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if (in_array($driverCode, array(1213, 1205), true) && $attempt < $maxAttempts) {
                usleep(50000 * $attempt);
                continue;
            }

            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/* ============================================================
 * 校验
 * ============================================================ */

/**
 * 校验并返回 article_id。
 * 只允许字母、数字、下划线、中划线，长度 1–191（与数据库字段一致）。
 * 非法直接 400 并终止。
 */
function validate_article_id($id)
{
    if (!is_string($id) || $id === '') {
        send_json(CODE_BAD_REQUEST, 'invalid article_id');
    }
    if (strlen($id) > 191) {
        send_json(CODE_BAD_REQUEST, 'article_id too long, max 191');
    }
    // 白名单式校验：不在集合内的字符一律拒绝，顺带挡掉路径穿越之类的花活
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
        send_json(CODE_BAD_REQUEST, 'article_id may only contain letters, digits, underscore and hyphen');
    }
    return $id;
}

/**
 * 校验并返回 visitor_id。
 * 长度 8–128，只允许可见 ASCII 字符。
 */
function validate_visitor_id($id)
{
    if (!is_string($id)) {
        send_json(CODE_BAD_REQUEST, 'invalid visitor_id');
    }
    $id = trim($id);

    if (strlen($id) < 8 || strlen($id) > 128) {
        send_json(CODE_BAD_REQUEST, 'visitor_id length must be between 8 and 128');
    }
    if (!preg_match('/^[\x20-\x7E]+$/', $id)) {
        send_json(CODE_BAD_REQUEST, 'visitor_id contains invalid characters');
    }
    return $id;
}

/**
 * 从 query string 读可选的 visitor_id。没传返回 null（表示"不用算 liked"）。
 */
function read_visitor_id_from_query()
{
    if (!isset($_GET['visitor_id']) || !is_string($_GET['visitor_id'])) {
        return null;
    }
    $id = trim($_GET['visitor_id']);
    if ($id === '') {
        return null;
    }
    return validate_visitor_id($id);
}

/**
 * 读取请求体。
 * 优先按 JSON 解析；顺带兼容 application/x-www-form-urlencoded，
 * 方便你用表单或简单工具调试。
 */
function read_json_body()
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // 不是合法 JSON，再看是不是表单格式
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    send_json(CODE_BAD_REQUEST, 'request body must be valid JSON');
}

/* ============================================================
 * 查询小工具
 * ============================================================ */

function get_like_count($articleId)
{
    $stmt = likes_db()->prepare('SELECT like_count FROM article_likes WHERE article_id = ?');
    $stmt->execute(array($articleId));
    $count = $stmt->fetchColumn();
    return $count === false ? 0 : (int) $count;
}

/**
 * 批量查"这些文章里，该访客赞过哪些"。
 *
 * @param  string[] $articleIds
 * @param  string   $visitorId
 * @return array    article_id => true
 */
function fetch_liked_map(array $articleIds, $visitorId)
{
    global $SALT;

    if (count($articleIds) === 0) {
        return array();
    }

    $visitorHash = hash('sha256', $visitorId . $SALT);
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));

    $params = $articleIds;
    $params[] = $visitorHash;

    $stmt = likes_db()->prepare(
        'SELECT article_id FROM like_records
         WHERE article_id IN (' . $placeholders . ') AND visitor_hash = ?'
    );
    $stmt->execute($params);

    $map = array();
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['article_id']] = true;
    }
    return $map;
}

/* ============================================================
 * 访客标识
 * ============================================================ */

/**
 * 取客户端 IP。
 * 只有 $TRUST_PROXY = true 时才采信 X-Forwarded-For 之类的头，
 * 否则任何人都能伪造它绕过限流。
 */
function client_ip()
{
    global $TRUST_PROXY;

    if ($TRUST_PROXY) {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR');
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $parts = explode(',', $_SERVER[$key]);
            $first = trim($parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }

    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** IP 哈希，用于限流。取不到 IP 时返回 null（则该请求不参与限流）。 */
function ip_hash()
{
    global $SALT;

    $ip = client_ip();
    if ($ip === '') {
        return null;
    }
    return hash('sha256', $ip . $SALT);
}

/** User-Agent 哈希，只作分析用，取不到就存 null。 */
function user_agent_hash()
{
    global $SALT;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua === '') {
        return null;
    }
    return hash('sha256', substr($ua, 0, 255) . $SALT);
}
