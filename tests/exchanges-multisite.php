<?php
if (DB_NAME !== 'raiven_release_test' || !is_multisite()) { throw new RuntimeException('Use disposable multisite database.'); }
global $wpdb;
$root_table=as329_rai_exchange_table();
$site=get_sites(array('path'=>'/log-test/','number'=>1));
$id=$site ? $site[0]->blog_id : wp_insert_site(array('domain'=>'localhost:10477','path'=>'/log-test/','title'=>'Log isolation test'));
if(is_wp_error($id)){throw new RuntimeException('Site creation failed');}
switch_to_blog($id);
try {
    $entry=as329_rai_exchange_start('native-ai-client','POST',AS329_RAI_DEFAULT_BASE_URL.'/chat/completions',array('messages'=>array('site-two-only')));
    as329_rai_exchange_finish($entry,200,'{"choices":[]}');
    $table=as329_rai_exchange_table();
    if($table===$root_table || !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$entry['id']))){throw new RuntimeException('Site table isolation failed');}
    if(!wp_next_scheduled('as329_rai_purge_exchanges')){throw new RuntimeException('Site cron missing');}
    echo "PASS: site table initialized after switch_to_blog; exchange and cleanup are site-local\n";
} finally {restore_current_blog();}
if($wpdb->get_var("SELECT id FROM $root_table WHERE request_body LIKE '%site-two-only%'")){throw new RuntimeException('Cross-site log leak');}
echo "PASS: main site contains no subsite exchange\n";
