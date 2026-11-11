<?php
function plugin_mcp_version() {
    return array('name'=>'mcp','longname'=>'Read-only MCP for Cacti 0.8.8a','version'=>'0.1.0','author'=>'cacti-plugin-mcp contributors','homepage'=>'https://github.com/hu284126748/cacti-plugin-mcp');
}
function plugin_mcp_install() { api_plugin_register_realm('mcp','endpoint.php','Access read-only MCP',true); }
function plugin_mcp_uninstall() { api_plugin_remove_realms('mcp'); }
function plugin_mcp_check_config() { return PHP_VERSION_ID >= 50600; }
function plugin_mcp_check_upgrade() { return false; }
function plugin_init_mcp() {}
