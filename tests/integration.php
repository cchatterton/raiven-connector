<?php
/** Run only in a disposable WordPress install: wp eval-file tests/integration.php */
if (DB_NAME !== 'raiven_release_test') { throw new RuntimeException('Use the disposable raiven_release_test database.'); }
$GLOBALS['passed'] = 0;
function rai_assert($value, $label) {
    global $passed;
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
    $passed++;
    echo "PASS: $label\n";
}
wp_set_current_user(1);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_as329_rai_models_%' OR option_name LIKE '_transient_timeout_as329_rai_models_%'");
wp_cache_flush();
$mode = 'ok'; $requests = array();
add_filter('pre_http_request', function ($pre, $args, $url) use (&$mode, &$requests) {
    $requests[] = array('url' => $url, 'args' => $args);
    $body = array('data' => array(array('id' => 'test-model')));
    $code = 200;
    if ($mode === 'offline-model') { return array('headers'=>array(), 'response'=>array('code'=>400,'message'=>'Bad Request'), 'body'=>wp_json_encode(array('error'=>true,'message'=>"Model has no available endpoint"))); }
    if (str_contains($url, 'chat/completions')) {
        $body = array('id' => 'test', 'choices' => array(array('message' => array('role' => 'assistant', 'content' => 'Test reply C:\\path'), 'finish_reason' => 'stop')), 'usage' => array('prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7));
    }
    if ($mode === 'failure') { $code = 401; $body = array('error' => 'secret-key-do-not-expose'); }
    if ($mode === 'invalid') { return array('headers' => array(), 'response' => array('code' => 200, 'message' => 'OK'), 'body' => 'invalid secret-key-do-not-expose'); }
    if (str_contains($url, 'update.json')) {
        if ($mode === 'github-failure') { return new WP_Error('http_error', 'mock network failure'); }
        $body = array('version' => $mode === 'current' ? AS329_RAI_VERSION : '0.3.0', 'body' => 'Test release');
    } elseif (str_contains($url, 'github.com')) { return new WP_Error('http_error', 'mock network failure'); }
    return array('headers' => array(), 'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Unauthorized'), 'body' => wp_json_encode($body));
}, 10, 3);
update_option(AS329_RAI_NATIVE_KEY_OPTION, 'test-key-a');
update_option(AS329_RAI_OPTION, array('model' => 'test-model', 'api_base_url' => AS329_RAI_DEFAULT_BASE_URL, 'temperature' => .7, 'max_tokens' => 2048, 'memory_enabled' => 0));
$registry = \WordPress\AiClient\AiClient::defaultRegistry();
rai_assert($registry->hasProvider('raiven'), 'native provider registered');
$registry->setProviderRequestAuthentication('raiven', new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('candidate-key'));
rai_assert($registry->isProviderConfigured('raiven'), 'candidate credentials validated');
rai_assert(end($requests)['args']['headers']['Authorization'] === 'Bearer candidate-key', 'candidate key used instead of stored key');
$count = count($requests);
rai_assert($registry->isProviderConfigured('raiven') && count($requests) === $count, 'model cache avoids duplicate requests');
$text = wp_ai_client_prompt('Test')->using_model_preference(array('raiven','test-model'))->generate_text();
rai_assert(!is_wp_error($text) && $text === 'Test reply C:\\path', 'native WordPress text generation');
rai_assert(end($requests)['args']['redirection'] === 0, 'native requests cannot redirect credentials');
$mode = 'failure';
$registry->setProviderRequestAuthentication('raiven', new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('rejected-key'));
rai_assert(!$registry->isProviderConfigured('raiven'), 'replacement invalid key is rejected');
$failed = as329_rai_direct_chat_completion(array(array('role' => 'user', 'content' => 'Test')));
rai_assert(is_wp_error($failed) && !str_contains($failed->get_error_message(), 'secret-key'), 'API failure is redacted');
$mode = 'offline-model';
$offline = as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Test')));
rai_assert(is_wp_error($offline) && str_contains($offline->get_error_message(), 'no active endpoint'), 'inactive service model gives actionable error');
$mode = 'invalid';
rai_assert(is_wp_error(as329_rai_direct_chat_completion(array(array('role' => 'user','content' => 'Test')))), 'invalid JSON handled');
$mode = 'ok';
$saved_settings = get_option(AS329_RAI_OPTION);
$default_settings = as329_rai_sanitize_settings(array_merge($saved_settings, array('model'=>'')));
rai_assert($default_settings['model'] === '', 'empty model selects service default');
update_option(AS329_RAI_OPTION, $default_settings);
rai_assert(!is_wp_error(as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Test')))), 'default generation succeeds');
rai_assert(!array_key_exists('model', json_decode(end($requests)['args']['body'], true)), 'default request omits model field');
update_option(AS329_RAI_OPTION, $saved_settings);
as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Test')));
rai_assert(!array_key_exists('model', json_decode(end($requests)['args']['body'], true)), 'stale saved model is ignored');
$bad = as329_rai_sanitize_settings(array('api_base_url' => 'http://127.0.0.1', 'model' => array('bad'), 'temperature' => 12, 'max_tokens' => -2));
rai_assert($bad['api_base_url'] === AS329_RAI_DEFAULT_BASE_URL && $bad['model'] === '' && $bad['temperature'] === .7 && $bad['max_tokens'] === 2048, 'invalid settings preserve previous valid values');
rai_assert(!as329_rai_valid_base_url('https://user:pass@example.com/api') && !as329_rai_valid_base_url('https://example.com/api?key=test'), 'URL credentials and query strings rejected');
$before = (int) wp_count_posts(AS329_RAI_POST_TYPE)->private;
$_GET = array('page' => 'as329-rai');
ob_start(); as329_rai_render_admin_page(); $html = ob_get_clean();
rai_assert(!str_contains($html, 'id="as329-rai-model"') && str_contains($html, 'No model setup is needed.'), 'model selector removed');
rai_assert((int) wp_count_posts(AS329_RAI_POST_TYPE)->private === $before, 'opening console never creates sessions');
rai_assert(str_contains($html, 'v' . AS329_RAI_VERSION) && str_contains($html, 'aria-label="Conversation"') && !str_contains($html, 'test-key-a'), 'version, accessible chat and no credentials in HTML');
$session = 0;
$result = as329_rai_process_prompt('Keep C:\\example\\file intact', $session);
rai_assert(!is_wp_error($result) && $session > 0 && count(as329_rai_get_session_messages($session)) === 2, 'chat creates private session and saves both messages');
rai_assert(as329_rai_get_session_messages($session)[0]['content'] === 'Keep C:\\example\\file intact', 'message backslashes survive storage');
$mode = 'failure';
$result = as329_rai_process_prompt('Retry me', $session);
rai_assert(is_wp_error($result) && count(as329_rai_get_session_messages($session)) === 2, 'failed requests do not duplicate saved user prompts');
$mode = 'ok';
$editor = username_exists('raiven_editor_test') ?: wp_insert_user(array('user_login'=>'raiven_editor_test','user_pass'=>wp_generate_password(),'role'=>'editor'));
wp_set_current_user($editor);
rai_assert(!current_user_can('edit_post', $session) && !current_user_can('read_post', $session) && !as329_rai_can_use_session($session), 'editor cannot access private transcripts');
$other = username_exists('raiven_admin_test') ?: wp_insert_user(array('user_login'=>'raiven_admin_test','user_pass'=>wp_generate_password(),'role'=>'administrator'));
wp_set_current_user($other);
rai_assert(!as329_rai_can_use_session($session), 'another administrator cannot continue someone else’s session');
$profile = array('people'=>array('Example'),'business'=>array(),'projects'=>array(),'systems'=>array());
update_post_meta($session, AS329_RAI_META_PROFILE_KEY, wp_json_encode($profile));
rai_assert(as329_rai_find_matching_memory_sessions($profile) === array(), 'memory never retrieves another administrator’s session');
wp_set_current_user(1);
foreach (array('as329_rai_github_latest_release','as329_rai_github_latest_release_error','as329_rai_github_release_backoff') as $cache) { delete_site_transient($cache); }
$updater = new AS329_RAI_GitHub_Updater();
$requests = array();
$update = $updater->add_update_data(new stdClass());
rai_assert(isset($update->response[AS329_RAI_PLUGIN_BASENAME]) && $update->response[AS329_RAI_PLUGIN_BASENAME]->new_version === '0.3.0', 'native WordPress update injected');
rai_assert(count($requests) === 1 && str_contains($requests[0]['url'], 'update.json'), 'manifest-first lookup never calls API on success');
$mode = 'current'; delete_site_transient('as329_rai_github_latest_release');
$update = $updater->add_update_data($update);
rai_assert(!isset($update->response[AS329_RAI_PLUGIN_BASENAME]) && empty($update->no_update), 'equal version removes stale update data');
$mode = 'github-failure'; delete_site_transient('as329_rai_github_latest_release');
$updater->add_update_data(new stdClass());
rai_assert(!get_site_transient('as329_rai_github_latest_release') && get_site_transient('as329_rai_github_release_backoff'), 'failed release lookup cached separately with backoff');
$count = count($requests); $updater->add_update_data(new stdClass());
rai_assert(count($requests) === $count, 'failure backoff prevents repeated network calls');
$links = $updater->plugin_row_meta(array(), AS329_RAI_PLUGIN_BASENAME);
rai_assert(str_contains(implode(' ', $links), 'Check for updates') && str_contains(implode(' ', $links), '_wpnonce'), 'native manual update link has nonce');
echo "Completed " . $GLOBALS['passed'] . " integration checks.\n";
