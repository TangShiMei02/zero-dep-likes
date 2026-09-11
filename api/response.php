<?php
/**
 * 博客点赞系统 — 统一响应与 CORS
 *
 * 约定所有接口都返回同一套 JSON 外壳：
 *   { "code": 0, "msg": "ok", "data": {...} }
 *
 * @package BlogLikes
 * @version 1.0.0
 */

if (!defined('LIKES_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/* ---------------- 业务错误码 ---------------- */

const CODE_OK            = 0;     // 成功
const CODE_BAD_REQUEST   = 400;   // 参数错误
const CODE_RATE_LIMITED  = 429;   // 请求过于频繁
const CODE_ALREADY_LIKED = 1001;  // 已经点过赞
const CODE_SERVER_ERROR  = 500;   // 服务器错误

/**
 * 业务码 → HTTP 状态码。
 * 1001 虽然是"没成功写入"，但对客户端来说不是错误，
 * 而是一个正常的业务状态（顺便还带回最新点赞数），所以给 200，
 * 让前端能顺利读到 data 并同步按钮状态。
 */
function http_status_for($code)
{
    switch ($code) {
        case CODE_OK:
        case CODE_ALREADY_LIKED:
            return 200;
        case CODE_BAD_REQUEST:
            return 400;
        case CODE_RATE_LIMITED:
            return 429;
        default:
            return 500;
    }
}

/**
 * 输出 JSON 并结束请求。
 *
 * @param int        $code  业务码
 * @param string     $msg   提示信息
 * @param mixed|null $data  数据体；null 会序列化成 {}
 */
function send_json($code, $msg, $data = null)
{
    if (!headers_sent()) {
        http_response_code(http_status_for($code));
        header('Content-Type: application/json; charset=utf-8');
        // 点赞数是实时数据，任何缓存层都不许缓存
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    $payload = array(
        'code' => $code,
        'msg'  => $msg,
        // 用 stdClass 而不是空数组，保证序列化出来是 {} 而不是 []
        'data' => $data === null ? new stdClass() : $data,
    );

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 成功响应 */
function send_ok($data = null)
{
    send_json(CODE_OK, 'ok', $data);
}

/**
 * 判断某个 Origin 是否在白名单内。
 *
 * 支持两种写法：
 *   1) 精确匹配：'https://blog.example.com'
 *   2) 通配子域：'https://*.example.com'
 *      —— 放行 example.com 的任意子域，但**不含** example.com 本身，
 *         也不含伪装域名（evilexample.com 不会通过，
 *         因为匹配时要求落在点边界上）
 *
 * @param  string $origin        浏览器发来的 Origin
 * @param  array  $allowedOrigins 白名单
 * @return bool
 */
function origin_is_allowed($origin, array $allowedOrigins)
{
    $origin = rtrim($origin, '/');

    // 先做精确匹配，这是最常用也最安全的路径
    if (in_array($origin, $allowedOrigins, true)) {
        return true;
    }

    // 再做通配匹配
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $originScheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    $originHost   = strtolower($parts['host']);

    foreach ($allowedOrigins as $pattern) {
        $pattern = rtrim(trim((string) $pattern), '/');

        // 只处理 'scheme://*.host' 这种形式
        if (strpos($pattern, '://*.') === false) {
            continue;
        }

        $pieces = explode('://', $pattern, 2);
        if (count($pieces) !== 2) {
            continue;
        }
        if (strtolower($pieces[0]) !== $originScheme) {
            continue;
        }

        // '*.example.com' → '.example.com'
        $suffix = substr($pieces[1], 1);
        if ($suffix === '' || $suffix[0] !== '.') {
            continue;
        }
        $suffix = strtolower($suffix);

        // 必须落在点边界上：
        //   blog.example.com 以 '.example.com' 结尾 → 放行
        //   evilexample.com  也以 '.example.com' 结尾吗？不 —— 它是 'example.com' 结尾，
        //   但前面没有点，所以下面的 substr 比较不成立
        if (substr($originHost, -strlen($suffix)) === $suffix) {
            return true;
        }
    }

    return false;
}

/**
 * 处理跨域（CORS）。
 *
 * 只有 Origin 命中白名单才回显允许头。
 * 另外两种情况不做 CORS 处理：
 *   - 没有 Origin 头：说明是同源请求或 curl / 服务端调用，本来就不需要 CORS
 *   - Origin 不在白名单：不输出允许头，浏览器自己就会拦下响应
 *
 * @param  array $allowedOrigins 白名单
 * @return bool  Origin 是否被接受
 */
function apply_cors(array $allowedOrigins)
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if ($origin === '') {
        return true; // 同源或非浏览器请求
    }

    // 告诉中间缓存：这个响应随 Origin 变化，别把 A 站的响应发给 B 站
    header('Vary: Origin');

    if (!origin_is_allowed($origin, $allowedOrigins)) {
        return false;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');

    return true;
}
