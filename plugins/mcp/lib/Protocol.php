<?php
namespace CactiMcp;

class Protocol {
    const VERSION = '2025-06-18';
    public static function tools() {
        $id=array('type'=>'integer','minimum'=>1,'maximum'=>16777215);
        $time=array('type'=>'integer','minimum'=>1,'description'=>'Unix seconds; interpret user calendar dates in Asia/Shanghai before calling.');
        $page=array('offset'=>array('type'=>'integer','minimum'=>0,'default'=>0),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50));
        $window=array('graph_id'=>$id,'start'=>$time,'end'=>$time,'resolution'=>array('type'=>'integer','minimum'=>1,'maximum'=>86400,'description'=>'Requested seconds per interval; actual RRD step is returned.'));
        $specs=array(
            array('search_tree_nodes','Find authorized trees, headers, hosts and virtual groups by name or path. Return candidates; do not guess between matches.',array_merge(array('query'=>array('type'=>'string','maxLength'=>255)),$page),array()),
            array('list_scope_graphs','List existing graphs under an exact tree scope, including host graphs and deduplication. Use returned traffic_supported to identify supported interfaces.',array_merge(array('tree_id'=>array('type'=>'integer','minimum'=>1,'maximum'=>65535),'node_id'=>array('type'=>'integer','minimum'=>0,'default'=>0),'graph_template_id'=>$id,'query_id'=>array('type'=>'integer','minimum'=>0,'maximum'=>16777215),'snmp_index'=>array('type'=>'string','maxLength'=>255)),$page),array('tree_id')),
            array('get_graph_timeseries','Read standard SNMP interface in/out octet-counter rates in bit/s and statistics. CDEF display transforms are not applied; custom/aggregate graphs return an explicit error. No missing values become zero.',$window,array('graph_id','start','end')),
            array('get_graph_image','Render an existing Cacti graph for a specified time window; returns inline PNG image content.',array('graph_id'=>$id,'start'=>$time,'end'=>$time,'width'=>array('type'=>'integer','minimum'=>200,'maximum'=>1600),'height'=>array('type'=>'integer','minimum'=>100,'maximum'=>800)),array('graph_id','start','end')),
            array('compare_host_interfaces','Compare up to 256 existing authorized interfaces on the reference graph device; deduplicate graphs, rank at reference peak or by average/peak. Does not establish causation.',array_merge($window,array('direction'=>array('type'=>'string','enum'=>array('in','out'),'default'=>'out'),'rank_by'=>array('type'=>'string','enum'=>array('at_reference_peak','average','peak'),'default'=>'at_reference_peak'))),array('graph_id','start','end'))
        );
        $out=array();
        foreach ($specs as $s) $out[]=array('name'=>$s[0],'description'=>$s[1],'inputSchema'=>array('type'=>'object','properties'=>(object)$s[2],'required'=>$s[3],'additionalProperties'=>false),'annotations'=>array('readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false));
        return $out;
    }
    private static function error($id,$code,$message) { return array('jsonrpc'=>'2.0','id'=>$id,'error'=>array('code'=>$code,'message'=>$message)); }
    public static function dispatch($msg, $service) {
        if (!is_object($msg) || !isset($msg->jsonrpc) || $msg->jsonrpc!=='2.0' || !isset($msg->method) || !is_string($msg->method) || (property_exists($msg,'id') && !is_int($msg->id) && !is_string($msg->id))) return self::error(null,-32600,'Invalid Request');
        $id=property_exists($msg,'id') ? $msg->id : null;
        if (property_exists($msg,'params') && !is_object($msg->params)) return $id===null ? null : self::error($id,-32602,'params must be an object');
        $params=isset($msg->params) ? (array)$msg->params : array();
        // Notifications must not invoke tools or cause responses.
        if ($id===null) return null;
        switch ($msg->method) {
        case 'initialize':
            if (!isset($params['protocolVersion']) || !is_string($params['protocolVersion']) || !isset($params['capabilities']) || !is_object($params['capabilities']) || !isset($params['clientInfo']) || !is_object($params['clientInfo'])) return self::error($id,-32602,'Invalid initialize parameters');
            $result=array('protocolVersion'=>self::VERSION,'capabilities'=>array('tools'=>array('listChanged'=>false)),'serverInfo'=>array('name'=>'cacti-mcp','version'=>'0.1.0'),'instructions'=>'Resolve tree candidates before choosing a scope. Use existing graphs only. Traffic rates are bit/s; check missing_ratio and actual step. Never sum arbitrary tree interfaces or infer causation from ranking.');
            break;
        case 'ping': $result=new \stdClass(); break;
        case 'tools/list': $result=array('tools'=>self::tools()); break;
        case 'tools/call':
            if (!isset($params['name']) || !is_string($params['name']) || (array_key_exists('arguments',$params) && !is_object($params['arguments']))) return self::error($id,-32602,'Invalid tool parameters');
            $schema=null; foreach (self::tools() as $t) if ($t['name']===$params['name']) $schema=$t['inputSchema'];
            if (!$schema) return self::error($id,-32602,'Unknown tool');
            $args=isset($params['arguments']) ? (array)$params['arguments'] : array();
            try {
                foreach ($args as $key=>$v) {
                    if (!property_exists($schema['properties'],$key)) throw new Fault('Unknown argument: '.$key);
                    $rule=$schema['properties']->$key;
                    if (($rule['type']==='integer' && !is_int($v)) || ($rule['type']==='string' && !is_string($v))) throw new Fault('Invalid type for '.$key);
                    if (isset($rule['minimum']) && $v<$rule['minimum']) throw new Fault('Invalid '.$key);
                    if (isset($rule['maximum']) && $v>$rule['maximum']) throw new Fault('Invalid '.$key);
                    if (isset($rule['maxLength']) && strlen($v)>$rule['maxLength']) throw new Fault('Invalid '.$key);
                    if (isset($rule['enum']) && !in_array($v,$rule['enum'],true)) throw new Fault('Invalid '.$key);
                }
                foreach ($schema['required'] as $key) if (!array_key_exists($key,$args)) throw new Fault('Missing '.$key);
                $data=$service->call($params['name'],$args);
                $content=array();
                if (isset($data['_image'])) {
                    $content[]=array('type'=>'image','mimeType'=>'image/png','data'=>$data['_image']); unset($data['_image']);
                }
                array_unshift($content,array('type'=>'text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
                $result=array('content'=>$content,'isError'=>false);
            } catch (Fault $e) {
                $result=array('content'=>array(array('type'=>'text','text'=>$e->getMessage())),'isError'=>true);
            }
            break;
        default: return self::error($id,-32601,'Method not found');
        }
        return array('jsonrpc'=>'2.0','id'=>$id,'result'=>$result);
    }
}

class Http {
    public static function authorize($server,$config) {
        $host=isset($server['HTTP_HOST']) ? strtolower($server['HTTP_HOST']) : '';
        if (!in_array($host,array_map('strtolower',$config['allowed_hosts']),true)) throw new Fault('Host is not permitted');
        if (isset($server['HTTP_ORIGIN']) && !in_array($server['HTTP_ORIGIN'],isset($config['allowed_origins']) ? $config['allowed_origins'] : array(),true)) throw new Fault('Origin is not permitted');
        $secure=isset($server['HTTPS']) && $server['HTTPS']!=='' && $server['HTTPS']!=='off';
        if (!$secure && empty($config['allow_plain_http'])) throw new Fault('HTTPS is required');
        $auth=isset($server['HTTP_AUTHORIZATION']) ? $server['HTTP_AUTHORIZATION'] : '';
        if (!preg_match('/^Bearer ([A-Za-z0-9._~-]{32,512})$/D',$auth,$m)) throw new Fault('Bearer authentication is required');
        $hash=hash('sha256',$m[1]);
        foreach ($config['tokens'] as $token) {
            if (!isset($token['sha256'],$token['user_id'],$token['expires_at']) || !is_string($token['sha256'])) continue;
            if (hash_equals($token['sha256'],$hash) && (int)$token['expires_at']>time()) return (int)$token['user_id'];
        }
        throw new Fault('Invalid or expired credential');
    }
}
