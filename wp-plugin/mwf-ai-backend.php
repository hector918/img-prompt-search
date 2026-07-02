<?php
/**
 * Plugin Name: MWF AI Backend
 * Description: 后端插件(无前端输出):设置页 + 数据层 + REST 端点(process / status / search / translate)+ 裸露检测(prompt 词表扫描 → _mwf_masked)。前端展示由单独的前端插件消费这些端点。
 * Version: 0.2
 *
 * 数据来源(全原生):
 *   图片    = attachment
 *   caption = attachment caption(post_excerpt)
 *   prompt  = attachment description(post_content)
 *   tags    = post_tag 挂在 attachment 上
 *   索引状态= _mwf_embedded meta(0/1,唯一自定义状态)
 */
if (!defined('ABSPATH')) exit;

/* ============================================================
 * 配置:统一从 option 读取,提供默认值
 * ============================================================ */
function mwf_opt($key, $default = '') {
    $o = get_option('mwf_ai_options', array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

function mwf_default_vl_prompt() {
    return "Describe this image as a detailed image-generation prompt. "
         . "Focus on subject, style, lighting, composition and notable details. "
         . "Output only the prompt text, with no preamble or explanation.";
}

function mwf_default_translate_prompt() {
    // Hy-MT2 官方推荐指令;{lang}=目标语言英文全名,{text}=待翻译原文
    return "Translate the following text into {lang}. "
         . "Note that you should only output the translated result without any additional explanation: {text}";
}

/**
 * 裸露检测默认词表(一行一个词/短语,词边界匹配,大小写不敏感)。
 * 口径:仅裸露生殖器 + 女性裸胸;内衣/性感/男性赤膊不算。
 */
function mwf_default_mask_words() {
    return "nude\nnaked\nnudity\ntopless\nbare breasts\nbreasts exposed\nexposed breasts\nbare-breasted\nnipple\nnipples\nareola\ngenital\ngenitals\npenis\nvulva\nvagina\nlabia\nscrotum\ntesticles\npubic\nbottomless\nporn\npornographic\nnsfw\nsex act\nsexual intercourse";
}

/** 豁免短语:扫描前先从文本剔除(如"裸色"系列,避免误伤) */
function mwf_default_mask_exempt() {
    return "nude tones\nnude tone\nnude color\nnude colors\nnude colour\nnude colours\nnude lipstick\nnude shade\nnude shades\nnude palette\nnude makeup";
}

/* ============================================================
 * 让 attachment 支持原生标签(post_tag)
 * ============================================================ */
add_action('init', function () {
    register_taxonomy_for_object_type('post_tag', 'attachment');
});

// 让 media REST 暴露/可写 tags(post_tag),并暴露 _mwf_embedded 状态
add_action('init', function () {
    register_post_meta('attachment', '_mwf_embedded', array(
        'type' => 'integer', 'single' => true, 'default' => 0,
        'show_in_rest' => true,
        'auth_callback' => function () { return current_user_can('edit_posts'); },
    ));
});

/* ============================================================
 * 隐私保护:游离图 / 未发布图集下的图,不对匿名公众暴露
 *  - 措施1:/wp/v2/media 列表 + 单条,匿名只见已发布图集下的图
 *  - 措施2:attachment 页面(?attachment_id=),匿名访问未发布/游离图 → 404
 * 登录用户(含用 Application Password 的 agent)不受限。
 * ============================================================ */

// 措施1a:media 列表查询 — 匿名时加“已发布图集”约束
add_filter('rest_attachment_query', function ($args, $request) {
    if (is_user_logged_in()) return $args;
    $args['mwf_published_parent'] = true;
    return $args;
}, 10, 2);

// 措施1b:media 单条 — 匿名访问未发布/游离图 → 404
add_filter('rest_prepare_attachment', function ($response, $post, $request) {
    if (is_user_logged_in()) return $response;
    if (mwf_parent_is_published($post->ID)) return $response;
    return new WP_REST_Response(
        array('code' => 'rest_post_invalid_id', 'message' => 'Invalid ID.', 'data' => array('status' => 404)),
        404
    );
}, 10, 3);

// 措施2:attachment 页面 — 匿名访问未发布/游离图 → 404
add_action('template_redirect', function () {
    if (!is_attachment() || is_user_logged_in()) return;
    $id = get_queried_object_id();
    if ($id && mwf_parent_is_published($id)) return;
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    $tpl = get_query_template('404');
    if ($tpl) { include $tpl; }
    exit;
});


add_action('admin_menu', function () {
    add_options_page('MWF AI', 'MWF AI', 'manage_options', 'mwf-ai', 'mwf_settings_page');
});

add_action('admin_init', function () {
    register_setting('mwf_ai_group', 'mwf_ai_options', array(
        'sanitize_callback' => 'mwf_sanitize_options',
    ));
});

function mwf_sanitize_options($in) {
    $out = array();
    $fields = array(
        'search_base', 'search_api_key',
        'vl_base', 'vl_api_key', 'vl_path', 'vl_prompt', 'vl_max_tokens',
        'translate_base', 'translate_api_key', 'translate_path', 'translate_prompt', 'translate_max_tokens',
        'mwf_api_key', 'image_size',
        'mask_words', 'mask_exempt',
    );
    foreach ($fields as $f) {
        $out[$f] = isset($in[$f]) ? trim(wp_unslash($in[$f])) : '';
    }
    // 数值/枚举兜底
    $out['vl_max_tokens'] = (string) max(1, (int) ($out['vl_max_tokens'] ?: 512));
    $out['translate_max_tokens'] = (string) max(1, (int) ($out['translate_max_tokens'] ?: 1024));
    if (!in_array($out['image_size'], array('medium_large', 'large', 'full'), true)) {
        $out['image_size'] = 'large';
    }
    return $out;
}

function mwf_settings_page() {
    if (!current_user_can('manage_options')) return;
    $o = get_option('mwf_ai_options', array());
    $g = function ($k, $d = '') use ($o) { return isset($o[$k]) ? esc_attr($o[$k]) : $d; };
    $vl_prompt = isset($o['vl_prompt']) && $o['vl_prompt'] !== '' ? $o['vl_prompt'] : mwf_default_vl_prompt();
    $translate_prompt = isset($o['translate_prompt']) && $o['translate_prompt'] !== '' ? $o['translate_prompt'] : mwf_default_translate_prompt();
    $mask_words  = isset($o['mask_words'])  && $o['mask_words']  !== '' ? $o['mask_words']  : mwf_default_mask_words();
    $mask_exempt = isset($o['mask_exempt']) && $o['mask_exempt'] !== '' ? $o['mask_exempt'] : mwf_default_mask_exempt();
    ?>
    <div class="wrap">
      <h1>MWF AI Suite</h1>
      <form method="post" action="options.php">
        <?php settings_fields('mwf_ai_group'); ?>
        <table class="form-table" role="presentation">

          <tr><th colspan="2"><h2>搜索服务(向量索引 / 搜索)</h2></th></tr>
          <tr><th>Base URL</th><td>
            <input type="text" class="regular-text" id="mwf_search_base" name="mwf_ai_options[search_base]" value="<?php echo $g('search_base'); ?>" placeholder="http://wp-img-prompt-search:8090">
            <button type="button" class="button mwf-test" data-svc="search">测试连接</button>
            <span class="mwf-test-result" data-for="search"></span>
          </td></tr>
          <tr><th>API Key</th><td><input type="text" class="regular-text" id="mwf_search_api_key" name="mwf_ai_options[search_api_key]" value="<?php echo $g('search_api_key'); ?>"></td></tr>

          <tr><th colspan="2"><h2>反推服务(VL,图片→prompt)</h2></th></tr>
          <tr><th>Base URL</th><td>
            <input type="text" class="regular-text" id="mwf_vl_base" name="mwf_ai_options[vl_base]" value="<?php echo $g('vl_base'); ?>" placeholder="http://llama-vl:8085">
            <button type="button" class="button mwf-test" data-svc="vl">测试连接</button>
            <span class="mwf-test-result" data-for="vl"></span>
          </td></tr>
          <tr><th>API Key</th><td><input type="text" class="regular-text" id="mwf_vl_api_key" name="mwf_ai_options[vl_api_key]" value="<?php echo $g('vl_api_key'); ?>"></td></tr>
          <tr><th>Path</th><td><input type="text" class="regular-text" id="mwf_vl_path" name="mwf_ai_options[vl_path]" value="<?php echo $g('vl_path', '/v1/chat/completions'); ?>" placeholder="/v1/chat/completions 或 /vl"></td></tr>
          <tr><th>Max tokens</th><td><input type="number" name="mwf_ai_options[vl_max_tokens]" value="<?php echo $g('vl_max_tokens', '512'); ?>"></td></tr>
          <tr><th>反推指令(可改)</th><td><textarea name="mwf_ai_options[vl_prompt]" rows="5" class="large-text"><?php echo esc_textarea($vl_prompt); ?></textarea>
              <p class="description">发给视觉模型的指令。模型据此把图片反推成 prompt。</p></td></tr>

          <tr><th colspan="2"><h2>翻译服务(llama.cpp,OpenAI chat 兼容)</h2></th></tr>
          <tr><th>Base URL</th><td>
            <input type="text" class="regular-text" id="mwf_translate_base" name="mwf_ai_options[translate_base]" value="<?php echo $g('translate_base'); ?>" placeholder="http://npc:11434">
            <button type="button" class="button mwf-test" data-svc="translate">测试连接</button>
            <span class="mwf-test-result" data-for="translate"></span>
          </td></tr>
          <tr><th>API Key</th><td><input type="text" class="regular-text" id="mwf_translate_api_key" name="mwf_ai_options[translate_api_key]" value="<?php echo $g('translate_api_key'); ?>"></td></tr>
          <tr><th>Path</th><td><input type="text" class="regular-text" id="mwf_translate_path" name="mwf_ai_options[translate_path]" value="<?php echo $g('translate_path', '/translate'); ?>" placeholder="/translate 或 /v1/chat/completions"></td></tr>
          <tr><th>Max tokens</th><td><input type="number" name="mwf_ai_options[translate_max_tokens]" value="<?php echo $g('translate_max_tokens', '1024'); ?>"></td></tr>
          <tr><th>翻译指令(可改)</th><td><textarea name="mwf_ai_options[translate_prompt]" rows="4" class="large-text"><?php echo esc_textarea($translate_prompt); ?></textarea>
              <p class="description">发给翻译模型的指令。必须包含 <code>{lang}</code>(目标语言)和 <code>{text}</code>(待翻译原文)两个占位符。</p></td></tr>

          <tr><th colspan="2"><h2>裸露检测(prompt 词表 → 前端打码)</h2></th></tr>
          <tr><th>命中词表</th><td>
            <textarea name="mwf_ai_options[mask_words]" rows="6" class="large-text"><?php echo esc_textarea($mask_words); ?></textarea>
            <p class="description">一行一个词/短语,词边界匹配、大小写不敏感;prompt 命中任意一条 → 该图标记打码(<code>_mwf_masked=1</code>)。口径:仅裸露生殖器 + 女性裸胸。</p>
          </td></tr>
          <tr><th>豁免短语</th><td>
            <textarea name="mwf_ai_options[mask_exempt]" rows="3" class="large-text"><?php echo esc_textarea($mask_exempt); ?></textarea>
            <p class="description">扫描前先从文本剔除,避免"nude tones(裸色调)"这类误伤。</p>
          </td></tr>
          <tr><th>存量回填</th><td>
            <button type="button" class="button" id="mwf-mask-rescan">全库重扫</button>
            <span id="mwf-mask-rescan-result" style="margin-left:8px"></span>
            <p class="description">按当前词表重扫所有图片的 prompt(手动强制打码/不打码的图不受影响)。改词表后先保存再重扫。</p>
          </td></tr>

          <tr><th colspan="2"><h2>通用</h2></th></tr>
          <tr><th>反推用图片尺寸</th><td>
            <select name="mwf_ai_options[image_size]">
              <?php foreach (array('medium_large'=>'medium_large (768)','large'=>'large (1024)','full'=>'full (原图)') as $k=>$label): ?>
                <option value="<?php echo $k; ?>" <?php selected($g('image_size','large'), $k); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="description">反推时取此尺寸转 base64 喂模型;不存在则回退到最大可用尺寸。</p>
          </td></tr>
          <tr><th>插件端点 API Key</th><td><input type="text" class="regular-text" name="mwf_ai_options[mwf_api_key]" value="<?php echo $g('mwf_api_key'); ?>">
              <p class="description">保护 /process、/status 等端点。留空=不鉴权(仅内网建议)。</p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>

      <script>
      (function(){
        var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce   = <?php echo wp_json_encode(wp_create_nonce('mwf_test_nonce')); ?>;
        var val = function(id){ var e=document.getElementById(id); return e?e.value:''; };
        var fields = {
          search:    {base:'mwf_search_base',    key:'mwf_search_api_key',    path:null},
          vl:        {base:'mwf_vl_base',         key:'mwf_vl_api_key',        path:'mwf_vl_path'},
          translate: {base:'mwf_translate_base',  key:'mwf_translate_api_key', path:'mwf_translate_path'}
        };
        document.querySelectorAll('.mwf-test').forEach(function(btn){
          btn.addEventListener('click', function(){
            var svc = btn.getAttribute('data-svc');
            var out = document.querySelector('.mwf-test-result[data-for="'+svc+'"]');
            var f = fields[svc];
            var base = val(f.base), key = val(f.key);
            var path = f.path ? val(f.path) : '';
            if(!base){ out.style.color='#b32d2e'; out.textContent='请先填 Base URL'; return; }
            out.style.color='#646970'; out.textContent='测试中…';
            var body = new URLSearchParams();
            body.append('action','mwf_test_service');
            body.append('nonce',nonce);
            body.append('svc',svc);
            body.append('base',base);
            body.append('key',key);
            body.append('path',path);
            fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
              .then(function(r){return r.json();})
              .then(function(j){
                if(j && j.success){
                  out.style.color='#1a7f37';
                  out.textContent='✔ 连接成功 (HTTP '+j.data.code+')'+(j.data.body?(' · '+j.data.body):'');
                }else{
                  out.style.color='#b32d2e';
                  out.textContent='✘ '+((j&&j.data&&j.data.message)?j.data.message:'失败');
                }
              })
              .catch(function(e){ out.style.color='#b32d2e'; out.textContent='✘ '+e.message; });
          });
        });

        var rescan = document.getElementById('mwf-mask-rescan');
        if (rescan) rescan.addEventListener('click', function(){
          var out = document.getElementById('mwf-mask-rescan-result');
          out.style.color='#646970'; out.textContent='扫描中…';
          var body = new URLSearchParams();
          body.append('action','mwf_mask_rescan');
          body.append('nonce',nonce);
          fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
            .then(function(r){return r.json();})
            .then(function(j){
              if(j && j.success){ out.style.color='#1a7f37'; out.textContent='✔ 已扫 '+j.data.scanned+' 张,打码 '+j.data.masked+' 张'; }
              else{ out.style.color='#b32d2e'; out.textContent='✘ '+((j&&j.data&&j.data.message)?j.data.message:'失败'); }
            })
            .catch(function(e){ out.style.color='#b32d2e'; out.textContent='✘ '+e.message; });
        });
      })();
      </script>
    </div>
    <?php
}

/* ============================================================
 * AJAX:测试服务连接 — 按服务做真实接口调用,返回实际结果验证
 *   search   : POST {base}/search {query:"test",limit:1} → 看是否返回 ids
 *   vl       : POST {base}{path}  纯文本 chat → 看模型回复(验证 chat 通)
 *   translate: POST {base}{path}  翻译一句 → 看译文
 * 报错原样返回。
 * ============================================================ */
add_action('wp_ajax_mwf_test_service', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '无权限'));
    }
    if (!check_ajax_referer('mwf_test_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'nonce 校验失败'));
    }
    $svc  = isset($_POST['svc']) ? sanitize_text_field(wp_unslash($_POST['svc'])) : '';
    $base = isset($_POST['base']) ? trim(esc_url_raw(wp_unslash($_POST['base']))) : '';
    $key  = isset($_POST['key']) ? trim(wp_unslash($_POST['key'])) : '';
    $path = isset($_POST['path']) ? trim(wp_unslash($_POST['path'])) : '';
    if ($base === '') {
        wp_send_json_error(array('message' => 'Base URL 为空'));
    }
    $base = rtrim($base, '/');
    $headers = array('Content-Type' => 'application/json');
    if ($key !== '') $headers['Authorization'] = 'Bearer ' . $key;

    if ($svc === 'search') {
        $url = $base . '/search';
        $body = wp_json_encode(array('query' => 'connectivity test', 'limit' => 1, 'rerank' => false));
        $resp = wp_remote_post($url, array('headers' => $headers, 'body' => $body, 'timeout' => 20));
        if (is_wp_error($resp)) wp_send_json_error(array('message' => $resp->get_error_message()));
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $j = json_decode($raw, true);
        if ($code === 200 && is_array($j) && array_key_exists('ids', $j)) {
            wp_send_json_success(array('code' => $code, 'body' => '搜索OK,返回 ' . count($j['ids']) . ' 个结果'));
        }
        wp_send_json_error(array('message' => 'HTTP ' . $code . ' · ' . mb_substr($raw, 0, 160)));
    }

    if ($svc === 'vl' || $svc === 'translate') {
        if ($path === '') $path = ($svc === 'vl') ? '/v1/chat/completions' : '/translate';
        $url = $base . $path;
        // 纯文本 chat 测试(不带图,仅验证 chat 接口通)
        $prompt = ($svc === 'vl')
            ? 'Reply with exactly: OK'
            : 'Translate the following text into Chinese. Output only the translation.\n\nhello';
        $body = wp_json_encode(array(
            'messages'   => array(array('role' => 'user', 'content' => $prompt)),
            'max_tokens' => 32,
            'temperature' => 0.1,
        ));
        $resp = wp_remote_post($url, array('headers' => $headers, 'body' => $body, 'timeout' => 40));
        if (is_wp_error($resp)) wp_send_json_error(array('message' => $resp->get_error_message()));
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $j = json_decode($raw, true);
        $content = $j['choices'][0]['message']['content'] ?? '';
        $content = trim((string) $content);
        if ($code === 200 && $content !== '') {
            if (mb_strlen($content) > 80) $content = mb_substr($content, 0, 80) . '…';
            wp_send_json_success(array('code' => $code, 'body' => '模型回复: ' . $content));
        }
        wp_send_json_error(array('message' => 'HTTP ' . $code . ' · ' . mb_substr($raw, 0, 160)));
    }

    // 兜底:未知服务,退回 /health 探测
    $resp = wp_remote_get($base . '/health', array('headers' => $headers, 'timeout' => 10));
    if (is_wp_error($resp)) wp_send_json_error(array('message' => $resp->get_error_message()));
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code >= 200 && $code < 500) wp_send_json_success(array('code' => $code, 'body' => ''));
    wp_send_json_error(array('message' => 'HTTP ' . $code));
});



/* ============================================================
 * 裸露检测:prompt 词表扫描 → _mwf_masked(0/1)
 *   - _mwf_masked_manual('1'/'0')存在时为手动强制,重扫不覆盖
 *   - 触发点:attachment 新增/编辑(含 VL 反推写回)、索引步骤、全库重扫
 * ============================================================ */
function mwf_mask_list($opt_key, $default) {
    $raw = mwf_opt($opt_key, $default);
    $out = array();
    foreach (preg_split('/[\r\n]+/', (string) $raw) as $l) {
        $l = trim(mb_strtolower($l));
        if ($l !== '') $out[] = $l;
    }
    return $out;
}

/** prompt 文本是否命中裸露词表 */
function mwf_scan_prompt_mask($text) {
    $text = mb_strtolower((string) $text);
    if (trim($text) === '') return false;
    foreach (mwf_mask_list('mask_exempt', mwf_default_mask_exempt()) as $ex) {
        $text = str_replace($ex, ' ', $text);
    }
    foreach (mwf_mask_list('mask_words', mwf_default_mask_words()) as $w) {
        $pattern = '/(?<![a-z0-9])' . preg_quote($w, '/') . '(?![a-z0-9])/u';
        if (preg_match($pattern, $text)) return true;
    }
    return false;
}

/** 计算并写入某图的 _mwf_masked;返回最终值(0/1) */
function mwf_apply_mask_scan($id) {
    $id = (int) $id;
    $manual = get_post_meta($id, '_mwf_masked_manual', true);
    if ($manual === '1' || $manual === '0') {
        $m = (int) $manual;
    } else {
        $m = mwf_scan_prompt_mask((string) get_post_field('post_content', $id)) ? 1 : 0;
    }
    update_post_meta($id, '_mwf_masked', $m);
    return $m;
}
add_action('add_attachment', 'mwf_apply_mask_scan');
add_action('edit_attachment', 'mwf_apply_mask_scan'); // 覆盖 VL 反推写回与人工编辑 description

/** 媒体编辑页:打码手动开关(自动/强制打码/强制不打码) */
add_filter('attachment_fields_to_edit', function ($fields, $post) {
    if (!current_user_can('edit_post', $post->ID)) return $fields;
    $manual = (string) get_post_meta($post->ID, '_mwf_masked_manual', true);
    $cur = get_post_meta($post->ID, '_mwf_masked', true) ? '打码' : '不打码';
    $html = '<select name="attachments[' . $post->ID . '][mwf_masked_manual]">';
    foreach (array('' => '自动(词表扫描)', '1' => '强制打码', '0' => '强制不打码') as $v => $label) {
        $html .= '<option value="' . esc_attr($v) . '"' . selected($manual, $v, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select> <span class="description">当前:' . esc_html($cur) . '</span>';
    $fields['mwf_masked_manual'] = array('label' => '裸露打码', 'input' => 'html', 'html' => $html);
    return $fields;
}, 10, 2);

add_filter('attachment_fields_to_save', function ($post, $attachment) {
    if (isset($attachment['mwf_masked_manual'])) {
        $v = (string) $attachment['mwf_masked_manual'];
        if ($v === '1' || $v === '0') {
            update_post_meta($post['ID'], '_mwf_masked_manual', $v);
        } else {
            delete_post_meta($post['ID'], '_mwf_masked_manual');
        }
        mwf_apply_mask_scan($post['ID']);
    }
    return $post;
}, 10, 2);

/** AJAX:全库重扫(设置页按钮;手动强制的图保持不变) */
add_action('wp_ajax_mwf_mask_rescan', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => '无权限'));
    if (!check_ajax_referer('mwf_test_nonce', 'nonce', false)) wp_send_json_error(array('message' => 'nonce 校验失败'));
    $ids = get_posts(array(
        'post_type' => 'attachment', 'post_mime_type' => 'image',
        'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids',
    ));
    $masked = 0;
    foreach ($ids as $id) {
        if (mwf_apply_mask_scan($id)) $masked++;
    }
    wp_send_json_success(array('scanned' => count($ids), 'masked' => $masked));
});

add_action('rest_api_init', function () {
    register_rest_route('mwf-ai/v1', '/process', array(
        'methods' => 'POST',
        'permission_callback' => 'mwf_endpoint_auth',
        'callback' => 'mwf_process_endpoint',
        'args' => array('count' => array('default' => 10)),
    ));
    register_rest_route('mwf-ai/v1', '/status', array(
        'methods' => 'GET',
        'permission_callback' => 'mwf_endpoint_auth',
        'callback' => 'mwf_status_endpoint',
    ));
});

// 端点鉴权:MWF_API_KEY 为空=放行;否则需 Authorization: Bearer <key>
function mwf_endpoint_auth(WP_REST_Request $req) {
    $key = mwf_opt('mwf_api_key', '');
    if ($key === '') return true;
    $auth = $req->get_header('authorization');
    return is_string($auth) && hash_equals('Bearer ' . $key, $auth);
}

/**
 * 取某 attachment 的 caption / prompt(description)/ tags
 */
function mwf_get_image_fields($id) {
    $post = get_post($id);
    $caption = $post ? (string) $post->post_excerpt : '';
    $prompt  = $post ? (string) $post->post_content : '';   // description
    $terms = wp_get_object_terms($id, 'post_tag', array('fields' => 'names'));
    $tags = is_wp_error($terms) ? array() : array_values($terms);
    return array('caption' => $caption, 'prompt' => $prompt, 'tags' => $tags);
}

/**
 * 取反推用的图片尺寸 URL/路径,带回退
 * 返回 [filepath, mime] 或 null
 */
function mwf_get_image_for_vl($id) {
    $size = mwf_opt('image_size', 'large');
    $candidates = array($size, 'large', 'medium_large', 'full');
    foreach ($candidates as $sz) {
        $src = wp_get_attachment_image_src($id, $sz);
        if ($src && !empty($src[0])) {
            // 优先用本地文件路径(省一次 HTTP)
            $path = get_attached_file($id);
            // 对非 full 尺寸,文件名不同;用 image_get_intermediate_size 拿具体文件
            if ($sz !== 'full') {
                $inter = image_get_intermediate_size($id, $sz);
                if ($inter && !empty($inter['path'])) {
                    $updir = wp_get_upload_dir();
                    $path = trailingslashit($updir['basedir']) . $inter['path'];
                }
            }
            if ($path && file_exists($path)) {
                return array('path' => $path, 'mime' => mime_content_type($path) ?: 'image/jpeg');
            }
        }
    }
    return null;
}

/**
 * 调反推服务(llama.cpp VL,OpenAI vision 兼容),返回 prompt 文本或 WP_Error
 */
function mwf_call_vl($id) {
    $base = rtrim(mwf_opt('vl_base', ''), '/');
    if ($base === '') return new WP_Error('vl', 'VL base 未配置');
    $path = mwf_opt('vl_path', '/v1/chat/completions');
    $img = mwf_get_image_for_vl($id);
    if (!$img) return new WP_Error('vl', '找不到图片文件');

    $data = file_get_contents($img['path']);
    if ($data === false) return new WP_Error('vl', '读图失败');
    $b64 = base64_encode($data);
    $dataurl = 'data:' . $img['mime'] . ';base64,' . $b64;

    $body = array(
        'messages' => array(array(
            'role' => 'user',
            'content' => array(
                array('type' => 'text', 'text' => mwf_opt('vl_prompt', mwf_default_vl_prompt())),
                array('type' => 'image_url', 'image_url' => array('url' => $dataurl)),
            ),
        )),
        'max_tokens' => (int) mwf_opt('vl_max_tokens', '512'),
        'temperature' => 0.2,
    );
    $headers = array('Content-Type' => 'application/json');
    $vlkey = mwf_opt('vl_api_key', '');
    if ($vlkey !== '') $headers['Authorization'] = 'Bearer ' . $vlkey;

    $resp = wp_remote_post($base . $path, array(
        'headers' => $headers,
        'body' => wp_json_encode($body),
        'timeout' => 120,
    ));
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return new WP_Error('vl', 'VL HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
    $j = json_decode(wp_remote_retrieve_body($resp), true);
    $text = $j['choices'][0]['message']['content'] ?? '';
    $text = trim((string) $text);
    if ($text === '') return new WP_Error('vl', 'VL 返回空');
    return $text;
}

/**
 * 调搜索服务 /index 建索引
 */
function mwf_call_index($id, $caption, $prompt, $tags) {
    $base = rtrim(mwf_opt('search_base', ''), '/');
    if ($base === '') return new WP_Error('search', 'search base 未配置');
    $headers = array('Content-Type' => 'application/json');
    $skey = mwf_opt('search_api_key', '');
    if ($skey !== '') $headers['Authorization'] = 'Bearer ' . $skey;
    $resp = wp_remote_post($base . '/index', array(
        'headers' => $headers,
        'body' => wp_json_encode(array(
            'id' => (int) $id, 'caption' => $caption, 'prompt' => $prompt, 'tags' => array_values($tags),
        )),
        'timeout' => 60,
    ));
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return new WP_Error('search', 'index HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
    return true;
}

/**
 * 公共:某 attachment 的所属 post(post_parent)是否为已发布。
 * 游离图(parent=0)视为“不可见/不处理”,返回 false。
 */
function mwf_parent_is_published($attachment_id) {
    $parent = (int) get_post_field('post_parent', $attachment_id);
    if ($parent <= 0) return false;                 // 游离图:无归属
    return get_post_status($parent) === 'publish';  // 仅 publish 图集
}

/**
 * WP_Query 子句过滤:当查询带 mwf_published_parent=true 时,
 * 只返回 post_parent 为已发布 post 的 attachment。
 * INNER JOIN 天然排除游离图(parent=0 无法匹配);status 过滤排除 draft 图集。
 */
add_filter('posts_clauses', function ($clauses, $query) {
    if (!$query->get('mwf_published_parent')) return $clauses;
    global $wpdb;
    $clauses['join']  .= " INNER JOIN {$wpdb->posts} mwfparent ON {$wpdb->posts}.post_parent = mwfparent.ID ";
    $clauses['where'] .= " AND mwfparent.post_status = 'publish' ";
    return $clauses;
}, 10, 2);

/**
 * 构造“待处理图片”查询:图片 + 未索引 + 已发布图集下
 */
function mwf_pending_query($limit, $count_only = false) {
    return new WP_Query(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $count_only ? 1 : $limit,
        'orderby'        => 'ID', 'order' => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => $count_only ? false : true,
        'mwf_published_parent' => true,
        'meta_query'     => array(
            'relation' => 'OR',
            array('key' => '_mwf_embedded', 'compare' => 'NOT EXISTS'),
            array('key' => '_mwf_embedded', 'value' => '1', 'compare' => '!='),
        ),
    ));
}

/**
 * 找出"未完成"的图片 ID(仅已发布图集下;游离/draft 跳过)
 */
function mwf_find_pending($limit) {
    return mwf_pending_query($limit, false)->posts;
}

/**
 * process:推进一批图片的状态(一次推一步)
 */
function mwf_process_endpoint(WP_REST_Request $req) {
    $count = max(1, min(100, (int) $req->get_param('count')));
    $ids = mwf_find_pending($count);

    $processed = 0;
    $errors = array();
    foreach ($ids as $id) {
        $f = mwf_get_image_fields($id);

        // 步骤1:没有 prompt(description)→ 反推
        if (trim($f['prompt']) === '') {
            $prompt = mwf_call_vl($id);
            if (is_wp_error($prompt)) { $errors[] = array('id' => $id, 'step' => 'vl', 'msg' => $prompt->get_error_message()); continue; }
            // 写回 description(原生字段)
            wp_update_post(array('ID' => $id, 'post_content' => $prompt));
            $processed++;
            continue; // 一次推一步:本轮只补 prompt,下轮再索引
        }

        // 步骤2:有 prompt,未索引 → 调搜索服务 /index
        $r = mwf_call_index($id, $f['caption'], $f['prompt'], $f['tags']);
        if (is_wp_error($r)) { $errors[] = array('id' => $id, 'step' => 'index', 'msg' => $r->get_error_message()); continue; }
        mwf_apply_mask_scan($id); // 索引前补扫(prompt 早于本插件版本写入的图不走 edit_attachment 钩子)
        update_post_meta($id, '_mwf_embedded', 1);
        $processed++;
    }

    return new WP_REST_Response(array(
        'processed' => $processed,
        'errors' => $errors,
        'pending' => mwf_count_pending(),
    ), 200);
}

function mwf_count_pending() {
    return (int) mwf_pending_query(1, true)->found_posts;
}

function mwf_status_endpoint(WP_REST_Request $req) {
    // total 口径与 pending 一致:只算已发布图集下的图片
    $tq = new WP_Query(array(
        'post_type'      => 'attachment', 'post_status' => 'inherit',
        'post_mime_type' => 'image', 'posts_per_page' => 1, 'fields' => 'ids',
        'mwf_published_parent' => true,
    ));
    $total = (int) $tq->found_posts;
    $pending = mwf_count_pending();
    return new WP_REST_Response(array(
        'total' => $total, 'pending' => $pending, 'done' => $total - $pending,
    ), 200);
}

/* ============================================================
 * REST:/mwf-ai/v1/search(前端搜索)与 /translate(按 post 翻 prompt)
 * ============================================================ */
add_action('rest_api_init', function () {
    // 搜索:公开(搜索服务在内网,前端只触达 WP)
    register_rest_route('mwf-ai/v1', '/search', array(
        'methods'             => array('GET', 'POST'),
        'permission_callback' => '__return_true',
        'callback'            => 'mwf_search_endpoint',
        'args'                => array(
            'q'      => array('required' => true),
            'limit'  => array('default' => 30),
            'tags'   => array('default' => array()),
            'after'  => array('default' => ''),
            'before' => array('default' => ''),
        ),
    ));
    // 翻译:按 post 翻其下所有图的 prompt;鉴权同 process(MWF_API_KEY,默认空放行)
    register_rest_route('mwf-ai/v1', '/translate', array(
        'methods'             => 'POST',
        'permission_callback' => 'mwf_endpoint_auth',
        'callback'            => 'mwf_translate_endpoint',
        'args'                => array(
            'post_id' => array('required' => true),
            'lang'    => array('required' => true),
        ),
    ));
});

/** prompt 在搜索结果里的截断长度(字符) */
function mwf_prompt_excerpt($text, $len = 140) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len) . '…';
}

/** 组装单张图给前端(搜索结果用) */
function mwf_assemble_item($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') return null;

    $size = 'medium_large';
    $src = wp_get_attachment_image_src($id, $size);
    if (!$src) { $src = wp_get_attachment_image_src($id, 'large'); }
    if (!$src) { $src = wp_get_attachment_image_src($id, 'full'); }

    $f = mwf_get_image_fields($id);

    // 所属图集 post:实时查 post_parent(图 id 不变,关联随时最新)
    $parent_id = (int) $post->post_parent;
    $post_url = '';
    if ($parent_id > 0) {
        $permalink = get_permalink($parent_id);
        if ($permalink) $post_url = $permalink . '#img-' . $id;   // 锚点定位到该图
    }

    return array(
        'id'      => (int) $id,
        'img'     => $src ? $src[0] : '',
        'w'       => $src ? (int) $src[1] : 0,
        'h'       => $src ? (int) $src[2] : 0,
        'caption' => $f['caption'],
        'prompt'  => mwf_prompt_excerpt($f['prompt']),   // 搜索结果只给截断
        'tags'    => $f['tags'],
        'masked'  => get_post_meta($id, '_mwf_masked', true) ? 1 : 0,
        'post_id' => $parent_id ?: null,
        'post_url'=> $post_url,
    );
}

/** 搜索:WP 调搜索服务 /search → 拿 id → 组装 */
function mwf_search_endpoint(WP_REST_Request $req) {
    $q = trim((string) $req->get_param('q'));
    if ($q === '') return new WP_Error('search', 'q 为空', array('status' => 400));

    $base = rtrim(mwf_opt('search_base', ''), '/');
    if ($base === '') return new WP_Error('search', 'search base 未配置', array('status' => 500));

    // tags 兼容:数组 或 逗号/空格分隔字符串
    $tags = $req->get_param('tags');
    if (is_string($tags)) {
        $tags = array_filter(array_map('trim', preg_split('/[,\s]+/', $tags)));
    }
    $tags = array_values((array) $tags);

    $payload = array(
        'query' => $q,
        'limit' => max(1, min(100, (int) $req->get_param('limit'))),
        'rerank' => true,
    );
    if (!empty($tags)) $payload['tags'] = $tags;
    $after  = trim((string) $req->get_param('after'));
    $before = trim((string) $req->get_param('before'));
    if ($after !== '')  $payload['after'] = $after;
    if ($before !== '') $payload['before'] = $before;

    $headers = array('Content-Type' => 'application/json');
    $skey = mwf_opt('search_api_key', '');
    if ($skey !== '') $headers['Authorization'] = 'Bearer ' . $skey;

    $resp = wp_remote_post($base . '/search', array(
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
        'timeout' => 30,
    ));
    if (is_wp_error($resp)) return new WP_Error('search', $resp->get_error_message(), array('status' => 502));
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return new WP_Error('search', 'search HTTP ' . $code, array('status' => 502));

    $j = json_decode(wp_remote_retrieve_body($resp), true);
    $ids = isset($j['ids']) && is_array($j['ids']) ? $j['ids'] : array();

    // 按搜索服务返回的顺序组装(保持相关度排序)
    $items = array();
    foreach ($ids as $id) {
        $it = mwf_assemble_item((int) $id);
        if ($it) $items[] = $it;
    }
    return new WP_REST_Response(array('items' => $items, 'count' => count($items)), 200);
}

/** 调翻译服务翻一段文本 */
function mwf_call_translate($text, $lang) {
    $base = rtrim(mwf_opt('translate_base', ''), '/');
    if ($base === '') return new WP_Error('translate', 'translate base 未配置');
    $path = mwf_opt('translate_path', '/translate');

    // 指令模板:替换 {lang} 与 {text}
    $tpl = mwf_opt('translate_prompt', mwf_default_translate_prompt());
    $content = str_replace(array('{lang}', '{text}'), array($lang, $text), $tpl);

    $body = array(
        'messages' => array(
            array('role' => 'user', 'content' => $content),
        ),
        'max_tokens'  => (int) mwf_opt('translate_max_tokens', '1024'),
        'temperature' => 0.2,
    );
    $headers = array('Content-Type' => 'application/json');
    $tkey = mwf_opt('translate_api_key', '');
    if ($tkey !== '') $headers['Authorization'] = 'Bearer ' . $tkey;

    $resp = wp_remote_post($base . $path, array(
        'headers' => $headers,
        'body'    => wp_json_encode($body),
        'timeout' => 60,
    ));
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return new WP_Error('translate', 'translate HTTP ' . $code . ': ' . wp_remote_retrieve_body($resp));
    $j = json_decode(wp_remote_retrieve_body($resp), true);
    $t = $j['choices'][0]['message']['content'] ?? '';
    $t = trim((string) $t);
    if ($t === '') return new WP_Error('translate', '翻译返回空');
    return $t;
}

/** 翻译:翻一个 post 下所有图的 prompt(有缓存跳过),缓存到 _mwf_prompt_i18n */
function mwf_translate_endpoint(WP_REST_Request $req) {
    $post_id = (int) $req->get_param('post_id');
    $lang    = trim((string) $req->get_param('lang'));
    if ($post_id <= 0 || $lang === '') {
        return new WP_Error('translate', 'post_id / lang 必填', array('status' => 400));
    }

    // 找出该 post 下所有图(post_parent = post_id)
    $imgs = get_posts(array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_parent'    => $post_id,
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    $results = array();
    foreach ($imgs as $id) {
        $prompt = (string) get_post($id)->post_content;   // description
        if (trim($prompt) === '') {
            $results[] = array('id' => (int) $id, 'text' => '', 'cached' => false, 'empty' => true);
            continue;
        }
        $i18n = get_post_meta($id, '_mwf_prompt_i18n', true);
        $i18n = is_array($i18n) ? $i18n : array();

        if (isset($i18n[$lang]) && $i18n[$lang] !== '') {
            $results[] = array('id' => (int) $id, 'text' => $i18n[$lang], 'cached' => true);
            continue;
        }
        $t = mwf_call_translate($prompt, $lang);
        if (is_wp_error($t)) {
            $results[] = array('id' => (int) $id, 'error' => $t->get_error_message());
            continue;
        }
        $i18n[$lang] = $t;
        update_post_meta($id, '_mwf_prompt_i18n', $i18n);
        $results[] = array('id' => (int) $id, 'text' => $t, 'cached' => false);
    }

    return new WP_REST_Response(array('lang' => $lang, 'post_id' => $post_id, 'results' => $results), 200);
}
