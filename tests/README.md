# Isolated verification

Never point these fixture scripts at production. The seed and permissions
scripts require MCP_TEST_DISPOSABLE=yes. The seed drops and recreates the
fixed database name cacti_mcp_test and regenerates only synthetic RRD files.

## Prerequisites

- Linux, PHP 5.6 CLI with PDO MySQL, JSON, hash, XML, SimpleXML and session.
- RRDTool, GNU timeout, a local disposable MySQL/MariaDB instance.
- Python 3.11+ and the packages pinned in requirements.txt for the SDK check.
- An unpacked, checksum-verified Cacti 0.8.8a official source archive.
- A local database root test account reachable through a private Unix socket
  with an empty password. Keep the database network listener disabled.

The fixture creates a separate SELECT-only mcp_test_reader account. The
application tests use that account. The permissions matrix uses the local
root account only to vary disposable ACL fixtures, and restores those fixtures
in a finally block.

## Run

Set environment variables for your isolated installation:

```sh
export MCP_TEST_DISPOSABLE=yes
export CACTI_SOURCE=/path/to/cacti-0.8.8a
export MCP_TEST_RUNTIME=/private/path/to/mcp-test
export MCP_TEST_DB_SOCKET=/private/path/to/mysql.sock
export RRDTOOL_BINARY=/usr/bin/rrdtool
export LEGACY_PHP_BINARY=/path/to/php56
export CACTI_MCP_CONFIG="$MCP_TEST_RUNTIME/config.php"

"$LEGACY_PHP_BINARY" tests/seed.php
"$LEGACY_PHP_BINARY" tests/integration.php
"$LEGACY_PHP_BINARY" tests/permissions.php
```

Start a local server in a separate terminal from the repository root:

```sh
"$LEGACY_PHP_BINARY" -d log_errors=1 -d date.timezone=Asia/Shanghai   -S 127.0.0.1:18765 -t .
```

Then run:

```sh
python3 tests/http_test.py
python3 -m pip install -r tests/requirements.txt
python3 tests/sdk_test.py
```

Tests obtain their random token from private client.json; no token is stored
in the repository or printed. The local seed deliberately enables HTTP and
permits only 127.0.0.1:18765. These settings are unsuitable for production.

The 60 requests/minute limit is active during HTTP tests. If deliberately
repeating tests, wait for the rate window to expire before rerunning; do not
disable the production limiter. Stop the local server after testing.

To test the endpoint core on PHP 8.2, run integration.php using PHP 8.2 with
PDO MySQL and SimpleXML enabled, retaining PHP 5.6 as legacy_php_binary.
This does not imply that Cacti 0.8.8a itself supports PHP 8.2.

## Fixtures and assertions

The seed imports official SQL and copies the built-in traffic graph/data
templates into synthetic device/interface instances. A modern MariaDB
reserved identifier (rows) is quoted during test schema import only; no Cacti
source file is changed.

Fixtures cover repeated room names, a tree order key ending in zero, nested
host expansion, direct plus host graph duplicates, denied trees/hosts/graphs,
multiple graph views of one interface, known input rates, synchronized output
peaks and missing samples.

Integration checks use real RRDTool exports and the original Cacti drawing
function. They check exact numeric rates and peak timestamps, PNG signatures,
unchanged RRD SHA-256 hashes, SQL write rejection, path confinement and
authentication. The permissions matrix compares all 64 policy/membership
combinations with the original Cacti is_graph_allowed implementation.

HTTP tests exercise status codes, malformed input, initialization,
notifications, tool discovery, all five tool calls and image content.
The official MCP Python SDK test independently exercises negotiation,
ping, discovery and every tool.

## Environment used for the initial validation

PHP 5.6.40 was compiled in the isolated workspace. On the ARM64 host with a
modern compiler, its historic zend_multiply.h inline assembly constraints
were changed from X to integer-register constraints (early-clobber outputs)
to build the test runtime. This affects the test runtime only; no PHP or
Cacti source is bundled or patched by plugin installation.

MariaDB 10.11.19 used a private Unix socket with networking disabled. PHP
8.2.35, RRDTool 1.7.2 and MCP Python SDK 1.30.0 were also used. Dependencies
were extracted/compiled under the workspace because container restrictions
prevented a normal package installation.
