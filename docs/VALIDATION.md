# First-version validation record

Date: 2026-11-12 (Beijing time).

## Delivered behavior

The plugin implements five read-only MCP tools: tree search, scope graph
listing, standard interface time series, original Cacti PNG rendering and
same-device interface comparison. It provides stateless Streamable HTTP,
Bearer-to-Cacti-user mapping, realm and data ACL checks, bounded child
execution, per-user concurrency/rate limits and private audit records.

The implementation resides under plugins/mcp. Installation instructions and
deployment constraints are in README.md; repeatable fixture/test instructions
are in tests/README.md.

## Evidence

| Check | Observed result |
| --- | --- |
| PHP 5.6 syntax validation | All 12 PHP source/test files passed. |
| Real database/RRD integration under PHP 5.6.40 | 35 assertions passed. |
| Endpoint core integration under PHP 8.2.35, with PHP 5.6 renderer | 35 assertions passed. |
| HTTP contract tests on PHP 5.6 endpoint | 20 assertions passed. |
| Official MCP Python SDK 1.30.0 on PHP 5.6 endpoint | 7 checks passed, including all five tools. |
| Official MCP Python SDK 1.30.0 on PHP 8.2 endpoint | 7 checks passed, including all five tools. |
| ACL comparison against original Cacti function | All 64 combinations avoid widening access. |
| Disabled user and disabled plugin | Requests rejected. |
| RRD integrity | SHA-256 hashes unchanged after query/render tests. |
| Database write protection | UPDATE rejected by both adapter and SELECT-only database principal. |
| Source hygiene | No generated RRDs, images, test logs or private local configuration in repository; diff whitespace check passed. |

Known numeric fixture: input 1,000,000 bit/s; reference output peak 8,000,000
bit/s; another interface at the same peak timestamp 6,000,000 bit/s. Assertions
check these values and the exact peak timestamp, not just response shapes.
Missing samples remain null. Duplicate graph views retain provenance but
represent a single interface in comparison.

The image test uses actual current-year timestamps beyond the original
graph_image.php time cap, invokes the original rrdtool_function_graph and
checks a valid PNG signature. The HTTP and SDK tests check inline image
content. It is not a pixel-by-pixel screenshot comparison.

## Environment and boundaries

Cacti source is the official 0.8.8a archive, verified SHA-256:
9fea2b6c438ebf2740b5982a6ff7cfd2816e48ea88a347ef0ef238c7b4875955

Tests run against a disposable MariaDB 10.11.19 database reachable only through
a private Unix socket, and synthetic RRDs read using RRDTool 1.7.2. Cacti core
files are unmodified. The test SQL import quotes a newly reserved MariaDB
column name. PHP 5.6 test-build compiler compatibility details are documented
in tests/README.md.

Twenty-one ACL combinations are intentionally stricter than the original
Cacti SQL: explicit denial wins over a grant in another dimension. This
behavior is documented, not treated as exact UI permission equivalence.

## Remaining deployment validation

No connection was made to 10.30.33.249. No production installation, migration,
polling, branch push or merge request was performed.

Before production use, verify the deployed PHP and RRDTool versions, local
Cacti patches, database charset, graph fonts and custom template behavior.
The renderer requires PHP 5.6 CLI; no compatibility with PHP 4 or PHP 5.3 is
claimed. Endpoint tests do not validate the complete Cacti UI under PHP 8.

Custom or aggregate numeric graph semantics are explicitly unsupported;
standard SNMP octet-counter pairs are supported. Third-party graph/cache
hooks are deliberately disabled, so their presentation or cache-flushing
behavior is not reproduced.

The first version returns average-archive interval statistics, not
instantaneous peaks. Capacity/load testing at the 256-interface cap,
historical archive configurations from the actual installation, end-to-end
TLS/reverse-proxy deployment and a specific business MCP client's image UI
remain to be validated. Static Bearer headers are supported; OAuth discovery
and legacy HTTP+SSE are not implemented.

Code changes are present in the conversation's development workspace. They
have not been committed or pushed to the remote repository.
