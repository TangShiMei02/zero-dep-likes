/* ============================================================
 * 博客文章点赞组件 — 交互脚本
 * 版本: 见项目 CHANGELOG.md
 *
 * 纯原生 JS，零依赖，不需要构建工具。
 *
 * 用法（路径按你的实际部署改）：
 *   1) 页面里放一个或多个 <div class="like-widget"></div>
 *      空组件即可，脚本会自动补出按钮结构
 *   2) 页面底部引入脚本：
 *      <script src="/你的项目目录/assets/likes/likes.js"></script>
 *   3) 想显式指定 API 位置就加 data-api：
 *      <script src="/你的项目目录/assets/likes/likes.js"
 *              data-api="/你的项目目录/api/likes.php"></script>
 *
 * 关于路径：本项目支持部署在**任意子目录**下（例如 /zero-dep-likes/）。
 * 即使漏写 data-api，脚本也会从自己的地址反推 API 位置 ——
 * 因为 api/ 和 assets/ 在项目里是固定层级的。
 *
 * 做的事：
 *   - 无 visitor_id 时生成一个，存进 localStorage
 *   - 自动找出页面上所有 .like-widget，拉取点赞数与"我赞过没"
 *   - 一次请求批量取（多篇）或单篇取，批量失败自动降级为逐个请求
 *   - 点击时禁用按钮防连点，成功后就地更新，失败回滚并提示
 * ============================================================ */

(function () {
  'use strict';

  var DEFAULT_API = '/api/likes.php';

  // 版本号。改脚本时顺手改一下 —— 它会打到控制台，是确认"服务器上跑的是哪一版"的唯一依据。
  var VERSION = '1.8.0';

  var VISITOR_KEY = 'visitor_id';
  var REQUEST_TIMEOUT = 10000;
  var MESSAGE_DURATION = 4000;

  /**
   * 一眼就能看出是"抄了示例没改"的 ID。
   *
   * 为什么要做这个检查：点赞数完全由 data-article-id 决定。
   * 如果多篇文章填了同一个值，它们会静默地共用同一个计数 —— 不报错、不提示，
   * 只是数字莫名其妙地一起涨。这是这套设计唯一会"安静出错"的地方。
   * 所以宁可误报，也不能让它悄悄发生。
   */
  var PLACEHOLDER_IDS = [
    'article_id', 'article-id', 'articleid', 'post_id', 'post-id',
    'post-1', 'post-2', 'test', 'test-001', 'demo', 'example',
    'your-article-id', 'xxx', 'todo', 'id', 'slug'
  ];

  function isPlaceholderId(id) {
    return PLACEHOLDER_IDS.indexOf(String(id).toLowerCase()) !== -1;
  }

  /* ---------------- 配置解析 ---------------- */

  /**
   * 从脚本自身的地址反推 API 地址。
   *
   *   /zero-dep-likes/assets/likes/likes.js   →   /zero-dep-likes/api/likes.php
   *   /assets/likes/likes.js              →   /api/likes.php
   *
   * 为什么需要这个兜底：API 和前端资源在同一个项目目录下，
   * 层级关系是固定的（assets/likes/ 与 api/ 平级），
   * 所以不知道 API 在哪时，从脚本自己的位置往回推一定推得出来。
   * 这样即使忘了写 data-api，也不会退化成"/api/likes.php"那种写死根目录的猜测。
   */
  function apiUrlFromScriptSrc(src) {
    if (typeof src !== 'string' || src === '') {
      return null;
    }

    var marker = '/assets/';
    var idx = src.indexOf(marker);
    if (idx === -1) {
      return null;
    }

    return src.slice(0, idx) + '/api/likes.php';
  }

  /**
   * 决定用哪个 API 地址。优先级从高到低：
   *
   *   1. <script> 标签上的 data-api 属性     ← 显式指定，最优先
   *   2. window.LIKES_API 全局变量            ← 不方便改 script 标签时用
   *   3. 从脚本自身地址反推                    ← 自动，覆盖绝大多数部署
   *   4. /api/likes.php                       ← 最后的兜底
   */
  function resolveApiUrl() {
    var script = document.currentScript;

    if (script) {
      var fromAttr = script.getAttribute('data-api');
      if (fromAttr) {
        return fromAttr;
      }
    }

    if (typeof window.LIKES_API === 'string' && window.LIKES_API) {
      return window.LIKES_API;
    }

    if (script) {
      var derived = apiUrlFromScriptSrc(script.src);
      if (derived) {
        console.info('[likes] 没写 data-api，已按脚本位置推断 API 地址：' + derived);
        return derived;
      }
    }

    // 走到这说明连脚本地址都拿不到（比如脚本是 defer / module 加载的，
    // currentScript 为 null）。这是最后的兜底，它假定项目在网站根目录下。
    console.warn(
      '[likes] 无法从脚本位置推断 API 地址，回退到默认值 ' + DEFAULT_API + '。\n'
      + '这个默认值假定项目部署在网站根目录。如果你的项目在子目录里（如 /zero-dep-likes/），\n'
      + '请在 <script> 标签上显式写上 data-api，否则请求会 404。'
    );

    return DEFAULT_API;
  }

  var API_URL = resolveApiUrl();

  /**
   * 在控制台报一次版本号和实际使用的 API 地址。
   *
   * 这一行是给自己救火用的：部署后"页面上没反应"这类问题，
   * 第一个要确认的永远是「服务器上跑的是哪一版」和「请求打到哪去了」。
   * 一行 info 就能回答，省掉一整轮来回。
   *
   * 判断方法：
   *   控制台有这一行 → 新版脚本确实加载了
   *   控制台没有这一行 → 服务器上的文件是旧的，或者浏览器缓存了旧文件（Ctrl+F5）
   */
  console.info('[likes] likes.js v' + VERSION + ' 已加载 · API: ' + API_URL);

  /* ---------------- 访客标识 ---------------- */

  /**
   * 取本地 visitor_id，没有就生成一个。
   * 这只是一个随机串，不含任何个人信息；后端会再哈希一次才落库。
   * 注意：用户清空浏览器数据后会拿到新 ID，理论上能再赞一次——
   *       匿名点赞的可接受代价，要严格就得接登录体系。
   */
  function getVisitorId() {
    var id = null;

    try {
      id = window.localStorage.getItem(VISITOR_KEY);
    } catch (e) {
      // 隐私模式等场景下 localStorage 可能不可用，忽略即可
    }

    if (!id) {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        id = window.crypto.randomUUID();
      } else {
        id = 'v-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
      }
      try {
        window.localStorage.setItem(VISITOR_KEY, id);
      } catch (e) {
        // 存不进去也没关系，本次会话内仍然能用
      }
    }

    return id;
  }

  /* ---------------- 文章标识 ---------------- */

  /**
   * 短哈希，用来在路径过长或归一化后啥都不剩时兜底，保证不同链接不撞 ID。
   * FNV-1a，同步计算，不依赖 crypto.subtle（那个是异步的，这里用不上）。
   */
  function hashShort(str) {
    var h = 0x811c9dc5;
    for (var i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    return h.toString(36);
  }

  /**
   * 没写 data-article-id 时，按当前页面链接自动派生一个文章标识。
   *
   * 好处：模板里只写一个空的 <div class="like-widget"></div>，
   *       每篇文章自动得到自己的 ID，**不可能忘了改**。
   *
   * 代价（用之前想清楚）：
   *   - 改了 URL（换 slug、加/去尾斜杠、http→https）会被当成新文章，点赞数从 0 开始
   *   - **只能用在文章详情页**。列表页上多个组件派生出的 ID 是同一个（因为 URL 是同一个），
   *     它们会共用一个计数 —— 下面的重复检测会把这种情况报出来
   *
   * 归一化规则：去掉 index/扩展名 → 非字母数字的字符一律变 '-' → 折叠连续 '-' → 去掉首尾 '-'
   * 例：/posts/hello-world.html  →  posts-hello-world
   *     /2026/09/11/why-slug/    →  2026-09-11-why-slug
   */
  function deriveIdFromUrl() {
    // 先去掉末尾斜杠，让 /x 和 /x/ 落到同一个 ID 上
    var raw = (window.location.pathname || '/').replace(/\/+$/, '') || '/';

    var decoded = raw;
    try {
      decoded = decodeURIComponent(raw);
    } catch (e) {
      // 非法百分号编码，保留原样
    }

    var id = decoded
      .replace(/\/index\.(html?|php|aspx?|jsp)$/i, '/')
      .replace(/\.(html?|php|aspx?|jsp)$/i, '')
      .replace(/[^A-Za-z0-9_-]+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-+|-+$/g, '');

    // 中文/全角路径归一化后可能什么都不剩。
    // 这时候退回用原始路径求哈希 —— 否则所有中文链接都会变成同一个 ID，全部撞车。
    if (id === '') {
      id = 'p-' + hashShort(raw);
    }

    // 接口规定 article_id 最长 191，留点余量
    if (id.length > 180) {
      id = id.slice(0, 170) + '-' + hashShort(decoded);
    }

    return id;
  }

  /* ---------------- 网络 ---------------- */

  function request(url, options) {
    var opts = options || {};
    var controller = null;

    if (typeof window.AbortController === 'function') {
      controller = new AbortController();
      opts.signal = controller.signal;
      window.setTimeout(function () {
        controller.abort();
      }, REQUEST_TIMEOUT);
    }

    opts.cache = 'no-store';
    opts.headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});

    return window.fetch(url, opts);
  }

  /* ---------------- 默认组件结构 ---------------- */

  /**
   * 空组件时自动补上的按钮结构。
   *
   * 为什么要有这个：文档里承诺「模板里只要写一行
   * <div class="like-widget"></div>」，那它就真的得能用。
   * 之前这里要求必须手写完整的按钮 + 图标 + 计数三层结构，
   * 和文档对不上，导致空组件被直接跳过、页面上什么都不显示。
   */
  var DEFAULT_BUTTON_HTML =
    '<svg class="like-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
    + '<path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06'
    + 'L14.17 1 7.58 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22'
    + 'l3.02-7.05c.09-.23.14-.47.14-.73v-1z"/></svg>'
    + '<span class="like-label">点赞</span>'
    + '<span class="like-count">--</span>';

  /**
   * 给空组件补上默认按钮。返回创建出来的按钮。
   * 已经写了完整结构的组件不会被碰。
   */
  function buildDefaultButton(node) {
    var button = document.createElement('button');
    button.className = 'like-button';
    button.type = 'button';
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', '点赞这篇文章');
    button.innerHTML = DEFAULT_BUTTON_HTML;
    node.appendChild(button);
    return button;
  }

  /* ---------------- 组件状态 ---------------- */

  function applyState(item, count, liked) {
    var n = parseInt(count, 10);
    item.count = isNaN(n) ? 0 : n;
    item.liked = !!liked;

    item.countEl.textContent = String(item.count);
    item.button.setAttribute('aria-pressed', item.liked ? 'true' : 'false');

    // 已赞时按钮**仍然可点** —— 再点一次是取消点赞。
    // 只在请求进行中临时禁用（见 onLike），避免连点。
    item.button.disabled = false;
    item.button.removeAttribute('data-pending');

    item.button.setAttribute('aria-label', item.liked ? '取消点赞' : '点赞这篇文章');
    item.button.setAttribute('title', item.liked ? '点击取消点赞' : '点击点赞');

    item.node.setAttribute('data-state', (item.placeholder || item.conflict) ? 'warn' : 'ready');
    item.node.setAttribute('data-liked', item.liked ? 'true' : 'false');

    var label = item.node.querySelector('.like-label');
    if (label) {
      label.textContent = item.liked ? '已赞' : '点赞';
    }
  }

  function setLoading(item) {
    item.node.setAttribute('data-state', 'loading');
    item.countEl.textContent = '--';
    item.button.disabled = true;
  }

  function setLoadFailed(item, showMessage) {
    // 加载失败不等于不能点：按钮保持可用，用户照样可以尝试点赞
    item.node.setAttribute('data-state', 'error');
    if (item.count === null) {
      item.countEl.textContent = '--';
    }
    item.button.disabled = item.liked;
    if (showMessage) {
      showMessageOn(item.node, '点赞数暂时读不到');
    }
  }

  function pulse(button) {
    button.classList.remove('is-just-liked');
    // 强制重排，保证连续点赞也能重新触发动画
    void button.offsetWidth;
    button.classList.add('is-just-liked');
    window.setTimeout(function () {
      button.classList.remove('is-just-liked');
    }, 300);
  }

  /** 拿到（或创建）这个组件里的提示文字节点 */
  function ensureMessageEl(node) {
    var msgEl = node.querySelector('.like-msg');
    if (!msgEl) {
      msgEl = document.createElement('span');
      msgEl.className = 'like-msg';
      msgEl.setAttribute('role', 'status');
      msgEl.setAttribute('aria-live', 'polite');
      node.appendChild(msgEl);
    }
    return msgEl;
  }

  /** 短暂提示（点赞失败之类），几秒后自动消失 */
  function showMessageOn(node, text) {
    var msgEl = ensureMessageEl(node);

    msgEl.textContent = text;

    if (node._likesMessageTimer) {
      window.clearTimeout(node._likesMessageTimer);
    }
    node._likesMessageTimer = window.setTimeout(function () {
      msgEl.textContent = '';
    }, MESSAGE_DURATION);
  }

  /**
   * 常驻告警（配置错误）。
   * 不自动消失 —— 配置错误不会自己好，留着才能被看见。
   */
  function showWarningOn(node, text) {
    if (node._likesMessageTimer) {
      window.clearTimeout(node._likesMessageTimer);
      node._likesMessageTimer = null;
    }
    ensureMessageEl(node).textContent = text;
  }

  /* ---------------- 数据加载 ---------------- */

  function loadOne(item, visitorId) {
    var url = API_URL
      + '?article_id=' + encodeURIComponent(item.id)
      + '&visitor_id=' + encodeURIComponent(visitorId);

    return request(url, { method: 'GET' })
      .then(function (res) {
        return res.json();
      })
      .then(function (body) {
        if (body && body.code === 0 && body.data) {
          applyState(item, body.data.like_count, body.data.liked);
        } else {
          throw new Error((body && body.msg) || 'unexpected response');
        }
      })
      .catch(function (err) {
        console.warn('[likes] 加载失败:', item.id, err);
        // 只在单篇文章页（页面上只有一个组件）提示，列表页出错时静默，
        // 否则一页十篇就会弹十句"读不到"，很吵
        setLoadFailed(item, document.querySelectorAll('.like-widget').length === 1);
      });
  }

  function loadMany(items, visitorId) {
    var ids = items.map(function (it) {
      return it.id;
    });
    var url = API_URL
      + '?article_ids=' + encodeURIComponent(ids.join(','))
      + '&visitor_id=' + encodeURIComponent(visitorId);

    return request(url, { method: 'GET' })
      .then(function (res) {
        return res.json();
      })
      .then(function (body) {
        if (!body || body.code !== 0 || !body.data || !body.data.items) {
          throw new Error((body && body.msg) || 'unexpected response');
        }

        var map = {};
        body.data.items.forEach(function (row) {
          map[row.article_id] = row;
        });

        items.forEach(function (it) {
          var row = map[it.id];
          if (row) {
            applyState(it, row.like_count, row.liked);
          } else {
            applyState(it, 0, false);
          }
        });
      })
      .catch(function (err) {
        // 批量接口出问题（比如旧版后端还没实现）就退回逐个请求，
        // 避免整页点赞数一起空着
        console.warn('[likes] 批量加载失败，降级为逐个请求:', err);
        return Promise.all(items.map(function (it) {
          return loadOne(it, visitorId);
        }));
      });
  }

  /* ---------------- 点赞 / 取消点赞 ---------------- */

  function onLike(item, visitorId) {
    if (item.pending) {
      return;
    }

    // 防误触：刚操作完 400ms 内忽略后续点击。
    // 因为已赞状态下按钮仍可点（用于取消），双击就会变成"点赞 → 立刻又取消"，
    // 用户只看到数字跳了一下又回去，很困惑。这一层挡住这种情况。
    // 想真心取消的话，过 400ms 再点就好，不影响正常使用。
    var now = Date.now();
    if (item.lastActionAt && now - item.lastActionAt < 400) {
      return;
    }

    var unliking = item.liked;

    item.pending = true;
    item.button.disabled = true;
    item.button.setAttribute('data-pending', 'true');

    var before = item.count;
    var optimistic = (typeof before === 'number')
      ? before + (unliking ? -1 : 1)
      : null;

    // 先动起来，让点击立刻有反馈；失败再回滚
    if (optimistic !== null) {
      item.countEl.textContent = String(Math.max(optimistic, 0));
    }

    request(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        article_id: item.id,
        visitor_id: visitorId,
        action: unliking ? 'unlike' : 'like'
      })
    })
      .then(function (res) {
        return res.json().then(function (body) {
          return { status: res.status, body: body };
        });
      })
      .then(function (result) {
        var body = result.body;

        // 取消点赞成功
        if (unliking) {
          if (body && body.code === 0 && body.data) {
            applyState(item, body.data.like_count, false);
            item.lastActionAt = Date.now();
            showMessageOn(item.node, '已取消点赞');
            return;
          }
          if (body && body.code === 429) {
            throw new Error('rate limited');
          }
          throw new Error((body && body.msg) || 'unexpected response');
        }

        // 点赞：0 = 成功，1001 = 早就赞过了。两者都拿到权威计数，直接同步。
        if (body && (body.code === 0 || body.code === 1001) && body.data) {
          applyState(item, body.data.like_count, true);
          item.lastActionAt = Date.now();

          if (body.code === 0) {
            pulse(item.button);
          } else {
            showMessageOn(item.node, '你已经赞过这篇了');
          }
          return;
        }

        if (body && body.code === 429) {
          throw new Error('rate limited');
        }
        throw new Error((body && body.msg) || 'unexpected response');
      })
      .catch(function (err) {
        // 回滚乐观更新
        if (typeof before === 'number') {
          item.countEl.textContent = String(before);
        } else if (item.count === null) {
          item.countEl.textContent = '--';
        }

        item.button.removeAttribute('data-pending');
        item.button.disabled = false;

        var message = (err && err.message === 'rate limited')
          ? '点得太快了，稍后再试'
          : (unliking ? '取消点赞失败，请稍后再试' : '点赞失败，请稍后再试');

        console.warn('[likes] ' + (unliking ? '取消点赞' : '点赞') + '失败:', item.id, err);
        showMessageOn(item.node, message);
      })
      .then(function () {
        item.pending = false;
      });
  }

  /* ---------------- 初始化 ---------------- */

  function init() {
    var nodes = [].slice.call(document.querySelectorAll('.like-widget'));
    if (nodes.length === 0) {
      return;
    }

    var visitorId = getVisitorId();

    var items = [];
    nodes.forEach(function (node) {
      var id = (node.getAttribute('data-article-id') || node.getAttribute('data-id') || '').trim();
      var derived = false;

      var button = node.querySelector('.like-button');
      var countEl = node.querySelector('.like-count');

      // 空组件 —— 只有 <div class="like-widget"></div>，里面什么都没有。
      // 这是文档里推荐的写法，所以这里必须能自己长出按钮来。
      if ((!button || !countEl) && node.children.length === 0) {
        button = buildDefaultButton(node);
        countEl = node.querySelector('.like-count');
      }

      // 有种情况仍然要跳过：组件里有东西，但结构不对
      // （比如 class 名拼错、或者嵌套层级乱了）。这时静默跳过比乱改 DOM 好，
      // 但必须报出来，否则用户只看到"页面上什么都没有"，无从下手。
      if (!button || !countEl) {
        console.warn(
          '[likes] 这个组件的结构不对，已跳过。\n'
          + '需要里面有一个 .like-button，且它内部含一个 .like-count。\n'
          + '要么写成空组件 <div class="like-widget"></div> 让脚本自动补，\n'
          + '要么按文档抄完整结构。出问题的组件：', node
        );

        // 只写控制台不够：用户看到的是"页面上什么都没有"，根本不知道该去开控制台。
        // 在页面上也留一行字，至少给个方向。
        showWarningOn(node, '点赞组件结构不对，已跳过（详见浏览器控制台）');
        node.setAttribute('data-state', 'warn');
        return;
      }

      // 没写 data-article-id 就按当前链接自动派生。
      // 详情页这样最省事：模板里放个空组件就行，永远不会忘了改 ID。
      if (!id) {
        id = deriveIdFromUrl();
        derived = true;

        // 把派生结果写回 DOM，方便在开发者工具里直接看到"这篇用的是什么 ID"
        node.setAttribute('data-article-id', id);
        node.setAttribute('data-article-id-source', 'auto');

        console.info('[likes] 组件没写 data-article-id，已按当前链接派生：' + id);
      } else {
        node.setAttribute('data-article-id-source', 'explicit');
      }

      items.push({
        node: node,
        id: id,
        derived: derived,
        button: button,
        countEl: countEl,
        liked: false,
        count: null,
        pending: false,
        lastActionAt: 0,
        placeholder: isPlaceholderId(id),
        conflict: ''
      });
    });

    if (items.length === 0) {
      return;
    }

    // ---- 共用检测 ----
    //
    // 点赞数完全由 ID 决定。同一页里两个组件用了同一个 ID，就会共用一个计数 ——
    // 不报错、不提示，只是数字莫名其妙地一起涨。这是整套设计唯一会"安静出错"的地方，
    // 所以只要发现，就必须显式报出来。
    //
    // 最常见的两种触发：
    //   1) 复制模板后忘了改 data-article-id
    //   2) 在列表页用了自动派生（同页所有组件派生出的 ID 都一样）
    var groups = {};
    items.forEach(function (item) {
      if (!groups[item.id]) {
        groups[item.id] = [];
      }
      groups[item.id].push(item);
    });

    Object.keys(groups).forEach(function (id) {
      var group = groups[id];
      if (group.length < 2) {
        return;
      }
      group.forEach(function (item) {
        item.conflict = '这一页有 ' + group.length + ' 个组件的 ID 都是「' + id + '」';
      });
    });

    items.forEach(function (item) {
      var problems = [];

      if (item.placeholder) {
        problems.push('文章 ID 还是示例值「' + item.id + '」');
      }
      if (item.conflict) {
        problems.push(item.conflict);
      }
      if (problems.length === 0) {
        return;
      }

      console.warn(
        '[likes] ' + problems.join('；') + '。\n'
        + '→ 这些组件会共用同一个点赞数。\n'
        + '→ 请给每个组件写各自的 data-article-id（只允许字母、数字、下划线、中划线），\n'
        + '   或者让模板直接输出文章 slug：data-article-id="<?php echo $post[\'slug\']; ?>"。\n'
        + '   列表页必须逐个指定，不能用自动派生。\n'
        + '出问题的组件：', item.node
      );

      showWarningOn(item.node, problems.join('；') + '，会和其他文章共用点赞数');
      item.node.setAttribute('data-state', 'warn');
    });

    items.forEach(setLoading);

    var loading = (items.length === 1)
      ? loadOne(items[0], visitorId)
      : loadMany(items, visitorId);

    // 不等加载完成就挂上事件：接口慢的时候用户依然能点
    items.forEach(function (item) {
      item.button.addEventListener('click', function () {
        onLike(item, visitorId);
      });
    });

    return loading;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
