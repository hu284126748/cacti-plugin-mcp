<?php
if (getenv('MCP_TEST_DISPOSABLE')!=='yes') exit("Disposable test database only\n");
require __DIR__.'/../plugins/mcp/lib/Common.php';
require __DIR__.'/../plugins/mcp/lib/Repository.php';
$c=CactiMcp\loadConfig();
$readDb=new CactiMcp\Db($c['database']);
$admin=new PDO('mysql:unix_socket='.getenv('MCP_TEST_DB_SOCKET').';dbname=cacti_mcp_test','root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
require $c['cacti_root'].'/lib/auth.php';
$_SESSION=array('sess_user_id'=>1);
function db_fetch_assoc($sql,$log=true) { global $readDb; return $readDb->all($sql); }
function db_fetch_row($sql,$log=true) { global $readDb; return $readDb->row($sql); }
$old=$admin->query('SELECT policy_graphs,policy_hosts,policy_graph_templates FROM user_auth WHERE id=1')->fetch(PDO::FETCH_NUM);
$perms=$admin->query('SELECT user_id,item_id,type FROM user_auth_perms WHERE user_id=1')->fetchAll(PDO::FETCH_NUM);
$count=0; $stricter=0;
try {
    for ($policies=0;$policies<8;$policies++) {
        $p=array(1+($policies&1),1+(($policies>>1)&1),1+(($policies>>2)&1));
        $stmt=$admin->prepare('UPDATE user_auth SET policy_graphs=?,policy_hosts=?,policy_graph_templates=? WHERE id=1'); $stmt->execute($p);
        for ($bits=0;$bits<8;$bits++) {
            $admin->exec('DELETE FROM user_auth_perms WHERE user_id=1');
            foreach (array(array(100,1),array(2,3),array(2,4)) as $i=>$perm) if ($bits & (1<<$i)) $admin->exec('INSERT INTO user_auth_perms VALUES (1,'.$perm[0].','.$perm[1].')');
            $repo=new CactiMcp\Repository($readDb,1);
            $graph=array('id'=>100,'host_id'=>2,'graph_template_id'=>2);
            $ours=$repo->graphAllowed($graph);
            $legacy=is_graph_allowed(100);
            if ($ours && !$legacy) throw new Exception('MCP widened legacy access');
            if (!$ours && $legacy) $stricter++;
            $count++;
        }
    }
    $admin->exec("UPDATE user_auth SET enabled='' WHERE id=1");
    $denied=false; try {new CactiMcp\Repository($readDb,1);}catch(CactiMcp\Fault $e){$denied=true;}
    if (!$denied) throw new Exception('Disabled user allowed');
    $admin->exec("UPDATE user_auth SET enabled='on' WHERE id=1");
    $admin->exec("UPDATE plugin_config SET status=4 WHERE directory='mcp'");
    $denied=false; try {new CactiMcp\Repository($readDb,1);}catch(CactiMcp\Fault $e){$denied=true;}
    if (!$denied) throw new Exception('Disabled plugin allowed');
} finally {
    $admin->prepare("UPDATE user_auth SET enabled='on',policy_graphs=?,policy_hosts=?,policy_graph_templates=? WHERE id=1")->execute($old);
    $admin->exec('DELETE FROM user_auth_perms WHERE user_id=1');
    $stmt=$admin->prepare('INSERT INTO user_auth_perms VALUES (?,?,?)');
    foreach ($perms as $p) $stmt->execute($p);
    $admin->exec("UPDATE plugin_config SET status=1 WHERE directory='mcp'");
}
echo "PASS ".$count." permission combinations never widen original Cacti access; ".$stricter." are intentionally stricter.\n";
echo "PASS disabled users and disabled plugin fail closed.\n";
