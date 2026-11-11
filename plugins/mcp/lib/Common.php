<?php
namespace CactiMcp;

class Fault extends \RuntimeException {}

class Input {
    public static function integer($args, $name, $min, $max, $default = null) {
        if (!isset($args[$name])) {
            if ($default !== null) return $default;
            throw new Fault('Missing ' . $name);
        }
        $v = $args[$name];
        if (!is_int($v) || $v < $min || $v > $max) throw new Fault('Invalid ' . $name);
        return $v;
    }
    public static function text($args, $name, $default = '') {
        $v = isset($args[$name]) ? $args[$name] : $default;
        if (!is_string($v) || strlen($v) > 255 || preg_match('/[\x00-\x1f]/', $v)) throw new Fault('Invalid ' . $name);
        return $v;
    }
    public static function window($args) {
        $start = self::integer($args, 'start', 1, PHP_INT_MAX);
        $end = self::integer($args, 'end', 1, PHP_INT_MAX);
        if ($end <= $start || $end - $start > 366 * 86400 || $end > time() + 300) throw new Fault('Use a past time window of at most 366 days');
        return array($start, $end);
    }
}

class Db {
    private $pdo;
    public function __construct($config) {
        $this->pdo = new \PDO($config['dsn'], $config['username'], $config['password'], array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ));
    }
    public function all($sql, $args = array()) {
        // The legacy renderer uses this adapter too; it never receives a write connection.
        if (!preg_match('/^\s*SELECT\b/i', $sql) || preg_match('/\b(INTO|FOR\s+UPDATE|LOCK\s+IN|SLEEP|BENCHMARK|LOAD_FILE)\b/i', $sql)) throw new Fault('Only read queries are permitted');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }
    public function row($sql, $args = array()) {
        $rows = $this->all($sql, $args);
        return count($rows) ? $rows[0] : array();
    }
}

class Process {
    public static function run($command, $input, $seconds, $maxBytes) {
        $pipes = array();
        $p = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($p)) throw new Fault('Cannot start renderer');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $deadline = microtime(true) + $seconds; $exit = -1;
        while (true) {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($p);
            if (strlen($out) + strlen($err) > $maxBytes || microtime(true) > $deadline) {
                proc_terminate($p, 9);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
                throw new Fault('Renderer time or output limit exceeded');
            }
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            usleep(10000);
        }
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        if ($exit !== 0 || strlen($out) + strlen($err) > $maxBytes) {
            error_log('Cacti MCP child failure: ' . substr($err,0,2048));
            throw new Fault('Renderer failed; check private server logs');
        }
        return $out;
    }
}

function loadConfig() {
    $path = getenv('CACTI_MCP_CONFIG');
    if (!$path) $path = '/etc/cacti-mcp.php';
    if (!is_file($path)) throw new Fault('MCP configuration is missing');
    $c = require $path;
    if (!is_array($c) || empty($c['enabled']) || empty($c['tokens']) || empty($c['allowed_hosts'])) throw new Fault('MCP is disabled or incompletely configured');
    $c['config_file'] = $path;
    return $c;
}
