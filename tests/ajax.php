<?php
/** wp eval-file tests/ajax.php in the disposable test database. */
if (DB_NAME !== 'raiven_release_test') { throw new RuntimeException('Use the disposable test database.'); }
define('DOING_AJAX', true);
class Raiven_Test_Json_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler', function () { return function () { throw new Raiven_Test_Json_Exit(); }; });
function rai_ajax_test($user, $post, $expected) {
    wp_set_current_user($user);
    $_POST = $post;
    $_REQUEST = $post;
    ob_start();
    try { as329_rai_ajax_send_prompt(); } catch (Raiven_Test_Json_Exit $exception) {}
    $raw = ob_get_clean();
    $data = json_decode($raw, true);
    if (!is_array($data) || $data['success'] || !str_contains($data['data']['message'], $expected)) {
        throw new RuntimeException('AJAX check failed: ' . $expected . ' ' . $raw);
    }
    echo 'PASS: ' . $expected . "\n";
}
rai_ajax_test(0, array(), 'Only site administrators');
rai_ajax_test(1, array('nonce'=>'invalid'), 'Your session expired');
wp_set_current_user(1);
$nonce = wp_create_nonce('as329_rai_chat_nonce');
rai_ajax_test(1, array('nonce'=>$nonce,'prompt'=>array('invalid')), 'Enter a message');
rai_ajax_test(1, array('nonce'=>$nonce,'prompt'=>str_repeat('a',16001)), 'Enter a message');
rai_ajax_test(1, array('nonce'=>$nonce,'prompt'=>'Test','session_id'=>999999), 'This chat is unavailable');
add_option('as329_rai_busy_1', time(), '', false);
rai_ajax_test(1, array('nonce'=>$nonce,'prompt'=>'Test'), 'A request is already running');
delete_option('as329_rai_busy_1');
// An upstream failure must remain JSON even on hosts that intercept HTTP 5xx.
add_filter('pre_http_request', function () { return new WP_Error('offline', 'mock outage'); });
$s = get_option(AS329_RAI_OPTION); $s['memory_enabled'] = 0; update_option(AS329_RAI_OPTION, $s);
$test_session = as329_rai_create_session();
http_response_code(200);
rai_ajax_test(1, array('nonce'=>$nonce,'prompt'=>'Test','session_id'=>$test_session), 'could not be reached');
if (http_response_code() !== 200) { throw new RuntimeException('Application error must not use HTTP 5xx'); }
wp_delete_post($test_session, true);
echo "Completed 7 AJAX checks, including HTTP 200 for an upstream failure.\n";
