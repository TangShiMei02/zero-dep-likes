<?php
/**
 * 一次性部署诊断文件
 *
 * 用途：你上传后打开它，它直接告诉你「文件到底在服务器的哪个目录」、
 *       「网站根目录是哪个」、「api/ 和 assets/ 在这个目录下找不找得到」。
 *
 * 用法：
 *   1. 上传到你「以为」的网站根目录
 *   2. 浏览器打开 https://你的域名/where.php
 *   3. 照它给出的结论做
 *   4. ★ 修好之后请立刻删除本文件 ★
 *
 * 它只读目录、不写任何东西，但会暴露服务器路径，不能长期留在线上。
 */

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__;
$sep = DIRECTORY_SEPARATOR;

/*
 * 按本文件的实际位置拼出接口地址。
 *
 * 刻意不写死成 https://域名/api/likes.php —— 本项目通常部署在子目录里
 * （例如 /zero-dep-likes/），写死根目录会给出一个必然 404 的地址，反而误导排查。
 */
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off')
    ? 'https' : 'http';
$hostName = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '你的域名';
$selfDir = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $selfDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
}
$baseUrl = $scheme . '://' . $hostName . $selfDir;
$testUrl = $baseUrl . '/api/likes.php?article_id=test-001';

echo "================= 部署诊断 =================\n\n";

echo "本文件所在目录      : {$dir}\n";
echo "网站根目录(文档根)  : " . (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '(服务器未提供)') . "\n";
echo "当前访问的 URL      : " . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '(未知)') . "\n";
echo "推断的接口地址      : {$testUrl}\n";
echo "PHP 版本            : " . PHP_VERSION . "\n";
echo "服务器软件          : " . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '(未知)') . "\n";

echo "\n---------- 这个目录是网站根目录吗 ----------\n\n";

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
$here    = rtrim(str_replace('\\', '/', $dir), '/');

if ($docRoot !== '' && $here === $docRoot) {
    echo "★ 是。本文件就在网站根目录下。\n";
    echo "  所以 api/ 和 assets/ 应该就放在这里，和本文件同级。\n";
} elseif ($docRoot !== '') {
    echo "☆ 不是。网站根目录是：{$docRoot}\n";
    echo "  本文件却在      ：{$here}\n";
    echo "  → 说明你上传的位置比网站根目录深了一层或偏了。\n";
    echo "    请把 api/ 和 assets/ 移到上面那个网站根目录里。\n";
} else {
    echo "服务器没提供 DOCUMENT_ROOT，无法自动判断。\n";
}

echo "\n---------- api/ 和 assets/ 找得到吗 ----------\n\n";

$checks = array(
    'api/likes.php'         => '后端入口（必须有）',
    'api/config.php'        => '后端配置（必须有）',
    'api/db.php'            => '数据库连接（必须有）',
    'api/response.php'      => '响应封装（必须有）',
    'assets/likes/likes.js' => '前端脚本（必须有）',
    'assets/likes/likes.css'=> '前端样式（必须有）',
    'sql/schema.sql'        => '建表语句（不需要上传，本地留档即可）',
);

$found = 0;
$total = 0;
foreach ($checks as $rel => $label) {
    $path = $dir . $sep . str_replace('/', $sep, $rel);
    $exists = file_exists($path);
    if (strpos($label, '必须有') !== false) {
        $total++;
        if ($exists) { $found++; }
    }
    printf("%-24s %s   %s\n", $rel, $exists ? '[找到]' : '[缺失]', $label);
}

echo "\n---------- 这个目录里实际有什么 ----------\n\n";

$entries = @scandir($dir);
$nestedHits = array();

if ($entries === false) {
    echo "(目录不可读，可能是权限限制)\n";
} else {
    $dirs = array();
    $files = array();
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') { continue; }
        if (is_dir($dir . $sep . $e)) {
            $dirs[] = $e;
        } else {
            $files[] = $e;
        }
    }
    sort($dirs);
    sort($files);

    // 探测"解压时多套了一层目录"：
    // 子目录里如果还有一份 api/likes.php，几乎可以肯定整包被解压到了下一层，
    // 而外面这些都是上一版留下的旧文件 —— 这是"明明传了却还是旧的"的典型成因。
    foreach ($dirs as $d) {
        if (is_file($dir . $sep . $d . $sep . 'api' . $sep . 'likes.php')) {
            $nestedHits[] = $d;
        }
    }

    if ($dirs) {
        echo "目录：\n";
        foreach ($dirs as $d) {
            $tag = in_array($d, $nestedHits, true) ? '   ← 这里面还有一份 api/likes.php' : '';
            echo "  [目录] {$d}/{$tag}\n";
        }
    } else {
        echo "目录：(一个都没有)\n";
    }
    echo "\n";
    if ($files) {
        echo "文件：\n";
        foreach ($files as $f) {
            echo "  {$f}\n";
        }
    } else {
        echo "文件：(一个都没有)\n";
    }
}

echo "\n---------- 关键警告：是不是多套了一层 ----------\n\n";

if (count($nestedHits) > 0) {
    echo "★★ 发现问题了 ★★\n\n";
    foreach ($nestedHits as $d) {
        echo "  子目录 {$d}/ 里也有一份 api/likes.php。\n";
        echo "  说明你压缩包解压到了下一层，新文件全在 {$d}/ 里面，\n";
        echo "  而本文件所在的这一层，还是上一版留下的旧文件 ——\n";
        echo "  这就是「明明上传了，行为却没变」的典型原因。\n\n";
        echo "  验证一下：浏览器打开\n";
        echo "    {$baseUrl}/{$d}/api/likes.php?article_id=test-001\n";
        echo "  如果能返回 JSON，就证实了。\n\n";
        echo "  怎么修：把 {$d}/ 里的 api/ 和 assets/ 移到本文件这一层（覆盖旧文件），\n";
        echo "  然后把空的 {$d}/ 目录删掉。也可以在解压时选择「解压到当前目录」，\n";
        echo "  不要把压缩包里的 zero-dep-likes/ 那一层也解出来。\n";
    }
} else {
    echo "这一层没有嵌套的 api/ 目录，解压位置没问题。\n\n";
    echo "如果前端行为仍是旧的，那就是浏览器缓存 —— Ctrl+F5 强刷一次。\n";
}

echo "\n---------- 结论 ----------\n\n";

if ($found === $total) {
    echo "这一层放对了：api/ 和 assets/ 都能找到，{$found}/{$total} 项齐全。\n";
    echo "如果这时候浏览器访问下面的地址还是 404，那说明网站的域名\n";
    echo "绑定的不是这个目录（虚拟主机里每个域名通常各自绑一个目录，\n";
    echo "你传到了别的域名的目录里）。\n\n";
    echo "下一步验证这个地址（已按本文件的实际位置拼好）：\n";
    echo "  {$testUrl}\n";
    echo "  期望返回 {\"code\":0,...,\"like_count\":0,...}\n";
} elseif ($found === 0) {
    echo "这一层完全没有 api/。\n\n";
    echo "两种可能：\n";
    echo "  1) 你上传时多套了一层目录 —— 压缩包解开后有个 zero-dep-likes/ 文件夹，\n";
    echo "     文件其实在 zero-dep-likes/api/ 里面。把 api/ 和 assets/ 从里面\n";
    echo "     移到本文件所在的这一层即可。\n";
    echo "  2) 本文件被你传到了错误的位置（比如 FTP 顶层，而不是网站根目录）。\n";
    echo "     那就把本文件挪到 api/ 所在的那一层，再打开一次。\n";
} else {
    echo "只找到 {$found}/{$total} 项，说明上传不完整（可能断线或漏传子目录）。\n";
    echo "对照上面标着 [缺失] 的路径，重新上传对应文件。\n";
}

echo "\n---------- 网站根目录怎么找 ----------\n\n";
echo "通用办法：你的博客首页 https://你的域名/ 能打开，\n";
echo "那么「首页那个 index 文件」所在的目录就是网站根目录，api/ 要放在那里。\n\n";
echo "虚拟主机常见的网站根目录名（在 FTP 里对号入座）：\n";
echo "  public_html/   htdocs/   wwwroot/   www/   web/\n";
echo "  或者与域名同名的文件夹，例如 blog.example.com/\n\n";

echo "★ 修好之后请删除本文件（where.php）。★\n";
