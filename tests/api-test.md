# API 测试用例

配套验收标准，逐条可执行。建议**按顺序**做，前面的用例会给后面的留数据。

命令以 bash / Git Bash 为主。Windows PowerShell 用户在旁边给了替代写法。

---

## 0. 准备

把下面两个变量改成你自己的：

```bash
# API 地址（末尾不要带斜杠）
API="https://blog.example.com/api/likes.php"

# 随便造一个访客 ID，长度必须在 8–128 之间
V1="test-visitor-aaaaaaaa"
V2="test-visitor-bbbbbbbb"
```

PowerShell 写法：

```powershell
$API = "https://blog.example.com/api/likes.php"
$V1  = "test-visitor-aaaaaaaa"
```

> 每次跑完一轮，建议换一组全新的 `article_id`，避免被历史数据干扰。
> 或者按文末「清理测试数据」把测试文章删掉。

---

## 1. 新文章无记录时返回 0

对应验收标准 1。

```bash
curl -s "$API?article_id=itest-fresh-001" | tee /tmp/r1.json; echo
```

期望：

```json
{"code":0,"msg":"ok","data":{"article_id":"itest-fresh-001","like_count":0,"liked":false}}
```

- [ ] `code` 是 `0`
- [ ] `like_count` 是 `0`（不是 `null`，不是报错）

---

## 2. 首次点赞 +1

对应验收标准 2。

```bash
curl -s -X POST "$API" \
  -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-fresh-001\",\"visitor_id\":\"$V1\"}"; echo
```

期望：

```json
{"code":0,"msg":"ok","data":{"article_id":"itest-fresh-001","like_count":1,"liked":true}}
```

- [ ] `code` 是 `0`
- [ ] `like_count` 变成 `1`
- [ ] HTTP 状态码是 200（加 `-i` 可以看到）

再查一次，确认**持久化**了：

```bash
curl -s "$API?article_id=itest-fresh-001&visitor_id=$V1"; echo
```

- [ ] `like_count` 仍是 `1`
- [ ] `liked` 是 `true`

---

## 3. 同一 visitor 重复点赞不增加

对应验收标准 3。

```bash
curl -s -X POST "$API" \
  -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-fresh-001\",\"visitor_id\":\"$V1\"}"; echo
```

期望：

```json
{"code":1001,"msg":"already liked","data":{"article_id":"itest-fresh-001","like_count":1,"liked":true}}
```

- [ ] `code` 是 `1001`
- [ ] `like_count` **仍然是 1**，没有变成 2
- [ ] HTTP 状态码是 200

---

## 4. 不同 visitor 点赞，计数继续增加

对应验收标准 4。

```bash
curl -s -X POST "$API" \
  -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-fresh-001\",\"visitor_id\":\"$V2\"}"; echo
```

期望：

- [ ] `code` 是 `0`
- [ ] `like_count` 变成 `2`

---

## 5. 不同文章互不影响

对应验收标准 5。

```bash
curl -s -X POST "$API" \
  -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-fresh-002\",\"visitor_id\":\"$V1\"}"; echo

curl -s "$API?article_id=itest-fresh-001&visitor_id=$V1"; echo
```

期望：

- [ ] 第二篇 `itest-fresh-002` 的计数是 `1`（同一个访客在新文章上可以重新点赞）
- [ ] 第一篇 `itest-fresh-001` 的计数**还是 2**，没有被带上去

---

## 6. 取消点赞

对应验收标准 11。

### 6.1 点赞后取消，计数应当减回去

```bash
# 先保证是赞着的
curl -s -X POST "$API" -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-unlike\",\"visitor_id\":\"$V1\"}"; echo

# 再取消
curl -s -X POST "$API" -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-unlike\",\"visitor_id\":\"$V1\",\"action\":\"unlike\"}"; echo
```

期望取消时返回：

```json
{"code":0,"msg":"ok","data":{"article_id":"itest-unlike","like_count":0,"liked":false,"removed":true}}
```

- [ ] `code` 是 `0`
- [ ] `like_count` **减回了 0**
- [ ] `liked` 是 `false`
- [ ] `removed` 是 `true`（说明真的删掉了记录）

### 6.2 取消之后还能重新点赞

```bash
curl -s -X POST "$API" -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-unlike\",\"visitor_id\":\"$V1\"}" | tee /tmp/u3.json; echo
```

- [ ] `code` 是 `0`（**不是 1001**）
- [ ] `like_count` 回到 `1`

> 如果这里返回 `1001`，说明取消**没有真的删掉明细**，只是把状态标记了一下 ——
> 那样会导致"取消了却再也赞不回来"。检查 `perform_unlike()` 里的 DELETE 语句。

### 6.3 没赞过就取消，应当是幂等的

用一个从没点过赞的访客：

```bash
V3="unlike-never-liked-visitor"
curl -s -X POST "$API" -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-unlike\",\"visitor_id\":\"$V3\",\"action\":\"unlike\"}"; echo
```

期望：

- [ ] `code` 是 `0`（**不是错误**）
- [ ] `removed` 是 `false`（本来就没赞过）
- [ ] `like_count` 不变

### 6.4 DELETE 方法等价

```bash
curl -s -X DELETE "$API" -H "Content-Type: application/json" \
  -d "{\"article_id\":\"itest-unlike\",\"visitor_id\":\"$V1\"}"; echo
```

- [ ] 行为与 `action: "unlike"` 完全一致

### 6.5 计数与明细依然对得上

```sql
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
WHERE l.article_id = 'itest-unlike'
GROUP BY l.article_id, l.like_count;
```

- [ ] 两个数字**相等**（取消点赞不该把计数和明细搞错位）

---

## 7. 批量接口

```bash
curl -s "$API?article_ids=itest-fresh-001,itest-fresh-002,itest-fresh-003&visitor_id=$V1"; echo
```

期望：

```json
{"code":0,"msg":"ok","data":{"items":[
  {"article_id":"itest-fresh-001","like_count":2,"liked":true},
  {"article_id":"itest-fresh-002","like_count":1,"liked":true},
  {"article_id":"itest-fresh-003","like_count":0,"liked":false}
]}}
```

- [ ] 三篇都返回了，顺序与请求一致
- [ ] 不存在的 `itest-fresh-003` 返回 `like_count: 0`，而不是被漏掉或报错

---

## 8. 非法参数返回 400

对应验收标准 6。

```bash
# 含空格
curl -s -i "$API?article_id=bad%20id" | head -1
# 含斜杠（疑似路径穿越）
curl -s "$API?article_id=../etc/passwd"; echo
# 超过 191 字符
curl -s "$API?article_id=$(printf 'a%.0s' {1..200})"; echo
# 完全不传
curl -s "$API"; echo
# visitor_id 太短（小于 8 位）
curl -s "$API?article_id=itest-fresh-001&visitor_id=abc"; echo
```

期望：

- [ ] 全部返回 `"code":400`
- [ ] 第一条的 HTTP 状态码是 `400`
- [ ] 返回体里**不包含任何数据库、文件路径信息**

---

## 9. CORS 只允许配置的域名

对应验收标准 7。

### 8.1 白名单内的域名 — 应放行

```bash
curl -s -i -H "Origin: https://blog.example.com" \
  "$API?article_id=itest-fresh-001" | grep -i "access-control-allow-origin"
```

- [ ] 输出 `Access-Control-Allow-Origin: https://blog.example.com`

### 8.2 白名单外的域名 — 应拒绝

```bash
curl -s -i -H "Origin: https://evil.example.com" \
  "$API?article_id=itest-fresh-001"
```

- [ ] 没有 `Access-Control-Allow-Origin` 头
- [ ] 返回 `"code":400`

### 8.3 OPTIONS 预检

```bash
curl -s -i -X OPTIONS -H "Origin: https://blog.example.com" \
  -H "Access-Control-Request-Method: POST" "$API" | head -10
```

- [ ] HTTP 状态码 `204`
- [ ] 有 `Access-Control-Allow-Methods`
- [ ] 有 `Access-Control-Allow-Headers: Content-Type, Accept`

---

## 10. 并发点赞不丢数据

对应验收标准 8。这是最关键的一条。

### 9.1 20 个不同访客同时点赞同一篇 —— 期望正好 +20

```bash
for i in $(seq 1 20); do
  curl -s -X POST "$API" \
    -H "Content-Type: application/json" \
    -d "{\"article_id\":\"itest-concurrent\",\"visitor_id\":\"concurrent-visitor-$i-xxxx\"}" \
    > /dev/null &
done
wait

curl -s "$API?article_id=itest-concurrent"; echo
```

- [ ] `like_count` **正好是 20**
- [ ] 不是 19、不是 21

> 如果少了：多半是某个请求撞上死锁被回滚了。代码里已经做了 3 次重试，
> 还出现说明要调大重试次数或缩短事务。
> 如果多了：检查 `like_records` 的唯一索引是不是真的建上了。

### 9.2 同一个访客 20 个请求同时打 —— 期望只 +1

```bash
curl -s -X POST "$API" -H "Content-Type: application/json" \
  -d '{"article_id":"itest-concurrent-2","visitor_id":"same-visitor-cccccccc"}' > /dev/null

for i in $(seq 1 20); do
  curl -s -X POST "$API" \
    -H "Content-Type: application/json" \
    -d '{"article_id":"itest-concurrent-2","visitor_id":"same-visitor-cccccccc"}' \
    > /dev/null &
done
wait

curl -s "$API?article_id=itest-concurrent-2"; echo
```

- [ ] `like_count` **正好是 1**

> 这条验证的是"唯一索引 + INSERT IGNORE"确实兜住了并发重复提交。

### 9.3 计数与明细校对

在数据库里执行（phpMyAdmin 的 SQL 窗口）：

```sql
SELECT l.article_id, l.like_count, COUNT(r.id) AS real_count
FROM article_likes l
LEFT JOIN like_records r ON r.article_id = l.article_id
GROUP BY l.article_id, l.like_count
HAVING l.like_count <> COUNT(r.id);
```

- [ ] **查不出任何行**（计数与明细完全一致）

---

## 11. 限流

```bash
# 连续快速点 40 篇不同文章（同一访客、同一 IP）
for i in $(seq 1 40); do
  curl -s -X POST "$API" \
    -H "Content-Type: application/json" \
    -d "{\"article_id\":\"itest-rl-$i\",\"visitor_id\":\"ratelimit-visitor-x$x\"}" \
    | grep -o '"code":[0-9]*'
done | sort | uniq -c
```

期望（默认配置 30 次/分钟）：

- [ ] 前 30 次是 `"code":0`
- [ ] 之后出现 `"code":429`
- [ ] 返回 429 时 HTTP 状态码也是 429

不想要限流的话，把 `config.php` 里 `$RATE_LIMIT_ENABLED` 改成 `false`。

---

## 12. 响应头检查

对应验收标准 10。

```bash
curl -s -i "$API?article_id=itest-fresh-001" | head -12
```

- [ ] `Content-Type: application/json; charset=utf-8`
- [ ] `Cache-Control` 里带 `no-store`
- [ ] `X-Content-Type-Options: nosniff`

---

## 13. 前端手动检查清单

对应验收标准 9。打开一篇真实文章页面，逐项确认：

- [ ] 打开控制台 Console，**没有任何红色报错**
- [ ] Network 面板能看到一次 `/api/likes.php` 请求，状态 200
- [ ] 点赞数正确显示（加载瞬间是 `--`，随后变成数字）
- [ ] 点击按钮：数字立刻 +1，按钮变珊瑚橙色、文字变「已赞」、图标变实心
- [ ] 点赞后按钮**不能再点**（disabled）
- [ ] **刷新页面**：点赞数保持，按钮仍是「已赞」状态
- [ ] 列表页有多篇文章时，只发出**一次批量请求**（`article_ids=...`）
- [ ] 断网后点击：出现「点赞失败，请稍后再试」，数字**回滚**到原值，按钮恢复可点
- [ ] 切换博客到深色主题，按钮颜色跟随，已赞的橙色仍然清晰
- [ ] 手机上按钮点击热区够大（不小于 40px 高）
- [ ] 在系统里开启「减少动态效果」，点赞不再有缩放动画

### 调试小技巧

在浏览器控制台里直接查有没有记录：

```js
localStorage.getItem('visitor_id')   // 当前访客 ID
```

想模拟"换一个人来点赞"：

```js
localStorage.removeItem('visitor_id'); location.reload();
```

---

## 14. 清理测试数据

测完把 `itest-` 开头的测试文章删掉：

```sql
DELETE FROM like_records  WHERE article_id LIKE 'itest-%';
DELETE FROM article_likes WHERE article_id LIKE 'itest-%';
```

---

## 验收标准对照表

| # | 验收标准 | 对应用例 | 结果 |
|---|---|---|---|
| 1 | 新文章返回 `like_count: 0` | 用例 1 | ☐ |
| 2 | 首次点赞 +1，刷新后保持 | 用例 2 | ☐ |
| 3 | 重复点赞不增加，返回 1001 | 用例 3 | ☐ |
| 4 | 不同访客继续增加 | 用例 4 | ☐ |
| 5 | 不同文章互不影响 | 用例 5 | ☐ |
| 6 | 非法 `article_id` 返回 400 | 用例 7 | ☐ |
| 7 | CORS 只允许配置域名 | 用例 9 | ☐ |
| 8 | 并发点赞不丢数据 | 用例 10 | ☐ |
| 9 | 前端无 JS 报错，按钮状态正确 | 用例 13 | ☐ |
| 10 | 全部返回 JSON，HTTP 状态码合理 | 用例 12 | ☐ |
| 11 | **取消点赞**：计数减回、可重新点赞、幂等 | 用例 6 | ☐ |
