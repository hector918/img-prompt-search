<?php
/**
 * Plugin Name: MWF AI Timer
 * Description: 纯触发器 —— 每 2 分钟经 WP-Cron 触发一次 do_action('mwf_ai_process_drain'),让后端插件推进反推/索引两队列。本插件不懂任何业务:只负责"敲钟"。停用即停;要换成独立容器/宿主机 cron 时,停用本插件、让外部改敲同一 action 或 /process 端点即可。
 * Version: 0.1
 * Author: hector
 *
 * 注意:WP-Cron 靠访问流量触发。没流量时这一拍会拖到下个访客;要准时,在宿主机加一条
 * 每 2 分钟的 crontab:  curl -s https://hygpo.com/wp-cron.php >/dev/null
 * 作为心跳(或 DISABLE_WP_CRON + 外部戳)。心跳只需叫醒 wp-cron,不需要懂端点。
 */

if (!defined('ABSPATH')) exit;

define('MWF_TIMER_HOOK', 'mwf_ai_timer_tick');
define('MWF_TIMER_SCHEDULE', 'mwf_2min');

/** 自定义 2 分钟周期(WP 默认只有 hourly/twicedaily/daily) */
add_filter('cron_schedules', function ($s) {
    $s[MWF_TIMER_SCHEDULE] = array('interval' => 120, 'display' => 'Every 2 Minutes (MWF)');
    return $s;
});

/** 幂等排程:只要插件在跑(init 每次都跑),没排过就排一个循环事件。
 *  这样即使是"直接写文件 + 加进 active_plugins"激活(未触发 activation 钩子)也能生效。 */
add_action('init', function () {
    if (!wp_next_scheduled(MWF_TIMER_HOOK)) {
        wp_schedule_event(time() + 60, MWF_TIMER_SCHEDULE, MWF_TIMER_HOOK);
    }
});

/** 每一拍:只做一件事 —— 通知后端排空两队列。不碰队列细节。 */
add_action(MWF_TIMER_HOOK, function () {
    do_action('mwf_ai_process_drain');
});

/** 停用时清掉排程,不留孤儿事件 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(MWF_TIMER_HOOK);
});
