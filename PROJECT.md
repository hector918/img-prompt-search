# PROJECT.md — AI 图片提示词站(hygpo.com)项目交接

> 给 Claude Code 的上下文文档。读完即可接手继续开发。
> 本项目是一个「AI 图片 + 提示词(prompt)」内容站,包含一个搜索微服务 + 两个 WordPress 插件 + 一个主题。
> **四个部分均已完成实现,站点已上线 hygpo.com。** 剩下的主要是真机功能验证(尤其付费流程)与内容灌入。
> 最近更新:2026-07-01(主题落地、登录/注册页完成、上线连通性验证)。

---

## 1. 项目全貌

**站点定位**:图片画廊站。用户搜图(只看图),点进「图集文章页」,图免费、提示词(prompt)付费解锁,解锁后可翻译成 38 种语言。

**四个组成部分:**

| 部分 | 名称 | 状态 | 位置 |
|---|---|---|---|
| A. 搜索微服务 | `wp-img-prompt-search` | ✅ 完成,8/8 测试过 | Python,用户 repo: github.com/hector918/img-prompt-search(即本 repo) |
| B. 后端插件 | `MWF AI Backend` (mwf-ai-backend.php) | ✅ 完成,已上线 | WordPress 插件,v0.1,`wp-plugin/` |
| C. 前端插件 | `MWF AI Frontend` (mwf-ai-frontend.php) | ✅ 完成,已上线 | WordPress 插件,v0.2,`wp-plugin/` |
| D. 主题 | `Hygpo` | ✅ **已实现,已上线** | `wp-theme/`(打包 zip;含完整模板集 + 登录/注册/资料页) |

**数据流:**
```
AI agent 上传图(标准 WP REST,设 post_parent 关联图集)
   → 后端插件 /process:VL 反推缺失的 prompt → 索引进搜索服务
   → 用户在搜索页 [mwf_search] 搜图(调后端 /search → 搜索服务)
   → 点图跳图集页 [mwf_gallery]:图免费 / prompt 付费(Coinsnap)/ 翻译(Hy-MT2)
```

---

## 2. 基础设施与环境

### 站点
- **hygpo.com**:SQLite WordPress,Docker,Cloudflare Tunnel,arm64(aarch64)服务器。已上线。
- **REST 入口**:代码统一走 `?rest_route=/mwf-ai/v1/...`(最稳,永远可用)。
  - ⚠️ 更新(2026-07-01 实测):当前部署下 `/wp-json/` **漂亮路由也已能用**(`/wp-json/wp/v2/posts`、`/wp-json/mwf-ai/v1/status` 均返回 200 真 JSON)。早先「`/wp-json/` 404、必须用 `?rest_route=`」的约束在此环境已不再成立(应是固定链接/rewrite 生效了)。两个入口并存,代码继续用 `?rest_route=` 无需改动。
- 机器:`root@instance-20230712-0726`,工作目录 `/home/ubuntu`。
- WP 容器:**`hygpo-wordpress-1`**,网络 `hygpo_web`。插件目录 `/var/www/html/wp-content/plugins/`。
  - 部署自定义插件改动:核心 REST 无法上传自定义 zip / 改文件,需 `docker exec hygpo-wordpress-1 ...`(sed/覆盖文件)或后台「上传插件→替换」。改动即时生效(无恼人 opcache)。
- **已装插件**(2026-07-01):coinsnap-paywall(active)、mwf-ai-backend/frontend(active)、sqlite-database-integration(active)、user-auto-tags(active,自定义)、simple-cloudflare-turnstile(**inactive**,注册验证码,开放注册时启用)、akismet/hello(inactive)。
- **上线连通性验证(2026-07-01)**:首页 200;`/?rest_route=/mwf-ai/v1/status` → `{"total":0,"pending":0,"done":0}`;公开 `/search`(POST `q`)→ `{"items":[],"count":0}`。链路全通,但**库内 0 张图**(业务全流程尚未用真实数据跑通)。

### GPU / AI 服务(llama.cpp,网络 `llmnet`)
| 服务 | 模型 | 端口 | 接口 |
|---|---|---|---|
| Embedder | Qwen3-Embedding-0.6B | 8081 | /v1/embeddings(1024 维) |
| Reranker | qwen3-reranker-0.6b | 8082 | /v1/rerank(Cohere 风格) |
| VL(反推) | Qwen3-VL-4B-Instruct | 8085 | /v1/chat/completions(OpenAI vision) |
| 翻译 | Hy-MT2-1.8B | 8083 | /v1/chat/completions(OpenAI chat) |

### NPS 隧道(WP 容器访问 AI 服务的通道)
- NPS server 公网:`150.230.113.32:8024`。
- npc 客户端跑在 Docker,网络 `hygpo_web`(与 WP 同网,容器名 `npc`)。
- **自建镜像**:官方 v0.26.10 arm64 二进制(`ehang-io/nps` releases),debian:12-slim,版本必须与 server 0.26 匹配(不匹配会 connection reset)。镜像 tag `npc-local:0.26.10`,conf 挂载 `/home/ubuntu/nps/conf/npc.conf`。
- **WP 侧访问地址**(填在后端插件设置页):
  - 搜索服务:`http://npc:8090`
  - VL 反推:`http://npc:11434` + path `/vl`
  - 翻译:`http://npc:11434` + path `/translate`
  - (11434 是一个网关端口,按 path `/vl`、`/translate` 分发到 8085、8083)

---

## 3. 数据模型(全部用 WordPress 原生字段)

| 概念 | WP 字段 | 备注 |
|---|---|---|
| 图片 | attachment | 图片 ID = WP attachment ID(自增整数,不可随机) |
| caption | attachment caption (post_excerpt) | |
| **prompt** | attachment **description (post_content)** | 可为空 → 需 VL 反推 |
| tags | 原生 post_tag(插件开启了 attachment 支持) | REST 传 term ID 数组 |
| 图 → 图集关系 | **post_parent**(实时查) | agent 上传时设 `post` 字段建立 |

**自定义 meta:**
- `_mwf_embedded`(0/1):索引状态,插件内部管理,agent 不要碰。
- `_mwf_prompt_i18n`(JSON `{"Japanese":"...","English":"..."}`):翻译缓存,key = 语言英文全名。
- `_mwf_paid_posts`(user_meta,数组):登录用户永久解锁的 post_id 列表(前端插件用)。

---

## 4. 组件详情

### A. 搜索微服务(wp-img-prompt-search)
- **技术**:FastAPI + PostgreSQL/pgvector + llama.cpp(embedder/reranker)。
- **用户 repo**:github.com/hector918/img-prompt-search。核心 main.py/db.py/clients.py 与最终版一致;用户加了部署修复(build `network:host`、`env_file:${ENV_FILE:-.env}`、`extra_hosts host-gateway`、run.sh `export ENV_FILE`、test.sh 健康等待轮询)。
- **端点**:
  - `GET /health`
  - `POST /index {id,caption,prompt,tags}`
  - `POST /index/batch {items}`
  - `POST /search {query,limit,tags:["+a","-b"],after,before,rerank}` → `{ids, results}`
  - `POST /delete {ids}`
- **Auth**:单个 `API_KEY`(默认空=开放;非空则除 /health 外都要 `Authorization: Bearer`)。
- **检索**:embedding = caption+"\n"+prompt;向量召回(RERANK_CANDIDATES=100)→ rerank 到 limit。
- **DB**:表 `images`(id BIGINT PK, caption, prompt, tags TEXT[], embedding vector(1024), created_at, updated_at)+ hnsw cosine + gin tags 索引。
  - **关键坑**:向量要用 `'[1,2,3]'` 字面量 + `%s::vector` 转型(psycopg 不自动适配 list);不要用 register_vector。tags:`@>`(全含)+ `NOT (&&)`(排除)。
- **部署**:`.env` 放在 code 目录外(`~/wp-img-prompt-search/.env`);run.sh 首次生成模板 .env 有 CHANGE_ME_ 占位符;`docker compose --env-file ... up -d --build`。

### B. 后端插件(mwf-ai-backend.php,v0.1,~735 行)
纯后端,无前端输出。WordPress 后台上传安装(不是 run.sh)。

- **设置页**(Settings→MWF AI):三服务配置(base+api_key+path)、VL 反推指令(可编辑)、翻译指令模板(可编辑,含 `{lang}`/`{text}` 占位符)、image_size、max_tokens、`mwf_api_key`(端点鉴权,空=开放)。存 option `mwf_ai_options`。
- **测试连接按钮**(AJAX,nonce+manage_options):
  - search:真实 POST /search 查询,返回结果数。
  - vl/translate:真实 POST 纯文本 chat,返回模型实际回复(验证接口通)。
- **REST 端点**(全走 `/?rest_route=/mwf-ai/v1/...`):
  - `POST /process {count 1-100}`:状态机,一次一步。无 prompt → VL 反推(读图 base64 内联到 OpenAI vision,写入 description);有 prompt 未索引 → 调搜索服务 /index,标记 `_mwf_embedded=1`。
  - `GET /status` → `{total,pending,done}`。
  - `POST /search`(**公开**):参数是 **`q`**(不是 query);tags 接受数组或逗号/空格串;调搜索服务 → 组装 `mwf_assemble_item`:`{id,img,w,h,caption,prompt(截断140),tags,post_id,post_url(permalink#img-{id})}`。
  - `POST /translate {post_id,lang}`:找 post_parent=post_id 的图,读 description 作 prompt,查 `_mwf_prompt_i18n[lang]` 缓存,无则调翻译模型(OpenAI chat),缓存后返回 `{results:[{id,text,empty?,error?}]}`。**lang 传语言英文全名**(如 "Japanese",Hy-MT2 要求)。
- **翻译指令**(已更新为 Hy-MT2 官方):`Translate the following text into {lang}. Note that you should only output the translated result without any additional explanation: {text}`
- **隐私保护**(重要):
  - `mwf_parent_is_published($attachment_id)`:游离图(parent=0)→ false;仅 post_parent 为 publish → true。
  - `posts_clauses` 过滤器(query var `mwf_published_parent`):INNER JOIN 父 post,只留 publish 父级(游离图因 INNER JOIN 自动排除,draft 图集因 status 排除)。
  - process/status 只处理**已发布图集下**的图(游离/draft 跳过)。
  - 措施1:`rest_attachment_query`(匿名→仅 publish 父级)+ `rest_prepare_attachment`(匿名+非publish父级→404)。
  - 措施2:`template_redirect` on `is_attachment()`(匿名+非publish/游离→404)。
  - **登录用户/Application Password agent 不受限**(只限匿名公众)。

### C. 前端插件(mwf-ai-frontend.php,v0.2,~523 行)
option `mwf_ai_frontend_options`。

- **设置页**(Settings→MWF AI Frontend):backend_base(同站留空)、default_paywall_id、button_position、内置 38 语言展示。
- **`mwf_f_languages()`**:38 种 Hy-MT2 官方语言,每项 `[code, name(英文全名), label(显示名)]`。传给后端/缓存 key 用 name。
- **`[mwf_search]`**:搜索框 + 瀑布流,**只显示图片**(无 prompt,避免付费内容泄露),点图跳 `post_url#img-{id}`。调后端 /search,参数 `q`。
- **`[mwf_gallery]`**(核心内页):
  - 每张图 `<img id="img-{id}">`(免费,始终显示)+ prompt 区。
  - 未付费 prompt 区显示英文 `This is paid content`;已付费显示 prompt 文字 + 语言选择 + 翻译按钮。
  - 浮动按钮(fixed,位置可配,默认右下):未付费=Coinsnap `[paywall_payment id="x"]`;已付费=语言下拉(默认浏览器语言)+ Translate 按钮。
  - 翻译:选语言→点按钮→调 /translate(传 post_id + 英文全名 lang)→ 按图 id 填回;可再选语言切换。
- **付费判断 `mwf_f_is_paid($post_id)`**(v0.2 三层):
  - 管理/编辑者(edit_posts)→ 预览直接解锁。
  - 登录用户 → 先查 user_meta `_mwf_paid_posts`(永久);无则查 Coinsnap session,若已付 → 升级写入 user_meta(永久,绑账号)。
  - 匿名 → 仅查 Coinsnap session(随缘)。
- **订阅者优化**:登录跳前台、隐藏 admin bar(仅对无 edit_posts 权限者)。

### D. 主题(Hygpo,✅ 已实现)
纯 CSS + 一个 vanilla JS,无 jQuery、无外部 CDN(适配受限 Docker 网络)。打包在 `wp-theme/Hygpo WordPress 主题设计.zip`,解包目录名 `hygpo/`。

- **模板集**:`style.css`(设计 token + 全部样式)、`functions.php`、`header.php`(sticky nav:桌面内嵌 `[mwf_search]` + 移动抽屉 + Login/Profile 链接)、`footer.php`、`front-page.php`(Hero + 搜索 + 最新图集)、`single.php`(核心:跑 `[mwf_gallery]`)、`archive.php`/`index.php`(封面卡片列表,不显 prompt)、`page.php`(承载 `[mwf_search]`)、`404.php`、`assets/theme.js`(移动搜索开关 + 状态 pill)、`assets/fonts/`(自托管 Space Grotesk)。
- **登录/注册/资料页模板**(解决旧待办):`template-login.php`(`wp_login_form()`)、`template-register.php`(核心注册,可用 `hygpo_register_form` 滤镜给 membership 插件接管)、`template-profile.php`(登录用户资料页,目前空态占位)。`functions.php` 里 `hygpo_login_url/register_url/profile_url` 按页面模板自动检测,回退 `wp-login.php`。
- **对接约束(已全部遵守)**:`single.php`/`.gallery-stage` 祖先**无 `transform`/`overflow:hidden`**,不裁剪 fixed 浮动按钮;`[id^="img-"]{scroll-margin-top:96px}`(nav 64+32)防锚点被遮;`the_content()` 正常执行短代码;不改任何 `.mwf-*` class;状态 pill 只**读** `.mwf-gallery.is-paid/.is-locked`,不决定付费;版心 1180px;多语言字体栈(CJK/阿拉伯/希伯来/泰/天城/藏)。
- **一次性配置**(README 有):Reading 设静态首页;建「Search」页放 `[mwf_search]`;每个图集 = 一个 Post,正文放 `[mwf_gallery]` + `[paywall_payment id="x"]` + 设 Featured image 作封面。

---

## 5. 付费机制(Coinsnap Bitcoin Paywall v1.3.1)

- **独立插件**(wordpress.org/plugins/coinsnap-paywall),不是 Coinsnap 主网关。是 BTC/闪电付费墙。
- **短代码 `[paywall_payment id="x"]`**:只输出**支付按钮**,不包裹内容(id 指向后台建的 paywall 配置:价格/按钮文字/时长)。
- **付费状态表**:`{prefix}coinsnap_paywall_access(post_id, session_id, access_expires)`。判断 = `coinsnap_paywall_has_access($post_id,$session_id)` 查 `access_expires > NOW()`。**按 post_id + PHP session 记账,有过期时间**。
- **时长按小时设**(用户设了 **24 小时**)。
- **重大局限**:Coinsnap 用裸 `session_start()`,PHP 默认 session(cookie_lifetime=0 关浏览器即失效,gc_maxlifetime 24分钟),**付费状态非常短命**。→ 因此前端插件加了「登录用户永久绑账号」方案弥补:登录用户付费后升级为 user_meta 永久记录;匿名随缘。
- **解耦**:前端插件只**读** `coinsnap_paywall_access` 表 + 用它的支付短代码,不碰其付费逻辑。

---

## 6. 上传契约(给 AI Agent)

标准 WP REST,**无自定义上传端点**。插件只「开开关」:attachment 支持 post_tag + 注册 `_mwf_embedded` meta。

流程(Application Password 认证):
1. `POST /wp/v2/posts` → post_id(图集,可 draft)。
2. 每张图:`POST /wp/v2/media` 上传 → image_id;`POST /wp/v2/media/{id} {caption, description(=prompt或空), tags:[term_ids], post: post_id}`。**`post` 字段设 post_parent 关联,关键**。
3. `POST /wp/v2/posts/{id} {featured_media}`(封面)。
4. status=publish(发布后 process 才处理该图)。
5. `POST /?rest_route=/mwf-ai/v1/process {count}` 触发处理(可多次直到 pending=0)。

要点:走 `?rest_route=` 入口;tags 传 term ID(先查/建 `/wp/v2/tags`);**文件名用随机串**(不是图片 ID,防 URL 枚举);不要设 `_mwf_embedded`;认证用户行为与标准 WP 一致,仅匿名读取受隐私限制。

---

## 7. 已完成 / 待办 / 坑

### 已完成
- [x] 搜索微服务(8/8 测试)
- [x] npc 隧道(版本 0.26.10 匹配)
- [x] 后端插件(process/status/search/translate + 隐私保护 + 测试按钮)
- [x] 前端插件(搜索页 + 内页 + 翻译 + 登录永久付费 + 订阅者优化)
- [x] 翻译指令换 Hy-MT2 官方模板
- [x] **主题 Hygpo 实现**(完整模板集,对接约束全遵守)
- [x] **登录/注册/资料页**(主题模板 `template-login/register/profile.php` + `hygpo_*_url` 检测)
- [x] **站点上线 hygpo.com**(首页 + 两插件 REST 连通性已验证)

### 待办 / 待验证
- [ ] **灌入首个测试图集**(前置条件):当前库内 0 张图,搜索/图集页/付费/翻译都要有内容才测得了。上传 → 发布 → `POST /process` 反推+索引。
- [ ] **前端↔主题对接实测**(真机):浮动按钮 fixed 不被裁剪、锚点 scroll-margin-top、短代码执行、多语言字体、图集版心。代码层已到位,缺真机跑一遍。
- [ ] **付费全流程实测**(重点,见下「§9 付费测试方案」):确认支付轨道(Coinsnap 托管 vs BTCPay testnet),再按分层方案验证。
- [ ] **开放注册**:后台设置→常规→勾「任何人都可以注册」+ 默认角色订阅者 + 装验证码插件防垃圾。
- [ ] **付费提醒文案**:英文,提示登录=永久、匿名=24h(已拟稿,待放到支付按钮上方;可做成按登录状态动态显示)。
- [ ] 上传批量脚本(如需):图 + 元数据一键灌入 + 触发 process。
- [ ] **按作者路由 paywall(未来)**:每个 author 映射一个 Coinsnap paywall id(user_meta,如 `_mwf_paywall_id`),`[mwf_gallery]` 无显式 `paywall=` 时按图集作者取,回退 `default_paywall_id`。**单钱包**(Coinsnap store 全局),只做账单归属区分,不分账到不同钱包(用户 2026-07-01 确认「有账单就行,不用分开钱包」)。

### 坑 / 注意
- REST 用 `?rest_route=` 入口最稳(始终可用);当前部署 `/wp-json/` 也已通(2026-07-01 实测,早先 404 的说法已过时)。
- 搜索端点参数是 `q` 不是 `query`(前端插件把 `q` 转发为搜索服务的 `query`)。
- 翻译 lang 传**英文全名**(Japanese 不是 ja)。
- npc 客户端版本必须匹配 server(0.26)。
- 向量插入用字面量 + `::vector` 转型。
- Coinsnap session 短命 → 登录永久方案弥补;匿名换设备/清 cookie 要重付。
- 图片文件 URL 默认公开(WP 通病);隐私靠「不索引游离/draft + REST/attachment 匿名 404 + 随机文件名」组合,非文件级加密。
- 浮动按钮 fixed 怕主题祖先 transform/overflow。

---

## 8. 关键文件清单(仓库实际布局)
```
main.py / db.py / clients.py        搜索微服务(FastAPI + pgvector + llama.cpp 客户端)
Dockerfile / docker-compose.yml
requirements.txt / run.sh / test.sh 部署 + 测试脚本
wp-plugin/
  mwf-ai-backend.php                后端插件 v0.1(REST + 隐私 + 设置)
  mwf-ai-frontend.php               前端插件 v0.2(搜索页 + 内页 + 付费 + 翻译)
  mwf-ai-frontend.zip               前端插件打包(与 .php 同步)
wp-theme/
  Hygpo WordPress 主题设计.zip      主题 Hygpo(解包目录名 hygpo/,含 README + 全部模板)
PROJECT.md                          本文档
```
> 注:`THEME-DESIGN-SPEC.md` 已不存在——主题已从设计稿进入实现,产物即上面的主题 zip。
> 注:`wp-theme/` 目前只提交了 zip,主题**源码未解包进 git**(插件那边同时有 .php + .zip)。如需版本管理/diff,可考虑把 `hygpo/` 解包提交。

---

## 9. 付费测试方案(paywall)

**要测的判定逻辑**(前端 `mwf_f_is_paid($post_id)`,三层,自上而下短路):
1. 登录且有 `edit_posts` 权限(管理/编辑)→ 直接解锁(预览)。
2. 登录用户:先查 user_meta `_mwf_paid_posts` 含该 post → 永久解锁;否则查 Coinsnap session,若已付 → 升级写入 user_meta(永久绑账号)并解锁。
3. 匿名:仅查 Coinsnap session(`{prefix}coinsnap_paywall_access` 表中 `post_id + PHP session_id + access_expires > NOW()` 有行)。

前端只**读** Coinsnap 的 `coinsnap_paywall_access` 表 + 用其 `[paywall_payment id="x"]` 支付按钮,不碰其付费写入逻辑。付费写入由 Coinsnap 插件在收到支付后完成。

### 前置检查(测之前先确认)
- **Coinsnap Bitcoin Paywall 插件是否已装且激活**:未装则表不存在,`mwf_f_is_paid` 匿名/session 层永远 false(前端有 `SHOW TABLES` 兜底)。确认表存在:`{prefix}coinsnap_paywall_access`。
- **paywall id 是否配置**:前端设置页 `default_paywall_id`(或短代码 `[mwf_gallery paywall="x"]`);未配置时锁定态浮层显示 "Paywall not configured."。
- **支付轨道决策(关键分叉)**:Coinsnap 托管网关 vs 自建 BTCPay。只有 BTCPay 能上 **testnet/regtest** 做零成本端到端真付。

### 分层测试(易 → 真,建议按序)
- **L0 管理员预览**(最快,不碰 Coinsnap):用管理员/编辑账号打开图集页 → `.mwf-gallery` 应带 `is-paid`,prompt 明文可见,浮层出现语言下拉 + Translate。验证「已付费渲染 + 翻译链路」,但绕过了付费本身。
- **L1 登录永久记录**(验证账号永久解锁路径,无需真付):给一个测试订阅者账号的 user_meta `_mwf_paid_posts` 加入该 post_id(wp-cli 或直改 SQLite),用该账号打开图集 → 解锁。测第 2 层永久路径。
- **L2 注入 Coinsnap session 记录**(验证真实付费读取路径 + 登录升级永久):浏览器拿到 `PHPSESSID` cookie → 往 `coinsnap_paywall_access` 插一行(该 session_id + post_id + `access_expires` 设未来时间)→ 刷新页面。匿名访客即解锁;若此时是登录用户,应触发「升级为 user_meta 永久」。测第 2/3 层的读取与升级。
- **L3 真实支付端到端**(最终验收):BTCPay testnet 小额(或 Coinsnap 托管真付小额)→ 点浮层支付按钮走完 → Coinsnap 回调写入 access 行 → 页面刷新解锁。测支付按钮渲染 + 回调 + 全链路。

### 实测结果(2026-07-01,用测试图集 post 43 / img 44-46)
- ✅ **内容流水线全通**:标准 WP REST 上传(app password `claude`,管理员)→ `/process` VL 反推(img 45 空 prompt 被正确反推出英文 prompt)→ 索引,`status` done=3 无错误。
- ✅ **搜索后端正常**:`POST /search q="sunset over the ocean"` 正确返回 44(日落)排首、46(田野)次之(向量召回+rerank 生效)。
- ✅ **翻译正常**:`/translate post_id=43 lang=Japanese` 三图均返回日文(Hy-MT2 通)。
- ✅ **匿名图集页正确**:`is-locked` 态、`#img-44/45/46` 锚点齐、"This is paid content" 占位、**prompt 明文未泄露**。
- 🐛 **已修 bug**:前端搜索 JS 读错返回字段——后端 `/search` 返回 `{items}`,而 `mwf-ai-frontend.php` 搜索脚本读的是 `data.results`,导致搜索页永远 "No results"。已改为 `data.items`(第 446 行的 `data.results` 是翻译用的,正确,未动)。zip 已同步重建。
- ⛔ **付费未配置(已解决)**:曾显示 "Paywall not configured." —— 原因是前端设置 `mwf_ai_frontend_options` 从未保存过(`default_paywall_id` 空)。Coinsnap 其实是 active 的。已用 `update_option` 写入 `default_paywall_id=29`(paywall CPT `paywall-shortcode`:id6 test01=$0/dark、id29 ray=$1/light、id42 Alan=$1/light 与 ray 重复)。
- 🐛 **已修 bug(重要,Coinsnap 集成)**:Coinsnap 只在**正文含 `[paywall_payment]`** 时才 enqueue 它的 `paywall.js/css`(`class-coinsnap-paywall-scripts.php` 的 `has_shortcode(post_content,'paywall_payment')`)。我们的支付按钮是 `[mwf_gallery]` **渲染时动态注入**的,正文里没有该短代码 → Coinsnap 脚本不加载 → "Pay Now" 按钮**是死的**(所有 paywall 支付都受影响,不止 $0)。修法:前端插件加 `wp_enqueue_scripts` 钩子,在含 `[mwf_gallery]` 的单页上按同样方式补 enqueue `coinsnap-paywall-paywall`(css+js)并 localize `coinsnap_paywall_ajax`(前端只需 ajax_url,无 nonce)。zip 已重建。
- ✅ **解锁链路验证通过(L2,零成本)**:直接 POST `admin-ajax.php?action=coinsnap_paywall_grant_access`(带浏览器 PHPSESSID cookie,`post_id=43&duration=24`)→ `{"success":true}` 写入 access 行 → 带同一 cookie 抓图集页得 `is-paid` + 三张 prompt 明文 + 翻译框;匿名抓仍 `is-locked` 无泄露。即「Coinsnap grant → 我们 `mwf_f_coinsnap_session_paid` 读 → 解锁」真实跑通(只跳过了开发票收钱那步)。
- ⛔ **$0 paywall 开不了发票**:`create_invoice()` 校验 `empty(filter_input('amount',FILTER_VALIDATE_FLOAT))`,而 `empty(0.0)===true` → $0(test01)恒被拒 "Invalid request parameters."。真付只能用 amount>0 的 paywall(ray/Alan $1)。
- ⛔ **L3 真付受阻于 Coinsnap 平台侧(非我方代码,已穷尽我方诊断)**:凭据有效(`GET /api/v1/stores/{id}`→200,store "Hygpo - graphic",storeId `HkdxxfYT…`,provider=coinsnap)、容器出网正常、`GET /rates`→200(拿到实时汇率),但 `POST /api/v1/stores/{id}/invoices` → **HTTP 500 "An error occurred while trying to create invoice."**。已验证与请求体无关(min/string/redir/meta/sats 5 种变体全 500),与我方代码无关(L2 解锁链路早已通过)。Coinsnap API 不暴露 store 列表(`GET /stores`→404)或支付方式状态,无法从 API 侧进一步定位。用户 2026-07-01 称已绑钱包但仍 500。**待办(全在 app.coinsnap.io,非服务器/非代码)**:① 确认绑钱包的 store 就是 `HkdxxfYT…`(否则换 store 或挪钱包);② 在 Coinsnap 后台手动开一张测试发票——后台也开不出则拿 storeId 找 Coinsnap 客服查「invoice 创建 HTTP 500」。搞定后开发票会从 500 变 200,再用 ray($1)走真付即可收尾 L3。
- **Coinsnap API**:base `https://app.coinsnap.io`;开发票 `POST /api/v1/stores/{store_id}/invoices`,认证 header `x-api-key` + `Authorization: token {key}`;查 store `GET /api/v1/stores/{store_id}`。凭据存 `coinsnap_paywall_options`。
- ⚠️ **Cloudflare 拦 UA**:hygpo.com 的 CF 用 Error 1010 封了 `Python-urllib` 默认 UA;脚本必须带浏览器 UA(curl 默认 UA 可过)。
- **Coinsnap 付费链路(供参考)**:点击 → `coinsnap_create_invoice`(需 Store/API + amount>0)→ checkoutLink → 轮询 `check_invoice_status` → 结算后 `coinsnap_paywall_grant_access(post_id,duration)` 写 `wp_coinsnap_paywall_access(session_id)`。前端 AJAX 无 nonce(nopriv)。

### 注意
- 站点是 **SQLite WordPress**,DB 操作走 wp-cli 或直接操作 SQLite 文件(非 MySQL 命令)。
- Coinsnap 用裸 `session_start()`,**PHP 默认 session 极短命**(关浏览器即失效、gc 约 24 分钟)→ L2 注入的 session 记录同样短命,匿名态测试要趁 session 活着时刷新。这也是「登录永久绑账号」方案存在的原因。
- 时长按小时设(用户设了 **24 小时**),但受 session 短命制约,匿名实际远短于 24h。
