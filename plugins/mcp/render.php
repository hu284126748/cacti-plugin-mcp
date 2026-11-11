<?php
// CLI-only, isolated bridge to unmodified Cacti 0.8.8a graph rendering.
if (PHP_SAPI !== 'cli') { header('HTTP/1.1 404 Not Found'); exit; }
require __DIR__ . '/lib/Common.php';
require __DIR__ . '/lib/Repository.php';
require __DIR__ . '/lib/Rrd.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set('Asia/Shanghai');
putenv('TZ=Asia/Shanghai');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);
try {
    if (PHP_VERSION_ID < 50600 || PHP_VERSION_ID >= 70000) throw new Exception('Renderer requires PHP 5.6');
    $mcpConfig = \CactiMcp\loadConfig();
    $request = json_decode(stream_get_contents(STDIN, 65536), true);
    if (!is_array($request)) throw new Exception('Invalid render request');
    $mcpDb = new \CactiMcp\Db($mcpConfig['database']);
    $mcpRepo = new \CactiMcp\Repository($mcpDb, (int)$request['user_id']);
    $mcpGraph = $mcpRepo->graph((int)$request['graph_id']);
    $rrd = new \CactiMcp\Rrd($mcpConfig);
    $mcpPaths = array();
    foreach ($mcpRepo->sources($mcpGraph) as $s) $mcpPaths[$s['local_data_id']] = $rrd->path($s);
    if (!$mcpPaths) throw new Exception('No RRD paths');
    $root = realpath($mcpConfig['cacti_root']);
    if (!$root || strpos(file_get_contents($root . '/include/global.php'), '$config["cacti_version"] = "0.8.8a"') === false) throw new Exception('Cacti 0.8.8a source required');
    $config = array('base_path'=>$root, 'library_path'=>$root.'/lib', 'include_path'=>$root.'/include', 'rra_path'=>realpath($mcpConfig['rrd_root']), 'cacti_server_os'=>'unix', 'url_path'=>'/');
    $database_default = $mcpConfig['database_name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database_default)) throw new Exception('Invalid database name');
    $_SESSION = array('sess_user_id'=>$mcpRepo->userId(), 'sess_config_array'=>array(
        'auth_method'=>1, 'path_rrdtool'=>escapeshellarg($mcpConfig['timeout_binary']).' --signal=KILL 15s '.escapeshellarg($mcpConfig['rrdtool_binary']),
        'path_rrdtool_default_font'=>'', 'log_verbosity'=>0, 'rrdtool_version'=>$mcpConfig['rrdtool_version']
    ));
    // Do not load global.php, database.php or plugins.php: no browser sessions or plugin hooks.
    require $root . '/include/global_constants.php';
    require $root . '/include/global_arrays.php';
    require $root . '/include/global_settings.php';
    require $root . '/lib/functions.php';
    require $root . '/lib/variables.php';
    require $root . '/lib/rrd.php';
    $png = rrdtool_function_graph((int)$request['graph_id'], 0, array(
        'graph_start'=>(int)$request['start'], 'graph_end'=>(int)$request['end'],
        'graph_width'=>(int)$request['width'], 'graph_height'=>(int)$request['height']
    ));
    if (substr($png,0,8) !== "\x89PNG\r\n\x1a\n") throw new Exception('Graph rendering did not produce PNG');
    echo $png;
} catch (Exception $e) {
    error_log('Cacti MCP renderer: ' . $e->getMessage());
    exit(1);
}

function db_fetch_assoc($sql, $log = true) {
    global $mcpDb, $mcpPaths;
    $rows = $mcpDb->all($sql);
    foreach ($rows as &$row) {
        if (array_key_exists('image_format_id',$row)) $row['image_format_id']=1;
        if (array_key_exists('data_source_path', $row)) {
            if (!preg_match('/local_data_id\s*=\s*[\x27"]?(\d+)/i', $sql, $m) || !isset($mcpPaths[$m[1]])) throw new Exception('Unapproved RRD path lookup');
            $row['data_source_path'] = $mcpPaths[$m[1]];
        }
    }
    return $rows;
}
function db_fetch_row($sql, $log = true) { $r=db_fetch_assoc($sql,$log); return count($r) ? $r[0] : array(); }
function db_fetch_cell($sql, $log = true) { $r=db_fetch_row($sql,$log); return $r ? reset($r) : ''; }
function db_execute($sql, $log = true) { throw new Exception('Database write blocked'); }
function is_graph_allowed($id) { global $mcpRepo; try { $mcpRepo->graph((int)$id); return true; } catch (Exception $e) { return false; } }
function api_plugin_hook_function($name, $value = null) { return $value; }
function api_plugin_hook($name) {}
