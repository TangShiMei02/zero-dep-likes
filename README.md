# zero-dep-likes

**给博客加一个点赞按钮。零依赖 —— 不用 Node.js，不用 Composer，没有构建步骤。**

把 `api/` 和 `assets/` 两个目录丢进 PHP 虚拟主机就能跑。点赞数据存在你自己的 MySQL 里，
不接第三方服务，不用注册账号。

<p align="center">
  <img src="docs/preview.svg" alt="点赞组件的三种状态：未赞（描边拇指）、已赞（实心拇指，珊瑚橙）、加载中" width="680">
</p>

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](#环境要求)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%7C%20MariaDB-4479a1?logo=mysql&logoColor=white)](#环境要求)
[![dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)](#它为什么存在)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

---

## 它为什么存在

现成的点赞方案基本分三类：**平台插件**（绑定 WordPress 之类的系统）、
**需要构建的前端方案**（要 `npm install`）、**第三方 SaaS**（要注册、数据在别人手里）。

但很多人的博客跑在一个几十块一年的 PHP 虚拟主机上。那台机器**没有 Node、
装不了 Composer，也不该为了一个点赞按钮去接别人的服务器**。

这个项目就是给这种环境写的。它只要求你的主机能跑 PHP 和 MySQL，除此之外什么都不要。

## 它能做什么

- **每篇文章独立计数** —— 靠 `article_id` 隔离，不同文章不会混
- **一人一篇只能赞一次** —— 数据库唯一索引兜底，绕过前端也拦得住
- **可以取消点赞** —— 再点一次即可取消，不是点过就锁死
- **并发不丢数** —— 事务 + 原子自增 + 死锁重试
- **一行接入** —— 模板里放 `<div class="like-widget"></div>` 就够，按钮由脚本自动补
- **自动生成文章 ID** —— 不写 `data-article-id` 时按页面链接派生，只有列表页需要手写
- **不存访客身份** —— `visitor_id` / IP / UA 全部加盐 sha256 后入库
- **深浅色主题自动适配** —— 颜色全部继承博客，不写死任何背景色与文字色
- **零依赖** —— 没有 `package.json`，没有 `vendor/`，没有构建产物

## 快速开始

```html
<!-- ① 模板 <head> 里，全站一次 -->
<link rel="stylesheet" href="/assets/likes/likes.css">

<!-- ② 文章正文末尾 -->
<div class="like-widget"></div>

<!-- ③ </body> 之前，全站一次 -->
<script src="/assets/likes/likes.js" data-api="/api/likes.php" defer></script>
```

上传 `api/` 和 `assets/` → 填好 `api/config.php` → 浏览器打开 `install.php` 建一次表
→ 打开 `check.html` 跑一遍自检 → 删掉这两个文件 → 完事。

**逐步说明看 [`DEPLOY.md`](DEPLOY.md)；想搞懂原理看 [`TUTORIAL.md`](TUTORIAL.md)。**

### 环境要求

- PHP **7.4 及以上**（在 PHP 8.1 上完整验证过）
- MySQL **5.7+** 或 MariaDB **10.2+**
- 不需要 Node.js、不需要 Composer、不需要额外扩展（PDO + `pdo_mysql` 是 PHP 标配）

### 三份文档，看哪一份

| 文档 | 什么时候看 |
|---|---|
| **[`TUTORIAL.md`](TUTORIAL.md)** | **第一次部署**。完整教程：从零到跑起来 → 接进博客 → 验收清单 → 按症状查问题 → 原理与设计取舍 |
| [`DEPLOY.md`](DEPLOY.md) | 赶时间。一页纸七步清单，只有操作步骤和验证点，不含原理 |
| `README.md`（本文件） | 当参考手册查。配置项逐条说明、API 契约、安全设计、维护 SQL |

> **最小可用路径**：建库 → 改 `api/config.php` 三处 → 跑一次 `install.php` 建表 →
> 跑一次 `check.html` 自检 → 模板里加三行 → 完事。

> **当前版本** v1.8.0 · 首次开源发布 · 变更记录见 [`CHANGELOG.md`](CHANGELOG.md)

---

## 一、目录结构

**上传到服务器的只有 `api/` 和 `assets/` 两个目录**，其余是文档和工具，按需使用。

```
zero-dep-likes/
├── api/                          ← 上传
│   ├── config.php                配置：数据库、salt、CORS 白名单、限流
│   ├── config.local.example.php  本地配置模板（复制成 config.local.php 用）
│   ├── db.php                    PDO 连接与日志
│   ├── response.php              统一 JSON 响应 + CORS 处理
│   └── likes.php                 API 入口（GET / POST / OPTIONS）
├── assets/likes/                 ← 上传
│   ├── likes.js                  前端交互
│   ├── likes.css                 组件样式
│   ├── icon.svg                  站点图标（主用，矢量）
│   ├── icon-32.png               PNG 兜底（服务器不认 svg 时用）
│   ├── icon-180.png              苹果设备加到桌面
│   ├── icon-512.png              大尺寸备用
│   ├── favicon.ico               通用兜底，内含 16/32/48 三档
│   └── icon-preview.html         图标自检页（验图标用）
├── sql/
│   └── schema.sql                建表语句（用 install.php 就不必手动导）
├── install.php                   一次性建表程序（放 api/ 同级，跑完删掉）
├── check.html                    部署自检页（放 api/ 同级，验完删掉）
├── where.php                     部署诊断页（路径/文件放错时用，诊断完删掉）
├── examples/
│   ├── demo.html                 离线预览页（含假后端，不用上传）
│   └── live-test.html            真实接口试用页（传到服务器上打开）
├── docs/
│   └── preview.svg               README 里的组件预览图
├── tests/
│   └── api-test.md               测试用例
├── TUTORIAL.md                   完整教程（第一次部署看这个）
├── DEPLOY.md                     一页纸上手清单（赶时间看这个）
├── CHANGELOG.md                  版本记录
├── LICENSE                       MIT
├── .gitignore                    已排除 config.local.php 与日志
└── README.md                     本文件（参考手册）
```

---

## 二、部署（五步）

### 第 1 步：建数据库

在虚拟主机控制面板里创建一个 MySQL 数据库和一个数据库用户，把用户绑定到该库上，记下四个值：

- 数据库地址（通常是 `localhost`）
- 数据库名
- 用户名
- 密码

### 第 2 步：建表

**推荐**：把 `install.php` 上传到 `api/` 的上一级，浏览器打开
`https://你的域名/安装路径/install.php`，点「开始建表」，完成后点「删除 install.php」。

它会先显示库名、主机、MySQL 版本让你确认，再建表，建完自动校验字段结构和唯一索引，
并给出你这次部署的真实接口地址。详见 [`DEPLOY.md`](DEPLOY.md) 第 2 步。

**也可以手动导入**：phpMyAdmin 里**先点中你的数据库**，再切到「导入」标签，
选 `sql/schema.sql` → 执行。

> ⚠️ phpMyAdmin 是「先选库、再导入」。左侧没选中就导，轻则报 `#1046 No database selected`，
> 重则**悄悄导进你上次打开的那个库**——然后你盯着目标库纳闷：明明"导入成功"了，表却不见了。

不管用哪种方式，建完都应该在**同一个库**里看到两张表：`article_likes`、`like_records`。
其中 `like_records` 必须带一个名为 `uniq_article_visitor` 的**唯一索引**——防重复点赞全靠它。

两种方式都是幂等的（`CREATE TABLE IF NOT EXISTS`），重复执行不会破坏已有数据。

### 第 3 步：改配置

打开 `api/config.php`，**必须改**这三处：

```php
// ① 数据库三项 —— 用主机面板建库时给你的值
$DB_HOST = 'localhost';                   // 多数虚拟主机就是 localhost
$DB_NAME = 'your_database_name';          // 库名，常带主机商前缀
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

// ② 加盐 —— 换成一段随机字符串，越乱越好
$SALT = 'CHANGE_ME_PLEASE_REPLACE_WITH_A_LONG_RANDOM_STRING';

// ③ CORS 白名单 —— 换成你博客的真实域名
$ALLOWED_ORIGINS = array(
    'https://blog.example.com',
);
```

> **`$SALT` 怎么生成？** 一条命令就够了：
>
> ```bash
> php -r "echo bin2hex(random_bytes(24));"
> ```
>
> 手敲一串 32 位以上的乱码也行，**但不要照抄本文档里的任何示例值**。
> `$SALT` 的作用是让数据库里存的是哈希值而不是访客的原始标识，保护访客隐私。
> **上线后不要再改** —— 改了之后所有老访客会被当成新访客，可以重复点赞。

### 第 4 步：上传文件

```
api/           →  网站根目录/api/
assets/likes/  →  网站根目录/assets/likes/
```

上传完确认这两个 URL 能在浏览器打开（应该是 403 或空内容，不是 404）：

- `https://你的域名/api/likes.php`
- `https://你的域名/assets/likes/likes.js`

> `api/` 下的 `config.php`、`db.php`、`response.php` 都带了访问守卫，
> 直接访问会返回 403，不会泄露配置内容。
>
> `examples/demo.html` 是本地预览用的，**不要上传**。

### 第 5 步：在博客模板里插入组件

在文章正文结束的位置（评论框上方）插入：

> **最省事的写法就是一个空组件**，脚本会自动补上按钮、图标、计数三层结构：
>
> ```html
> <div class="like-widget"></div>
> ```
>
> 想自定义按钮文字或图标时，才需要写下面这套完整结构。

```html
<div class="like-widget" data-article-id="文章的唯一ID">
  <button class="like-button" type="button" aria-pressed="false" aria-label="点赞这篇文章">
    <svg class="like-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.58 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-1z"/>
    </svg>
    <span class="like-label">点赞</span>
    <span class="like-count">--</span>
  </button>
</div>
```

然后在页面底部（`</body>` 之前）引入样式和脚本：

```html
<link rel="stylesheet" href="/assets/likes/likes.css">
<script src="/assets/likes/likes.js"></script>
```

> **只引一次。** 列表页有多篇文章时，`likes.js` 会自动用一次批量请求把整页的点赞数拉回来，不需要给每篇文章单独引脚本。

#### 关于 `data-article-id`

这个值必须**每篇唯一、且不随文章修改而变化**。推荐直接用 URL 里的 slug：

| 文章 URL | 建议的 `data-article-id` |
|---|---|
| `/posts/hello-world.html` | `hello-world` |
| `/2026/09/11/why-slug.html` | `why-slug` |
| `/p/1234` | `1234` |

允许的字符只有**字母、数字、下划线、中划线**（`A-Z a-z 0-9 _ -`），长度 1–191。含中文或空格的 ID 会被接口拒绝并返回 400。

> 因为主键就是 `article_id`，所以**改 slug 会丢掉该文章的点赞数**。文章发布后尽量别再改 slug。

#### 点赞数是怎么做到"每篇文章各算各的"

**完全靠 `data-article-id`。** 数据库里 `article_id` 是 `article_likes` 的主键，
`like_records` 上还有 `(article_id, visitor_hash)` 唯一索引——
不同 ID 落在不同的行上，物理层面就不可能混在一起。

所以"会不会两篇文章共用一个点赞数"，**只取决于你填的 ID 是不是真的不一样**。

⚠️ **这是整套设计里唯一会"安静出错"的地方**：
如果你复制模板后忘了改 `data-article-id`，多篇文章就会共用同一个计数——
**不报错、不提示**，只是数字莫名其妙地一起涨。

为此 `likes.js` 内置了一道保险：ID 要是 `post-1`、`article_id`、`test`、`xxx`、`todo`
这类明显的示例值，会在**页面上**用强调色直接提示：

> 文章 ID 还是示例值「post-1」，会和其他文章共用点赞数，请改成这篇的 slug

看到这个提示，就是有篇文章的 ID 没改。

**定期体检**：在 phpMyAdmin 里跑一句，看看有没有可疑的 ID：

```sql
-- 点赞数最高的文章（如果出现 post-1 / test 这类 ID 且数字特别高，就是共用了）
SELECT article_id, like_count FROM article_likes ORDER BY like_count DESC LIMIT 20;

-- 顺手确认计数与明细对得上，正常应该查不出任何行
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
GROUP BY l.article_id, l.like_count
HAVING l.like_count <> COUNT(r.id);
```

#### 最稳的填法：让模板自动输出

手写 `data-article-id` 迟早会漏。如果博客支持模板变量，直接输出它：

```php
<!-- 通用 PHP 博客 -->
<div class="like-widget" data-article-id="<?php echo htmlspecialchars($post['slug']); ?>">
```

```html
<!-- Hexo / Hugo 之类 -->
<div class="like-widget" data-article-id="{{ page.slug }}">
```

这样 ID 由系统生成，不可能重复，也不可能忘改。

#### 更省事的做法：让组件自己从链接派生

**如果连模板变量都懒得加**，可以直接不写 `data-article-id`：

```html
<div class="like-widget"></div>
```

`likes.js` 会自动按当前页面链接派生一个 ID。归一化规则：

| 页面链接 | 派生出的 `article_id` |
|---|---|
| `/posts/hello-world.html` | `posts-hello-world` |
| `/posts/hello-world/` | `posts-hello-world` |
| `/2026/09/11/why-slug/` | `2026-09-11-why-slug` |
| `/文章/你好`（中文链接） | `p-` + 短哈希 |

同一篇文章的 `/x.html`、`/x`、`/x/` 三种写法会归一到**同一个 ID**，不会分裂计数。

**代价要认清楚**：

- 换了 URL（改 slug、换目录、`http`→`https`）会被当成新文章，点赞数**从 0 开始**
- **只能用在文章详情页**。列表页上所有组件的 URL 是同一个，派生出的 ID 自然也全一样，
  它们会共用一个计数

第二点 `likes.js` 会自己抓出来：同一页出现重复 ID 时，会在页面上直接告警。

**一句话取舍**：`slug` 是文章的**身份**，URL 是文章的**地址**。地址会变，身份不该变。
所以能写 `data-article-id` 就写；写不了再用自动派生，并且接受"以后别改 URL"这个约束。

两种方式可以混用：**写了 `data-article-id` 就用你写的，没写才自动派生。**

#### 列表页怎么插

列表页每篇摘要下面各放一个 `.like-widget` 即可，页码里带不同的 `data-article-id`：

```html
<div class="like-widget" data-article-id="hello-world"> ... </div>
<!-- ... -->
<div class="like-widget" data-article-id="why-slug"> ... </div>
```

一页最多一次批量查 100 篇，超过会返回 400。按每页 10–20 篇算完全够用。

---

## 三、配置项说明

> **推荐把要改的配置写进 `api/config.local.php`，而不是直接改 `config.php`。**
>
> `config.php` 会在最后加载这个文件（存在的话），**它覆盖任何一项**。
> 这样升级时整个压缩包重新解压也不会弄丢你的数据库密码。
> 做法：把 `api/config.local.example.php` 复制成 `api/config.local.php`，照注释填。
> 文件不存在时行为完全不变。

`api/config.php` 里所有可调项：

| 变量 | 默认值 | 说明 |
|---|---|---|
| `$DB_HOST` | `localhost` | 数据库地址。多数虚拟主机就是 `localhost` |
| `$DB_PORT` | `3306` | 端口 |
| `$DB_NAME` | — | 数据库名 |
| `$DB_USER` | — | 数据库用户 |
| `$DB_PASS` | — | 数据库密码 |
| `$DB_SOCKET` | 空 | 面板给的是 socket 路径时填这里，填了就走 socket，忽略 host/port |
| `$SALT` | 占位符 | **必须改**。哈希加盐 |
| `$ALLOWED_ORIGINS` | 几个示例域名 | **必须改**。允许跨域调用 API 的域名列表，支持 `'https://*.example.com'` 通配子域 |
| `$RATE_LIMIT_ENABLED` | `true` | 是否开启限流 |
| `$RATE_LIMIT_MAX` | `30` | 窗口内最多点赞次数 |
| `$RATE_LIMIT_WINDOW` | `60` | 窗口长度（秒） |
| `$TRUST_PROXY` | `false` | 套了 CDN / 反代才设 `true`，见下方排错 |
| `$MAX_BATCH_IDS` | `100` | 批量接口一次最多查多少篇 |
| `$LOG_ERRORS` | `true` | 是否记错误日志 |
| `$LOG_FILE` | 空 | 留空则写进 PHP 错误日志；填绝对路径则写文件，**建议放在网站根目录之外** |
| `$DEBUG` | `false` | 生产必须保持 `false` |

上面所有数据库项都支持用**环境变量**覆盖（`LIKES_DB_HOST`、`LIKES_DB_NAME`、`LIKES_DB_USER`、`LIKES_DB_PASS`、`LIKES_DB_PORT`、`LIKES_DB_SOCKET`）。同一份代码要在本地和生产之间切换时，改环境变量即可，不用改文件。

---

## 四、API 文档

所有接口都返回同一套外壳：

```json
{ "code": 0, "msg": "ok", "data": {} }
```

### 错误码

| code | HTTP | 含义 |
|---|---|---|
| `0` | 200 | 成功 |
| `400` | 400 | 参数错误（ID 非法、缺少参数、域名不在白名单） |
| `429` | 429 | 请求过于频繁 |
| `1001` | 200 | 已经点过赞（`data` 里仍会带回最新计数） |
| `500` | 500 | 服务器错误 |

> `1001` 故意返回 HTTP 200：对前端来说"早就赞过了"不是错误，而是一个正常的业务状态，
> 顺便还能把权威计数带回来同步按钮。这样前端不用为它写异常分支。

### 1. 查单篇

```http
GET /api/likes.php?article_id=hello-world&visitor_id=xxx
```

```json
{
  "code": 0,
  "msg": "ok",
  "data": { "article_id": "hello-world", "like_count": 12, "liked": true }
}
```

`visitor_id` 可以不传，此时 `liked` 恒为 `false`。

### 2. 批量查

```http
GET /api/likes.php?article_ids=hello-world,why-slug,third-post&visitor_id=xxx
```

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "items": [
      { "article_id": "hello-world", "like_count": 12, "liked": true },
      { "article_id": "why-slug",    "like_count": 5,  "liked": false },
      { "article_id": "third-post",  "like_count": 0,  "liked": false }
    ]
  }
}
```

- 逗号分隔，自动去重，顺序按请求返回
- 数据库里没有记录的文章返回 `like_count: 0`，**不会漏掉**
- 传了 `visitor_id` 才会带 `liked` 字段
- 单次上限由 `$MAX_BATCH_IDS` 控制（默认 100）

### 3. 点赞

```http
POST /api/likes.php
Content-Type: application/json

{ "article_id": "hello-world", "visitor_id": "uuid-or-local-id" }
```

成功：

```json
{ "code": 0, "msg": "ok", "data": { "article_id": "hello-world", "like_count": 13, "liked": true } }
```

已经赞过：

```json
{ "code": 1001, "msg": "already liked", "data": { "article_id": "hello-world", "like_count": 13, "liked": true } }
```

> 为了方便调试，请求体也接受 `application/x-www-form-urlencoded` 格式，正式前端用 JSON 就好。
>
> **不带 `action` 字段时默认就是点赞**，所以老版本的调用方不用改。

### 4. 取消点赞

```http
POST /api/likes.php
Content-Type: application/json

{ "article_id": "hello-world", "visitor_id": "uuid-or-local-id", "action": "unlike" }
```

成功：

```json
{
  "code": 0,
  "msg": "ok",
  "data": { "article_id": "hello-world", "like_count": 12, "liked": false, "removed": true }
}
```

- `removed: true` = 之前确实赞过，这次真的删掉了记录、计数减了 1
- `removed: false` = 本来就没赞过。**这不算错误，仍然返回 `code: 0`**（幂等）

也接受 `DELETE` 方法（请求体同 POST），等价于 `action: "unlike"`：

```http
DELETE /api/likes.php
Content-Type: application/json

{ "article_id": "hello-world", "visitor_id": "uuid-or-local-id" }
```

> **为什么主推 `POST + action` 而不是 `DELETE`**：部分虚拟主机 / CDN / WAF 会拦掉
> 带请求体的 `DELETE`，症状是"本地 curl 好用、线上莫名其妙失败"，非常难查。
> `POST` 到处都能过。`DELETE` 只是顺手提供的别名。

取消点赞**不计入限流**——它不会新增明细，没有刷的价值，误伤正常用户得不偿失。

### 5. OPTIONS 预检

```http
OPTIONS /api/likes.php
```

命中白名单返回 `204` 并带齐 CORS 头；域名不在白名单返回 `403`。

### 响应头

```http
Content-Type: application/json; charset=utf-8
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
X-Content-Type-Options: nosniff
```

---

## 五、安全设计说明

### 先纠正一个常见误解：CORS 白名单不是安全防线

**它挡不住任何有意为之的调用。** CORS 是浏览器自己给自己定的规矩，服务端没有这个概念，
所以白名单只能拦住"别的网站的前端 JS 读你的响应"这一种情况：

| 攻击方式 | 白名单挡得住吗 |
|---|---|
| 别的网站的前端 fetch | ✅ 挡得住 |
| curl / Postman / Python 直接调 | ❌ 挡不住 |
| 自己写脚本批量请求 | ❌ 挡不住 |
| 浏览器直接打开接口地址 | ❌ 挡不住（本来就该能看） |

**要理解这一点**：接口地址是公开的，任何人知道地址就能调，不需要密钥。
这不是配置疏漏，而是**匿名点赞这个功能的固有形态** —— 没有用户身份，
就没有可凭据的东西；而且**放在前端的密钥等于没有密钥**（页面源码人人可见）。

**这套设计防的不是"未授权访问"，而是"重复计数"。** 目标不同，手段自然不同。

所以对博客点赞这个场景：**最坏情况是"某篇文章的数字被刷得不可信"，
不是"系统被攻破"** —— 没有敏感数据可泄露，单次请求极轻且有 IP 限流兜着。
真需要严格防刷，唯一可靠的办法是接入登录体系。

### 防重复点赞 —— 三道防线

1. **前端**：点击后立刻禁用按钮，防止手抖连点。
2. **数据库唯一索引**：`like_records` 上的 `(article_id, visitor_hash)` 唯一索引。
   即使有人绕过前端直接发请求、或者并发同时打进来，也最多只写进一行。
3. **原子自增**：计数用 `INSERT ... ON DUPLICATE KEY UPDATE like_count = like_count + 1`，
   自增在 MySQL 内部完成，没有"读-改-写"的空隙，并发不会互相覆盖。

事务里还做了**死锁重试**（最多 3 次，退避 50ms / 100ms），万一两个请求撞车也不会丢数据。

### 隐私

数据库里**不存访客的原始标识**：

- 前端生成的随机 `visitor_id` → 后端 `sha256(visitor_id + salt)` 后才落库
- IP → `sha256(IP + salt)`
- User-Agent → `sha256(UA + salt)`

即使数据库被拖走，也没法反推出具体是谁点的赞。

### SQL 注入

全部走 PDO 预处理语句，且关闭了模拟预处理（`ATTR_EMULATE_PREPARES = false`），参数由 MySQL 真正绑定。

`article_id` 还做了白名单式校验：只允许 `A-Za-z0-9_-`，从源头掐掉一切花活。

另外限流用的时间窗是强制 `(int)` 转换后才拼进 SQL 的，同样不存在注入。

### 其它

- **数据库用户权限**：在面板里把这个用户只授予 `SELECT`、`INSERT`、`UPDATE` 三种权限，
  不要给 `DROP` / `ALTER`。这样万一代码出漏洞，损失面也可控。
- **错误信息不外泄**：接口出错只返回 `{"code":500,"msg":"server error"}`，
  详细堆栈写进日志，不发给前端。
- **`$DEBUG` 生产保持 `false`**：打开时错误响应里会带 `data.debug`，仅用于临时排查。
- **CORS 不用 `*`**：只允许白名单域名跨域调用，防止别的站点替你刷赞。

### 已知限制

用户清空浏览器数据（localStorage）后会拿到新的 `visitor_id`，理论上能对同一篇文章再赞一次。
这是所有**匿名点赞**方案的共同限制，可接受。要做到严格，需要接入登录体系，把 `visitor_id` 换成 `user_id`。

---

## 六、测试

### 最快的一条路：自检页

把 `check.html` 上传到网站根目录（和 `api/` 同级），浏览器打开 `https://你的域名/check.html`，
点「开始检测」。它会自动跑 12 项检查并逐条告诉你期望值和实际值差在哪。

**注意两点**：

- 必须放在**自己的域名下**。本地双击打开不行——接口有 CORS 白名单，本地 `file://` 的来源是 `null`，会被拒。
- **验完请把 `check.html` 删掉。** 它会把接口信息暴露给任何访问者（虽然只能读点赞数、写测试数据）。

自检页覆盖了验收标准的第 1–7、10 条。剩下三条要手动做：

| 验收标准 | 在哪测 |
|---|---|
| 8. 并发不丢数据 | `tests/api-test.md` 用例 9（命令行，自检页只能串行请求） |
| 9. 前端无 JS 报错、按钮状态正确 | 把组件插进博客模板后，实际点一下、刷新、看控制台 |
| 7. CORS 只允许配置域名 | 命令行 curl（自检页与接口同源，测不了），命令见自检页底部 |

### 完整用例

详细用例（含并发测试、验收标准对照表）见 `tests/api-test.md`。

### 上传前想先看前端效果

直接用浏览器打开 `examples/demo.html`。
它内置了一个假后端（拦截 `/api/likes.php`），不需要 PHP 和数据库就能看到完整交互——
点赞 +1、已赞变橙、重复点击提示"已经赞过"、深色主题切换。数据存在内存里，刷新即重置。

---

## 七、常见问题排错

### 接口返回 500

**第一步：把 `$DEBUG` 临时改成 `true`**，重新上传 `api/config.php`，再请求一次那个地址。
响应的 `data.error` 里会直接带 **MySQL 的原话**，照着下表对号入座。

```json
{
  "code": 500,
  "msg": "server error",
  "data": {
    "error": "RuntimeException: database connection failed: SQLSTATE[HY000] [1045] Access denied for user 'abc'@'localhost' (using password: YES)",
    "where": "db.php:78"
  }
}
```

MySQL 的错误码是分门别类的，一眼能定位：

| 错误码 / 关键词 | 意思 | 怎么办 |
|---|---|---|
| `[1045] Access denied` | 用户名或密码不对 | 回面板核对，**密码里如果有特殊字符要注意** |
| `[1044] Access denied for database` | 用户存在，但没绑定到这个库 | 在面板里把用户**绑定/授权**给这个数据库 |
| `[1049] Unknown database` | 数据库名写错 | 库名常带主机商前缀，如 `sql_abc123_likes`，**一个字符都不能错** |
| `[2002] Connection refused` | 主机地址写错 | 多数虚拟主机是 `localhost`；面板若给了别的地址就照填 |
| `Base table or view not found` / `[1146]` | **表不存在** —— `schema.sql` 没导入 | 去 phpMyAdmin 导入 `sql/schema.sql` |
| `could not find driver` | PHP 没装 pdo_mysql 扩展 | 找主机商开，或改用他们的 MySQL 面板确认 PHP 版本 |
| `Connection timed out` | 主机地址在网络上是不可达的 | 核对 `$DB_HOST`，或改用 socket（填 `$DB_SOCKET`） |

**查完立刻把 `$DEBUG` 改回 `false`** —— 打开时会把数据库信息暴露给任何访问者。

如果 `data.error` 还是看不出问题，就看虚拟主机面板的「错误日志」，
代码把完整信息都写进去了（`[likes]` 开头）。

**注意**：还有一种"不是 500 但也是数据库问题"的情况——`schema.sql` 导入到了**另一个库**里。
phpMyAdmin 左边能切换数据库，确认两张表出现在 `$DB_NAME` 指向的那个库里。

### 接口返回 4000/404，或者浏览器控制台报 404

路径不对。`likes.php` 必须放在网站根目录的 `api/` 目录下。
如果你的博客在子目录（比如 `/blog/`），那 `likes.js` 里的 API 地址要跟着改：

```html
<script src="/blog/assets/likes/likes.js" data-api="/blog/api/likes.php"></script>
```

### 查询正常，但一点赞就返回 400（`origin not allowed`）

**这是最隐蔽的一个坑，先看这里。**

症状：`GET /api/likes.php?article_id=xxx` 一切正常，能拿到计数；
一旦点按钮走 `POST`，就返回 `{"code":400,"msg":"origin not allowed"}`。

原因在浏览器的规范里：

- **同源的 GET 不发 `Origin` 头** → 接口直接放行
- **同源的 POST 会发 `Origin` 头** → 接口拿它去比对白名单，没匹配上就拒绝

所以只要「你正在访问的域名」没写进 `$ALLOWED_ORIGINS`，就会出现
"读得到、点不了"这种半死不活的状态。

**解决**：把你在浏览器地址栏里正在用的那个域名，原样加进白名单。

```php
$ALLOWED_ORIGINS = array(
    'https://blog.example.com',
    'https://www.example.com',
    'https://blog.example.com',
    'https://staging.example.com',          // 临时测试用的域名也要写

    // 域名多的话用通配，以后加子域就不用再改这里
    'https://*.example.com',
);
```

注意三点：**必须带协议**（`https://` 和 `http://` 是两个不同的来源）、
**带不带 `www.` 也不同**、**端口不同也不同**。有几个入口就写几行。

#### 通配写法

`'https://*.example.com'` 会放行 `example.com` 的**任意子域**：
`blog.example.com`、`www.example.com`、以后再加的 `api.example.com` 全都通过。

两点要注意：

- **不含裸域名本身**。`https://example.com` 要单独写一行。
- 匹配要求**落在点边界上**，所以 `evilexample.com` 不会被误放行
  （它以 `example.com` 结尾，但前面没有那个点）。

嫌通配太宽就还是逐个列精确域名，最安全。

### 浏览器控制台报 CORS 错误

1. 确认 `$ALLOWED_ORIGINS` 里的域名和你访问博客用的域名**完全一致**
   —— 注意 `https://` 和 `http://` 是不同的，`www.` 和裸域名也是不同的，端口号也要一致
2. 用 curl 检查响应头：
   ```bash
   curl -s -i -H "Origin: https://你的域名" "https://你的域名/api/likes.php?article_id=test-x" | grep -i access-control
   ```
3. 如果同时用 `www.` 和裸域名访问，**两个都要写进白名单**

### 点赞点了没反应，数字不动

1. 打开浏览器 F12 → Network，看 `/api/likes.php` 这个请求：
   - **没发出请求**：多半是 `likes.js` 没加载成功（看 Source 面板有没有这个文件）
   - **状态 400**：`data-article-id` 里有非法字符（中文、空格、点号都不行）
   - **状态 429**：触发限流了，等一分钟再试
   - **状态 500**：按上面"接口返回 500"排查
2. 控制台 Console 里会有 `[likes]` 开头的警告，写着具体原因

### 计数与实际感觉不符

用这条 SQL 校对计数与明细是否一致（正常应该查不出任何行）：

```sql
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
GROUP BY l.article_id, l.like_count
HAVING l.like_count <> COUNT(r.id);
```

### 限流误伤了真实用户

如果你套了 Cloudflare 或 Nginx 反代，PHP 拿到的 `REMOTE_ADDR` 是 CDN 的 IP，
所有访客看起来是同一个 IP，就会一起被限流。

解决：把 `$TRUST_PROXY` 改成 `true`，让代码去读 `CF-Connecting-IP` / `X-Forwarded-For`。

> 但只有**确实套了反代**才能开。没套反代还开着的话，客户端可以自己伪造
> `X-Forwarded-For` 头来绕过限流。

如果只是觉得 30 次/分钟太紧，直接调大 `$RATE_LIMIT_MAX`，或者把 `$RATE_LIMIT_ENABLED` 关掉。

### 中文乱码

不太可能出现——表结构、连接、响应头三处都是 `utf8mb4`。
真遇到先确认表是不是用 `schema.sql` 建的（而不是别处复制来的）。

---

## 八、维护

### 备份

点赞数据只有两张表，备份很轻量。phpMyAdmin → 选中这两张表 → 导出 → SQL 格式：

- `article_likes`（计数，丢了就全没了）
- `like_records`（明细，用来重建计数）

**只要 `like_records` 在，计数丢了还能重建：**

```sql
-- 从明细重建计数
INSERT INTO article_likes (article_id, like_count)
SELECT article_id, COUNT(*) FROM like_records GROUP BY article_id
ON DUPLICATE KEY UPDATE like_count = VALUES(like_count);
```

> 注意：MySQL 8.0.20+ 里 `VALUES()` 写法已废弃但仍可用；
> 如果报错，把它换成 `like_count = (SELECT COUNT(*) FROM ...)` 或改用别名语法。

### 常用 SQL

```sql
-- 点赞最多的 20 篇
SELECT article_id, like_count FROM article_likes ORDER BY like_count DESC LIMIT 20;

-- 最近一小时的点赞
SELECT article_id, created_at FROM like_records
WHERE created_at > NOW() - INTERVAL 1 HOUR ORDER BY id DESC;

-- 每天的点赞量趋势
SELECT DATE(created_at) AS d, COUNT(*) AS n FROM like_records
GROUP BY d ORDER BY d DESC LIMIT 30;

-- 按日统计，用于做图表（配合"可选增强"）

-- 清零某篇文章（连防重记录一起清）
DELETE FROM like_records  WHERE article_id = 'hello-world';
DELETE FROM article_likes WHERE article_id = 'hello-world';
```

---

## 九、自定义

### 换回 emoji 图标

规格里原本写的是用 `👍` emoji。本实现换成了内联 SVG，理由是：
emoji 当功能图标在不同系统上渲染差异很大（Windows / macOS / Android 都长得不一样），
而且和博客排版的字重、行高很难对齐。

如果你就是想用 emoji，把 HTML 里的 `<svg>...</svg>` 整段换成 `👍` 即可，
样式不用改——`.like-icon` 的填充规则对文字不生效，颜色仍由按钮的 `color` 控制。

### 改强调色

在你的博客样式里覆盖 CSS 变量：

```css
.like-widget {
    --like-accent: #F77234;    /* 已赞状态的强调色 */
    --like-font-size: 0.875rem; /* 组件字号 */
    --like-radius: 4px;         /* 按钮圆角 */
}
```

### 站点图标

图标有五种格式，**主用 `icon.svg`**，其余是兜底：

| 文件 | 用途 |
|---|---|
| `icon.svg` | 主用，矢量，任意尺寸都清晰 |
| `icon-32.png` | 服务器不认 svg 时的兜底 |
| `icon-180.png` | 苹果设备「加到主屏幕」 |
| `icon-512.png` | 大尺寸备用 |
| `favicon.ico` | 最通用的兜底，内含 16/32/48 |

**上传文件不会让图标自动生效**，必须在页面的 `<head>` 里声明，每个页面各自声明一次：

```html
<link rel="icon" type="image/svg+xml" href="/assets/likes/icon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/likes/icon-32.png">
<link rel="apple-touch-icon" href="/assets/likes/icon-180.png">
```

> **路径要按你的实际部署调整。** 文件在 `/zero-dep-likes/assets/likes/` 下，
> `href` 就得写成 `/zero-dep-likes/assets/likes/icon.svg`。
>
> `check.html`、`install.php`、`examples/demo.html` 已内置这三行声明。

#### 图标不显示时怎么办

**先用自检页定位**，打开 `assets/likes/icon-preview.html`（它和图标文件同目录，路径没有歧义）。
它会把四个图标文件**实际加载一遍**并逐个报结果，一次区分三种情况：

| 现象 | 原因 |
|---|---|
| 图能显示，标签页没图标 | 浏览器**缓存了之前的 404**，`Ctrl + F5` 强刷，或开无痕窗口 |
| 图是裂图 / 显示成一段代码 | 文件没上传到这一层，**或服务器没把 svg 当图片类型发出去**（MIME 问题）→ 改用 PNG 那两行 |
| 全部裂图 | 整个 `assets/likes/` 目录不在这一层，检查上传时是不是多套了一层目录 |

> ⚠️ **不要用 `/api/likes.php` 那个地址来验证图标。**
> 它返回的是 JSON 数据，不是网页，浏览器不会给 JSON 响应显示任何图标。
> 那个页面永远不会有图标，这不是坏了。

想换配色，改 `icon.svg` 里的 `fill` 值后重新导出 PNG 即可。

### 改成"点赞 + 取消点赞"

当前设计是**点过即锁**（不允许取消）。要支持取消，需要：

1. 后端加一个 `DELETE` 分支：删掉 `like_records` 里那行，并把 `article_likes.like_count - 1`
2. 前端去掉 `item.button.disabled = item.liked`，改成已赞时再点触发取消

---

## 十、迭代路线

当前版本已实现规格中的全部必需项。以下为可选增强，按需再做：

- 后台管理页：查看、重置、导出点赞数据
- 按时间统计点赞趋势（数据已经在 `like_records.created_at` 里了，直接用）
- WordPress shortcode：`[like_button id="post-1"]`
- 登录用户点赞绑定 `user_id`（彻底解决清缓存重复点赞）
- Redis / 文件缓存热门文章点赞数，减少数据库压力
- 点赞邮件或 Telegram 通知

---

## 附：交付物对照

| 规格要求的文件 | 实际位置 | 说明 |
|---|---|---|
| `/api/config.php` | ✅ | 配置 |
| `/api/db.php` | ✅ | PDO 连接 |
| `/api/response.php` | ✅ | 统一响应 + CORS |
| `/api/likes.php` | ✅ | API 入口 |
| `/assets/likes/likes.js` | ✅ | 前端脚本 |
| `/assets/likes/likes.css` | ✅ | 前端样式 |
| `/sql/schema.sql` | ✅ | 建表 |
| `/README.md` | ✅ | 本文件 |
| `/tests/api-test.md` | ✅ | 测试用例 |
| — | 额外 | `install.php` 一次性建表程序（跑完删） |
| — | 额外 | `check.html` 一键部署自检页（验完删） |
| — | 额外 | `where.php` 部署位置诊断（查 404 用，用完删） |
| — | 额外 | `DEPLOY.md` 一页纸上手清单 |
| — | 额外 | `examples/demo.html` 离线预览页 |
| — | 额外 | `CHANGELOG.md` 版本记录 |

> 三个"用完即删"的文件（`install.php` / `check.html` / `where.php`）都会暴露服务器信息，
> 上线前记得从服务器上删掉。它们不在 `api/` 里，删掉不影响点赞功能。
