# 上手清单

> **这是一页纸的快查版**：只有操作步骤和验证点，不含原理。
> 第一次部署建议看 [`TUTORIAL.md`](TUTORIAL.md)——它有完整的教程、排错索引和设计说明。

照着从上往下做。每一步都写了「**这一步做完你应该看到什么**」，对不上就停下来查，
不要跳着往下走——后面每一步都依赖前面。

---

## 第 0 步：文件放对位置

> ### ⚠️ 两个最常见的 404 陷阱，先看这两条
>
> **陷阱一：多套了一层文件夹。**
> 压缩包解开后外面有个 `zero-dep-likes/` 文件夹。**要上传的是它里面的内容，不是它本身。**
> 如果连文件夹一起传，真实地址就变成 `/zero-dep-likes/api/likes.php`，而不是 `/api/likes.php`。
>
> **陷阱二：FTP 登录看到的根目录，通常不是网站根目录。**
> 虚拟主机的真实网站根目录常叫 `public_html` / `htdocs` / `wwwroot` / `www` / `web`，
> 或者是**与域名同名的文件夹**。而且**每个域名各绑一个目录**——
> 你把文件传到总根目录，`blog.你的域名/api/...` 照样 404。
>
> **怎么找对地方**：你的博客首页能正常打开，那么**首页那个 index 文件所在的目录**
> 就是网站根目录，`api/` 要放在那里。
>
> **不确定的话**：上传 `where.php` 到那个目录，浏览器打开 `https://你的域名/where.php`，
> 它会直接告诉你「这里是不是网站根目录」「api/ 找不找得到」。用完删掉。

目标结构（**注意没有 `zero-dep-likes` 这一层**）：

```
网站根目录/
├── index.php 或 index.html   ← 你博客的首页，本来就在这儿
├── api/
│   ├── config.php
│   ├── db.php
│   ├── response.php
│   └── likes.php
├── assets/
│   └── likes/
│       ├── likes.js
│       └── likes.css
├── install.php               ← 建表用，和 api/ 同级，用完删
└── check.html                ← 自检用，和 api/ 同级，用完删
```

**验证**：浏览器打开下面三个地址。

| 地址 | 应该看到 |
|---|---|
| `https://你的域名/api/likes.php` | `{"code":400,"msg":"article_id is required","data":{}}` |
| `https://你的域名/api/config.php` | `Forbidden`（这是对的，是安全守卫，不是报错） |
| `https://你的域名/assets/likes/likes.js` | JS 源码 |

**看到 404（nginx 默认页）** = 文件没放对位置，PHP 根本没执行到。
先确认网站根目录在哪，再确认有没有多套一层 `zero-dep-likes/`。

> ⚠️ 第一条返回 400 只能说明 **PHP 跑起来了**，说明不了数据库通不通。
> 数据库要等第 3 步才验。

---

## 第 1 步：建数据库

虚拟主机控制面板 → MySQL 数据库 → 新建数据库 + 新建用户 → 把用户绑定到这个库。

记下四个值：**地址**（通常 `localhost`）、**库名**、**用户名**、**密码**。

> 库名经常带主机商前缀，比如 `sql_abc123_likes`，**一个字都不能错**。

---

## 第 2 步：建表

两种方式，**推荐用 A**。

### 方式 A：跑一次 install.php（推荐）

1. 把 `install.php` 上传到 **`api/` 目录的上一级**（和 `api/` 同级）
2. 浏览器打开 `https://你的域名/安装路径/install.php`
3. 页面会先显示连接情况（库名、主机、MySQL 版本），确认库名对不对
4. 点 **「开始建表」**
5. 看到两张表都是 **✓ 已存在**、字段结构校验通过
6. 点 **「删除 install.php」** 把它自己删掉

**这脚本为什么安全**：

- 只执行 `CREATE TABLE IF NOT EXISTS`，**幂等**——表已存在就跳过，绝不 `DROP`、绝不 `ALTER`、绝不碰已有数据
- **不会自动执行**：打开页面只是看状态，必须手动点按钮才动手
- 建完会自动校验字段结构和唯一索引，对不上会当场报出来
- 支持一键自删

> 用完仍然必须删掉——它会暴露数据库名、表结构等服务器信息。

### 方式 B：phpMyAdmin 导入

> ⚠️ **必须先点中数据库再导入。** phpMyAdmin 是「先选库、再导入」，左侧没选中的话，
> 轻则报 `#1046 No database selected`，重则**悄悄导进你上次打开的那个库**——
> 然后你盯着目标库纳闷：明明"导入成功"了，表却不见了。
>
> **导入的库必须和 `config.php` 里 `$DB_NAME` 填的完全一致。**

1. 打开 phpMyAdmin
2. **在左侧栏点一下你的数据库名**，让它高亮选中
3. 顶部切到 **导入**
4. 「选择文件」→ 挑 `sql/schema.sql`
5. 拉到最下点 **执行**
6. 看到「导入已成功执行」之类的提示

导入报错对照：

| 报错 | 意思 | 怎么办 |
|---|---|---|
| `#1046 No database selected` | 没在左侧点中数据库 | 按上面第 2 步选中再导 |
| `#1142 CREATE command denied` | 数据库用户没有建表权限 | 面板里给该用户加 `CREATE` 权限 |
| `#1044 Access denied for database` | 登录的用户没被授权操作这个库 | 面板里把用户绑定到这个库 |
| 导完看不见表 | **导到别的库里去了** | 左侧逐个库点开找，或搜 `article_likes` |

> `schema.sql` 用的是 `CREATE TABLE IF NOT EXISTS`，**重复执行不会破坏已有数据**，
> 不确定导没导成功，再导一次即可。

### 建完应该看到

数据库里出现两张表：

```
你的数据库
├── article_likes      ← 每篇文章的总点赞数
└── like_records       ← 点赞明细（防重复）
```

`like_records` 上必须有一个叫 `uniq_article_visitor` 的**唯一索引**——
防重复点赞全靠它。用方式 A 的话脚本会替你确认；用方式 B 的话在 phpMyAdmin 里点开
`like_records` → 「结构」，看索引列表里有没有它。

---

## 第 3 步：改配置（最容易出错的一步）

> **推荐：把配置写进 `api/config.local.php`，别改 `config.php`。**
> 因为升级时整包解压会把 `config.php` 打回模板状态、丢掉你的密码。
> 做法：把 `api/config.local.example.php` 复制成 `api/config.local.php`，填好上传。
> 一次配好，以后升级不用再管。

用文本编辑器打开 `api/config.php`（或上面说的 `config.local.php`），改**三处**：

```php
// ① 数据库三项 —— 用第 2 步记下的值
$DB_HOST = 'localhost';                   // 多数虚拟主机就是 localhost
$DB_NAME = 'your_database_name';          // 库名，常带主机商前缀
$DB_USER = 'your_database_user';
$DB_PASS = 'your_database_password';

// ② 加盐 —— 随便敲一串乱码，32 位以上
$SALT = 'CHANGE_ME_PLEASE_REPLACE_WITH_A_LONG_RANDOM_STRING';

// ③ CORS 白名单 —— 把你「会在浏览器里打开的域名」全列上
$ALLOWED_ORIGINS = array(
    'https://blog.example.com',   // 正式博客域名
    'https://www.example.com',
    'https://staging.example.com',         // 虚拟主机自带域名（直接用 IP/主机域名访问时也要加）
);
```

> **白名单少写一个域名，会出现很迷惑的症状**：
> 查询点赞数正常（GET 同源不发 `Origin`），但一点赞就 400（POST 同源会发 `Origin`）。
> 详细解释见 README「查询正常，但一点赞就返回 400」。
> 记住：`https://` 和 `http://` 不同、`www.` 和裸域名不同、端口不同也不同。

改完**重新上传** `api/config.php`。

> **`$SALT` 上线后不要再改。** 改一次，所有老访客都会被当成新访客，能重复点赞。
> 现在还在测试阶段，随便改无所谓；正式开放前定下来就行。

> **白名单里 `https://` 和 `http://` 是不同的，`www.` 和裸域名也是不同的。**
> 如果你两个都用来访问，就写两行。

**验证**：浏览器打开

```
https://你的域名/api/likes.php?article_id=test-001
```

应该看到：

```json
{"code":0,"msg":"ok","data":{"article_id":"test-001","like_count":0,"liked":false}}
```

**这一步才是真正的分水岭。** 这条请求会去数据库里翻表——

| 你看到的 | 说明什么 | 怎么办 |
|---|---|---|
| `code:0` + `like_count:0` | ✅ 数据库通、表建好了、权限也对 | 继续第 4 步 |
| `code:500` | 数据库这一步没过 | **把 `$DEBUG` 改成 `true`**，重传 config.php，再请求一次，`data.error` 里会带 MySQL 的原话。对照 README 的「接口返回 500」错误码表定位；**查完立刻改回 `false`** |
| `code:400` | 参数没传进去，URL 被截断了 | 检查 `?` 和 `article_id=` 有没有写全 |

`$DEBUG=true` 时你会看到类似这样的响应，MySQL 把原因说得非常清楚：

```json
{"code":500,"msg":"server error","data":{
  "error":"RuntimeException: database connection failed: SQLSTATE[HY000] [1045] Access denied for user 'abc'@'localhost' (using password: YES)"}}
```

| MySQL 原话 | 意思 |
|---|---|
| `[1045] Access denied` | 用户名或密码错 |
| `[1044] Access denied for database` | 用户没绑定到这个库 |
| `[1049] Unknown database` | 库名写错（常带前缀） |
| `[2002] Connection refused` | 主机地址写错 |
| `Base table or view not found` | `schema.sql` 没导入（或导到了别的库） |

---

## 第 4 步：跑自检页

上传 `check.html` 到**网站根目录**（和 `api/` 同级），浏览打开：

```
https://你的域名/check.html
```

点「开始检测」，等十几秒。

**应该看到**：顶部绿框写着「自动检测全部通过：12 / 13 项」，下面是逐条明细。

- 有一项显示「跳过」是**正常的**——那是 CORS，同源请求测不了，原因页面上写了
- 有红色「不通过」的，表格里「实际情况」那一列会直接告诉你期望值和实际值差在哪
- 点「复制结果」可以把结果复制下来发我

**验完记得把 `check.html` 从服务器删掉。**

---

## 第 5 步：插进博客

在文章正文结束、评论框之前，插入：

```html
<div class="like-widget" data-article-id="这篇文章的slug">
  <button class="like-button" type="button" aria-pressed="false" aria-label="点赞这篇文章">
    <svg class="like-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.58 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-1z"/>
    </svg>
    <span class="like-label">点赞</span>
    <span class="like-count">--</span>
  </button>
</div>
```

然后在页面底部 `</body>` 之前引入（**全站引一次就够**）：

```html
<link rel="stylesheet" href="/assets/likes/likes.css">
<script src="/assets/likes/likes.js"></script>
```

### `data-article-id` 填什么

填 URL 里的 slug 最省事：

| 文章 URL | 填 |
|---|---|
| `/posts/hello-world.html` | `hello-world` |
| `/2026/09/11/why-slug.html` | `why-slug` |

**只能有**字母、数字、下划线、中划线。**中文、空格、点号都不行**，会被接口拒掉。

> 主键就是这个 ID，所以**改 slug 会丢掉那篇文章的点赞数**。发布后别再改。

> 博客在子目录（比如 `/blog/`）的话，脚本路径和 API 地址都要带子目录：
> `<script src="/blog/assets/likes/likes.js" data-api="/blog/api/likes.php"></script>`

---

## 第 6 步：端到端验收

打开一篇真实文章，按 F12，逐条确认：

- [ ] Console 里**没有红色报错**
- [ ] Network 里有一次 `/api/likes.php` 请求，状态 200
- [ ] 点赞数正确显示（先闪一下 `--`，然后变成数字）
- [ ] 点按钮：数字立刻 +1，变珊瑚橙、文字变「已赞」、图标从描边变实心
- [ ] 点完之后按钮**变灰不能点**
- [ ] **刷新页面**：数字还在，按钮还是「已赞」
- [ ] 列表页有多篇文章时，只发**一次**请求（URL 里是 `article_ids=...` 复数）
- [ ] 断网再点：出现「点赞失败，请稍后再试」，数字**弹回原值**
- [ ] 切深色主题，按钮跟着变，橙色仍然清楚
- [ ] 手机上按钮够大好点

---

## 第 7 步：收尾

**三件事，别忘：**

1. **删掉 `check.html`**（它会把接口信息暴露给任何访问者）
2. **清掉测试数据**（phpMyAdmin 里执行）：
   ```sql
   DELETE FROM like_records  WHERE article_id LIKE 'check-%';
   DELETE FROM like_records  WHERE article_id LIKE 'itest-%';
   DELETE FROM article_likes WHERE article_id LIKE 'check-%';
   DELETE FROM article_likes WHERE article_id LIKE 'itest-%';
   DELETE FROM like_records  WHERE article_id LIKE 'test-%';
   DELETE FROM article_likes WHERE article_id LIKE 'test-%';
   ```
3. **备份**：导出 `article_likes` 和 `like_records` 两张表。
   只要 `like_records` 在，计数丢了也能重建（SQL 见 README 第八节）。

---

## 还有两项要命令行才能验

`tests/api-test.md` 里的**用例 9（并发）**，自检页覆盖不了——它只能串行发请求。
并发这条是验收标准里最容易翻车的一项，建议抽十分钟跑一下：

```bash
API="https://你的域名/api/likes.php"

for i in $(seq 1 20); do
  curl -s -X POST "$API" -H "Content-Type: application/json" \
    -d "{\"article_id\":\"itest-concurrent\",\"visitor_id\":\"concurrent-visitor-$i-xxxx\"}" > /dev/null &
done
wait

curl -s "$API?article_id=itest-concurrent"; echo
```

**期望 `like_count` 正好是 20**，不是 19 也不是 21。

---

## 卡住了怎么办

把这两样发agent试试，它们应该能看出问题：

1. 自检页点「复制结果」后的文本
2. 浏览器 F12 → Console 里以 `[likes]` 开头的黄色警告
   （PHP 侧的报错在虚拟主机面板的「错误日志」里）
