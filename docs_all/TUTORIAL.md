# 博客点赞系统 · 完整教程

从零部署到博客跑起来，含原理讲解和排错。第一次用请从头看一遍。

- **赶时间**？看 [`DEPLOY.md`](DEPLOY.md)，一页纸七步清单，不含原理。
- **想搞懂为什么这么设计**？看本文件第九章。
- **已经跑起来了，只想看怎么接进博客**？直接跳第 4 章。

当前版本：**v1.5.0（首个稳定版）**

---

## 目录

1. [它是什么](#1-它是什么)
2. [确认你的环境](#2-确认你的环境)
3. [部署：从零到能跑](#3-部署从零到能跑)
4. [接进博客](#4-接进博客) — 含「**API 地址是怎么定下来的**」和「**别人怎么调用这个接口**」
5. [上线前的验收清单](#5-上线前的验收清单) — 含「**安全确认**」：CORS 白名单**不是**安全防线，以及上线前逐条确认项
6. [日常维护](#6-日常维护)
7. [按症状查问题](#7-按症状查问题)
8. [常见需求怎么改](#8-常见需求怎么改)
9. [原理与设计取舍](#9-原理与设计取舍)
10. [文件清单](#10-文件清单)

---

## 1. 它是什么

给博客每篇文章加一个点赞按钮。数据存在**你自己的 MySQL** 里，前端通过一个独立 API 读写。

```
   读者浏览器
       │
       │  ① 页面里的一段 HTML + JS
       ▼
   likes.js  ──fetch──▶  api/likes.php  ──PDO──▶  MySQL
       │                      │                      │
       │                统一 JSON 响应          article_likes（计数）
       │                                      like_records（明细，防重复）
       ▼
   点赞数显示在页面上
```

**为什么不让前端直接连数据库**：数据库密码不能出现在浏览器里。中间隔一层 API，
密码只存在于服务器上；顺带还获得了参数校验、防刷、统一错误处理的入口。

### 它能做什么

| 能力 | 说明 |
|---|---|
| 每篇文章独立计数 | 靠 `article_id` 隔离，物理上不会混 |
| 一人一篇只能赞一次 | 数据库唯一索引兜底，绕过前端也拦得住 |
| **可以取消点赞** | 已赞状态再点一次即可取消，误点了能撤回 |
| 并发不丢数 | 事务 + 原子自增 + 死锁重试 |
| 列表页批量查询 | 一页十几篇只发一次请求 |
| 不存访客原始身份 | 访客标识 / IP / UA 全部加盐哈希 |
| 深浅色主题自适应 | 颜色继承博客，不写死 |
| 零依赖 | 纯 PHP + PDO + 原生 JS，不需要 Node.js / Composer |

---

## 2. 确认你的环境

| 需要 | 最低要求 | 怎么确认 |
|---|---|---|
| PHP | 7.4 以上 | 虚拟主机面板一般写着；本项目在 PHP 8.1 上验证通过 |
| MySQL / MariaDB | 5.7 / 10.2 以上 | 面板里能建库就行 |
| PDO + pdo_mysql 扩展 | 必须有 | 部署后自检页会告诉你有没有 |
| 能上传文件 | FTP 或面板文件管理器 | —— |

**不需要**：Node.js、Composer、Redis、命令行权限、root 权限。

> 本项目的 API 目录里放了一个 `api/config.php` 作为配置入口。
> 如果你把 `api/` 传上去后打开 `api/likes.php` 显示的是**源代码**而不是 JSON，
> 说明这台主机没开 PHP 或者 PHP 没配好——先解决这个，别的都免谈。

---

## 3. 部署：从零到能跑

下面每一步都写了「**做完应该看到什么**」。对不上就停下来查，不要往下走。

### 3.1 文件放对位置 ⚠️ 最容易错的一步

> **陷阱一：多套了一层文件夹。** 压缩包解开后外面有个 `zero-dep-likes/` 文件夹。
> **要上传的是它里面的内容，不是它本身。**
>
> **陷阱二：FTP 登录看到的根目录，通常不是网站根目录。**
> 常见真实根目录名：`public_html` / `htdocs` / `wwwroot` / `www` / `web`，
> 或者**与域名同名的文件夹**。而且**每个域名各绑一个目录**。
>
> **怎么找对地方**：你的网站首页能正常打开，那么**首页那个 index 文件所在的目录**
> 就是网站根目录。

**两种部署位置，选一种**：

| 方式 | 放哪里 | API 地址 |
|---|---|---|
| **A. 放子目录**（不污染根目录，推荐） | 网站根目录/`zero-dep-likes/` | `/zero-dep-likes/api/likes.php` |
| **B. 放网站根目录** | 把 `api/` 和 `assets/` 直接放根目录 | `/api/likes.php` |

本教程以 **A** 为例（`/zero-dep-likes/`）。选 B 的话，后面所有路径里的 `/zero-dep-likes` 都去掉即可。

**验证**：浏览器打开

```
https://你的域名/zero-dep-likes/api/likes.php
```

**应该看到**：

```json
{"code":400,"msg":"article_id is required","data":{}}
```

- ✅ 看到这个 = PHP 跑起来了，文件位置对了
- ❌ **404（nginx 默认页）** = 路径不对，回到上面看两个陷阱
- ❌ 看到 PHP 源码 = 主机没开 PHP

> 这一步的 400 **只能证明 PHP 正常**，证明不了数据库通不通——数据库要等 3.4。

### 3.2 建数据库

主机面板 → MySQL 数据库 → 新建数据库 + 新建用户 → **把用户绑定/授权到这个库**。

记下四个值：地址（通常 `localhost`）、库名、用户名、密码。

> 库名经常带主机商前缀，比如 `abc123_likes` 这种，**一个字符都不能错**。

### 3.3 改配置

> **推荐做法：把配置写进 `config.local.php`，而不是改 `config.php`。**
>
> 因为升级时通常是把整个压缩包重新上传解压，那会把 `config.php` 覆盖回模板状态，
> 你的数据库密码就丢了，每次都得重填 —— 而每次重填都是一次填错的机会。
>
> 用 `config.local.php` 就一次配好、长期有效：
>
> ```bash
> # 1. 把 api/config.local.example.php 复制成 api/config.local.php
> # 2. 照里面的注释填上你自己的值
> # 3. 上传到 api/ 目录，和 config.php 放一起
> ```
>
> `config.php` 会在最后加载它并覆盖对应项。文件不存在也完全正常，不影响使用。

不管用哪种方式，要改的都是**三处**：

```php
// ① 数据库三项 —— 用 3.2 记下的值
$DB_HOST = 'localhost';                   // 多数虚拟主机就是 localhost
$DB_NAME = 'your_database_name';          // 库名，常带主机商前缀
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

// ② 加盐 —— 随便敲一串乱码，32 位以上
$SALT = 'CHANGE_ME_PLEASE_REPLACE_WITH_A_LONG_RANDOM_STRING';

// ③ CORS 白名单 —— 把你会在浏览器里打开的所有域名都列上
$ALLOWED_ORIGINS = array(
    'https://你的博客域名',
    'https://你的测试域名',
    'https://*.你的域名',        // 通配：该域名下所有子域都放行
);
```

改完**重新上传**那个文件。

> **为什么默认值是占位符而不是真值**：留一个一眼看得出「还没填」的值，
> 好过一个看起来像真值的东西 —— 万一哪次忘了改，MySQL 会明确报「密码错」，
> 而不是让人对着一个莫名其妙的连接失败挠头。

> **`$SALT` 上线后不要再改。** 改一次，所有老访客会被当成新访客，可以重复点赞。
> 正式开放前定下来就行。

> **白名单漏了域名的症状很迷惑**：查询正常，但一点赞就返回 400。
> 原因见第 7 章「查询正常但点赞返回 400」。

### 3.4 建表

**推荐**：上传 `install.php` 到 `api/` 的上一级，打开

```
https://你的域名/zero-dep-likes/install.php
```

点「开始建表」。它会先显示连接情况（**确认库名对不对**），再建表，建完自动校验字段结构
和防重复用的唯一索引，最后给出你这次部署的真实接口地址。

建完点「删除 install.php」把它自己删掉。

**也可以手动导入**：phpMyAdmin 里**先点中数据库**，再切到「导入」，选 `sql/schema.sql` 执行。

> ⚠️ phpMyAdmin 是「先选库、再导入」。左侧没选中就导，轻则报 `#1046 No database selected`，
> 重则**悄悄导进你上次打开的那个库**，然后你盯着目标库纳闷。

**验证**：数据库里出现两张表

```
article_likes    ← 每篇文章的总点赞数
like_records     ← 点赞明细（防重复）
```

`like_records` 上必须有个叫 `uniq_article_visitor` 的**唯一索引**——防重复全靠它。

### 3.5 验证后端真的通了

浏览器打开：

```
https://你的域名/zero-dep-likes/api/likes.php?article_id=test-001
```

**应该看到**：

```json
{"code":0,"msg":"ok","data":{"article_id":"test-001","like_count":0,"liked":false}}
```

这一步才是**分水岭**——它真的去数据库查了。

| 你看到的 | 说明 | 怎么办 |
|---|---|---|
| `code:0` + `like_count:0` | ✅ 连接、建表、权限全就绪 | 继续 3.6 |
| `code:500` | 数据库这步没过 | 把 `$DEBUG` 改成 `true` 再请求一次，`data.error` 里会带 MySQL 原话，对照第 7 章的表；**查完立刻改回 `false`** |
| `code:400` | URL 被截断了 | 检查 `?` 和 `article_id=` 写全没有 |

### 3.6 跑一遍自检（强烈建议）

上传 `check.html` 到 `api/` 同级，打开

```
https://你的域名/zero-dep-likes/check.html
```

点「开始检测」。它会自动跑 12 项，逐条给出**期望值 vs 实际值**。
预期结果：**12 项通过 + 1 项跳过**（跳过的是 CORS，同源测不了，页面上写了原因）。

**验完删掉 `check.html`** —— 它会把接口信息暴露给访问者。

### 3.7 别忘关调试

如果为了排查开过 `$DEBUG = true`，**现在改回 `false` 并重新上传**。
打开时它会把数据库信息暴露给任何访问者。

---

## 4. 接进博客

### 4.1 三步

**第一步**：在文章模板的**正文末尾**（评论框之前）加一个空组件：

```html
<div class="like-widget"></div>
```

就这一行。**不用写 `data-article-id`** —— 组件会从当前页面链接自动派生，
每篇文章天然得到自己的 ID（原理见第 9 章）。

> **为什么空的也能用**：脚本发现组件里什么都没有时，会自动把按钮、图标、计数
> 三层结构建出来。所以你不必手抄一长串 HTML。
>
> 想自定义按钮里的文字或图标？那就自己写完整结构（见下面的"完整写法"），
> 脚本检测到里面已经有 `.like-button` 就不会再动它。

<details>
<summary>完整写法（想自定义时用）</summary>

```html
<div class="like-widget" data-article-id="hello-world">
  <button class="like-button" type="button" aria-pressed="false" aria-label="点赞这篇文章">
    <svg class="like-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.58 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-1z"/>
    </svg>
    <span class="like-label">点赞</span>
    <span class="like-count">--</span>
  </button>
</div>
```

三个 class 名不能改：`like-button`、`like-icon`、`like-count`。`like-label` 可以删掉。
`--` 是占位，加载完会显示真实数字。

</details>

**第二步**：在模板的 `<head>` 里加样式表，**全站只加一次**：

```html
<link rel="stylesheet" href="/zero-dep-likes/assets/likes/likes.css">
```

**第三步**：在 `</body>` 之前加脚本，**同样全站只加一次**：

```html
<script src="/zero-dep-likes/assets/likes/likes.js?v=1.7.3"
        data-api="/zero-dep-likes/api/likes.php"></script>
```

> ### ⚠️ `?v=1.7.3` 这个查询串，**强烈建议加上**，不是可选
>
> 它不影响文件怎么加载，作用是**让 URL 变掉**。这很重要，因为：
>
> - **浏览器会缓存 `.js`**，有时 Ctrl+F5 也未必刷得干净
> - **国内虚拟主机常有 CDN / 静态资源缓存层**。这种情况下缓存根本不在你电脑上，
>   **你怎么刷浏览器都没用**——只有换一个 URL 才能绕过去
>
> 结果就是：文件明明传上去了，页面行为却还是旧的，非常难自查。
> 加上 `?v=` 之后，每次改脚本就把这个数字改一下（跟 `CHANGELOG.md` 的版本号一致），
> 缓存问题从根上消失。
>
> **怎么确认当前跑的是哪一版**：打开页面按 F12，看控制台有没有
> `[likes] likes.js v1.7.3 已加载 · API: …`。
> 有 = 新脚本确实加载了；没有 = 还是旧的（缓存），改 `?v=` 的数字再试。

> **`data-api` 这一项不能漏。** `likes.js` 默认去找 `/api/likes.php`，
> 你把文件放在 `/zero-dep-likes/` 下就必须显式告诉它。忘了改的话，页面看起来正常，
> 但点赞数永远是 `--`，控制台里会有 404。

### 4.2 三种场景

#### 场景一：博客支持模板变量（最稳）

在文章详情模板里让 ID 由系统生成：

```php
<!-- PHP 博客 -->
<div class="like-widget" data-article-id="<?php echo htmlspecialchars($post['slug']); ?>"></div>
```

```html
<!-- Hexo / Hugo -->
<div class="like-widget" data-article-id="{{ page.slug }}"></div>
```

**为什么推荐这种**：ID 由系统保证唯一，文章改标题、改分类都不影响计数。

#### 场景二：手写 HTML（用自动派生）

模板里放空的 `<div class="like-widget"></div>`，ID 从链接派生：

| 文章链接 | 派生出的 ID |
|---|---|
| `/posts/hello-world.html` | `posts-hello-world` |
| `/posts/hello-world/` | `posts-hello-world` |
| `/2026/09/11/why-slug/` | `2026-09-11-why-slug` |

同一篇文章的 `/x.html`、`/x`、`/x/` 三种写法会归一到**同一个 ID**，不会分裂计数。

**代价**：以后**改 URL**（换 slug、换目录）会被当成新文章，点赞数从 0 开始。

#### 场景三：列表页（必须逐个指定）

列表页一页显示多篇文章摘要，**不能用自动派生** —— 它们同处一个 URL，
算出来的 ID 会是同一个，反而共用一个计数。

```html
<article>
  <h2>第一篇标题</h2>
  <div class="like-widget" data-article-id="hello-world"></div>
</article>

<article>
  <h2>第二篇标题</h2>
  <div class="like-widget" data-article-id="why-slug"></div>
</article>
```

写漏了也不会悄悄错下去——组件会在页面上直接告警。

> **`data-article-id` 的格式**：只允许字母、数字、下划线、中划线（`A-Za-z0-9_-`），
> 长度 1–191。**中文、空格、点号都不行**，会被接口拒绝并返回 400。
>
> 主键就是这个 ID，所以**改 slug 会丢掉那篇文章的点赞数**。发布后尽量别再改。

### 4.3 先试一下再改模板

想在不碰博客的前提下先看效果，上传 `examples/live-test.html` 到 `/zero-dep-likes/examples/`，
打开它。那一页接的是**你真实的数据库**：点一下赞、刷新，数字会留下来。

页面上还会显示组件**实际派生出**的 `article_id`，方便你确认。

### 4.4 API 地址是怎么定下来的（`likes.js` 要不要改成我的链接？）

**不用改 `likes.js`。** 那个文件里**没有写死任何域名或路径**，你一次都不需要编辑它。

API 地址在**引入脚本的那一行**决定：

```html
<script src="/zero-dep-likes/assets/likes/likes.js"
        data-api="/zero-dep-likes/api/likes.php"></script>
                                      ↑ 就改这一处
```

`likes.js` 按这个顺序找地址，找到就用，不再往下找：

| 优先级 | 来源 | 什么时候用 |
|---|---|---|
| 1 | `<script>` 标签上的 `data-api` | **推荐**。最明确，一眼能看出指向哪 |
| 2 | 全局变量 `window.LIKES_API` | 不方便改 script 标签时（比如在文章正文里贴代码） |
| 3 | 从脚本自身地址反推 | 忘了写 `data-api` 时的自动兜底 |
| 4 | `/api/likes.php` | 最后的兜底 |

**第 3 条是怎么推的**：`api/` 和 `assets/` 在同一个项目目录下，层级关系固定，
所以从脚本自己的位置往回推一定推得出来：

```
/zero-dep-likes/assets/likes/likes.js   →   /zero-dep-likes/api/likes.php
/assets/likes/likes.js              →   /api/likes.php
```

也就是说**即使你漏写 `data-api`，通常也能正常工作**（控制台会有一句 `[likes] 没写 data-api…` 的提示）。
但建议还是写上——显式比隐式好排查。

#### 路径用哪种写法

| 写法 | 例子 | 特点 |
|---|---|---|
| **根相对（推荐）** | `/zero-dep-likes/api/likes.php` | 以 `/` 开头，跟着域名走，不受文章 URL 层级影响 |
| 绝对地址 | `https://staging.example.com/zero-dep-likes/api/likes.php` | 最明确，但换域名要改 |
| 页面相对 | `../api/likes.php` | **别用**。文章 URL 深浅不一，会算错 |

**为什么推荐根相对**：文章的 URL 层级往往不一样（`/posts/a.html` 和 `/2026/09/11/b/`），
页面相对路径在深链接上会算到错误的目录。以 `/` 开头则永远从域名根开始，稳。

#### 多个域名指向同一套文件

如果你的博客有多个域名（比如 `blog.example.com` 和 `www.example.com`）**指向同一个目录**，
那么一份引入代码就够了，不用区分。

如果指向**不同目录**，`data-api` 的路径就要各自适配。

### 4.5 别人怎么调用这个接口

接口是**公开的 HTTP 接口**，没有密钥，任何人都能调。

```
https://你的域名/zero-dep-likes/api/likes.php
```

| 接口 | 用法 |
|---|---|
| 查单篇 | `GET /api/likes.php?article_id=hello-world&visitor_id=xxx` |
| 批量查 | `GET /api/likes.php?article_ids=a,b,c&visitor_id=xxx` |
| 点赞 | `POST`，body `{"article_id":"…","visitor_id":"…"}` |
| 取消点赞 | `POST`，body 同上再加 `"action":"unlike"`（也接受 `DELETE` 方法） |

完整字段说明见 `README.md` 第四章「API 文档」。

#### 关键：CORS 只管浏览器，不管服务端

很多人会在这里困惑，其实规则很单纯：

| 谁在调 | 受 CORS 白名单限制吗 | 说明 |
|---|---|---|
| 浏览器地址栏直接打开 | **不受** | 你不是在"跨域请求"，是在访问一个地址 |
| 服务端代码（curl / Python / PHP / Node） | **不受** | CORS 是浏览器自己加的规矩，服务端没有这个概念 |
| 同域名页面里的 `fetch` | **不受** | 同源，压根不走 CORS |
| **别的网站的前端 `fetch`** | **受** | 只有这一种情况需要把对方域名加进白名单 |

所以「别人能不能用」取决于**他怎么用**：

- **在自己服务器上抓点赞数做统计** → 直接调，不需要配任何东西：
  ```bash
  curl "https://你的域名/zero-dep-likes/api/likes.php?article_id=hello-world"
  ```
- **在他自己的网页上放点赞按钮** → 需要把他的域名加进 `$ALLOWED_ORIGINS`，
  否则浏览器会拦下响应（症状：能打开接口看到 JSON，但网页里的 fetch 失败）

#### 两个要提醒对方的点

1. **写操作需要 `visitor_id`**，而且要求 8–128 位可见 ASCII 字符。
   它只是个随机串，由调用方自己生成，服务端不校验身份——**匿名点赞本来就是这个设计**。
2. **`article_id` 只允许字母、数字、下划线、中划线**（`A-Za-z0-9_-`），长度 1–191。
   传中文或空格会返回 400。

> **别把接口地址当成"私密"的**。它本来就是公开的，任何知道地址的人都能读点赞数。
> 防护靠的是：写操作有唯一索引防重复、有 IP 限流、
> 以及数据库用户只授予 `SELECT` / `INSERT` / `UPDATE` 三种权限。

---

## 5. 上线前的验收清单

### 后端（已可自动验证）

跑 `check.html`，预期 **12 项通过 + 1 项跳过**。

### 前端（要在真实页面上手点）

改完模板后，打开一篇文章，按 F12：

- [ ] Console 里**没有红色报错**
- [ ] Network 里有 `/api/likes.php` 请求，状态 200
- [ ] 点赞数正确显示（先闪一下 `--`，然后变成数字）
- [ ] 点按钮：数字立刻 +1，变珊瑚橙、文字变「已赞」、图标从描边变实心
- [ ] **已赞状态下按钮仍然可点**（不是灰的）—— 这是取消点赞的入口
- [ ] 鼠标悬停时标题提示「点击取消点赞」
- [ ] **再点一次**：数字回到原值，颜色和文字恢复成未赞状态
- [ ] 取消之后**还能再点赞**（可以反复切换）
- [ ] **刷新页面**：状态与数据库一致（赞了就是已赞，取消了就是未赞）
- [ ] 快速双击**不会**变成"赞了又取消"（有 400ms 防误触）
- [ ] 列表页有多篇时只发**一次**请求（URL 里是 `article_ids=...` 复数）
- [ ] 断网再点：出现「点赞失败，请稍后再试」，数字**弹回原值**
- [ ] 切深色主题，按钮跟着变
- [ ] 手机上按钮够大好点

### 并发（只能命令行）

自检页是串行请求，测不了并发。这条是**验收标准里最容易翻车的一项**，建议跑一次：

```bash
API="https://你的域名/zero-dep-likes/api/likes.php"

# 20 个不同访客同时点赞同一篇
for i in $(seq 1 20); do
  curl -s -X POST "$API" -H "Content-Type: application/json" \
    -d "{\"article_id\":\"itest-concurrent\",\"visitor_id\":\"concurrent-visitor-$i-xxxx\"}" > /dev/null &
done
wait

curl -s "$API?article_id=itest-concurrent"; echo
```

**期望 `like_count` 正好是 20**，不是 19 也不是 21。

再对照一下计数与明细是否一致（正常应该查不出任何行）：

```sql
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
GROUP BY l.article_id, l.like_count
HAVING l.like_count <> COUNT(r.id);
```

### 收尾

- [ ] 删掉 `install.php`（表已建好，使命结束）
- [ ] 删掉 `check.html`（会暴露接口信息）
- [ ] 删掉 `where.php`（会暴露服务器路径）
- [ ] 清掉测试数据（见第 6 章）
- [ ] 确认 `$DEBUG = false`
- [ ] **备份数据库**

### 安全确认（含一个必须纠正的误解）

#### 误解：以为 CORS 白名单是一道安全防线

**它不是。** 这一点必须先说清楚，否则容易产生虚假的安全感。

**CORS 是浏览器自己给自己定的规矩，服务端没有这个概念。** 所以白名单实际能挡的只有一种情况：

| 攻击方式 | 白名单挡得住吗 |
|---|---|
| 别的网站的前端 JS 调用你的接口 | ✅ 挡得住（浏览器拦下响应） |
| 用 curl / Postman / Python 调 | ❌ **完全挡不住** |
| 在自己服务器上批量刷赞 | ❌ 挡不住 |
| 直接在浏览器打开接口看数据 | ❌ 挡不住（本来就该能看） |

**一句话**：白名单防的是"别人拿你的接口当他网站的后端"，
**不是**"别人调你的接口"。任何人都能绕过白名单直接发请求，只要他不经过浏览器。

#### 那"没有密钥"算不算问题？

**对这个功能来说，不算。** 三个理由：

1. **本来就没有"用户身份"这个概念。** 点赞是匿名的，密钥发给谁？
2. **前端可见的密钥等于没有密钥。** 页面源码、网络请求全是公开的，
   任何放在前端的凭据都能被复制走。想靠它防刷是自欺欺人。
3. **数据本来就是公开的。** 点赞数显示在页面上，任何人都看得到。

所以这套设计防的**不是"未授权访问"，而是"重复计数"**。目标不同，手段自然不同。

#### 实际生效的防护

| 风险 | 措施 | 效果 |
|---|---|---|
| 手抖连点 | 前端禁用按钮 | 体验层面，不是安全 |
| 一个人反复赞 | 唯一索引 + `INSERT IGNORE` | **有效** |
| 清空浏览器数据后再赞 | 无解 | 匿名方案的固有限制 |
| 脚本换 `visitor_id` 批量刷 | IP 限流 | **减缓**，不能根除 |
| SQL 注入 | PDO 预处理 + 参数白名单校验 | **有效** |
| 数据库被拖走 | 访客标识 / IP / UA 全部加盐哈希 | **无法反推访客身份** |
| 配置泄露 | 非入口 PHP 文件 403 守卫 | **有效** |
| 报错泄露数据库信息 | 生产环境只返回 `server error` | **有效** |
| 数据库被删 | 用户只授予 `SELECT`/`INSERT`/`UPDATE` | 拿不到 `DROP`/`ALTER` |

#### 最坏情况是什么

有人写脚本给某篇文章刷 500 个赞。后果：

- 那篇文章的**数字不真实** —— 仅此而已
- **没有数据泄露**（本来就没有敏感数据）
- **不会拖垮服务**（单次请求极轻，且有 IP 限流）

**代价是"数字可能不可信"，不是"系统被攻破"。** 对博客点赞这个量级，这个代价可以接受。

#### 上线前逐条确认

- [ ] `$DEBUG = false`（**最重要的一条**。打开时会把数据库信息返回给任何访问者）
- [ ] `$SALT` 已改成自己的随机串（不是默认占位符）
- [ ] `$ALLOWED_ORIGINS` 里没有 `*`，只列你自己的域名
- [ ] 数据库用户只授予了 `SELECT` / `INSERT` / `UPDATE`
- [ ] 删掉 `install.php` / `check.html` / `where.php`
- [ ] `$RATE_LIMIT_ENABLED` 是 `true`
- [ ] 数据库已备份，且知道怎么恢复

#### 想再紧一点，可以做的

| 手段 | 代价 |
|---|---|
| 把 `$RATE_LIMIT_MAX` 从 30 调到 10 | 正常用户也可能被误伤 |
| 套 Cloudflare 并把 `$TRUST_PROXY` 设 `true` | 多一层配置，但限流才真正按真实 IP 算 |
| 定期跑校对 SQL，发现异常计数就清理 | 需要人工 |
| **接入登录，把 `visitor_id` 换成 `user_id`** | **唯一真正解决"一人一赞"的办法**，但需要博客有登录体系 |

**如果哪天发现被刷了**：先看 `like_records` 里同一 `ip_hash` 的记录量，
把限流调紧；极端情况直接清掉那篇的明细重来（SQL 见第 6 章）。

---

## 6. 日常维护

### 备份

只有两张表，很轻量。phpMyAdmin 里选中它们导出即可。

**关键认识**：`like_records` 是**真相**，`article_likes` 只是它的汇总。
只要明细在，计数丢了也能重建：

```sql
INSERT INTO article_likes (article_id, like_count)
SELECT article_id, COUNT(*) FROM like_records GROUP BY article_id
ON DUPLICATE KEY UPDATE like_count = VALUES(like_count);
```

### 常用查询

```sql
-- 点赞最多的 20 篇（顺便体检：出现 post-1 / test 这类 ID 且数字特别高，说明撞车了）
SELECT article_id, like_count FROM article_likes ORDER BY like_count DESC LIMIT 20;

-- 今天的点赞
SELECT article_id, created_at FROM like_records
WHERE created_at >= CURDATE() ORDER BY id DESC;

-- 每天的点赞量趋势
SELECT DATE(created_at) AS d, COUNT(*) AS n FROM like_records
GROUP BY d ORDER BY d DESC LIMIT 30;

-- 校对计数与明细（正常查不出任何行）
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
GROUP BY l.article_id, l.like_count
HAVING l.like_count <> COUNT(r.id);
```

### 清理测试数据

```sql
DELETE FROM like_records   WHERE article_id LIKE 'check-%' OR article_id LIKE 'itest-%' OR article_id LIKE 'test-%';
DELETE FROM article_likes  WHERE article_id LIKE 'check-%' OR article_id LIKE 'itest-%' OR article_id LIKE 'test-%';
```

### 清零某篇文章的点赞

```sql
DELETE FROM like_records  WHERE article_id = 'hello-world';
DELETE FROM article_likes WHERE article_id = 'hello-world';
```

> 两行都要删。只删计数不删明细的话，同一个人再也赞不了这篇文章
> （明细还在，会被判定为"已赞过"）。

---

## 7. 按症状查问题

### 查询正常，但一点赞就返回 400（`origin not allowed`）

**最常见的坑，先看这条。**

原因在浏览器规范里：

- **同源的 GET 不发 `Origin` 头** → 接口直接放行
- **同源的 POST 会发 `Origin` 头** → 接口拿它比对白名单，没匹配上就拒绝

所以只要「你正在访问的域名」没写进 `$ALLOWED_ORIGINS`，就会出现"读得到、点不了"。

**解决**：把浏览器地址栏里正在用的那个域名，原样加进白名单。

```php
$ALLOWED_ORIGINS = array(
    'https://blog.example.com',
    'https://www.example.com',
    'https://测试用的域名',
    'https://*.example.com',      // 通配子域
);
```

注意：**必须带协议**（`https://` 和 `http://` 是两个来源）、**带不带 `www.` 也不同**、
**端口不同也不同**。

### 接口返回 500

**第一步**：把 `$DEBUG` 临时改成 `true`，重传 `config.php`，再请求一次。
`data.error` 里会带 **MySQL 的原话**，照下表对号入座：

| MySQL 原话 | 意思 | 怎么办 |
|---|---|---|
| `[1045] Access denied` | 用户名或密码不对 | 回面板核对，注意密码里的特殊字符 |
| `[1044] Access denied for database` | 用户没绑定到这个库 | 面板里授权 |
| `[1049] Unknown database` | 库名写错 | 库名常带前缀，一字不能错 |
| `[2002] Connection refused` | 主机地址写错 | 多数虚拟主机是 `localhost` |
| `Base table or view not found` / `[1146]` | 表不存在 | `schema.sql` 没导入，或导到了别的库 |
| `could not find driver` | 没装 pdo_mysql | 找主机商开 |

**查完立刻改回 `false`**。

### 页面上什么都没有，连按钮都看不见

组件空着（`<div class="like-widget"></div>`）是**正常可用的**，脚本会自动补上按钮。

如果页面上真的什么都不显示，F12 看 Console：

| 控制台看到 | 原因 |
|---|---|
| `[likes] 这个组件的结构不对，已跳过` | 组件**里面有东西但结构不对**——class 名拼错了，或者嵌套层级乱了。要么清空成 `<div class="like-widget"></div>`，要么按文档抄完整结构 |
| 完全没有 `[likes]` 开头的输出 | `likes.js` 根本没加载（见上一条的 Network 排查），或者页面上没有 `.like-widget` 元素 |
| `[likes] 跳过` 都没出现，但工具栏也没有 | CSS 没加载 → 按钮在，只是没有样式，是透明背景的一行文字。检查 `<link rel="stylesheet">` 路径 |

> 记住三个不能改的 class 名：`like-button`、`like-icon`、`like-count`。

### 点赞数一直显示 `--`

1. F12 → Network，看有没有 `/api/likes.php` 请求：
   - **完全没有** → `likes.js` 没加载成功，看 Source 面板有没有这个文件
   - **404** → `data-api` 路径写错了（见 4.1 第三步）
   - **400** → `data-article-id` 里有非法字符（中文、空格、点号都不行）
   - **429** → 触发限流了，等一分钟
   - **500** → 见上一条
2. Console 里会有 `[likes]` 开头的警告，写着具体原因

### 取消点赞点了没反应

按顺序查：

1. **按钮是灰的吗？** 已赞状态下按钮**应该是可点的**（那是取消的入口）。
   如果是灰的、点不动，说明浏览器加载的还是旧版 `likes.js` ——
   去看控制台有没有 `[likes] likes.js v1.8.0 已加载`。没有就是缓存，见第 4 章第三步。
2. **是不是刚点过？** 有 400ms 防误触：第一次点击后 400 毫秒内的第二次点击会被忽略
   （防止双击变成"点赞又取消"）。等一下再点。
3. **F12 → Network**，看那次 `likes.php` 请求的请求体里有没有 `"action":"unlike"`。
   没有就还是旧版脚本。
4. 接口返回 `removed: false` 是**正常的** —— 说明本来就没赞过，不是错误。

### 页面上出现橙色的「文章 ID 还是示例值」

组件检测到 `data-article-id` 还是 `post-1`、`article_id`、`test` 这类示例值。
**这是好消息**——说明检测生效了。改成这篇文章自己的 slug 即可。

### 页面上出现橙色的「这一页有 N 个组件的 ID 都是…」

同一页里多个组件用了同一个 ID，它们会共用一个计数。最常见的原因：
**在列表页用了自动派生**（第 4.2 场景三）。

### 图标不显示

**上传图标文件不会让图标自动生效**，必须在页面 `<head>` 里声明：

```html
<link rel="icon" type="image/svg+xml" href="/zero-dep-likes/assets/likes/icon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/zero-dep-likes/assets/likes/icon-32.png">
```

**排查**：打开 `assets/likes/icon-preview.html`，它会把四个图标文件实际加载一遍并逐个报结果，
一次区分三种情况：文件没上传 / 服务器 MIME 不认 svg / 浏览器缓存了之前的 404。

> ⚠️ **`/api/likes.php` 那个地址永远不会有图标。** 它返回的是 JSON 数据，不是网页，
> 没有 `<head>` 就没地方声明 favicon。这是原理上不可能，不是配置问题。

### 服务器把 PHP 源码显示出来了

主机没开 PHP，或者路径被当静态文件处理了。找主机商确认 PHP 已启用。
自检页的 ② 项就是专门查这个的（应该返回 403）。

---

## 8. 常见需求怎么改

### 换强调色

在你的博客样式里覆盖 CSS 变量：

```css
.like-widget {
    --like-accent: #F77234;     /* 已赞状态的强调色 */
    --like-font-size: 0.9375rem;
    --like-radius: 6px;
}
```

### 想用 emoji 图标（👍）替代内联 SVG

把 HTML 里的 `<svg>...</svg>` 整段换成 `👍` 即可，样式不用改。

> 默认用 SVG 的原因：emoji 作功能图标在不同系统上渲染差异很大（Windows / macOS /
> Android 长得都不一样），跟博客排版也难对齐。

### 改成"点赞后不能取消"（点过即锁）

当前是**可取消**的：已赞状态再点一次就取消。这是默认行为，因为"误点了没法撤回"
是很糟的体验。

如果确实想恢复成"点过就锁死"：

1. **前端**：`likes.js` 的 `applyState()` 里，把
   `item.button.disabled = false;` 改成 `item.button.disabled = item.liked;`
2. **后端**：不用改。保留 `unlike` 能力但没有入口，不影响任何东西

> 但不建议。取消功能是后加的，就是为了解决"误点"；锁死之后用户只能干看着。

### 调按钮上的文案

「点赞」和「已赞」这两个词写在两处，都要改：

- **HTML 里**：`<span class="like-label">点赞</span>`（空组件自动生成的那份在 `likes.js`）
- **`likes.js` 的 `applyState()` 里**：`label.textContent = item.liked ? '已赞' : '点赞';`

想换成图标不带文字，把 `.like-label` 那个 span 删掉即可，样式不用动。

### 关掉限流 / 调松

```php
$RATE_LIMIT_ENABLED = false;   // 完全关掉
$RATE_LIMIT_MAX = 60;          // 或者调大上限
```

> 套了 Cloudflare 或 Nginx 反代的，记得把 `$TRUST_PROXY` 改成 `true`，
> 否则所有访客看起来是同一个 IP，会一起被限流。

### 显示"谁赞过"列表

数据已经在 `like_records` 里了，加个查询接口即可。但注意：
**库里存的是哈希，没有原始身份**，所以只能显示"某某访客"，
要显示昵称得接入登录体系。

---

## 9. 原理与设计取舍

这一章解释"为什么这么做"。出了怪问题时，答案往往在这里。

### 9.1 数据怎么组织的

两张表分工明确：

```
article_likes                     like_records
┌──────────────┬────────────┐    ┌────┬──────────────┬──────────────┬──────────┐
│ article_id   │ like_count │    │ id │ article_id   │ visitor_hash │ ip_hash  │
├──────────────┼────────────┤    ├────┼──────────────┼──────────────┼──────────┤
│ hello-world  │     12     │    │ 1  │ hello-world  │ a3f9…        │ 7c21…    │
│ why-slug     │      5     │    │ 2  │ hello-world  │ b81e…        │ 7c21…    │
└──────────────┴────────────┘    │ 3  │ why-slug     │ a3f9…        │ 7c21…    │
   ↑ 计数（主键隔离）             └────┴──────────────┴──────────────┴──────────┘
                                     ↑ 明细：(article_id + visitor_hash) 唯一
```

- **计数表**用 `article_id` 做主键，所以"一篇文章只有一个计数"是**结构保证**的，
  不靠代码自觉
- **明细表**在 `(article_id, visitor_hash)` 上建唯一索引，所以"一人一篇只能赞一次"
  也是**结构保证**的

### 9.2 防重复的三道防线

1. **前端**：点完立刻禁用按钮 —— 防手抖，但这只是体验，**不是安全**
2. **唯一索引 + `INSERT IGNORE`**：绕过前端直接发请求、并发同时打进来，
   也最多只写进一行。这一层是真正兜底的
3. **原子自增**：`ON DUPLICATE KEY UPDATE like_count = like_count + 1` ——
   自增在 MySQL 内部完成，没有"读-改-写"的空隙，并发不会互相覆盖

再加**死锁重试**（3 次，退避 50/100ms），两个请求撞车也不丢数据。

### 9.3 为什么 `1001` 返回 HTTP 200

"早就赞过了"不是错误，是**正常业务状态**。而且这时还要把最新计数带回去同步按钮。
返回 200 让前端一个分支就能处理完，不用写异常路径。

**这是有意的**，不是 bug。接手的人别"顺手修掉"。

### 9.4 隐私：为什么库里没有访客身份

前端生成的随机 `visitor_id`、IP、User-Agent **全部加盐 sha256 后**才落库：

```
visitor_id  →  sha256(visitor_id + $SALT)  →  visitor_hash
```

数据库被拖走也没法反推出具体是谁点的赞。

**代价**：`$SALT` 一旦更改，所有算出的哈希都变了，老访客会被当成新访客。
所以**上线后不要再改**。

### 9.5 `article_id` 决定一切

**点赞数的隔离完全由 `data-article-id` 决定。** 这是整套设计里唯一会"安静出错"的地方：

> 多篇文章填了同一个 ID → 它们共用计数 → **不报错、不提示**，只是数字莫名一起涨。

所以前端内置了两道检测：**示例值检测**（`post-1` 这种）和**同页重复检测**，
命中就在页面上用强调色告警。宁可误报，也不能让它悄悄发生。

**身份 vs 地址**：slug 是文章的**身份**，URL 是文章的**地址**。
地址会变，身份不该变。所以能写死 `data-article-id` 就写死；用自动派生要接受"以后别改 URL"。

### 9.6 取消点赞为什么"先删明细，再减计数"

顺序不是随便定的：**先删 `like_records` 里那行，删掉了才去把 `article_likes` 减 1。**

这样"**计数 = 明细条数**"这个不变量始终成立。反过来的话——先减计数再删明细——
一旦删明细失败，就会留下：计数少了 1，但那个人仍被记为"已赞过"。
后果是他既赞不了（被认为已赞）也取消不掉（取消会再减一次），**只能手工改数据库**。

另外 `like_count - 1` 外面套了一层 `GREATEST(..., 0)`。正常运行永远触发不到，
但它保证任何异常情况下计数都不会变成负数。

**为什么取消点赞不计入限流**：它不新增明细，没有刷的价值；
而且把取消也限流的话，正常用户误点两次反而会被拦，得不偿失。

### 9.7 为什么主推 `POST + action` 而不是 `DELETE`

`DELETE` 在语义上更对，但**部分虚拟主机 / CDN / WAF 会拦掉带请求体的 `DELETE`**，
症状是"本地 curl 好用、线上莫名其妙失败"——这种环境差异极难排查。

`POST` 到处都能过。所以正式前端走 `POST + action: "unlike"`，
`DELETE` 只作为别名提供，方便用工具直接调。

**顺带**：不带 `action` 字段时默认是点赞，所以**老版本的调用方不用改**（向后兼容）。

### 9.8 前端为什么要有 400ms 防误触

已赞状态下按钮仍可点（这是取消的入口），于是**双击就会变成"点赞 → 立刻又取消"**，
用户只看到数字跳了一下又回去，非常困惑。

所以 `likes.js` 里记了一次 `lastActionAt`：距上次操作不足 400ms 的点击直接忽略。
想真心取消的话，过 400ms 再点就好——对正常使用没有任何影响。

### 9.9 为什么限流按"成功写入的明细"计数

重复点赞不产生新明细，所以正常用户几乎不可能触发限流，
它主要拦的是"脚本换 `visitor_id` 批量刷不同文章"。

---

## 10. 文件清单

### 必须上传的

| 文件 | 作用 |
|---|---|
| `api/config.php` | 配置：数据库、salt、白名单、限流 |
| `api/db.php` | PDO 连接与日志 |
| `api/response.php` | 统一 JSON 响应 + CORS |
| `api/likes.php` | API 入口 |
| `assets/likes/likes.js` | 前端交互 |
| `assets/likes/likes.css` | 组件样式 |

### 一次性工具（用完删）

| 文件 | 什么时候用 |
|---|---|
| `install.php` | 建表。建完删 |
| `check.html` | 部署自检 12 项。验完删 |
| `where.php` | 文件位置诊断（遇到 404 时）。用完删 |

### 图标（可选）

`assets/likes/` 下的 `icon.svg` / `icon-32.png` / `icon-180.png` / `icon-512.png` /
`favicon.ico` / `icon-preview.html`。**不用图标的话可以全部删掉**，不影响点赞功能。

### 不用上传

| 文件 | 用途 |
|---|---|
| `examples/demo.html` | 离线预览（内置假后端，本地打开看长相） |
| `examples/live-test.html` | 真实接口试用（想试的话传上去） |
| `sql/schema.sql` | 建表语句，手动导入时用（用 `install.php` 就不需要） |
| `tests/api-test.md` | curl 测试用例 |
| `README.md` / `DEPLOY.md` / `TUTORIAL.md` / `CHANGELOG.md` | 文档 |

### 想彻底卸载

1. 从博客模板里删掉那三处引入（组件 div、CSS、JS）
2. 删掉服务器上的 `zero-dep-likes/` 整个目录
3. 数据库里 `DROP TABLE like_records; DROP TABLE article_likes;`

---

## 附：出问题时该给我什么

1. `check.html` 点「复制结果」后的文本
2. F12 → Console 里以 `[likes]` 开头的警告
3. 如果是 500，`$DEBUG = true` 时 `data.error` 的内容
4. 你正在访问的**完整地址**

有这几样，基本一眼能定位。
