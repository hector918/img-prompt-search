# 裸露打码(Sensitive masking)设计留档

打码由 `.is-masked` class 驱动(服务端加,无法插子元素),纯 CSS、首帧即遮、
三个交互态齐全(默认模糊+角标 / 桌面 hover 单图揭示 / `body.mwf-show-sensitive`
整页揭示 + `.mwf-sensitive-toggle` 浮动开关)。

**当前线上采用:方案 2 · Tinted veil。** 方案 3 · Film grain 留此备用。
两套只有「色罩那一段」不同,`::after` 图标 / hover / 整页揭示 / 浮动开关通用。

---

## 四个「图片盒」与 class 从哪来

打码作用于四个容器,`is-masked` 的注入点各不同:

| 场景 | 容器(带 `.is-masked`) | 图片盒(罩/图标锚点) | class 来源 |
|---|---|---|---|
| 图集内页 | `.mwf-item`(figure) | `.mwf-item-media`(包 img 的 span) | 插件 `[mwf_gallery]` 服务端,逐图 `_mwf_masked` |
| 搜索结果 | `.mwf-cell` | `.mwf-cell` 自身(格=图) | 前端 JS `it.masked` |
| 首页 Latest galleries | `.card`(a) | `.card .thumb` | 主题 `front-page.php` 调 `mwf_f_cover_masked()` |
| 归档 / tag / blog | `.post-card`(a) | `.post-card .cover` | 主题 `archive.php`/`index.php` 调 `mwf_f_cover_masked()` |

- 罩(`::before`)和图标(`::after`)锚在**图片盒**上而非容器,避免盖住卡片标题 / prompt。
- 四个图片盒都 `overflow:hidden`(`.mwf-item-media` 新加;其余主题/插件本就有),
  会自动裁掉模糊外溢与色罩圆角,故实现里**不再用 `clip-path`**、色罩也不用单独设圆角。
- `mwf_f_cover_masked($post_id)`(前端插件)= 该 post 特色图的 `_mwf_masked`。
  主题以 `function_exists` 守卫调用,插件停用时不致命(只是不打码)。

> 下面两套 CSS 是同事产出的**原始设计稿**(容器即图片盒的理想模型)。线上实现按上表
> 把选择器改成了「四个真实图片盒」的写法,`{BLUR}` 注为后台 `mask_blur`,`$toggle_side`
> 保留动态方位,SVG 属性引号用 `%27` 以免打断 PHP 单引号字符串。以实际插件代码为准。

---

## 方案 2 · Tinted veil(赤陶色罩)——【当前采用】

```css
/* ===== Sensitive masking — Tinted veil ===== */
.mwf-item.is-masked,
.mwf-cell.is-masked,
.card.is-masked { position: relative; }

/* 首帧即模糊。clip-path 收住模糊外溢并做 14px 圆角(与卡片一致) */
.mwf-item.is-masked .mwf-item-img,
.mwf-cell.is-masked img,
.card.is-masked .thumb > img {
  filter: blur({BLUR}px);            /* {BLUR} = 后台 mask_blur，4–80，默认 20 */
  clip-path: inset(0 round 14px);
  transition: filter .35s ease;
}

/* 品牌色罩 + 居中 “Sensitive” 文字（::before 铺满整幅） */
.is-masked::before {
  content: "Sensitive";
  position: absolute; inset: 0; z-index: 2;
  display: flex; align-items: flex-end; justify-content: center;
  padding-bottom: 20px;
  font-size: 11px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase;
  color: #fff;
  background: rgba(210,80,42,.40);   /* 赤陶薄雾；随 {BLUR} 想更浓可调 .34–.48 */
  border-radius: 14px;
  pointer-events: none; transition: opacity .25s ease;
}

/* 居中锁形图标（::after，内联 SVG 作背景图，白描边） */
.is-masked::after {
  content: ""; position: absolute; z-index: 3;
  top: 50%; left: 50%; transform: translate(-50%, calc(-50% - 10px));
  width: 44px; height: 44px; border-radius: 50%;
  border: 1px solid rgba(255,255,255,.7);
  background: rgba(255,255,255,.14)
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%23fff' stroke-width='1.7'%3E%3Crect x='4' y='10.5' width='16' height='9.5' rx='2'/%3E%3Cpath d='M8 10.5V7a4 4 0 0 1 8 0v3.5'/%3E%3C/svg%3E")
    center / 18px no-repeat;
  pointer-events: none; transition: opacity .25s ease;
}

/* 桌面 hover：单图揭示（触屏不触发） */
@media (hover: hover) {
  .mwf-item.is-masked:hover .mwf-item-img,
  .mwf-cell.is-masked:hover img,
  .card.is-masked:hover .thumb > img { filter: none; }
  .is-masked:hover::before,
  .is-masked:hover::after { opacity: 0; }
}

/* 整页揭示（开关在 body 上加 class） */
body.mwf-show-sensitive .is-masked .mwf-item-img,
body.mwf-show-sensitive .is-masked img,
body.mwf-show-sensitive .is-masked .thumb > img { filter: none !important; }
body.mwf-show-sensitive .is-masked::before,
body.mwf-show-sensitive .is-masked::after { display: none; }

/* 右下浮动开关左下角版（避开右下的付费浮动按钮） */
.mwf-sensitive-toggle {
  position: fixed; left: 20px; bottom: 20px; z-index: 45; display: none;
  align-items: center; gap: 8px;
  background: rgba(255,255,255,.92);
  -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
  border: 1px solid #e4e0db; border-radius: 999px;
  padding: 10px 16px; min-height: 44px;
  font-size: 13.5px; font-weight: 500; color: #1a1a1a;
  font-family: inherit; cursor: pointer;
  box-shadow: 0 10px 30px -12px rgba(0,0,0,.28);
}
.mwf-sensitive-toggle::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #d2502a; }
.mwf-sensitive-toggle:hover { border-color: #d2502a; color: #d2502a; }
body:has(.is-masked) .mwf-sensitive-toggle { display: inline-flex; }
@media (max-width: 560px) { .mwf-sensitive-toggle { left: 12px; bottom: 12px; } }
```

---

## 方案 3 · Film grain(模糊 + 噪点)——【备用】

把方案 2 的 `.is-masked::before` 一段替换成下面这段即可(`::after` 图标、hover、
整页揭示、浮动开关都通用,不用改):

```css
/* 深色薄雾 + 噪点纹理 + 居中 “Sensitive” 文字 */
.is-masked::before {
  content: "Sensitive";
  position: absolute; inset: 0; z-index: 2;
  display: flex; align-items: flex-end; justify-content: center;
  padding-bottom: 20px;
  font-size: 11px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase;
  color: #fff; text-shadow: 0 1px 6px rgba(0,0,0,.5);
  background-color: rgba(22,21,19,.30);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)' opacity='0.55'/%3E%3C/svg%3E");
  background-size: 140px; background-repeat: repeat;
  border-radius: 14px;
  pointer-events: none; transition: opacity .25s ease;
}
/* 方案 3 的图标改深色更配：把 ::after 的 stroke='%23fff' 换成 stroke='%23161513'，
   底色 rgba(255,255,255,.14) 换成 rgba(255,255,255,.9)、去掉白描边即可。 */
```

---

## 同事给的提醒

- `::before` 的 `border-radius:14px` 要和图片圆角一致;若某容器圆角不同,同步改这个值。
  (线上实现改用图片盒 `overflow:hidden` 自动裁切,不再单独设色罩圆角。)
- 文字 `content:"Sensitive"` 便于后台改词;若要多语言,可让后台输出到一个 `data-` 属性
  再 `content: attr(...)`。
- `body:has(.is-masked)` 只控制开关按钮的显示;真正整页揭示仍需现有 JS 给 body 加
  `mwf-show-sensitive`。
- 想让 `{BLUR}` 顺带联动色罩浓度,把 veil 的透明度也做成后台变量即可。
