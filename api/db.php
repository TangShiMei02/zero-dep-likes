<?php
/**
 * 博客点赞系统 — 数据库连接与日志
 *
 * 全程使用 PDO + 预处理语句，杜绝 SQL 注入。
 * 不引入任何 Composer 依赖，纯 PHP 标准库。
 *
 * @package BlogLikes
 * @version 1.0.0
 */

if (!defined('LIKES_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 取得 PDO 连接（同一次请求内复用，只连一次）。
 *
 * @return PDO
 * @throws RuntimeException 连接失败时抛出
 */
function likes_db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET, $DB_SOCKET;

    if ($DB_SOCKET !== '') {
        $dsn = 'mysql:unix_socket=' . $DB_SOCKET
             . ';dbname=' . $DB_NAME
             . ';charset=' . $DB_CHARSET;
    } else {
        $dsn = 'mysql:host=' . $DB_HOST
             . ';port=' . $DB_PORT
             . ';dbname=' . $DB_NAME
             . ';charset=' . $DB_CHARSET;
    }

    $options = array(
        // 出错就抛异常，别默默返回 false
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // 关掉模拟预处理，交给 MySQL 真正做预处理，参数类型才不会跑偏
        PDO::ATTR_EMULATE_PREPARES   => false,
        // 让 INT 字段以 PHP int 返回，而不是字符串 "12"
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_TIMEOUT            => 5,
    );

    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    } catch (PDOException $e) {
        // 连不上数据库是部署期最常见的问题，日志里留全信息
        likes_log('DB connect failed: ' . $e->getMessage());

        // 把 MySQL 的原话附在异常消息里。
        // 它只会在 $DEBUG = true 时被返回到前端；生产环境（$DEBUG = false）
        // 对外始终只有一句 "server error"，不会泄露数据库信息。
        //
        // 附上原话很重要：MySQL 的报错是分门别类的，一眼能定位问题——
        //   1045 Access denied            → 用户名或密码错
        //   1044 Access denied for db     → 用户没绑定到这个库 / 没有权限
        //   1049 Unknown database         → 数据库名写错
        //   2002 Connection refused       → 主机地址写错（不该是 localhost）
        throw new RuntimeException('database connection failed: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * 写错误日志。
 * 配了 $LOG_FILE 就写文件（建议放在网站根目录之外），否则交给 PHP 错误日志。
 *
 * @param string $message
 */
function likes_log($message)
{
    global $LOG_ERRORS, $LOG_FILE;

    if (!$LOG_ERRORS) {
        return;
    }

    $line = '[likes] ' . date('Y-m-d H:i:s') . ' ' . $message;

    if ($LOG_FILE !== '') {
        // 文件锁 + 追加，避免并发写串行
        @file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        return;
    }

    error_log($line);
}
