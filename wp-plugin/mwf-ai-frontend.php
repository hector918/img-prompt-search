<?php
/**
 * Plugin Name: MWF AI Frontend
 * Description: 前端展示插件 —— 搜索页([mwf_search],只显示图片)+ 图集内页([mwf_gallery],图片免费/prompt付费/翻译)。配合 MWF AI Backend 使用。
 * Version: 0.2
 * Author: hector
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * 常量与语言表
 * ============================================================ */
define('MWF_F_OPT', 'mwf_ai_frontend_options');

/**
 * Hy-MT2 官方 38 种语言。
 * 每项:code(浏览器语言匹配) | name(传给模型的英文全名) | label(界面显示名)
 * 模型要求 target_lang 用英文全名,故 name 同时作为传给后端的 lang 与缓存 key。
 */
function mwf_f_languages() {
    return array(
        array('zh',      'Chinese',             '简体中文'),
        array('en',      'English',             'English'),
        array('fr',      'French',              'Français'),
        array('pt',      'Portuguese',          'Português'),
        array('es',      'Spanish',             'Español'),
        array('ja',      'Japanese',            '日本語'),
        array('tr',      'Turkish',             'Türkçe'),
        array('ru',      'Russian',             'Русский'),
        array('ar',      'Arabic',              'العربية'),
        array('ko',      'Korean',              '한국어'),
        array('th',      'Thai',                'ไทย'),
        array('it',      'Italian',             'Italiano'),
        array('de',      'German',              'Deutsch'),
        array('vi',      'Vietnamese',          'Tiếng Việt'),
        array('ms',      'Malay',               'Bahasa Melayu'),
        array('id',      'Indonesian',          'Bahasa Indonesia'),
        array('tl',      'Filipino',            'Filipino'),
        array('hi',      'Hindi',               'हिन्दी'),
        array('zh-Hant', 'Traditional Chinese', '繁體中文'),
        array('pl',      'Polish',              'Polski'),
        array('cs',      'Czech',               'Čeština'),
        array('nl',      'Dutch',               'Nederlands'),
        array('km',      'Khmer',               'ខ្មែរ'),
        array('my',      'Burmese',             'မြန်မာ'),
        array('fa',      'Persian',             'فارسی'),
        array('gu',      'Gujarati',            'ગુજરાતી'),
        array('ur',      'Urdu',                'اردو'),
        array('te',      'Telugu',              'తెలుగు'),
        array('mr',      'Marathi',             'मराठी'),
        array('he',      'Hebrew',              'עברית'),
        array('bn',      'Bengali',             'বাংলা'),
        array('ta',      'Tamil',               'தமிழ்'),
        array('uk',      'Ukrainian',           'Українська'),
        array('bo',      'Tibetan',             'བོད་སྐད'),
        array('kk',      'Kazakh',              'Қазақша'),
        array('mn',      'Mongolian',           'Монгол'),
        array('ug',      'Uyghur',              'ئۇيغۇرچە'),
        array('yue',     'Cantonese',           '粵語'),
    );
}

function mwf_f_opt($key, $default = '') {
    $o = get_option(MWF_F_OPT, array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

/* ============================================================
 * 后台设置页(Settings → MWF AI Frontend)
 * ============================================================ */
add_action('admin_menu', function () {
    add_options_page('MWF AI Frontend', 'MWF AI Frontend', 'manage_options', 'mwf-ai-frontend', 'mwf_f_settings_page');
});

add_action('admin_init', function () {
    register_setting('mwf_ai_frontend_group', MWF_F_OPT, 'mwf_f_sanitize');
});

function mwf_f_sanitize($in) {
    $out = array();
    foreach (array('backend_base', 'default_paywall_id', 'button_position') as $f) {
        $out[$f] = isset($in[$f]) ? trim(wp_unslash($in[$f])) : '';
    }
    if (!in_array($out['button_position'], array('bottom-right', 'bottom-center', 'bottom-left'), true)) {
        $out['button_position'] = 'bottom-right';
    }
    return $out;
}

function mwf_f_settings_page() {
    if (!current_user_can('manage_options')) return;
    $g = function ($k, $d = '') { return esc_attr(mwf_f_opt($k, $d)); };
    ?>
    <div class="wrap">
      <h1>MWF AI Frontend 设置</h1>
      <form method="post" action="options.php">
        <?php settings_fields('mwf_ai_frontend_group'); ?>
        <table class="form-table">
          <tr><th colspan="2"><h2>后端连接</h2></th></tr>
          <tr><th>站点 Base URL</th><td>
            <input type="text" class="regular-text" name="mwf_ai_frontend_options[backend_base]" value="<?php echo $g('backend_base'); ?>" placeholder="留空=本站(同站推荐留空)">
            <p class="description">前端通过 <code>?rest_route=/mwf-ai/v1/...</code> 调后端。同站部署留空即可(自动用本站地址)。</p>
          </td></tr>

          <tr><th colspan="2"><h2>付费墙</h2></th></tr>
          <tr><th>默认 Paywall 短代码 ID</th><td>
            <input type="text" class="regular-text" name="mwf_ai_frontend_options[default_paywall_id]" value="<?php echo $g('default_paywall_id'); ?>" placeholder="Coinsnap Paywall 短代码的 ID">
            <p class="description">[mwf_gallery] 未指定 paywall 时使用。对应 Coinsnap Bitcoin Paywall 后台 “Paywall Shortcodes” 里某条的 ID。</p>
          </td></tr>

          <tr><th colspan="2"><h2>界面</h2></th></tr>
          <tr><th>浮动按钮位置</th><td>
            <select name="mwf_ai_frontend_options[button_position]">
              <?php foreach (array('bottom-right'=>'右下角','bottom-center'=>'底部居中','bottom-left'=>'左下角') as $k=>$label): ?>
                <option value="<?php echo $k; ?>" <?php selected(mwf_f_opt('button_position','bottom-right'), $k); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </td></tr>

          <tr><th colspan="2"><h2>支持语言</h2></th></tr>
          <tr><th>已内置语言</th><td>
            <p class="description">Hy-MT2 官方 38 种语言已全部内置,无需配置。翻译时传英文全名给后端。</p>
            <p style="max-width:680px;line-height:1.8"><?php
              $names = array_map(function($l){ return esc_html($l[2]); }, mwf_f_languages());
              echo implode(' · ', $names);
            ?></p>
          </td></tr>
        </table>
        <?php submit_button(); ?>
      </form>

      <hr>
      <h2>用法</h2>
      <p>搜索页(可嵌首页):放短代码 <code>[mwf_search]</code> —— 只显示图片,点击进入图集内页。</p>
      <p>图集内页(放在图集 post 正文):放短代码 <code>[mwf_gallery]</code> 和 Coinsnap 付费短代码 <code>[paywall_payment id="X"]</code>(或在 [mwf_gallery] 用 paywall 属性指定)。</p>
    </div>
    <?php
}

/* ============================================================
 * 公共助手
 * ============================================================ */

/** 后端 REST 入口(同站默认本站;走 ?rest_route= 入口避免 /wp-json/ 404) */
function mwf_f_backend_url($path) {
    $base = mwf_f_opt('backend_base', '');
    if ($base === '') $base = home_url();
    return rtrim($base, '/') . '/?rest_route=/mwf-ai/v1/' . ltrim($path, '/');
}

/**
 * 读 Coinsnap Bitcoin Paywall 的访问表,判断当前 session 是否已为该 post 付费。
 * 解耦:只读它的表结构 wp_coinsnap_paywall_access(post_id, session_id, access_expires)。
 * 它在 init 时已 session_start();此处用同一 session_id。
 */
/** 登录用户已永久解锁的 post 列表(user_meta) */
function mwf_f_user_paid_posts($user_id) {
    $v = get_user_meta($user_id, '_mwf_paid_posts', true);
    return is_array($v) ? array_map('intval', $v) : array();
}

/** 给登录用户写入一条永久解锁记录(按 post) */
function mwf_f_grant_permanent($user_id, $post_id) {
    $list = mwf_f_user_paid_posts($user_id);
    $post_id = (int) $post_id;
    if (!in_array($post_id, $list, true)) {
        $list[] = $post_id;
        update_user_meta($user_id, '_mwf_paid_posts', $list);
    }
}

/** 查 Coinsnap session 表:当前 session 是否对该 post 有未过期访问 */
function mwf_f_coinsnap_session_paid($post_id) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start(); // 正常 Coinsnap 已开 session;兜底
    }
    $sid = session_id();
    if (!$sid) return false;

    global $wpdb;
    $table = $wpdb->prefix . 'coinsnap_paywall_access';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) return false; // 未装/未激活 paywall

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT 1 FROM {$table} WHERE post_id = %d AND session_id = %s AND access_expires > NOW() LIMIT 1",
        $post_id, $sid
    ));
    return $row !== null;
}

/**
 * 判断当前访客是否已为该 post 付费(可见 prompt)。
 *  - 管理/作者:预览视为已解锁
 *  - 登录用户:先查永久记录(user_meta);若无但 Coinsnap session 显示已付 → 升级为永久(绑账号,换设备/清缓存都在)
 *  - 匿名用户:仅靠 Coinsnap session(随缘,session 活多久算多久)
 */
function mwf_f_is_paid($post_id) {
    $post_id = (int) $post_id;

    if (is_user_logged_in() && current_user_can('edit_posts')) return true; // 预览

    if (is_user_logged_in()) {
        $uid = get_current_user_id();
        // 1) 永久记录
        if (in_array($post_id, mwf_f_user_paid_posts($uid), true)) return true;
        // 2) 刚通过 Coinsnap 付费 → 升级为账号永久记录
        if (mwf_f_coinsnap_session_paid($post_id)) {
            mwf_f_grant_permanent($uid, $post_id);
            return true;
        }
        return false;
    }

    // 匿名:随缘
    return mwf_f_coinsnap_session_paid($post_id);
}

/** 取某 post 下的图片(已发布父级才会有,沿用后端同样的 post_parent 关联) */
function mwf_f_post_images($post_id) {
    return get_posts(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_parent'    => $post_id,
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order ID',
        'order'          => 'ASC',
    ));
}

/* ============================================================
 * [mwf_search] 搜索页(独立,可嵌首页)
 *   - 搜索框 → 调后端 /search
 *   - 结果:瀑布流,只显示图片(无 prompt)
 *   - 点图 → 跳 post_url(= 图集permalink#img-{id})
 * ============================================================ */
/**
 * 强制加载 Coinsnap 付费墙前端资源。
 * Coinsnap 只在正文含 [paywall_payment] 时才 enqueue 它的 paywall.js/css;我们的支付
 * 按钮是 [mwf_gallery] 渲染时动态注入的,正文里没有该短代码 → Coinsnap 不加载脚本 →
 * "Pay Now" 按钮失效。这里在含 [mwf_gallery] 的单页上按同样方式补齐资源(仅需 ajax_url)。
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) return;
    $post = get_post();
    if (!$post || !has_shortcode($post->post_content, 'mwf_gallery')) return;
    if (!defined('COINSNAP_PAYWALL_VERSION')) return; // Coinsnap 未激活则跳过
    $base = plugins_url('coinsnap-paywall');
    $ver  = COINSNAP_PAYWALL_VERSION;
    wp_enqueue_style('coinsnap-paywall-paywall', $base . '/assets/css/paywall.css', array(), $ver);
    wp_enqueue_script('coinsnap-paywall-paywall', $base . '/assets/js/paywall.js', array('jquery'), $ver, true);
    wp_localize_script('coinsnap-paywall-paywall', 'coinsnap_paywall_ajax', array('ajax_url' => admin_url('admin-ajax.php')));
}, 20);

add_shortcode('mwf_search', function ($atts) {
    $atts = shortcode_atts(array(
        'placeholder' => 'Search images…',
        'limit'       => 30,
    ), $atts, 'mwf_search');

    $search_url = esc_js(mwf_f_backend_url('search'));
    $ph    = esc_attr($atts['placeholder']);
    $limit = (int) $atts['limit'];
    $uid   = 'mwfs_' . wp_rand(1000, 9999);

    ob_start(); ?>
    <div class="mwf-search" id="<?php echo esc_attr($uid); ?>">
      <form class="mwf-search-form" onsubmit="return false;">
        <input type="search" class="mwf-search-input" placeholder="<?php echo $ph; ?>" autocomplete="off">
        <button type="submit" class="mwf-search-btn">Search</button>
      </form>
      <div class="mwf-search-status" aria-live="polite"></div>
      <div class="mwf-masonry"></div>
    </div>
    <script>
    (function(){
      var root = document.getElementById('<?php echo esc_js($uid); ?>');
      var input = root.querySelector('.mwf-search-input');
      var btn   = root.querySelector('.mwf-search-btn');
      var status= root.querySelector('.mwf-search-status');
      var grid  = root.querySelector('.mwf-masonry');
      var SEARCH_URL = '<?php echo $search_url; ?>';
      var LIMIT = <?php echo $limit; ?>;
      var busy = false;

      function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

      function run(){
        var q = (input.value||'').trim();
        if(!q){ status.textContent=''; grid.innerHTML=''; return; }
        if(busy) return; busy=true;
        status.textContent='Searching…'; grid.innerHTML='';
        fetch(SEARCH_URL, {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ q:q, limit:LIMIT })
        }).then(function(r){ return r.json(); }).then(function(data){
          busy=false;
          var items = (data && data.items) ? data.items : [];
          if(!items.length){ status.textContent='No results.'; return; }
          status.textContent = items.length + ' result' + (items.length>1?'s':'');
          var html = items.map(function(it){
            var img = esc(it.img||'');
            var url = esc(it.post_url||'#');
            if(!img) return '';
            return '<a class="mwf-cell" href="'+url+'">'+
                     '<img loading="lazy" src="'+img+'" alt="">'+
                   '</a>';
          }).join('');
          grid.innerHTML = html;
        }).catch(function(e){
          busy=false; status.textContent='Search failed. Please try again.';
        });
      }
      btn.addEventListener('click', run);
      input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); run(); }});
    })();
    </script>
    <?php
    return ob_get_clean();
});

/* ============================================================
 * [mwf_gallery] 图集内页(放图集 post 正文)
 *   每张图:图片(免费)+ prompt 区(id="img-{id}",付费控制)
 *   浮动按钮:未付费=Coinsnap 支付;已付费=语言选择+翻译
 * ============================================================ */
add_shortcode('mwf_gallery', function ($atts) {
    $atts = shortcode_atts(array(
        'paywall'  => '',   // Coinsnap paywall 短代码 ID;留空用设置里的默认
        'post_id'  => 0,    // 默认当前 post
    ), $atts, 'mwf_gallery');

    $post_id = (int) $atts['post_id'] ?: get_the_ID();
    if (!$post_id) return '';

    $paywall_id = $atts['paywall'] !== '' ? $atts['paywall'] : mwf_f_opt('default_paywall_id', '');
    $paid   = mwf_f_is_paid($post_id);
    $images = mwf_f_post_images($post_id);
    if (!$images) return '';

    $pos = mwf_f_opt('button_position', 'bottom-right');
    $translate_url = esc_js(mwf_f_backend_url('translate'));
    $uid = 'mwfg_' . wp_rand(1000, 9999);

    // 语言列表给 JS
    $langs = array();
    foreach (mwf_f_languages() as $l) {
        $langs[] = array('code' => $l[0], 'name' => $l[1], 'label' => $l[2]);
    }

    ob_start(); ?>
    <div class="mwf-gallery <?php echo $paid ? 'is-paid' : 'is-locked'; ?>" id="<?php echo esc_attr($uid); ?>" data-post-id="<?php echo esc_attr($post_id); ?>">
      <?php foreach ($images as $img):
        $iid = $img->ID;
        $src = wp_get_attachment_image_url($iid, 'large');
        if (!$src) continue;
        $prompt = trim((string) $img->post_content); // description = prompt
      ?>
        <figure class="mwf-item">
          <img class="mwf-item-img" id="img-<?php echo esc_attr($iid); ?>" loading="lazy"
               src="<?php echo esc_url($src); ?>" alt="">
          <figcaption class="mwf-prompt" data-img-id="<?php echo esc_attr($iid); ?>">
            <?php if ($paid): ?>
              <span class="mwf-prompt-text" data-original="<?php echo esc_attr($prompt); ?>"><?php echo esc_html($prompt); ?></span>
            <?php else: ?>
              <span class="mwf-prompt-locked">This is paid content</span>
            <?php endif; ?>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>

    <div class="mwf-float mwf-float-<?php echo esc_attr($pos); ?>">
      <?php if ($paid): ?>
        <div class="mwf-translate-box" id="<?php echo esc_attr($uid); ?>_tx">
          <select class="mwf-lang-select"></select>
          <button type="button" class="mwf-translate-btn">Translate</button>
        </div>
      <?php else: ?>
        <?php
        // Coinsnap 支付按钮;付款后用户刷新页面即变为已付费状态
        if ($paywall_id !== '') {
            echo do_shortcode('[paywall_payment id="' . esc_attr($paywall_id) . '"]');
        } else {
            echo '<div class="mwf-paywall-missing">Paywall not configured.</div>';
        }
        ?>
      <?php endif; ?>
    </div>

    <?php if ($paid): ?>
    <script>
    (function(){
      var root = document.getElementById('<?php echo esc_js($uid); ?>');
      var box  = document.getElementById('<?php echo esc_js($uid); ?>_tx');
      var sel  = box.querySelector('.mwf-lang-select');
      var btn  = box.querySelector('.mwf-translate-btn');
      var POST_ID = root.getAttribute('data-post-id');
      var TRANSLATE_URL = '<?php echo $translate_url; ?>';
      var LANGS = <?php echo wp_json_encode($langs); ?>;
      var busy = false;

      // 填充语言;默认选浏览器语言(匹配不到→English)
      var nav = (navigator.language || 'en');
      var navLow = nav.toLowerCase();
      var defIdx = 0;
      LANGS.forEach(function(l, i){
        var o = document.createElement('option');
        o.value = l.name;            // 传给后端 = 英文全名
        o.textContent = l.label;
        o.setAttribute('data-code', l.code);
        sel.appendChild(o);
        var c = l.code.toLowerCase();
        if (c === navLow || navLow.indexOf(c) === 0 || c.indexOf(navLow.split('-')[0]) === 0) {
          if (l.name === 'English') { /* keep as fallback unless better match */ }
        }
      });
      // 更精确的默认匹配:先精确,再前缀
      (function(){
        var exact=-1, prefix=-1;
        for (var i=0;i<LANGS.length;i++){
          var c = LANGS[i].code.toLowerCase();
          if (c === navLow) { exact = i; break; }
          if (prefix<0 && (navLow.split('-')[0] === c.split('-')[0])) prefix = i;
        }
        defIdx = exact>=0 ? exact : (prefix>=0 ? prefix : 0);
        sel.selectedIndex = defIdx;
      })();

      function setText(map){
        // map: { imgId: translated }
        root.querySelectorAll('.mwf-prompt').forEach(function(fc){
          var id = fc.getAttribute('data-img-id');
          var span = fc.querySelector('.mwf-prompt-text');
          if (!span) return;
          if (map && map[id] != null) {
            span.textContent = map[id];
          }
        });
      }
      function restoreOriginal(){
        root.querySelectorAll('.mwf-prompt-text').forEach(function(span){
          span.textContent = span.getAttribute('data-original') || '';
        });
      }

      btn.addEventListener('click', function(){
        if (busy) return;
        var lang = sel.value;
        if (!lang) return;
        busy = true;
        var old = btn.textContent; btn.textContent = 'Translating…'; btn.disabled = true;
        fetch(TRANSLATE_URL, {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ post_id: POST_ID, lang: lang })
        }).then(function(r){ return r.json(); }).then(function(data){
          busy=false; btn.textContent = old; btn.disabled=false;
          // 后端返回 { results: [ {id, text, empty?, error?} ] }
          var map = {};
          if (data && data.results) {
            data.results.forEach(function(it){
              if (!it || it.id == null) return;
              if (it.empty || it.error) return;        // 空 prompt / 出错:保持原文
              if (typeof it.text === 'string') map[String(it.id)] = it.text;
            });
          }
          setText(map);
        }).catch(function(){
          busy=false; btn.textContent = old; btn.disabled=false;
        });
      });
    })();
    </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
});

/* ============================================================
 * 前端样式(瀑布流 + 内页 + 浮动按钮)
 * ============================================================ */
add_action('wp_enqueue_scripts', function () {
    $css = '
    /* 搜索瀑布流 */
    .mwf-search-form{display:flex;gap:8px;margin:0 0 14px}
    .mwf-search-input{flex:1;padding:10px 14px;border:1px solid #d0d0d5;border-radius:10px;font-size:15px}
    .mwf-search-btn,.mwf-translate-btn{padding:10px 18px;border:0;border-radius:10px;background:#111;color:#fff;font-size:14px;cursor:pointer}
    .mwf-search-btn:hover,.mwf-translate-btn:hover{opacity:.88}
    .mwf-search-status{color:#666;font-size:13px;margin:0 0 10px;min-height:18px}
    .mwf-masonry{column-gap:12px;column-count:2}
    @media(min-width:640px){.mwf-masonry{column-count:3}}
    @media(min-width:1024px){.mwf-masonry{column-count:4}}
    .mwf-cell{display:block;break-inside:avoid;margin:0 0 12px;border-radius:12px;overflow:hidden;background:#f3f3f5}
    .mwf-cell img{display:block;width:100%;height:auto}

    /* 内页 */
    .mwf-gallery{display:grid;grid-template-columns:1fr;gap:22px;margin:0 0 90px}
    @media(min-width:760px){.mwf-gallery{grid-template-columns:1fr 1fr}}
    .mwf-item{margin:0}
    .mwf-item-img{display:block;width:100%;height:auto;border-radius:12px;background:#f3f3f5}
    .mwf-prompt{margin:8px 2px 0;font-size:14px;line-height:1.6;color:#222}
    .mwf-prompt-locked{display:inline-block;color:#9a6a00;background:#fff6e0;border:1px solid #ffe2a8;
      padding:6px 12px;border-radius:8px;font-size:13px}

    /* 浮动按钮 */
    .mwf-float{position:fixed;z-index:9999;bottom:20px}
    .mwf-float-bottom-right{right:20px}
    .mwf-float-bottom-left{left:20px}
    .mwf-float-bottom-center{left:50%;transform:translateX(-50%)}
    .mwf-translate-box{display:flex;gap:8px;align-items:center;background:#fff;
      padding:8px;border:1px solid #e2e2e8;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.14)}
    .mwf-lang-select{padding:9px 12px;border:1px solid #d0d0d5;border-radius:9px;font-size:14px;max-width:170px}
    .mwf-paywall-missing{background:#fff;border:1px solid #e2e2e8;border-radius:12px;padding:10px 14px;color:#b00;font-size:13px}
    ';
    wp_register_style('mwf-ai-frontend', false);
    wp_enqueue_style('mwf-ai-frontend');
    wp_add_inline_style('mwf-ai-frontend', $css);
});

/* ============================================================
 * 订阅者(Subscriber)体验优化
 *   - 隐藏前台顶部 admin bar(无管理能力,保持画廊页干净)
 *   - 登录后跳前台首页(不进 wp-admin)
 * 仅影响"无后台编辑权限"的用户(订阅者);编辑/管理员不受影响。
 * ============================================================ */
add_action('after_setup_theme', function () {
    if (is_user_logged_in() && !current_user_can('edit_posts')) {
        show_admin_bar(false);
    }
});

add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if ($user instanceof WP_User && in_array('subscriber', (array) $user->roles, true)) {
        return home_url('/');
    }
    return $redirect_to;
}, 10, 3);
