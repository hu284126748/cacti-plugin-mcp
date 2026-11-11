<?php
// Destructive only to the named disposable test database; never use a production DSN.
if (getenv('MCP_TEST_DISPOSABLE') !== 'yes') { fwrite(STDERR,"Set MCP_TEST_DISPOSABLE=yes for a disposable database only\n"); exit(1); }
$root=getenv('CACTI_SOURCE');
$runtime=getenv('MCP_TEST_RUNTIME');
$socket=getenv('MCP_TEST_DB_SOCKET');
if (!$root || !$runtime || !$socket) throw new Exception('Missing test environment');
if (!is_dir($runtime)) mkdir($runtime,0700,true);
if (!is_dir($runtime.'/rrd')) mkdir($runtime.'/rrd',0700);
if (!is_dir($runtime.'/locks')) mkdir($runtime.'/locks',0700);
$db=new PDO('mysql:unix_socket='.$socket.';charset=utf8','root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec('DROP DATABASE IF EXISTS cacti_mcp_test');
$db->exec('CREATE DATABASE cacti_mcp_test CHARACTER SET utf8');
$db->exec('USE cacti_mcp_test');
$db->exec("SET SESSION sql_mode=''");
$sql=str_replace('  rows int', '  `rows` int', file_get_contents($root.'/cacti.sql'));
foreach (preg_split('/;[ \t]*\r?\n/', $sql) as $statement) {
    if (trim(preg_replace('/^--.*$/m', '', $statement)) !== '') $db->exec($statement);
}
$db->exec("CREATE USER IF NOT EXISTS 'mcp_test_reader'@'localhost' IDENTIFIED BY ''");
$db->exec("GRANT SELECT ON cacti_mcp_test.* TO 'mcp_test_reader'@'localhost'");
$db->exec("INSERT INTO plugin_config (directory,name,status) VALUES ('mcp','MCP',1)");
$db->exec("INSERT INTO plugin_realms (plugin,file,display) VALUES ('mcp','endpoint.php','MCP')");
$realm=$db->lastInsertId()+100;
$db->exec("UPDATE user_auth SET enabled='on' WHERE id=1");
$db->exec("INSERT IGNORE INTO user_auth_realm VALUES (7,1),(".$realm.",1)");
$db->exec("INSERT INTO host (id,description,hostname) VALUES (2,'Synthetic Router','test.invalid'),(3,'Denied Router','denied.invalid')");
$db->exec("INSERT INTO graph_tree (id,name) VALUES (2,'Lab'),(3,'Hidden tree')");
function keyFor($p) { return str_pad($p,90,'0'); }
$stmt=$db->prepare('INSERT INTO graph_tree_items (id,graph_tree_id,title,host_id,local_graph_id,order_key) VALUES (?,?,?,?,?,?)');
foreach (array(
    array(100,2,'Room',0,0,'010'),
    array(101,2,'External',0,0,'010001'),
    array(102,2,'',2,0,'010001001'),
    array(103,2,'',0,100,'010001002'),
    array(104,2,'Room',0,0,'011'),
    array(105,2,'',3,0,'011001'),
    array(106,3,'Secret',0,0,'001')
) as $r) { $r[5]=keyFor($r[5]); $stmt->execute($r); }
$db->exec('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES (1,103,1),(1,3,3),(1,3,2)');
function cloneRow($db,$table,$where,$replace) {
    $row=$db->query('SELECT * FROM '.$table.' WHERE '.$where.' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Missing template '.$table);
    unset($row['id']);
    $row=array_merge($row,$replace);
    $stmt=$db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
    $stmt->execute(array_values($row));
    return $db->lastInsertId();
}
$rrd=getenv('RRDTOOL_BINARY');
$base=(int)(floor(time()/300)*300)-14400;
foreach (array(100=>array(2,'1'),101=>array(2,'2'),102=>array(2,'3'),103=>array(2,'4'),105=>array(3,'1')) as $gid=>$identity) {
    $host=$identity[0]; $index=$identity[1]; $did=$gid; $path=$runtime.'/rrd/interface-'.$gid.'.rrd';
    $db->exec("INSERT INTO graph_local (id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES ($gid,2,$host,1,'$index')");
    cloneRow($db,'graph_templates_graph','local_graph_id=0 AND graph_template_id=2',array('local_graph_id'=>$gid,'title'=>'Interface '.$gid,'title_cache'=>'Interface '.$gid,'image_format_id'=>1));
    $db->exec("INSERT INTO data_local (id,data_template_id,host_id,snmp_query_id,snmp_index) VALUES ($did,41,$host,1,'$index')");
    $dtd=cloneRow($db,'data_template_data','local_data_id=0 AND data_template_id=41',array('local_data_id'=>$did,'data_source_path'=>$path,'rrd_step'=>300));
    $in=cloneRow($db,'data_template_rrd','id=54',array('local_data_id'=>$did,'local_data_template_rrd_id'=>54));
    $out=cloneRow($db,'data_template_rrd','id=55',array('local_data_id'=>$did,'local_data_template_rrd_id'=>55));
    foreach ($db->query('SELECT id,task_item_id FROM graph_templates_item WHERE local_graph_id=0 AND graph_template_id=2')->fetchAll(PDO::FETCH_ASSOC) as $item) {
        cloneRow($db,'graph_templates_item','id='.$item['id'],array('local_graph_id'=>$gid,'task_item_id'=>$item['task_item_id']==54 ? $in : $out));
    }
    $db->exec("INSERT INTO data_template_data_rra (data_template_data_id,rra_id) VALUES ($dtd,1),($dtd,2),($dtd,3),($dtd,4)");
    foreach (array('ifName'=>'eth'.$index,'ifDescr'=>'Synthetic interface '.$index,'ifAlias'=>'lab only','ifSpeed'=>'1000000000') as $field=>$value) {
        $s=$db->prepare('INSERT INTO host_snmp_cache (host_id,snmp_query_id,snmp_index,field_name,field_value,oid) VALUES (?,1,?,?,?,?)');
        $s->execute(array($host,$index,$field,$value,'test'));
    }
    if (is_file($path)) unlink($path);
    $cmd=escapeshellarg($rrd).' create '.escapeshellarg($path).' --start '.($base-300).' --step 300 DS:traffic_in:COUNTER:600:0:U DS:traffic_out:COUNTER:600:0:U RRA:AVERAGE:0.5:1:600 RRA:MAX:0.5:1:600';
    exec($cmd,$unused,$code); if ($code) throw new Exception('rrd create failed');
    $updates=array(); $ci=0; $co=0;
    for ($i=0;$i<48;$i++) {
        $ci+=125000*300;
        $rate=$gid===100 ? ($i===24 ? 1000000 : 250000) : ($gid===101 ? ($i===24 ? 750000 : 125000) : 62500);
        $co+=$rate*300;
        $updates[]=($base+$i*300).':'.($gid===102 && $i>=20 && $i<=22 ? 'U:U' : $ci.':'.$co);
    }
    exec(escapeshellarg($rrd).' update '.escapeshellarg($path).' '.implode(' ',$updates),$unused,$code);
    if ($code) throw new Exception('rrd update failed');
}
$db->exec("INSERT INTO graph_local (id,graph_template_id,host_id,snmp_query_id,snmp_index) VALUES (104,2,2,1,'1')");
cloneRow($db,'graph_templates_graph','local_graph_id=100',array('local_graph_id'=>104,'title_cache'=>'Duplicate view'));
foreach ($db->query('SELECT id FROM graph_templates_item WHERE local_graph_id=100')->fetchAll(PDO::FETCH_ASSOC) as $item) cloneRow($db,'graph_templates_item','id='.$item['id'],array('local_graph_id'=>104));
$token=bin2hex(file_get_contents('/dev/urandom',false,null,0,32));
$config=array('enabled'=>true,'allowed_hosts'=>array('127.0.0.1:18765'),'allowed_origins'=>array(),'allow_plain_http'=>true,'tokens'=>array(array('sha256'=>hash('sha256',$token),'user_id'=>1,'expires_at'=>time()+86400)),'database'=>array('dsn'=>'mysql:unix_socket='.$socket.';dbname=cacti_mcp_test;charset=utf8','username'=>'mcp_test_reader','password'=>''),'database_name'=>'cacti_mcp_test','cacti_root'=>$root,'rrd_root'=>$runtime.'/rrd','rrdtool_binary'=>$rrd,'rrdtool_version'=>'rrd-1.4.x','legacy_php_binary'=>getenv('LEGACY_PHP_BINARY'),'timeout_binary'=>'/usr/bin/timeout','runtime_dir'=>$runtime.'/locks');
file_put_contents($runtime.'/config.php',"<?php\nreturn ".var_export($config,true).";\n");
chmod($runtime.'/config.php',0600);
file_put_contents($runtime.'/client.json',json_encode(array('token'=>$token,'start'=>$base+300,'end'=>$base+47*300,'peak_time'=>$base+24*300)));
chmod($runtime.'/client.json',0600);
echo "Seeded disposable Cacti schema, five synthetic RRDs and restrictive test principal.\n";
