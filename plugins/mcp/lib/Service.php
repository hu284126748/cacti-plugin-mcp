<?php
namespace CactiMcp;

class Service {
    private $repo;
    private $rrd;
    public function __construct($repo, $rrd) { $this->repo=$repo; $this->rrd=$rrd; }
    private function page($rows, $args) {
        $offset=Input::integer($args,'offset',0,100000,0);
        $limit=Input::integer($args,'limit',1,100,50);
        return array('items'=>array_slice($rows,$offset,$limit), 'total'=>count($rows), 'next_offset'=>$offset+$limit<count($rows) ? $offset+$limit : null);
    }
    public function call($name, $a) {
        switch ($name) {
        case 'search_tree_nodes':
            $query=Input::text($a,'query');
            $rows=array(); $seen=array();
            foreach ($this->repo->nodes() as $n) {
                $scope=array('tree_id'=>$n['tree_id'], 'node_id'=>$n['node_id']);
                $entry=array('name'=>$n['name'], 'path'=>$n['path'], 'kind'=>$n['kind'], 'scope'=>$scope);
                if ($query === '' || stripos($entry['path'],$query)!==false) $rows[]=$entry;
                if ($n['kind'] !== 'host') continue;
                foreach ($this->repo->hostGraphs($n['host_id']) as $g) {
                    $groups=array(
                        array('graph_template_id'=>(int)$g['graph_template_id']),
                        array('query_id'=>(int)$g['snmp_query_id'])
                    );
                    if ($g['snmp_index'] !== '' && (int)$g['snmp_query_id']) $groups[]=array('query_id'=>(int)$g['snmp_query_id'],'snmp_index'=>$g['snmp_index']);
                    foreach ($groups as $group) {
                        $selector=array_merge($scope,$group);
                        $key=json_encode($selector);
                        if (isset($seen[$key])) continue;
                        $seen[$key]=true;
                        $info=$this->repo->interfaceInfo($g);
                        $label=isset($group['snmp_index']) ? (isset($info['ifName']) ? $info['ifName'] : $group['snmp_index']) : (isset($group['graph_template_id']) ? $g['template_name'] : ((int)$g['snmp_query_id'] ? $g['query_name'] : 'Non Query Based'));
                        $path=$n['path'].' / '.$label;
                        if ($query === '' || stripos($path,$query)!==false) $rows[]=array('name'=>$label,'path'=>$path,'kind'=>'virtual_group','scope'=>$selector);
                    }
                }
            }
            return $this->page($rows,$a);
        case 'list_scope_graphs':
            $tree=Input::integer($a,'tree_id',1,65535);
            $node=Input::integer($a,'node_id',0,16777215,0);
            $template=Input::integer($a,'graph_template_id',0,16777215,0);
            $q=array_key_exists('query_id',$a) ? Input::integer($a,'query_id',0,16777215) : null;
            $index=Input::text($a,'snmp_index');
            if ($index!=='' && ($q===null || $q===0)) throw new Fault('snmp_index requires query_id');
            $graphs=$this->repo->scopeGraphs($tree,$node,$template,$q,$index);
            $rows=array(); foreach ($graphs as $g) $rows[]=$this->repo->publicGraph($g);
            return $this->page($rows,$a);
        case 'get_graph_timeseries':
            list($start,$end)=Input::window($a);
            $g=$this->repo->graph(Input::integer($a,'graph_id',1,16777215));
            $s=$this->repo->trafficSources($g);
            if (!$s) throw new Fault('This graph has no supported pair of SNMP octet counters; use graph image for custom graphs');
            $resolution=Input::integer($a,'resolution',1,86400,max(300,(int)ceil(($end-$start)/2000)));
            $data=$this->rrd->series($s,$start,$end,$resolution);
            $data['graph']=$this->repo->publicGraph($g);
            $data['note']='Values are interface counter rates in bit/s, independent of graph CDEF styling. Peaks are maxima of AVERAGE intervals; missing values remain null.';
            return $data;
        case 'get_graph_image':
            list($start,$end)=Input::window($a);
            $g=$this->repo->graph(Input::integer($a,'graph_id',1,16777215));
            $png=$this->rrd->image($this->repo,$g,$start,$end,Input::integer($a,'width',200,1600,900),Input::integer($a,'height',100,800,300));
            return array('_image'=>base64_encode($png),'graph_id'=>(int)$g['id'],'title'=>$g['title_cache'],'start'=>$start,'end'=>$end);
        case 'compare_host_interfaces':
            return $this->compare($a);
        }
        throw new Fault('Unknown tool');
    }
    private function compact($series,$direction) {
        $values=array();
        foreach ($series['points'] as $p) $values[$p['timestamp']]=$p[$direction];
        return array('values'=>$values,'statistics'=>$series['statistics'],'step'=>$series['step']);
    }
    private function compare($a) {
        list($start,$end)=Input::window($a);
        $g=$this->repo->graph(Input::integer($a,'graph_id',1,16777215));
        $direction=Input::text($a,'direction','out');
        $rank=Input::text($a,'rank_by','at_reference_peak');
        if (!in_array($direction,array('in','out'),true) || !in_array($rank,array('average','peak','at_reference_peak'),true)) throw new Fault('Invalid direction or rank_by');
        $referenceSources=$this->repo->trafficSources($g);
        if (!$referenceSources) throw new Fault('Reference graph is not a supported interface traffic graph');
        $sets=array(); $unsupported=array();
        foreach ($this->repo->hostGraphs((int)$g['host_id']) as $other) {
            try { $sources=$this->repo->trafficSources($other); } catch (Fault $e) { $sources=array(); }
            if (!$sources) { $unsupported[]=(int)$other['id']; continue; }
            $key=$other['snmp_query_id'].':'.$other['snmp_index'];
            $signature=$sources['in']['id'].':'.$sources['out']['id'];
            if (isset($sets[$key])) {
                if ($sets[$key]['signature']!==$signature) throw new Fault('Multiple monitored data sets for one interface; resolve ambiguity before comparison');
                $sets[$key]['graph_ids'][]=(int)$other['id'];
                continue;
            }
            $sets[$key]=array('graph'=>$other,'sources'=>$sources,'signature'=>$signature,'graph_ids'=>array((int)$other['id']));
        }
        if (count($sets)>256) throw new Fault('Device has more than 256 monitored interfaces; comparison limit reached');
        $resolution=Input::integer($a,'resolution',1,86400,max(300,(int)ceil(($end-$start)/1000)));
        $resolution=max($resolution,(int)ceil(($end-$start)/1000));
        $series=array(); $common=$resolution; $deadline=microtime(true)+90;
        foreach ($sets as $key=>$set) {
            if (microtime(true)>$deadline) throw new Fault('Comparison time limit reached');
            $data=$this->rrd->series($set['sources'],$start,$end,$resolution);
            $series[$key]=$this->compact($data,$direction); $common=max($common,$data['step']);
            unset($data);
        }
        // Re-read at a shared interval; do not compare differently consolidated samples.
        foreach ($sets as $key=>$set) if ($series[$key]['step']!==$common) {
            if (microtime(true)>$deadline) throw new Fault('Comparison time limit reached');
            $series[$key]=$this->compact($this->rrd->series($set['sources'],$start,$end,$common),$direction);
            if ($series[$key]['step']!==$common) throw new Fault('RRD archives cannot provide a common comparison step');
        }
        $refKey=$g['snmp_query_id'].':'.$g['snmp_index'];
        $peakTime=$series[$refKey]['statistics'][$direction]['peak_time'];
        $rows=array();
        foreach ($sets as $key=>$set) {
            $at=null;
            if ($peakTime!==null && array_key_exists($peakTime,$series[$key]['values'])) $at=$series[$key]['values'][$peakTime];
            $stats=$series[$key]['statistics'];
            $rows[]=array('interface'=>$this->repo->interfaceInfo($set['graph']), 'graph_ids'=>$set['graph_ids'], 'statistics'=>$stats, 'at_reference_peak'=>$at, 'rank_value'=>$rank==='at_reference_peak' ? $at : $stats[$direction][$rank]);
        }
        usort($rows,function($a,$b) {
            if ($a['rank_value'] === $b['rank_value']) return strcmp($a['interface']['index'],$b['interface']['index']);
            if ($a['rank_value']===null) return 1;
            if ($b['rank_value']===null) return -1;
            return $a['rank_value']>$b['rank_value'] ? -1 : 1;
        });
        return array('host_id'=>(int)$g['host_id'],'reference_graph_id'=>(int)$g['id'],'reference_peak_time'=>$peakTime,'direction'=>$direction,'rank_by'=>$rank,'unit'=>'bit/s','step'=>$common,'interfaces'=>$rows,'unsupported_graph_ids'=>$unsupported,'note'=>'Only existing, authorized SNMP traffic graphs are compared. Rank indicates association, not causation; interfaces are not summed.');
    }
}
