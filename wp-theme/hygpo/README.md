# Hygpo — WordPress theme

A gallery-first theme for an AI **image + prompt** site. Images are the hero;
prompts are unlocked and translated by the companion plugins. The theme only
provides the visual shell and clean containers for the plugin shortcodes.

---

## Install

1. Zip the `hygpo/` folder (or copy it into `wp-content/themes/hygpo`).
2. **Appearance → Themes → Activate** “Hygpo”.

The theme is intentionally light: pure CSS + one tiny vanilla JS file, no jQuery
requirement, no external CDNs (fonts are local — see *Fonts* below).

---

## Source of truth & deploy  ⭐读我

**This `hygpo/` folder is the single source of truth.** There is intentionally
**no committed `.zip`** in the repo (it was a binary blob — unreviewable and
undiffable — so it was removed). The zip is a *build artifact*, generated on
demand, never checked in.

- **Edit** → change files here in `hygpo/`. Normal git diff / review / merge.
- **Hot-update a live site** → copy the single changed file into the container
  (same paste-run docker-copy pattern as the plugins). No zip needed.
- **Fresh install / hand off a package** → build the zip on the spot:
  ```
  cd wp-theme && zip -r hygpo.zip hygpo/   # then upload via Appearance → Themes
  ```
  Don't commit the resulting zip — rebuild it whenever needed.

If you ever find a loose stray file outside this folder or a committed zip, that
is drift — reconcile it back into `hygpo/`.

---

## One-time setup

### 1. Static front page (Hero + search)
**Settings → Reading → Your homepage displays → A static page.**
- Create a Page (e.g. “Home”) and set it as **Homepage**.
- Optionally put `[mwf_search]` in that page — the front template will render it
  inside the hero. If you leave the page empty, the theme shows its own search
  box automatically.

### 2. Search page (standalone)
Create a Page called **“Search”**, put just `[mwf_search]` in it, publish.
`page.php` detects the shortcode and renders it in a clean, wide container.
This page can be linked directly and is also reachable from the nav search.

### 3. Gallery posts (the money page)
Each **Post** is one gallery (图集). In the post body:
- optional intro text first,
- then `[mwf_gallery]`,
- the paywall button shortcode where your plugin expects it (e.g.
  `[paywall_payment id="x"]`).

The theme runs `the_content()` normally, so **all shortcodes execute**. Set a
**Featured image** — it becomes the card cover on the home/archive grids
(prompts are never shown in lists).

### 4. Menus (optional)
**Appearance → Menus** → assign a menu to **“Footer Menu”**. The top nav is
deliberately minimal: search + **Login** only.

---

## Concept ↔ template map

| Concept | WordPress | Template | Notes |
|---|---|---|---|
| Gallery (图集) | Post | `single.php` | Body runs `[mwf_gallery]` = many images + prompts |
| Single image | Attachment | — | One `.mwf-item` cell inside the gallery; anchor `#img-{id}` |
| List of galleries | Archive | `archive.php` / `index.php` | Featured-image cards, no prompts |
| Home | Front page | `front-page.php` | Hero + search + latest |
| Search host | Page w/ `[mwf_search]` | `page.php` | Standalone search |
| 404 | — | `404.php` | Also shown for private/orphan attachments |

Unlock and translation are **per-Post** (the whole gallery unlocks at once);
the plugin’s fixed float handles both.

---

## Scroll offset for anchor jumps  ⭐

Search results deep-link to `…/post/#img-123`. The sticky nav is **64px** tall,
so targets use a matching offset in `style.css`:

```css
:root{ --nav-h: 64px; }
[id^="img-"]{ scroll-margin-top: 96px; }  /* nav 64 + 32 breathing room */
```

If you change the nav height (`--nav-h`), update `scroll-margin-top` to
`nav-height + ~32px` so a jumped-to image always clears the nav bar.

---

## Plugin contract (do not break)

The theme **only adds visual styling** to these plugin classes — it never
renames them, never removes the shortcodes, and puts **no `transform` or
`overflow:hidden`** on `.site-content` or any ancestor of `.mwf-float`
(that would clip the fixed button):

`.mwf-search .mwf-search-form .mwf-search-input .mwf-search-btn
.mwf-search-status .mwf-masonry .mwf-cell` ·
`.mwf-gallery(.is-paid/.is-locked) .mwf-item .mwf-item-img
.mwf-prompt .mwf-prompt-text .mwf-prompt-locked
.mwf-float(.mwf-float-bottom-*) .mwf-translate-box .mwf-lang-select
.mwf-translate-btn`

The gallery grid switches to a single column at **760px** to match the
plugin’s own breakpoint.

A small status pill (“locked / unlocked”) is painted by `assets/theme.js`,
which only *reads* `.mwf-gallery.is-paid|.is-locked` — the theme never decides
paid state.

---

## Fonts

- **Headings:** Space Grotesk, **self-hosted**. Drop the woff2 files into
  `assets/fonts/` (names listed in `assets/fonts/fonts.css`). If absent,
  headings fall back to the system sans stack — nothing breaks.
- **Body & prompts:** system multilingual stack covering **CJK / Arabic /
  Hebrew / Thai / Devanagari / Tibetan**, so translated prompts render without
  tofu. No webfont is loaded for body text.

---

## Files

```
hygpo/
  style.css          design tokens + all styles + theme header
  functions.php      setup, enqueue, helpers (hygpo_search, card cover/tags)
  header.php         sticky nav: in-bar search (desktop) / icon+drawer (mobile) + Login
  footer.php
  front-page.php     hero + search + latest galleries
  single.php         ⭐ gallery post (runs [mwf_gallery])
  archive.php        gallery list (cards)
  index.php          fallback list
  page.php           generic page + [mwf_search] host
  404.php
  assets/
    theme.js         mobile search toggle + status pill
    fonts/fonts.css  self-hosted Space Grotesk @font-face
```
