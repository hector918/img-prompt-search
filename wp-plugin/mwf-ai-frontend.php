<?php
/**
 * Plugin Name: MWF AI Frontend
 * Description: 前端展示插件 —— 搜索页([mwf_search],只显示图片)+ 图集内页([mwf_gallery],图片免费/prompt付费/翻译)+ 免费模式 + 分账流水。配合 MWF AI Backend 使用。
 * Version: 0.3
 * Author: hector
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * 常量与语言表
 * ============================================================ */
define('MWF_F_OPT', 'mwf_ai_frontend_options');
define('MWF_F_DB_VERSION', '1');

/* ============================================================
 * 自有数据表(版本门控,init 时按需建表;不碰 Coinsnap 的表)
 *   wp_mwf_paywall_access:免费模式下匿名访客的解锁记录(仿 Coinsnap 表结构)
 *   wp_mwf_earnings      :解锁流水(分账依据);免费解锁 amount=0,
 *                          以后真付款结算时用 mwf_f_record_earning() 记实际金额即可
 * ============================================================ */
function mwf_f_access_table()   { global $wpdb; return $wpdb->prefix . 'mwf_paywall_access'; }
function mwf_f_earnings_table() { global $wpdb; return $wpdb->prefix . 'mwf_earnings'; }

add_action('init', function () {
    if (get_option('mwf_f_db_version') === MWF_F_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $t1 = mwf_f_access_table();
    $t2 = mwf_f_earnings_table();
    dbDelta("CREATE TABLE {$t1} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        session_id VARCHAR(128) NOT NULL,
        access_expires DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_session (post_id, session_id)
    ) {$charset};");
    dbDelta("CREATE TABLE {$t2} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        paywall_id VARCHAR(64) NOT NULL DEFAULT '',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        source VARCHAR(16) NOT NULL DEFAULT 'free',
        session_id VARCHAR(128) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY author_id (author_id),
        KEY post_id (post_id)
    ) {$charset};");
    update_option('mwf_f_db_version', MWF_F_DB_VERSION);
});

/**
 * 免费模式下提前开 session(匿名解锁靠 session_id 记录)。
 * Coinsnap 激活时它自己在 init 开 session;这里兜底 Coinsnap 停用的情况。
 * 页面渲染阶段再 session_start 会因 headers 已发送而失败,故必须在 init 早期做。
 */
add_action('init', function () {
    if (mwf_f_opt('free_mode', '') !== '1') return;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
}, 1);

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
    $out['free_mode'] = !empty($in['free_mode']) ? '1' : '';
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
            <p class="description">[mwf_gallery] 未指定 paywall 时使用。对应 Coinsnap Bitcoin Paywall 后台 “Paywall Shortcodes” 里某条的 ID。解析优先级:短代码 paywall 属性 &gt; 作者资料页的 Paywall ID &gt; 此默认值。</p>
          </td></tr>
          <tr><th>免费模式(0 元直通)</th><td>
            <label>
              <input type="checkbox" name="mwf_ai_frontend_options[free_mode]" value="1" <?php checked(mwf_f_opt('free_mode', ''), '1'); ?>>
              开启后付费按钮变为"免费解锁",点击直接通过,不走 Coinsnap。
            </label>
            <p class="description">解锁事件照常记入分账流水(金额 0)。以后要收费:取消勾选即恢复 Coinsnap 支付,内容与主题无需任何改动。</p>
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
 * 分账:作者 ↔ Paywall ID 关联(user_meta: mwf_paywall_id)
 *   仅管理员(edit_users)可见可改;图集未显式指定 paywall 时按作者取
 * ============================================================ */
add_action('show_user_profile', 'mwf_f_user_paywall_field');
add_action('edit_user_profile', 'mwf_f_user_paywall_field');
function mwf_f_user_paywall_field($user) {
    if (!current_user_can('edit_users')) return;
    ?>
    <h2>MWF 分账</h2>
    <table class="form-table">
      <tr>
        <th><label for="mwf_paywall_id">Paywall ID</label></th>
        <td>
          <input type="text" class="regular-text" id="mwf_paywall_id" name="mwf_paywall_id"
                 value="<?php echo esc_attr(get_user_meta($user->ID, 'mwf_paywall_id', true)); ?>">
          <p class="description">该作者专属的 Coinsnap Paywall 短代码 ID。其图集未显式指定 paywall 时自动使用;解锁流水按此归属分账。留空则回退到插件设置里的默认 ID。</p>
        </td>
      </tr>
    </table>
    <?php
}
add_action('personal_options_update', 'mwf_f_save_user_paywall_field');
add_action('edit_user_profile_update', 'mwf_f_save_user_paywall_field');
function mwf_f_save_user_paywall_field($user_id) {
    if (!current_user_can('edit_users')) return;
    if (isset($_POST['mwf_paywall_id'])) {
        update_user_meta($user_id, 'mwf_paywall_id', sanitize_text_field(wp_unslash($_POST['mwf_paywall_id'])));
    }
}

/* ============================================================
 * 分账:流水报表(Tools → MWF 分账)
 * ============================================================ */
add_action('admin_menu', function () {
    add_management_page('MWF 分账流水', 'MWF 分账', 'manage_options', 'mwf-earnings', 'mwf_f_earnings_page');
});

function mwf_f_earnings_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = mwf_f_earnings_table();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
    echo '<div class="wrap"><h1>MWF 分账流水</h1>';
    if ($exists !== $t) {
        echo '<p>数据表尚未创建(访问任意前台页面后自动创建)。</p></div>';
        return;
    }
    $sums = $wpdb->get_results(
        "SELECT author_id, paywall_id, COUNT(*) AS unlocks, SUM(amount) AS total
         FROM {$t} GROUP BY author_id, paywall_id ORDER BY total DESC, unlocks DESC"
    );
    echo '<h2>按作者汇总</h2>';
    if (!$sums) {
        echo '<p>暂无解锁记录。</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:760px"><thead><tr>'
           . '<th>作者</th><th>Paywall ID</th><th>解锁次数</th><th>合计金额</th>'
           . '</tr></thead><tbody>';
        foreach ($sums as $r) {
            $u = $r->author_id ? get_userdata($r->author_id) : null;
            $name = $u ? $u->display_name . ' (#' . $r->author_id . ')' : '未知 (#' . (int) $r->author_id . ')';
            echo '<tr><td>' . esc_html($name) . '</td>'
               . '<td>' . esc_html($r->paywall_id !== '' ? $r->paywall_id : '—') . '</td>'
               . '<td>' . (int) $r->unlocks . '</td>'
               . '<td>' . esc_html(number_format((float) $r->total, 2)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    $recent = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 50");
    echo '<h2 style="margin-top:24px">最近 50 条</h2>';
    if (!$recent) {
        echo '<p>暂无记录。</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>'
           . '<th>#</th><th>时间</th><th>图集</th><th>作者</th><th>Paywall ID</th><th>金额</th><th>来源</th><th>解锁用户</th>'
           . '</tr></thead><tbody>';
        foreach ($recent as $r) {
            $title = get_the_title($r->post_id);
            $u = $r->author_id ? get_userdata($r->author_id) : null;
            $buyer = $r->user_id ? get_userdata($r->user_id) : null;
            echo '<tr><td>' . (int) $r->id . '</td>'
               . '<td>' . esc_html($r->created_at) . '</td>'
               . '<td><a href="' . esc_url(get_edit_post_link($r->post_id) ?: '#') . '">'
                     . esc_html($title !== '' ? $title : ('#' . (int) $r->post_id)) . '</a></td>'
               . '<td>' . esc_html($u ? $u->display_name : ('#' . (int) $r->author_id)) . '</td>'
               . '<td>' . esc_html($r->paywall_id !== '' ? $r->paywall_id : '—') . '</td>'
               . '<td>' . esc_html(number_format((float) $r->amount, 2)) . '</td>'
               . '<td>' . esc_html($r->source) . '</td>'
               . '<td>' . esc_html($buyer ? $buyer->display_name : '匿名') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
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

/** 当前 PHP session id;未开则兜底开一次(正常已在 init 开好) */
function mwf_f_session_id() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    return session_id();
}

/** 查自有访问表:当前 session 是否对该 post 有未过期的免费解锁 */
function mwf_f_own_session_paid($post_id) {
    $sid = mwf_f_session_id();
    if (!$sid) return false;
    global $wpdb;
    $t = mwf_f_access_table();
    $row = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t} WHERE post_id = %d AND session_id = %s AND access_expires > %s LIMIT 1",
        (int) $post_id, $sid, current_time('mysql')
    ));
    return $row !== null;
}

/** 给匿名访客的当前 session 写一条解锁记录 */
function mwf_f_own_grant_session($post_id, $ttl_seconds) {
    $sid = mwf_f_session_id();
    if (!$sid) return false;
    global $wpdb;
    return false !== $wpdb->replace(mwf_f_access_table(), array(
        'post_id'        => (int) $post_id,
        'session_id'     => $sid,
        'access_expires' => gmdate('Y-m-d H:i:s', current_time('timestamp') + (int) $ttl_seconds),
    ));
}

/**
 * 解析某图集应使用的 paywall id。
 * 优先级:短代码显式属性 > 图集作者 user_meta(mwf_paywall_id)> 全局默认。
 */
function mwf_f_resolve_paywall_id($post_id, $explicit = '') {
    $explicit = trim((string) $explicit);
    if ($explicit !== '') return $explicit;
    $author = (int) get_post_field('post_author', $post_id);
    if ($author > 0) {
        $pid = trim((string) get_user_meta($author, 'mwf_paywall_id', true));
        if ($pid !== '') return $pid;
    }
    return mwf_f_opt('default_paywall_id', '');
}

/**
 * 记一条解锁流水(分账依据)。author_id / paywall_id 按 post 归属即时解析。
 * 免费模式 $amount=0、$source='free';以后 Coinsnap 结算时以实际金额调用即可。
 */
function mwf_f_record_earning($post_id, $amount = 0, $source = 'free') {
    global $wpdb;
    $post_id = (int) $post_id;
    return false !== $wpdb->insert(mwf_f_earnings_table(), array(
        'post_id'    => $post_id,
        'author_id'  => (int) get_post_field('post_author', $post_id),
        'paywall_id' => mwf_f_resolve_paywall_id($post_id),
        'amount'     => (float) $amount,
        'source'     => (string) $source,
        'session_id' => (string) mwf_f_session_id(),
        'user_id'    => (int) get_current_user_id(),
        'created_at' => current_time('mysql'),
    ));
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
        // 2) 刚通过 Coinsnap 付费 / 匿名时免费解锁过(同一 session)→ 升级为账号永久记录
        if (mwf_f_coinsnap_session_paid($post_id) || mwf_f_own_session_paid($post_id)) {
            mwf_f_grant_permanent($uid, $post_id);
            return true;
        }
        return false;
    }

    // 匿名:随缘(Coinsnap session 或自有免费解锁记录)
    return mwf_f_coinsnap_session_paid($post_id) || mwf_f_own_session_paid($post_id);
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
    if (mwf_f_opt('free_mode', '') === '1') return; // 免费模式不走 Coinsnap,无需其资源
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
 * 免费模式:0 元直通解锁(admin-ajax,登录/匿名均可)
 *   登录用户 → 永久记录(user_meta);匿名 → 自有 session 表(24h)
 *   每次解锁记一条 amount=0 的分账流水
 * ============================================================ */
add_action('wp_ajax_mwf_free_unlock', 'mwf_f_free_unlock_ajax');
add_action('wp_ajax_nopriv_mwf_free_unlock', 'mwf_f_free_unlock_ajax');
function mwf_f_free_unlock_ajax() {
    if (mwf_f_opt('free_mode', '') !== '1') {
        wp_send_json_error(array('message' => 'free mode disabled'), 403);
    }
    if (!check_ajax_referer('mwf_free_unlock', 'nonce', false)) {
        wp_send_json_error(array('message' => 'invalid nonce'), 403);
    }
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $post = $post_id ? get_post($post_id) : null;
    if (!$post || $post->post_status !== 'publish' || !has_shortcode((string) $post->post_content, 'mwf_gallery')) {
        wp_send_json_error(array('message' => 'invalid post'), 400);
    }
    if (mwf_f_is_paid($post_id)) {
        wp_send_json_success(array('unlocked' => true, 'already' => true)); // 已解锁不重复记账
    }
    if (is_user_logged_in()) {
        mwf_f_grant_permanent(get_current_user_id(), $post_id);
    } else {
        if (!mwf_f_own_grant_session($post_id, DAY_IN_SECONDS)) {
            wp_send_json_error(array('message' => 'session unavailable'), 500);
        }
    }
    mwf_f_record_earning($post_id, 0, 'free');
    wp_send_json_success(array('unlocked' => true));
}

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

    $paywall_id = mwf_f_resolve_paywall_id($post_id, $atts['paywall']);
    $free   = mwf_f_opt('free_mode', '') === '1';
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
      <?php elseif ($free): ?>
        <?php
        // 免费模式:同 Coinsnap 卡片的 class 结构,主题的 .mwf-float .paywall 样式直接生效
        ?>
        <div class="paywall light mwf-free-paywall">
          <h2>Unlock prompts</h2>
          <p>Prompts for this gallery are free for a limited time. Unlock instantly below.</p>
          <button type="button" class="paywall-payment-button mwf-free-unlock-btn"
                  data-post="<?php echo esc_attr($post_id); ?>"
                  data-nonce="<?php echo esc_attr(wp_create_nonce('mwf_free_unlock')); ?>">Unlock for free</button>
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

    <?php if (!$paid && $free): ?>
    <script>
    (function(){
      var btn = document.querySelector('#<?php echo esc_js($uid); ?> ~ .mwf-float .mwf-free-unlock-btn')
             || document.querySelector('.mwf-free-unlock-btn');
      if (!btn) return;
      btn.addEventListener('click', function(){
        if (btn.disabled) return;
        btn.disabled = true;
        var old = btn.textContent; btn.textContent = 'Unlocking…';
        var body = new URLSearchParams();
        body.append('action', 'mwf_free_unlock');
        body.append('nonce', btn.getAttribute('data-nonce'));
        body.append('post_id', btn.getAttribute('data-post'));
        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: body.toString()
        }).then(function(r){ return r.json(); }).then(function(j){
          if (j && j.success) { location.reload(); return; }
          btn.disabled = false; btn.textContent = old;
        }).catch(function(){
          btn.disabled = false; btn.textContent = old;
        });
      });
    })();
    </script>
    <?php endif; ?>

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

    /* 免费解锁卡片兜底样式(主题的 .mwf-float .paywall 规则会覆盖) */
    .mwf-free-paywall{background:#fff;border:1px solid #e2e2e8;border-radius:14px;padding:14px 16px;max-width:300px;box-shadow:0 6px 24px rgba(0,0,0,.14)}
    .mwf-free-paywall h2{font-size:16px;margin:0 0 6px}
    .mwf-free-paywall p{font-size:13px;line-height:1.5;margin:0 0 10px;color:#555}
    .mwf-free-unlock-btn{display:block;width:100%;padding:10px 18px;border:0;border-radius:10px;background:#111;color:#fff;font-size:14px;cursor:pointer}
    .mwf-free-unlock-btn:disabled{opacity:.6;cursor:default}
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
