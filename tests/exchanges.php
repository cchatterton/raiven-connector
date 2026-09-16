<?php
/** Run with wp eval-file only in the disposable test database. */
if (DB_NAME !== 'raiven_release_test') { throw new RuntimeException('Use disposable test database.'); }
$GLOBALS['passed'] = 0;
function log_assert($condition, $label) { global $passed; if (!$condition) { throw new RuntimeException($label); } echo "PASS: $label\n"; $passed++; }
wp_set_current_user(1);
as329_rai_exchange_install();
global $wpdb;
$table = as329_rai_exchange_table();
$wpdb->query("DELETE FROM $table");
update_option(AS329_RAI_NATIVE_KEY_OPTION, 'credential-redaction-test');
update_option(AS329_RAI_OPTION, array('api_base_url'=>AS329_RAI_DEFAULT_BASE_URL,'model'=>'gpt-4','temperature'=>.7,'max_tokens'=>2048,'memory_enabled'=>0));
$mode = 'ok';
add_filter('pre_http_request', function($pre,$args,$url) use (&$mode) {
    if ($mode === 'network') { return new WP_Error('timeout','Secret credential-redaction-test'); }
    $body = str_ends_with($url,'models') ? array('data'=>array(array('id'=>'test-model'))) : array('id'=>'log-test','model'=>'test-model','choices'=>array(array('message'=>array('role'=>'assistant','content'=>'<script>alert(1)</script> credential-redaction-test'),'finish_reason'=>'stop')),'usage'=>array('prompt_tokens'=>1,'completion_tokens'=>2,'total_tokens'=>3));
    if ($mode === 'http') { $body = array('error'=>'credential-redaction-test','api_key'=>'should-not-be-stored'); }
    return array('headers'=>array(), 'response'=>array('code'=>$mode === 'http' ? 429 : 200,'message'=>'Test'),'body'=>$mode === 'invalid' ? 'not json' : wp_json_encode($body));
},10,3);
$count = function() use ($wpdb,$table) { return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table"); };
as329_rai_fetch_models('',true);
log_assert($count()===1,'model discovery logged');
as329_rai_fetch_models();
log_assert($count()===1,'cached lookup does not duplicate an exchange');
as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Hello credential-redaction-test')));
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert($count()===2 && $row->status==='success' && $row->http_code==='200' && $row->method==='POST','console success logged once');
log_assert(str_contains($row->request_body,'Hello') && str_contains($row->response_body,'choices') && $row->user_id==='1','request, response and actor stored');
log_assert(!str_contains($row->request_body.$row->response_body,'credential-redaction-test'),'actual API credential redacted from both bodies');
$registry=\WordPress\AiClient\AiClient::defaultRegistry();
$registry->setProviderRequestAuthentication('raiven',new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('credential-redaction-test'));
$before=$count();
$text=wp_ai_client_prompt('Native Hello')->using_model_preference(array('raiven','test-model'))->generate_text();
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert(!is_wp_error($text) && $count()===$before+1 && $row->source==='native-ai-client' && $row->model==='test-model','native generation logged exactly once');
log_assert(!str_contains($row->response_body,'credential-redaction-test'),'native authentication key redacted');
$mode='http'; as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Rate limit')));
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert($row->status==='error' && $row->http_code==='429' && !str_contains($row->response_body,'should-not-be-stored'),'HTTP error and structured secret redaction');
$mode='network'; as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Timeout')));
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert($row->status==='error' && $row->http_code==='0' && $row->error_message==='Network request failed.','direct network failure logged');
$before=$count();
wp_ai_client_prompt('Native timeout')->using_model_preference(array('raiven','test-model'))->generate_text();
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert($count()===$before+1 && $row->source==='native-ai-client' && $row->status==='error','native network exception logged once');
$mode='invalid'; as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Bad JSON')));
$row=$wpdb->get_row("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
log_assert($row->error_message==='Invalid JSON response.','invalid response logged');
$pending=as329_rai_exchange_start('console','POST',AS329_RAI_DEFAULT_BASE_URL.'/chat/completions?api_key=hidden',array('nested'=>array('password'=>'hidden'),'messages'=>array()));
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$pending['id']));
log_assert($row->status==='pending' && !str_contains($row->endpoint.$row->request_body,'hidden'),'pending requests captured; query and nested credentials excluded');
log_assert(str_ends_with(as329_rai_exchange_body(str_repeat('x',2*MB_IN_BYTES+1)),'[Truncated at 2 MiB]'),'oversized body has explicit truncation marker');
$old=as329_rai_exchange_start('console','GET',AS329_RAI_DEFAULT_BASE_URL.'/models',null);
$wpdb->update($table,array('created_at'=>gmdate('Y-m-d H:i:s',time()-11*DAY_IN_SECONDS)),array('id'=>$old['id']));
as329_rai_exchange_purge();
log_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$old['id'])) && $count()>0,'retention removes old rows and preserves recent ones');
log_assert((bool)wp_next_scheduled('as329_rai_purge_exchanges'),'automatic cleanup scheduled');
$_GET=array('exchange'=>$pending['id']); ob_start(); as329_rai_exchange_page(); $html=ob_get_clean();
log_assert(str_contains($html,'Exchange #'.$pending['id']) && str_contains($html,'Last 10 days'),'details and retention UI rendered');
$success_id=$wpdb->get_var("SELECT id FROM $table WHERE status='success' AND method='POST' LIMIT 1");
$_GET=array('exchange'=>$success_id); ob_start(); as329_rai_exchange_page(); $html=ob_get_clean();
log_assert(!str_contains($html,'<script>alert(1)</script>') && str_contains($html,'&lt;script&gt;'),'untrusted response escaped in log page');
$editor=username_exists('raiven_editor_test'); wp_set_current_user($editor);
add_filter('wp_die_handler',function(){return function(){throw new RuntimeException('denied');};});
$denied=false; try{ as329_rai_exchange_page(); }catch(RuntimeException $e){$denied=$e->getMessage()==='denied';}
log_assert($denied,'non-admin cannot view logs');
wp_set_current_user(1);
$mode='ok';
$wpdb->query("RENAME TABLE $table TO {$table}_test_backup");
try {
    ob_start();
    $reply=as329_rai_direct_chat_completion(array(array('role'=>'user','content'=>'Storage failure')));
    $output=ob_get_clean();
    log_assert(!is_wp_error($reply) && $output==='' && get_option('as329_rai_exchange_storage_error'), 'database logging failure leaves generation intact and sets warning');
} finally {
    $wpdb->query("RENAME TABLE {$table}_test_backup TO $table");
    delete_option('as329_rai_exchange_storage_error');
}
for($i=0;$i<26;$i++){ as329_rai_exchange_start('console','POST',AS329_RAI_DEFAULT_BASE_URL.'/chat/completions',array('messages'=>array())); }
$_GET=array('log_page'=>2); ob_start(); as329_rai_exchange_page(); $html=ob_get_clean();
log_assert(str_contains($html,'Page 2 of 2') && str_contains($html,'Previous'), 'older recent entries accessible through pagination');
echo "Completed " . $GLOBALS['passed'] . " exchange log checks.\n";
