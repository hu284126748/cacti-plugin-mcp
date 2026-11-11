<?php
namespace CactiMcp;

class Repository {
    private $db;
    private $user;
    private $perms = array();
    private $nodes;
    public function __construct($db, $userId) {
        $this->db = $db;
        $this->user = $db->row('SELECT id, enabled, policy_graphs, policy_hosts, policy_graph_templates, policy_trees FROM user_auth WHERE id=?', array($userId));
        if (!$this->user || $this->user['enabled'] !== 'on') throw new Fault('User is disabled or unavailable');
        if (!$db->row('SELECT realm_id FROM user_auth_realm WHERE user_id=? AND realm_id=7', array($userId))) throw new Fault('View Graphs permission is required');
        if (!$db->row("SELECT id FROM plugin_config WHERE directory='mcp' AND status=1")) throw new Fault('MCP plugin is not enabled');
        $realm = $db->row("SELECT id FROM plugin_realms WHERE plugin='mcp' AND file='endpoint.php'");
        if (!$realm || !$db->row('SELECT realm_id FROM user_auth_realm WHERE user_id=? AND realm_id=?', array($userId, 100 + $realm['id']))) throw new Fault('MCP realm permission is required');
        foreach ($db->all('SELECT item_id, type FROM user_auth_perms WHERE user_id=?', array($userId)) as $p) {
            $this->perms[$p['type'] . ':' . $p['item_id']] = true;
        }
    }
    public function userId() { return (int)$this->user['id']; }
    public function treeAllowed($id) {
        $hit = isset($this->perms['2:' . $id]);
        return (int)$this->user['policy_trees'] === 1 ? !$hit : ((int)$this->user['policy_trees'] === 2 && $hit);
    }
    public function graphAllowed($g) {
        $allow = false; $needsAllow = false;
        foreach (array(array('policy_graphs',1,'id'), array('policy_hosts',3,'host_id'), array('policy_graph_templates',4,'graph_template_id')) as $rule) {
            $policy = (int)$this->user[$rule[0]];
            $hit = isset($this->perms[$rule[1] . ':' . $g[$rule[2]]]);
            if ($policy === 1 && $hit) return false;
            if ($policy === 2) { $needsAllow = true; if ($hit) $allow = true; }
            if ($policy !== 1 && $policy !== 2) return false;
        }
        // Explicit denials override grants across graph, host and template dimensions.
        return !$needsAllow || $allow;
    }
    public function graph($id) {
        $g = $this->db->row('SELECT g.*, t.title_cache, t.vertical_label FROM graph_local g JOIN graph_templates_graph t ON t.local_graph_id=g.id WHERE g.id=?', array($id));
        if (!$g || !$this->graphAllowed($g)) throw new Fault('Graph is unavailable or not permitted');
        return $g;
    }
    public static function prefix($key) {
        if (!preg_match('/^[0-9]{3,90}$/', $key) || strlen($key) % 3) throw new Fault('Invalid tree order key');
        $n = (int)ceil(strlen(rtrim($key, '0')) / 3);
        return substr($key, 0, $n * 3);
    }
    public function nodes() {
        if ($this->nodes !== null) return $this->nodes;
        $rows = $this->db->all('SELECT i.*, t.name AS tree_name, h.description AS host_name FROM graph_tree_items i JOIN graph_tree t ON t.id=i.graph_tree_id LEFT JOIN host h ON h.id=i.host_id ORDER BY i.graph_tree_id, i.order_key LIMIT 10001');
        if (count($rows) > 10000) throw new Fault('Tree inventory exceeds the configured first-version limit');
        $result = array(); $paths = array();
        foreach ($this->db->all('SELECT id, name FROM graph_tree ORDER BY id') as $tree) {
            if ($this->treeAllowed($tree['id'])) $result[] = array('tree_id'=>(int)$tree['id'], 'node_id'=>0, 'kind'=>'tree', 'name'=>$tree['name'], 'path'=>$tree['name'], 'prefix'=>'', 'host_id'=>0, 'graph_id'=>0);
        }
        foreach ($rows as $r) {
            if (!$this->treeAllowed($r['graph_tree_id'])) continue;
            $prefix = self::prefix($r['order_key']);
            $parent = substr($prefix, 0, max(0, strlen($prefix) - 3));
            $kind = $r['local_graph_id'] > 0 ? 'graph' : ((string)$r['title'] !== '' ? 'header' : 'host');
            if ($kind === 'graph') {
                try { $g = $this->graph((int)$r['local_graph_id']); } catch (Fault $e) { continue; }
                $name = $g['title_cache'];
            } else {
                if ($kind === 'host' && !$this->hostGraphs((int)$r['host_id'])) continue;
                $name = $kind === 'header' ? $r['title'] : $r['host_name'];
            }
            $path = (isset($paths[$r['graph_tree_id'] . ':' . $parent]) ? $paths[$r['graph_tree_id'] . ':' . $parent] : $r['tree_name']) . ' / ' . $name;
            $paths[$r['graph_tree_id'] . ':' . $prefix] = $path;
            $result[] = array('tree_id'=>(int)$r['graph_tree_id'], 'node_id'=>(int)$r['id'], 'kind'=>$kind, 'name'=>$name, 'path'=>$path, 'prefix'=>$prefix, 'host_id'=>(int)$r['host_id'], 'graph_id'=>(int)$r['local_graph_id']);
        }
        $this->nodes = $result;
        return $result;
    }
    public function scope($treeId, $nodeId) {
        foreach ($this->nodes() as $n) if ($n['tree_id'] === $treeId && $n['node_id'] === $nodeId) return $n;
        throw new Fault('Tree node is unavailable or not permitted');
    }
    public function scopeGraphs($treeId, $nodeId, $templateId = 0, $queryId = null, $index = '') {
        $scope = $this->scope($treeId, $nodeId); $ids = array(); $hosts = array();
        foreach ($this->nodes() as $n) {
            if ($n['tree_id'] !== $treeId || strpos($n['prefix'], $scope['prefix']) !== 0) continue;
            if ($n['kind'] === 'graph') $ids[$n['graph_id']] = true;
            if ($n['kind'] === 'host') $hosts[$n['host_id']] = true;
        }
        $clauses = array(); $args = array();
        if ($ids) { $clauses[] = 'g.id IN (' . implode(',', array_fill(0,count($ids),'?')) . ')'; $args = array_keys($ids); }
        if ($hosts) { $clauses[] = 'g.host_id IN (' . implode(',', array_fill(0,count($hosts),'?')) . ')'; $args = array_merge($args, array_keys($hosts)); }
        if (!$clauses) return array();
        return $this->graphRows('(' . implode(' OR ', $clauses) . ')', $args, $templateId, $queryId, $index);
    }
    public function hostGraphs($hostId) { return $this->graphRows('g.host_id=?', array($hostId), 0, null, ''); }
    private function graphRows($where, $args, $templateId, $queryId, $index) {
        if ($templateId) { $where .= ' AND g.graph_template_id=?'; $args[] = $templateId; }
        if ($queryId !== null) { $where .= ' AND g.snmp_query_id=?'; $args[] = $queryId; }
        if ($index !== '') { $where .= ' AND g.snmp_index=?'; $args[] = $index; }
        $rows = $this->db->all('SELECT g.*, t.title_cache, t.vertical_label, gt.name AS template_name, q.name AS query_name FROM graph_local g JOIN graph_templates_graph t ON t.local_graph_id=g.id LEFT JOIN graph_templates gt ON gt.id=g.graph_template_id LEFT JOIN snmp_query q ON q.id=g.snmp_query_id WHERE ' . $where . ' ORDER BY g.id LIMIT 2001', $args);
        if (count($rows) > 2000) throw new Fault('Scope exceeds 2000 graphs; select a smaller node');
        $out = array();
        foreach ($rows as $g) if ($this->graphAllowed($g)) $out[] = $g;
        return $out;
    }
    public function sources($g) {
        return $this->db->all('SELECT DISTINCT d.id, d.local_data_id, d.data_source_name, d.data_source_type_id, d.local_data_template_rrd_id, p.data_source_path, p.rrd_step, l.host_id, l.snmp_query_id, l.snmp_index FROM graph_templates_item i JOIN data_template_rrd d ON d.id=i.task_item_id JOIN data_template_data p ON p.local_data_id=d.local_data_id JOIN data_local l ON l.id=d.local_data_id WHERE i.local_graph_id=? ORDER BY d.id', array($g['id']));
    }
    public function trafficSources($g) {
        $out = array();
        if (!(int)$g['host_id'] || !(int)$g['snmp_query_id'] || $g['snmp_index'] === '') return $out;
        $sources=$this->sources($g);
        if (count($sources)!==2) return $out;
        foreach ($sources as $s) {
            if ($s['host_id'] != $g['host_id'] || $s['snmp_query_id'] != $g['snmp_query_id'] || $s['snmp_index'] !== $g['snmp_index'] || (int)$s['data_source_type_id'] !== 2) continue;
            $maps = $this->db->all('SELECT DISTINCT r.snmp_field_name FROM snmp_query_graph_rrd r JOIN snmp_query_graph q ON q.id=r.snmp_query_graph_id WHERE r.data_template_rrd_id=? AND q.graph_template_id=? AND q.snmp_query_id=?', array($s['local_data_template_rrd_id'], $g['graph_template_id'], $g['snmp_query_id']));
            foreach ($maps as $map) {
                $field = $map['snmp_field_name'];
                $direction = in_array($field, array('ifInOctets','ifHCInOctets'), true) ? 'in' : (in_array($field,array('ifOutOctets','ifHCOutOctets'),true) ? 'out' : null);
                if ($direction) {
                    if (isset($out[$direction]) && $out[$direction]['id'] != $s['id']) throw new Fault('Multiple data sources for one direction; aggregated graph is unsupported for interface statistics');
                    $s['direction'] = $direction; $out[$direction] = $s;
                }
            }
        }
        return count($out) === 2 ? $out : array();
    }
    public function interfaceInfo($g) {
        $result = array('host_id'=>(int)$g['host_id'], 'query_id'=>(int)$g['snmp_query_id'], 'index'=>$g['snmp_index']);
        foreach ($this->db->all("SELECT field_name, field_value FROM host_snmp_cache WHERE host_id=? AND snmp_query_id=? AND snmp_index=? AND field_name IN ('ifName','ifDescr','ifAlias','ifSpeed','ifHighSpeed','ifOperStatus')", array($g['host_id'],$g['snmp_query_id'],$g['snmp_index'])) as $r) $result[$r['field_name']] = $r['field_value'];
        return $result;
    }
    public function publicGraph($g) {
        try { $sources = $this->trafficSources($g); } catch (Fault $e) { $sources = array(); }
        return array('graph_id'=>(int)$g['id'], 'title'=>$g['title_cache'], 'graph_template_id'=>(int)$g['graph_template_id'], 'interface'=>$this->interfaceInfo($g), 'traffic_supported'=>(bool)$sources, 'display_unit'=>$g['vertical_label']);
    }
}
