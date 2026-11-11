<?php
require __DIR__ . '/lib/Common.php';
require __DIR__ . '/lib/Repository.php';
require __DIR__ . '/lib/Rrd.php';
require __DIR__ . '/lib/Service.php';
require __DIR__ . '/lib/Protocol.php';

ini_set('display_errors','0');
ini_set('memory_limit','128M');
set_time_limit(120);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
function mcp_reply($status,$data) {
    http_response_code($status);
    if ($data !== null) echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    $settings=\CactiMcp\loadConfig();
    try { $uid=\CactiMcp\Http::authorize($_SERVER,$settings); }
    catch (\CactiMcp\Fault $e) {
        $status=in_array($e->getMessage(),array('Host is not permitted','Origin is not permitted','HTTPS is required'),true) ? 403 : 401;
        if ($status===401) header('WWW-Authenticate: Bearer realm="cacti-mcp"');
        mcp_reply($status,array('error'=>$e->getMessage()));
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); mcp_reply(405,array('error'=>'Use POST; this stateless endpoint does not provide an SSE stream')); }
    if (!isset($_SERVER['CONTENT_TYPE']) || strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'])[0]))!=='application/json') mcp_reply(415,array('error'=>'Content-Type must be application/json'));
    $accept=isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    if (strpos($accept,'application/json')===false || strpos($accept,'text/event-stream')===false) mcp_reply(406,array('error'=>'Accept must include application/json and text/event-stream'));
    if (isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']) && $_SERVER['HTTP_MCP_PROTOCOL_VERSION']!==\CactiMcp\Protocol::VERSION) mcp_reply(400,array('error'=>'Unsupported MCP protocol version'));
    $body=file_get_contents('php://input',false,null,0,65537);
    if (strlen($body)>65536) mcp_reply(413,array('error'=>'Request exceeds 64 KiB'));
    $message=json_decode($body);
    if (json_last_error()!==JSON_ERROR_NONE) mcp_reply(400,array('jsonrpc'=>'2.0','id'=>null,'error'=>array('code'=>-32700,'message'=>'Parse error')));
    $db=new \CactiMcp\Db($settings['database']);
    $repo=new \CactiMcp\Repository($db,$uid);
    // Serialize expensive calls per principal and impose a bounded request rate.
    $rateDir=isset($settings['runtime_dir']) ? realpath($settings['runtime_dir']) : false;
    if (!$rateDir || !is_writable($rateDir)) throw new \CactiMcp\Fault('Private writable runtime directory is required');
    $lock=fopen($rateDir.'/user-'.$uid.'.json','c+');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) mcp_reply(429,array('error'=>'Another request is active for this user'));
    $rate=json_decode(stream_get_contents($lock),true);
    $now=time();
    if (!$rate || $now-$rate['start']>=60) $rate=array('start'=>$now,'count'=>0);
    if ($rate['count']>=60) { header('Retry-After: 60'); mcp_reply(429,array('error'=>'Request rate exceeded')); }
    $rate['count']++; rewind($lock); ftruncate($lock,0); fwrite($lock,json_encode($rate)); fflush($lock);
    $start=microtime(true);
    $response=\CactiMcp\Protocol::dispatch($message,new \CactiMcp\Service($repo,new \CactiMcp\Rrd($settings)));
    error_log(json_encode(array('event'=>'cacti_mcp','user_id'=>$uid,'method'=>isset($message->method) && is_string($message->method) ? substr($message->method,0,64) : 'invalid','tool'=>isset($message->params->name) && is_string($message->params->name) ? substr($message->params->name,0,64) : null,'elapsed_ms'=>(int)((microtime(true)-$start)*1000),'error'=>isset($response['error']) || (isset($response['result']) && is_array($response['result']) && !empty($response['result']['isError'])))));
    flock($lock,LOCK_UN); fclose($lock);
    mcp_reply($response===null ? 202 : (isset($response['error']) && $response['error']['code']===-32600 ? 400 : 200),$response);
} catch (\CactiMcp\Fault $e) {
    error_log('Cacti MCP: '.$e->getMessage());
    mcp_reply(503,array('error'=>'MCP service is unavailable; check configuration, permissions and private logs'));
} catch (\Exception $e) {
    error_log('Cacti MCP internal failure: '.get_class($e));
    mcp_reply(500,array('error'=>'Internal server error'));
} catch (\Throwable $e) {
    error_log('Cacti MCP runtime failure: '.get_class($e));
    mcp_reply(500,array('error'=>'Internal server error'));
}
