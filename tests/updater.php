<?php
/** Actual bundled PUC 5.7, simulated WordPress APIs/HTTP. No live network or installation. */
error_reporting(E_ALL);
set_error_handler(static function($level,$message,$file,$line){$GLOBALS['php_warning']=$level;throw new ErrorException($message,0,$level,$file,$line);});
$plugin_root = is_file(dirname(__DIR__).'/ai-article-assistant.php') ? dirname(__DIR__) : dirname(__DIR__).'/ai-article-assistant';
define('ABSPATH',__DIR__.'/'); define('WP_DEBUG',false); define('AAA_FILE',$plugin_root.'/ai-article-assistant.php');
$filters=$scheduled=$http_calls=$options=array(); $checks=0;
class WP_Error { public function __construct(public $code,public $message){} }
function is_wp_error($x){return $x instanceof WP_Error;}
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function add_filter($tag,$fn,$priority=10,$args=1){$GLOBALS['filters'][$tag][$priority][]=array($fn,$args);}
function add_action($tag,$fn,$priority=10,$args=1){add_filter($tag,$fn,$priority,$args);}
function apply_filters($tag,$value,...$args){$list=$GLOBALS['filters'][$tag]??array();ksort($list);foreach($list as $items)foreach($items as [$fn,$count])$value=$fn(...array_slice(array_merge(array($value),$args),0,$count));return $value;}
function do_action($tag,...$args){$list=$GLOBALS['filters'][$tag]??array();ksort($list);foreach($list as $items)foreach($items as [$fn,$count])$fn(...array_slice($args,0,$count));}
function plugin_basename($file){return 'ai-article-assistant/'.basename($file);}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function wp_next_scheduled($tag){return $GLOBALS['scheduled'][$tag]??false;}
function wp_schedule_event($time,$schedule,$tag){if($GLOBALS['boot_failure']??false)throw new RuntimeException('Simulated scheduler failure');$GLOBALS['scheduled'][$tag]=array($time,$schedule);return true;}
function register_deactivation_hook($file,$fn){add_action('deactivate_'.plugin_basename($file),$fn);}
function trailingslashit($path){return rtrim($path,'/\\').'/';}
function plugins_url($path='',$file=''){return 'https://example.test/wp-content/plugins/ai-article-assistant/'.$path;}
function did_action($tag){return 0;}
function is_admin(){return false;}
function wp_doing_cron(){return false;}
function wp_get_schedules(){return array('twicedaily'=>array('interval'=>43200));}
function current_user_can($cap){return false;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function balanceTags($text,$force=false){return $text;}
function wp_kses($text,$allowed=array(),$protocols=array()){return $text;}
function __($text,$domain=''){return $text;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_remote_retrieve_response_code($response){return $response['code'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function get_available_languages(){return array();}
function get_locale(){return 'en_US';}
function get_site_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_site_option($key,$value){$GLOBALS['options'][$key]=$value;return true;}
function _cleanup_header_comment($text){return trim($text);}
function get_plugin_data($file,$markup=false,$translate=false){$text=file_get_contents($file);$result=array();foreach(array('Name'=>'Plugin Name','Version'=>'Version','Description'=>'Description','PluginURI'=>'Plugin URI','TextDomain'=>'Text Domain','RequiresWP'=>'Requires at least','RequiresPHP'=>'Requires PHP') as $key=>$label)if(preg_match('/^\s*\* '.preg_quote($label,'/').': (.+)$/m',$text,$m))$result[$key]=trim($m[1]);return $result;}
function wp_remote_get($url,$args=array()){
 $GLOBALS['http_calls'][]=array($url,$args);
 if($GLOBALS['network_failure']??false)return new WP_Error('network','Simulated network outage');
 $path=parse_url($url,PHP_URL_PATH);
 if(str_ends_with($path,'/releases/latest'))return array('code'=>200,'body'=>json_encode($GLOBALS['release']));
 if(str_contains($path,'/contents/')){
  $name=basename($path);
  $text=$name==='ai-article-assistant.php'?str_replace('Version: 0.1.0','Version: '.$GLOBALS['remote_version'],file_get_contents(AAA_FILE)):($name==='readme.txt'?"=== AI Article Assistant ===\nRequires at least: 6.3\nRequires PHP: 8.0\nStable tag: ".$GLOBALS['remote_version']."\n\n== Description ==\nArticle drafting.\n\n== Changelog ==\n\n= ".$GLOBALS['remote_version']." =\n* Update test.\n":'');
  return array('code'=>200,'body'=>json_encode(array('encoding'=>'base64','content'=>base64_encode($text))));
 }
 throw new RuntimeException('Unexpected request: '.$url);
}
require $plugin_root.'/includes/class-config.php';
require $plugin_root.'/includes/class-updater.php';
use AAA\Updater;
function release_fixture($version='0.1.1'){
 return (object)array('tag_name'=>'v'.$version,'prerelease'=>false,'draft'=>false,'created_at'=>'2026-10-26T00:00:00Z','body'=>'Validated release notes.','zipball_url'=>'https://api.github.com/repos/cni-works/ai-article-assistant/zipball/v'.$version,'assets'=>array((object)array('name'=>Updater::ASSET,'state'=>'uploaded','size'=>2048,'browser_download_url'=>Updater::REPOSITORY.'/releases/download/v'.$version.'/'.Updater::ASSET,'download_count'=>0)));
}
$release=release_fixture();$remote_version='0.1.1';
$business=array('site'=>array('organization'=>'Example site'),'profile'=>array('enabled'=>'1'),'category'=>array('purpose'=>'Example category'),'article'=>array('id'=>1),'media'=>array('id'=>2));
$options['business-data']=$business;
if(($argv[1]??'')==='preloaded')require_once $plugin_root.'/vendor/plugin-update-checker/plugin-update-checker.php';
if(($argv[1]??'')==='failure')$boot_failure=true;
if(($argv[1]??'')==='conflict'){add_filter('puc_is_slug_in_use-ai-article-assistant',static function(){return 'another-plugin/main.php';});$boot_failure=true;}
Updater::boot();
if($boot_failure??false){check(isset($filters['admin_notices'])&&empty($GLOBALS['php_warning']),'Updater initialization handled without PHP fatal/warning');ob_start();Updater::unavailable_notice();check(ob_get_clean()==='','No updater diagnostic exposed to unauthorized visitor');echo "PASS: 2 updater initialization-failure checks (API doubles)\n";return;}
$property=new ReflectionProperty(Updater::class,'checker');$property->setAccessible(true);$checker=$property->getValue();
check($checker instanceof YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker,'Actual PUC initializes on front-end, not restricted to admin');
check(count($http_calls)===0,'Bootstrap schedules checks without immediate HTTP requests');
check(isset($scheduled['puc_cron_check_updates-ai-article-assistant']),'WordPress cron check registered');
check(!isset($filters['auto_update_plugin']),'Does not force background auto updates');
check($checker->pluginFile==='ai-article-assistant/ai-article-assistant.php','Stable plugin basename');
check(isset($filters['site_transient_update_plugins'],$filters['plugins_api'],$filters['upgrader_source_selection']),'Core update, details, and source-selection hooks installed');
$strategies=apply_filters('puc_vcs_update_detection_strategies-ai-article-assistant',array('latest_release'=>function(){},'latest_tag'=>function(){},'branch'=>function(){}));
check(array_keys($strategies)===array('latest_release'),'No tag or branch fallback');
$count=count($filters);Updater::boot();check(count($filters)===$count,'Boot is idempotent');
$info=$checker->requestInfo();
check($info && $info->version==='0.1.1' && $info->download_url===Updater::REPOSITORY.'/releases/download/v0.1.1/'.Updater::ASSET,'Actual PUC retrieves validated release and exact ZIP');
check(!empty($info->sections['changelog']),'Version details contain changelog');
$update=$checker->requestUpdate();$wp=$update->toWpFormat();
check($wp->new_version==='0.1.1' && $wp->plugin==='ai-article-assistant/ai-article-assistant.php','WordPress-format update targets existing plugin');
foreach($http_calls as [$url,$args]){check(str_starts_with($url,'https://api.github.com/repos/cni-works/ai-article-assistant/'),'HTTP metadata restricted to configured repository');check(empty($args['body'])&&!isset($args['headers']['Authorization']),'No article data or token sent');}
$release=release_fixture('0.1.0');$remote_version='0.1.0';$same=$checker->requestInfo();
check($same && !version_compare($same->version,'0.1.0','>'),'Same installed version is not newer');
$release=release_fixture();$release->prerelease=true;check($checker->requestInfo()===null,'Prerelease not offered');
$release=release_fixture();$release->draft=true;check($checker->requestInfo()===null,'Draft not offered');
$release=release_fixture();$release->tag_name='v0.1.1-rc.1';check($checker->requestInfo()===null,'Prerelease-like tag rejected even without flag');
$release=release_fixture();$release->assets=array();check($checker->requestInfo()===null,'No asset means no source ZIP fallback');
$release=release_fixture();$release->assets[0]->name='source.zip';check($checker->requestInfo()===null,'Wrong ZIP name rejected');
$release=release_fixture();$release->assets[0]->browser_download_url='https://example.test/wrong.zip';check($checker->requestInfo()===null,'Foreign download URL rejected');
$release=release_fixture();$release->assets[0]->size=0;check($checker->requestInfo()===null,'Empty ZIP not offered');
$release=release_fixture();$release->assets[]=clone $release->assets[0];check($checker->requestInfo()===null,'Ambiguous matching assets rejected');
$release=release_fixture();$remote_version='0.1.2';check($checker->requestInfo()===null,'Remote file header mismatch with Release tag rejected');
$release=release_fixture();$remote_version='0.1.1';$network_failure=true;check($checker->requestInfo()===null,'Network failure returns no installable update');unset($network_failure);
check($options['business-data']===$business,'Updater does not alter existing business settings/article/media data in API double');
$other=(object)array('response'=>array('other/other.php'=>(object)array('new_version'=>'99.0.0')));
check($checker->injectUpdate($other)->response['other/other.php']->new_version==='99.0.0','Other plugin update data preserved');
echo "PASS: $checks updater checks (actual PUC 5.7, HTTP/WP doubles; real update/install/rollback unverified)\n";
