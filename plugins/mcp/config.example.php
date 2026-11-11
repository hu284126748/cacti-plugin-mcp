<?php
// Copy outside the web root, e.g. /etc/cacti-mcp.php; restrict to the service account.
// Installation changes plugin metadata only. All endpoint database access uses SELECT.
return array(
    'enabled'=>false,
    'allowed_hosts'=>array('cacti.example.internal'),
    'allowed_origins'=>array(),
    'allow_plain_http'=>false,
    'tokens'=>array(
        // array('sha256'=>'<SHA-256 of a random token>', 'user_id'=>42, 'expires_at'=>...),
    ),
    'database'=>array(
        'dsn'=>'mysql:host=127.0.0.1;port=3306;dbname=cacti;charset=utf8',
        'username'=>'cacti_mcp_readonly',
        'password'=>'',
    ),
    'database_name'=>'cacti',
    'cacti_root'=>'/var/www/cacti',
    'rrd_root'=>'/var/www/cacti/rra',
    'rrdtool_binary'=>'/usr/bin/rrdtool',
    'rrdtool_version'=>'rrd-1.4.x',
    'legacy_php_binary'=>'/usr/bin/php',
    'timeout_binary'=>'/usr/bin/timeout',
    'runtime_dir'=>'/var/run/cacti-mcp',
);
