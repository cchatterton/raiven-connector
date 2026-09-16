<?php
/** Per-site, bounded-retention exchange audit trail for both connector transports. */
if (!defined('ABSPATH')) { exit; }

function as329_rai_exchange_table() { global $wpdb; return $wpdb->prefix . 'as329_rai_exchanges'; }
/** Logging storage must never corrupt an API response with database diagnostics. */
function as329_rai_exchange_storage($callback, $fallback = null) {
    global $wpdb;
    $previous = $wpdb->suppress_errors(true);
    try { return $callback(); }
    catch (Throwable $exception) { update_option('as329_rai_exchange_storage_error', 1, false); return $fallback; }
    finally { $wpdb->suppress_errors($previous); }
}
function as329_rai_exchange_install() {
    return as329_rai_exchange_storage('as329_rai_exchange_install_store');
}
function as329_rai_exchange_install_store() {
    global $wpdb;
    if (get_option('as329_rai_exchange_schema') !== '1') {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = as329_rai_exchange_table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(32) NOT NULL,
            method varchar(10) NOT NULL,
            endpoint text NOT NULL,
            model varchar(200) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            http_code smallint unsigned NOT NULL DEFAULT 0,
            duration_ms bigint unsigned NOT NULL DEFAULT 0,
            request_body longtext NOT NULL,
            response_body longtext NOT NULL,
            error_message text NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) " . $wpdb->get_charset_collate() . ';');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            update_option('as329_rai_exchange_schema', '1', false);
        }
    }
    if (!wp_next_scheduled('as329_rai_purge_exchanges')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'as329_rai_purge_exchanges'); }
}
add_action('init', 'as329_rai_exchange_install', 0);
add_action('as329_rai_purge_exchanges', 'as329_rai_exchange_purge');
function as329_rai_exchange_purge() { return as329_rai_exchange_storage('as329_rai_exchange_purge_store'); }
function as329_rai_exchange_purge_store() {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . as329_rai_exchange_table() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)));
}

/** Headers are never persisted. Redact credential fields and the actual request key in bodies. */
function as329_rai_exchange_body($body, $secrets = array()) {
    if (is_string($body)) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) { $body = $decoded; }
    }
    $clean = function ($value) use (&$clean) {
        if (!is_array($value)) { return $value; }
        foreach ($value as $key => $item) {
            $value[$key] = preg_match('/^(authorization|proxy.authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|cookie|set-cookie)$/i', (string) $key) ? '[REDACTED]' : $clean($item);
        }
        return $value;
    };
    $text = is_array($body) ? wp_json_encode($clean($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : (string) $body;
    foreach ($secrets as $secret) { if (is_string($secret) && $secret !== '') { $text = str_replace($secret, '[REDACTED]', $text); } }
    $text = preg_replace('/Bearer\s+[^\s"<>]+/i', 'Bearer [REDACTED]', $text);
    $limit = 2 * MB_IN_BYTES;
    return strlen($text) > $limit ? wp_check_invalid_utf8(substr($text, 0, $limit), true) . "\n[Truncated at 2 MiB]" : $text;
}
function as329_rai_exchange_start($source, $method, $url, $payload, $secrets = array()) {
    return as329_rai_exchange_storage(function () use ($source, $method, $url, $payload, $secrets) {
        return as329_rai_exchange_start_store($source, $method, $url, $payload, $secrets);
    }, array('id'=>0));
}
function as329_rai_exchange_start_store($source, $method, $url, $payload, $secrets = array()) {
    global $wpdb;
    as329_rai_exchange_install(); // Also handles switch_to_blog() and network activation lazily.
    // Purge on traffic as well as cron, without a DELETE for every request.
    if (!get_transient('as329_rai_exchange_pruned')) {
        as329_rai_exchange_purge(); set_transient('as329_rai_exchange_pruned', 1, HOUR_IN_SECONDS);
    }
    $parts = wp_parse_url($url);
    $endpoint = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    $model = is_array($payload) && is_string($payload['model'] ?? null) ? $payload['model'] : '';
    $ok = $wpdb->insert(as329_rai_exchange_table(), array('created_at'=>gmdate('Y-m-d H:i:s'), 'user_id'=>get_current_user_id(),
        'source'=>$source, 'method'=>$method, 'endpoint'=>as329_rai_exchange_body($endpoint, $secrets), 'model'=>wp_check_invalid_utf8(substr(as329_rai_exchange_body($model, $secrets), 0, 200), true),
        'request_body'=>as329_rai_exchange_body($payload, $secrets), 'response_body'=>'', 'error_message'=>''));
    if (!$ok) { update_option('as329_rai_exchange_storage_error', 1, false); }
    return array('id'=>$ok ? $wpdb->insert_id : 0, 'table'=>as329_rai_exchange_table(), 'start'=>microtime(true), 'secrets'=>$secrets);
}
function as329_rai_exchange_finish($entry, $code, $body, $error = '') {
    return as329_rai_exchange_storage(function () use ($entry, $code, $body, $error) {
        return as329_rai_exchange_finish_store($entry, $code, $body, $error);
    });
}
function as329_rai_exchange_finish_store($entry, $code, $body, $error = '') {
    global $wpdb;
    if (!$entry['id']) { return; }
    $decoded = is_string($body) ? json_decode($body, true) : $body;
    if (!$error && $code >= 200 && $code < 300 && !is_array($decoded)) { $error = 'Invalid JSON response.'; }
    $ok = $wpdb->update($entry['table'], array('status'=>($error || $code < 200 || $code >= 300) ? 'error' : 'success',
        'http_code'=>(int)$code, 'duration_ms'=>(int)round((microtime(true)-$entry['start'])*1000),
        'response_body'=>as329_rai_exchange_body($body, $entry['secrets']), 'error_message'=>as329_rai_exchange_body($error, $entry['secrets'])), array('id'=>$entry['id']));
    if ($ok === false) { update_option('as329_rai_exchange_storage_error', 1, false); }
}

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=' . AS329_RAI_POST_TYPE, 'rAIven Logs', 'Logs', 'manage_options', 'as329-rai-logs', 'as329_rai_exchange_page');
});
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === AS329_RAI_POST_TYPE . '_page_as329-rai-logs') { wp_enqueue_style('as329-rai-console', AS329_RAI_PLUGIN_URL . 'assets/style.css', array(), AS329_RAI_VERSION); }
});
function as329_rai_exchange_page() {
    if (!current_user_can('manage_options')) { wp_die(esc_html__('Only administrators can view rAIven logs.', 'raiven-connector'), '', array('response'=>403)); }
    global $wpdb;
    as329_rai_exchange_install(); as329_rai_exchange_purge();
    $table = as329_rai_exchange_table();
    $cutoff = gmdate('Y-m-d H:i:s', time()-10*DAY_IN_SECONDS);
    $page = max(1, isset($_GET['log_page']) && is_scalar($_GET['log_page']) ? absint($_GET['log_page']) : 1);
    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s", $cutoff));
    $pages = max(1, (int)ceil($total/25)); $page = min($page, $pages);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id,created_at,user_id,source,method,endpoint,model,status,http_code,duration_ms FROM $table WHERE created_at >= %s ORDER BY id DESC LIMIT 25 OFFSET %d", $cutoff, ($page-1)*25));
    $id = isset($_GET['exchange']) && is_scalar($_GET['exchange']) ? absint($_GET['exchange']) : 0;
    $entry = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND created_at >= %s", $id, $cutoff)) : null;
    $base = admin_url('edit.php?post_type=' . AS329_RAI_POST_TYPE . '&page=as329-rai-logs');
    ?>
    <div class="wrap as329-rai-wrap">
        <h1 class="screen-reader-text">rAIven Logs</h1>
        <header class="as329-rai-hero"><span class="as329-rai-version"><?php echo esc_html('v'.AS329_RAI_VERSION); ?></span><p class="as329-rai-eyebrow">ALPHASYS</p><h2>rAIven Logs</h2><p>Every outgoing connector request, including native AI Client calls, model discovery, console chat and memory requests.</p></header>
        <section class="as329-rai-status"><div><strong>Last 10 days · <?php echo esc_html(number_format_i18n($total)); ?> exchanges</strong><p>Times are UTC. Logs contain prompts and responses and are visible to site administrators. Credentials are redacted. Each body is limited to 2 MiB. Cached model lookups make no request and create no exchange.</p><p>Older logs are removed automatically by WordPress scheduled tasks, on connector traffic and when this page opens. Logging starts with this update.</p></div><a class="button" href="<?php echo esc_url($base); ?>">Refresh logs</a></section>
        <?php if (get_option('as329_rai_exchange_storage_error')) : ?><div class="notice notice-error"><p>Some exchanges could not be saved. Check database write access and available storage; the log may be incomplete.</p></div><?php endif; ?>
        <?php if ($id && !$entry) : ?><div class="notice notice-warning"><p>This exchange is unavailable or outside the 10-day retention period.</p></div><?php endif; ?>
        <?php if ($entry) : ?>
        <section class="as329-rai-settings-panel"><h2><?php echo esc_html('Exchange #'.$entry->id); ?></h2><p><?php echo esc_html($entry->method.' '.$entry->endpoint); ?></p>
        <?php if ($entry->error_message) : ?><p class="as329-rai-error-text"><?php echo esc_html($entry->error_message); ?></p><?php endif; ?>
        <h3>Request</h3><pre class="as329-rai-log-body"><?php echo esc_html($entry->request_body ?: '(No body)'); ?></pre>
        <h3>Response</h3><pre class="as329-rai-log-body"><?php echo esc_html($entry->response_body ?: '(No response captured)'); ?></pre></section>
        <?php endif; ?>
        <div class="as329-rai-log-table" tabindex="0" role="region" aria-label="Recent rAIven exchanges">
        <table class="widefat striped"><caption class="screen-reader-text">Connector exchanges in the last 10 days</caption><thead><tr><th scope="col">Time (UTC)</th><th scope="col">Source / user</th><th scope="col">Request / model</th><th scope="col">Result</th><th scope="col">Duration</th><th scope="col">Details</th></tr></thead><tbody>
        <?php if (!$rows) : ?><tr><td colspan="6">No exchanges recorded in the last 10 days. Send a request through the connector to see it here.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row) : ?>
        <tr><td><?php echo esc_html($row->created_at); ?></td><td><?php echo esc_html($row->source); ?><br><?php echo esc_html($row->user_id ? 'User #'.$row->user_id : 'System / background'); ?></td><td><?php echo esc_html($row->method.' '.(wp_parse_url($row->endpoint, PHP_URL_PATH) ?: '/')); ?><br><?php echo esc_html($row->model ?: ($row->method === 'GET' ? 'Model discovery' : 'Service default')); ?></td><td><?php echo esc_html($row->status === 'pending' ? 'Pending / interrupted' : ucfirst($row->status)); ?><?php echo $row->http_code ? esc_html(' · HTTP '.$row->http_code) : ''; ?></td><td><?php echo esc_html(number_format_i18n($row->duration_ms).' ms'); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('exchange'=>$row->id,'log_page'=>$page), $base)); ?>"><?php echo esc_html('View #'.$row->id); ?></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <nav class="as329-rai-log-pages" aria-label="Log pages"><?php if ($page>1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('log_page',$page-1,$base)); ?>">Previous</a><?php endif; ?><span><?php echo esc_html('Page '.$page.' of '.$pages); ?></span><?php if ($page<$pages) : ?><a class="button" href="<?php echo esc_url(add_query_arg('log_page',$page+1,$base)); ?>">Next</a><?php endif; ?></nav>
    </div>
    <?php
}
