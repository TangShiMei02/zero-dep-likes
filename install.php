<?php
/**
 * 点赞系统 · 一次性建表程序
 *
 * ============================================================
 * 用法
 * ============================================================
 *   1. 把本文件上传到 api/ 目录的**上一级**（也就是和 api/ 同级）
 *   2. 浏览器打开   https://你的域名/安装路径/install.php
 *   3. 点「开始建表」
 *   4. 看到两张表都是 ✓ 之后，点「删除本文件」，它会把自己删掉
 *
 *   如果你的布局是多套了一层，例如 /zero-dep-likes/api/…
 *   那就把本文件放进 /zero-dep-likes/ 里，和 api/ 同级。
 *
 * ============================================================
 * 安全性说明（为什么这个文件可以放心跑）
 * ============================================================
 *   - 只执行 CREATE TABLE IF NOT EXISTS，**幂等**：
 *     表已存在就跳过，绝不 DROP、绝不 ALTER、绝不碰已有数据
 *   - **不会自动执行**：打开页面只是看状态，必须手动点按钮才动手
 *   - 不显示数据库密码，只显示库名与主机
 *   - 支持一键删除自身，省得忘记
 *   - 即便如此，**用完仍然必须删掉**：它会暴露数据库名、表结构等服务器信息
 *
 * ============================================================
 * 注意
 * ============================================================
 *   本文件里的建表语句与 sql/schema.sql 是同一套结构。
 *   改了一处就要改另一处；本程序会在建表后自动校验字段，
 *   万一两边不一致会当场报出来。
 *
 * @version 1.2.0
 */

// ============================================================
// 一、定位并加载配置
// ============================================================

$configPath = null;

$candidates = array(
    __DIR__ . '/api/config.php',        // 常规：install.php 与 api/ 同级
    __DIR__ . '/config.php',            // 配置与 install.php 同级
    dirname(__DIR__) . '/api/config.php', // install.php 被放进了子目录
);

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $configPath = $candidate;
        break;
    }
}

if ($configPath === null) {
    install_render_fatal(
        '找不到 api/config.php',
        '本文件必须和 api/ 目录放在同一层。',
        '请把它移动到 api/ 的上一级目录，也就是 <code>api/</code> 和本文件并排的位置，再刷新本页。',
        $candidates
    );
}

define('LIKES_APP', true);
require $configPath;
require dirname($configPath) . '/db.php';

// ============================================================
// 二、建表语句（与 sql/schema.sql 保持一致）
// ============================================================

$TABLES = array('article_likes', 'like_records');

$DDL = array();

$DDL['article_likes'] = "
CREATE TABLE IF NOT EXISTS `article_likes` (
  `article_id` VARCHAR(191) NOT NULL COMMENT '文章唯一标识，建议用 slug',
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计点赞数',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次被点赞的时间',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后一次被点赞的时间',
  PRIMARY KEY (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章点赞总数'
";

$DDL['like_records'] = "
CREATE TABLE IF NOT EXISTS `like_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article_id` VARCHAR(191) NOT NULL COMMENT '文章唯一标识',
  `visitor_hash` CHAR(64) NOT NULL COMMENT 'sha256(visitor_id + salt)',
  `ip_hash` CHAR(64) NULL COMMENT 'sha256(IP + salt)，用于限流',
  `user_agent_hash` CHAR(64) NULL COMMENT 'sha256(User-Agent + salt)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '点赞时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_article_visitor` (`article_id`, `visitor_hash`),
  KEY `idx_article_id` (`article_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_created` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='点赞明细（防重）'
";

// 建表后用来校验结构的期望字段
$EXPECTED_COLUMNS = array(
    'article_likes' => array('article_id', 'like_count', 'created_at', 'updated_at'),
    'like_records'  => array('id', 'article_id', 'visitor_hash', 'ip_hash', 'user_agent_hash', 'created_at'),
);

// 防重复点赞靠的就是这个唯一索引，必须单独确认它在
$REQUIRED_INDEX = array(
    'table' => 'like_records',
    'name'  => 'uniq_article_visitor',
);

// ============================================================
// 三、执行
// ============================================================

$action = isset($_POST['action']) ? $_POST['action'] : '';

$pdo          = null;
$connectError = null;
$mysqlVersion = '';
$dbName       = '';
$dbHost       = '';

try {
    $pdo = likes_db();
    $mysqlVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    $stmt = $pdo->query('SELECT DATABASE()');
    $dbName = (string) $stmt->fetchColumn();
    $dbHost = $DB_HOST . ($DB_SOCKET !== '' ? ' (socket: ' . $DB_SOCKET . ')' : ':' . $DB_PORT);
} catch (Throwable $e) {
    $connectError = $e->getMessage();
}

/**
 * 表是否存在。
 *
 * 注意：这里刻意不用 SHOW TABLES —— MySQL 的 SHOW 语句**不支持预处理协议**，
 * 而本项目关闭了模拟预处理（ATTR_EMULATE_PREPARES = false），
 * 用 SHOW 会直接抛 "This command is not supported in the prepared statement protocol yet"。
 * 查 information_schema 是普通 SELECT，可以正常绑定参数。
 */
function install_table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute(array($table));
    return ((int) $stmt->fetchColumn()) > 0;
}

/** 取字段名列表（同样避开 SHOW COLUMNS） */
function install_columns(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute(array($table));

    $names = array();
    foreach ($stmt->fetchAll() as $row) {
        $names[] = $row['COLUMN_NAME'];
    }
    return $names;
}

/** 索引是否存在（避开 SHOW INDEX） */
function install_index_exists(PDO $pdo, $table, $indexName)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute(array($table, $indexName));
    return ((int) $stmt->fetchColumn()) > 0;
}

$tablesBefore = array();
$installLog   = array();

if ($pdo !== null) {
    foreach ($TABLES as $t) {
        $tablesBefore[$t] = install_table_exists($pdo, $t);
    }

    if ($action === 'install') {
        foreach ($TABLES as $t) {
            if ($tablesBefore[$t]) {
                $installLog[] = array($t, 'skip', '本来就有，跳过（没有动它）');
                continue;
            }
            try {
                $pdo->exec($DDL[$t]);
                $installLog[] = array($t, 'created', '已创建');
            } catch (Throwable $e) {
                $installLog[] = array($t, 'error', $e->getMessage());
            }
        }
    }

    // 执行完重新读一遍真实状态
    foreach ($TABLES as $t) {
        $tablesBefore[$t] = install_table_exists($pdo, $t);
    }
}

// 结构校验
$structureCheck = array();
$indexOk = null;

if ($pdo !== null) {
    foreach ($EXPECTED_COLUMNS as $t => $expected) {
        if (!$tablesBefore[$t]) {
            $structureCheck[$t] = null; // 表都没有，无从校验
            continue;
        }
        $actual = install_columns($pdo, $t);
        $missing = array_diff($expected, $actual);
        $extra   = array_diff($actual, $expected);
        $structureCheck[$t] = array(
            'ok'      => (count($missing) === 0 && count($extra) === 0),
            'missing' => $missing,
            'extra'   => $extra,
            'count'   => count($actual),
        );
    }

    if ($tablesBefore[$REQUIRED_INDEX['table']]) {
        $indexOk = install_index_exists($pdo, $REQUIRED_INDEX['table'], $REQUIRED_INDEX['name']);
    }
}

// 删自己
$selfDeleted = false;
$deleteError = null;

if ($action === 'selfdestruct') {
    if (@unlink(__FILE__)) {
        $selfDeleted = true;
    } else {
        $deleteError = '自动删除失败：文件权限不足。请用 FTP 或主机面板手动删除。';
    }
}

// 拼出接口的真实地址，省得她自己猜
$https  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off');
$scheme = $https ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '你的域名';
$here   = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
$here   = rtrim($here, '/');

$apiUrl   = $scheme . '://' . $host . $here . '/api/likes.php';
$testUrl  = $apiUrl . '?article_id=test-001';
$checkUrl = $scheme . '://' . $host . $here . '/check.html';

$allTablesOk = ($pdo !== null);
if ($allTablesOk) {
    foreach ($TABLES as $t) {
        if (!$tablesBefore[$t]) { $allTablesOk = false; }
    }
}

$structureAllOk = true;
foreach ($structureCheck as $c) {
    if ($c !== null && !$c['ok']) { $structureAllOk = false; }
}

$debugOn = !empty($DEBUG);

// ============================================================
// 四、输出页面
// ============================================================

install_render_top($selfDeleted);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>点赞系统 · 建表安装</title>
<link rel="icon" type="image/svg+xml" href="assets/likes/icon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/likes/icon-32.png">
<link rel="apple-touch-icon" href="assets/likes/icon-180.png">
<style>
  :root {
    --bg: #f6f6f7; --surface: #fff; --text: #23262b; --dim: #6a7078; --line: #e3e4e7;
    --ok: #1a7f4b; --ok-bg: #eaf6ef; --bad: #c0392b; --bad-bg: #fdeeec;
    --warn: #8a6100; --warn-bg: #fff8e6; --accent: #F77234;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 2.5rem 1.25rem 4rem; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
                 "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    font-size: 15px; line-height: 1.7;
  }
  .wrap { max-width: 50rem; margin: 0 auto; }
  h1 { font-size: 1.25rem; margin: 0 0 .3rem; }
  h2 { font-size: .95rem; margin: 0 0 .7rem; }
  .sub { color: var(--dim); font-size: .85rem; margin: 0 0 1.25rem; }
  .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 8px;
           padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
  table { width: 100%; border-collapse: collapse; font-size: .875rem; }
  th { text-align: left; font-weight: 600; font-size: .78rem; color: var(--dim);
       padding: .45rem .5rem; border-bottom: 1px solid var(--line); white-space: nowrap; }
  td { padding: .55rem .5rem; border-bottom: 1px solid var(--line); vertical-align: top; }
  tr:last-child td { border-bottom: none; }
  .badge { display: inline-block; min-width: 3.4rem; text-align: center; padding: .1rem .4rem;
           border-radius: 4px; font-size: .76rem; white-space: nowrap; }
  .badge.ok { background: var(--ok-bg); color: var(--ok); }
  .badge.bad { background: var(--bad-bg); color: var(--bad); }
  .badge.skip { background: #f0f0f1; color: var(--dim); }
  .badge.created { background: var(--ok-bg); color: var(--ok); }
  .kv { font-size: .85rem; }
  .kv div { display: flex; gap: .6rem; padding: .18rem 0; }
  .kv b { flex: 0 0 8.5rem; font-weight: 600; color: var(--dim); }
  .kv span { word-break: break-all; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem; word-break: break-all; }
  .btn { padding: .55rem 1.2rem; font: inherit; font-size: .92rem; color: #fff;
         background: var(--accent); border: 1px solid var(--accent); border-radius: 6px; cursor: pointer; }
  .btn.ghost { background: transparent; color: var(--text); border-color: var(--line); }
  .btn.danger { background: transparent; color: var(--bad); border-color: #f2c3bd; }
  .btn:disabled { opacity: .5; cursor: default; }
  .row { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
  .note { font-size: .85rem; color: var(--dim); margin: .6rem 0 0; }
  .callout { border-radius: 6px; padding: .75rem 1rem; font-size: .86rem; margin: 0 0 1rem; border: 1px solid; }
  .callout.warn { background: var(--warn-bg); border-color: #f0dcae; color: var(--warn); }
  .callout.ok { background: var(--ok-bg); border-color: #b7e0c8; color: var(--ok); }
  .callout.bad { background: var(--bad-bg); border-color: #f2c3bd; color: var(--bad); }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .86em;
         padding: .1em .35em; border-radius: 4px; background: #f0f0f1; }
  a { color: var(--accent); }
  ul { margin: .4rem 0 0; padding-left: 1.2rem; font-size: .86rem; }
</style>
</head>
<body>
<div class="wrap">
<?php if ($selfDeleted): ?>

  <h1>已删除</h1>
  <div class="callout ok">
    <b>install.php 已经从服务器上删掉了。</b>这个页面刷新后会变成 404，那是正常的。
  </div>
  <?php if ($allTablesOk): ?>
    <div class="panel">
      <h2>接下来</h2>
      <ul>
        <li>测试接口：<a href="<?php echo install_h($testUrl); ?>" target="_blank" class="mono"><?php echo install_h($testUrl); ?></a>
            —— 应返回 <code>code:0</code> 和 <code>like_count:0</code></li>
        <li>如果之前把 <code>$DEBUG</code> 改成了 <code>true</code>，<b>现在改回 <code>false</code></b> 并重传 <code>api/config.php</code>。</li>
        <li>跑一遍 <code>check.html</code> 做完整自检，然后也可以把它删掉。</li>
      </ul>
    </div>
  <?php endif; ?>

<?php else: ?>

  <h1>点赞系统 · 建表安装</h1>
  <p class="sub">一次性程序。建完表请点「删除本文件」把它自己删掉。</p>

  <div class="callout warn">
    <b>本文件只做一件事：执行 <code>CREATE TABLE IF NOT EXISTS</code>。</b>
    它是幂等的——表已经存在就跳过，不会 DROP、不会修改、不会碰你的任何数据。
    但用完之后它没有留在服务器上的理由，请务必删除。
  </div>

  <?php if ($connectError !== null): ?>

    <div class="callout bad">
      <b>连不上数据库，建表没法进行。</b>
      <div class="mono" style="margin-top:.4rem"><?php echo install_h($connectError); ?></div>
    </div>

    <div class="panel">
      <h2>对照着查</h2>
      <table>
        <thead><tr><th style="width:44%">报错里的关键词</th><th>意思与处理</th></tr></thead>
        <tbody>
          <tr><td class="mono">[1045] Access denied</td><td>用户名或密码不对，回主机面板核对</td></tr>
          <tr><td class="mono">[1044] Access denied for database</td><td>用户存在但没绑定到这个库，去面板里授权</td></tr>
          <tr><td class="mono">[1049] Unknown database</td><td>数据库名写错（注意主机商前缀）</td></tr>
          <tr><td class="mono">[2002] Connection refused</td><td>主机地址写错，虚拟主机多数是 <code>localhost</code></td></tr>
          <tr><td class="mono">could not find driver</td><td>PHP 没装 pdo_mysql 扩展，需要找主机商开</td></tr>
        </tbody>
      </table>
      <p class="note">改完 <code>api/config.php</code> 里的数据库四项后，刷新本页重试。</p>
    </div>

  <?php else: ?>

    <div class="panel">
      <h2>连接情况</h2>
      <div class="kv">
        <div><b>数据库</b><span class="mono"><?php echo install_h($dbName); ?></span></div>
        <div><b>主机</b><span class="mono"><?php echo install_h($dbHost); ?></span></div>
        <div><b>MySQL 版本</b><span class="mono"><?php echo install_h($mysqlVersion); ?></span></div>
        <div><b>PHP 版本</b><span class="mono"><?php echo install_h(PHP_VERSION); ?></span></div>
        <div><b>配置文件</b><span class="mono"><?php echo install_h($configPath); ?></span></div>
      </div>
      <p class="note">
        确认这里的「数据库」就是你在 <code>api/config.php</code> 里填的那个库——
        表必须建在这个库里，否则接口照样找不到。
      </p>
    </div>

    <?php if (count($installLog) > 0): ?>
      <div class="panel">
        <h2>本次执行结果</h2>
        <table>
          <thead><tr><th style="width:11rem">数据表</th><th style="width:5rem">结果</th><th>说明</th></tr></thead>
          <tbody>
            <?php foreach ($installLog as $entry): ?>
              <tr>
                <td class="mono"><?php echo install_h($entry[0]); ?></td>
                <td>
                  <?php if ($entry[1] === 'created'): ?>
                    <span class="badge created">已创建</span>
                  <?php elseif ($entry[1] === 'skip'): ?>
                    <span class="badge skip">跳过</span>
                  <?php else: ?>
                    <span class="badge bad">失败</span>
                  <?php endif; ?>
                </td>
                <td class="mono"><?php echo install_h($entry[2]); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h2>数据表状态</h2>
      <table>
        <thead><tr><th style="width:11rem">数据表</th><th style="width:5rem">状态</th><th>用途</th></tr></thead>
        <tbody>
          <tr>
            <td class="mono">article_likes</td>
            <td><?php echo $tablesBefore['article_likes']
                  ? '<span class="badge ok">已存在</span>'
                  : '<span class="badge bad">缺失</span>'; ?></td>
            <td>每篇文章的总点赞数</td>
          </tr>
          <tr>
            <td class="mono">like_records</td>
            <td><?php echo $tablesBefore['like_records']
                  ? '<span class="badge ok">已存在</span>'
                  : '<span class="badge bad">缺失</span>'; ?></td>
            <td>点赞明细，靠唯一索引防止重复点赞</td>
          </tr>
        </tbody>
      </table>

      <?php if ($allTablesOk): ?>
        <p class="note">两张表都在了。</p>

        <div style="margin-top:.9rem">
          <?php if ($structureAllOk): ?>
            <div class="callout ok" style="margin:0">
              <b>字段结构校验通过</b>，和 <code>sql/schema.sql</code> 一致。
              <?php if ($indexOk === true): ?>
                防重复用的唯一索引 <code>uniq_article_visitor</code> 也在。
              <?php elseif ($indexOk === false): ?>
                但<b>唯一索引 <code>uniq_article_visitor</code> 不在</b>——少了它，同一个人可以重复点赞。
                建议删掉 <code>like_records</code> 表后重新跑一次本程序。
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="callout bad" style="margin:0">
              <b>字段结构对不上</b>，说明表是别的方式建出来的，或者版本不一致：
              <ul>
                <?php foreach ($structureCheck as $t => $c): ?>
                  <?php if ($c !== null && !$c['ok']): ?>
                    <li class="mono"><?php echo install_h($t); ?>：
                      <?php if ($c['missing']): ?>缺少 <?php echo install_h(implode(', ', $c['missing'])); ?><?php endif; ?>
                      <?php if ($c['extra']): ?> 多出 <?php echo install_h(implode(', ', $c['extra'])); ?><?php endif; ?>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <p class="note">有表还没建，点下面的按钮执行。</p>
      <?php endif; ?>
    </div>

    <?php if (!$allTablesOk): ?>
      <div class="panel">
        <h2>建表</h2>
        <form method="post">
          <input type="hidden" name="action" value="install">
          <div class="row">
            <button class="btn" type="submit">开始建表</button>
            <span class="note" style="margin:0">只执行 CREATE TABLE IF NOT EXISTS，可反复点，不会损坏数据。</span>
          </div>
        </form>
      </div>
    <?php else: ?>
      <div class="panel">
        <h2>下一步</h2>
        <ol style="font-size:.88rem; margin:0; padding-left:1.2rem">
          <li style="margin-bottom:.5rem">
            测试接口是否通了（应返回 <code>code:0</code>、<code>like_count:0</code>）：<br>
            <a href="<?php echo install_h($testUrl); ?>" target="_blank" class="mono"><?php echo install_h($testUrl); ?></a>
          </li>
          <li style="margin-bottom:.5rem">
            <?php if ($debugOn): ?>
              <b>把 <code>api/config.php</code> 里的 <code>$DEBUG</code> 改回 <code>false</code> 并重新上传。</b>
              现在它是打开的，会把数据库信息暴露给访问者。
            <?php else: ?>
              <code>$DEBUG</code> 是关闭的，很好，保持这样。
            <?php endif; ?>
          </li>
          <li style="margin-bottom:.5rem">
            想做完整体检的话，把 <code>check.html</code> 传到同一层再打开：<br>
            <a href="<?php echo install_h($checkUrl); ?>" target="_blank" class="mono"><?php echo install_h($checkUrl); ?></a>
          </li>
          <li>
            最后——<b>删掉本文件</b>。点下面的按钮就行。
          </li>
        </ol>
      </div>

      <div class="panel">
        <h2>删除本文件</h2>
        <?php if ($deleteError !== null): ?>
          <div class="callout bad" style="margin-bottom:.8rem"><?php echo install_h($deleteError); ?></div>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('确定删除 install.php？删除后如需再次建表，重新上传本文件即可。');">
          <input type="hidden" name="action" value="selfdestruct">
          <div class="row">
            <button class="btn danger" type="submit">删除 install.php</button>
            <span class="note" style="margin:0">删完刷新本页会变成 404，那是正常的。</span>
          </div>
        </form>
      </div>
    <?php endif; ?>

  <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
<?php

// ============================================================
// 辅助函数
// ============================================================

function install_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 页面顶部（在 DOCTYPE 之前）不应该有任何输出，这里留个空实现以便将来扩展 */
function install_render_top($selfDeleted)
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
}

/** 找不到配置文件时的兜底页面 */
function install_render_fatal($title, $reason, $howToFix, array $tried)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>点赞系统 · 安装程序</title>
<style>
  body { margin:0; padding:2.5rem 1.25rem; background:#f6f6f7; color:#23262b;
         font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
         font-size:15px; line-height:1.7; }
  .wrap { max-width:44rem; margin:0 auto; background:#fff; border:1px solid #e3e4e7;
          border-radius:8px; padding:1.5rem; }
  h1 { font-size:1.15rem; margin:0 0 .6rem; color:#c0392b; }
  code { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.86em;
         padding:.1em .35em; border-radius:4px; background:#f0f0f1; }
  ul { padding-left:1.2rem; font-size:.85rem; color:#6a7078; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?php echo install_h($title); ?></h1>
  <p><?php echo install_h($reason); ?></p>
  <p><?php echo $howToFix; ?></p>
  <p style="font-size:.85rem;color:#6a7078;margin-bottom:.3rem">已经找过这些位置：</p>
  <ul>
    <?php foreach ($tried as $path): ?>
      <li><code><?php echo install_h($path); ?></code></li>
    <?php endforeach; ?>
  </ul>
</div>
</body>
</html><?php
    exit;
}
