<?php
namespace CactiMcp;

class Rrd {
    private $config;
    public function __construct($config) { $this->config = $config; }
    public function path($source) {
        $root = realpath($this->config['rrd_root']);
        $path = $source['data_source_path'];
        if (!$root || !$path) throw new Fault('RRD path is missing; no automatic repair is performed');
        $path = str_replace('<path_rra>', $root, $path);
        if (strpos($path, '/') === false) $path = $root . '/' . $path;
        $real = realpath($path);
        if (!$real || strpos($real, $root . '/') !== 0 || !is_file($real) || !is_readable($real) || substr($real,-4) !== '.rrd') throw new Fault('RRD is unavailable or outside the allowed directory');
        return $real;
    }
    private function command($args) {
        $cmd = 'exec ' . escapeshellarg($this->config['timeout_binary']) . ' --signal=KILL 20s ' . escapeshellarg($this->config['rrdtool_binary']);
        foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string)$arg);
        return Process::run($cmd, '', 22, 8 * 1024 * 1024);
    }
    public function series($sources, $start, $end, $resolution) {
        $args = array('xport', '--start', $start, '--end', $end, '--step', $resolution, '--maxrows', 10000);
        $names = array();
        foreach ($sources as $direction => $s) {
            if (!preg_match('/^[A-Za-z0-9_]{1,19}$/', $s['data_source_name'])) throw new Fault('Unsupported RRD data source name');
            $path = $this->path($s);
            // Colons are delimiters in RRD DEF syntax, even after shell quoting.
            $path = str_replace(array('\\', ':'), array('\\\\', '\\:'), $path);
            $name = 'd' . count($names);
            $args[] = 'DEF:' . $name . '=' . $path . ':' . $s['data_source_name'] . ':AVERAGE';
            $args[] = 'CDEF:' . $name . 'bits=' . $name . ',8,*';
            $args[] = 'XPORT:' . $name . 'bits:' . $direction;
            $names[] = $direction;
        }
        $xml = $this->command($args);
        if (stripos($xml, '<!DOCTYPE') !== false) throw new Fault('Unexpected RRD XML');
        $old = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        if (!$doc || !isset($doc->meta->step)) throw new Fault('Invalid RRD response');
        $step = (int)$doc->meta->step;
        if ($step <= 0 || (int)$doc->meta->columns !== count($names)) throw new Fault('Invalid RRD metadata');
        $points = array(); $next = (int)$doc->meta->start;
        foreach ($doc->data->row as $row) {
            $t = isset($row->t) ? (int)$row->t : $next;
            $next = $t + $step;
            // RRD timestamps mark the end of each consolidated interval.
            if ($t <= $start || $t > $end) continue;
            $point = array('timestamp'=>$t); $i=0;
            foreach ($row->v as $v) {
                $str = (string)$v; $number = is_numeric($str) ? (float)$str : null;
                $point[$names[$i++]] = ($number !== null && is_finite($number) && $number >= 0) ? $number : null;
            }
            if ($i !== count($names)) throw new Fault('Invalid RRD column count');
            $points[] = $point;
        }
        if (!$points) throw new Fault('No RRD intervals in this time window');
        return array('unit'=>'bit/s', 'measurement'=>'interface_octet_counters', 'consolidation'=>'AVERAGE', 'step'=>$step, 'requested_start'=>$start, 'requested_end'=>$end, 'points'=>$points, 'statistics'=>self::statistics($points, $names));
    }
    public static function statistics($points, $names) {
        $out = array();
        foreach ($names as $name) {
            $sum=0.0; $count=0; $peak=null; $peakTime=null; $latest=null; $latestTime=null;
            foreach ($points as $p) {
                if ($p[$name] === null) continue;
                $v=$p[$name]; $sum += $v; $count++; $latest=$v; $latestTime=$p['timestamp'];
                if ($peak === null || $v > $peak) { $peak=$v; $peakTime=$p['timestamp']; }
            }
            $out[$name] = array('average'=>$count ? $sum/$count : null, 'peak'=>$peak, 'peak_time'=>$peakTime, 'latest_valid'=>$latest, 'latest_valid_time'=>$latestTime, 'valid_points'=>$count, 'total_points'=>count($points), 'missing_ratio'=>count($points) ? 1-$count/count($points) : 1);
        }
        return $out;
    }
    public function image($repo, $g, $start, $end, $width, $height) {
        $allowed = array();
        foreach ($repo->sources($g) as $source) $allowed[(int)$source['local_data_id']] = $this->path($source);
        if (!$allowed) throw new Fault('Graph has no readable RRD data sources');
        $request = array('user_id'=>$repo->userId(), 'graph_id'=>(int)$g['id'], 'start'=>$start, 'end'=>$end, 'width'=>$width, 'height'=>$height, 'paths'=>$allowed);
        $cmd = 'exec ' . escapeshellarg($this->config['timeout_binary']) . ' --signal=KILL 20s ' . escapeshellarg($this->config['legacy_php_binary']) . ' ' . escapeshellarg(dirname(__DIR__) . '/render.php');
        $png = Process::run($cmd, json_encode($request), 22, 4 * 1024 * 1024);
        if (substr($png,0,8) !== "\x89PNG\r\n\x1a\n") throw new Fault('Cacti did not return a PNG image');
        return $png;
    }
}
