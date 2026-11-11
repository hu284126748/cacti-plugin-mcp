# Cacti MCP plugin

A first-version, read-only HTTP MCP plugin for **Cacti 0.8.8a**. It reads
existing graph trees and monitored interfaces; it does not create graphs,
poll devices, or connect to any production instance during development.

The endpoint is `/cacti/plugins/mcp/endpoint.php`. It implements stateless
Streamable HTTP with JSON responses and MCP version **2025-06-18**.
A client must support a configured Bearer header. OAuth discovery and the
older HTTP+SSE transport are not implemented.

## Tools

| Tool | Purpose |
| --- | --- |
| search_tree_nodes | Search tree names, header/host paths and virtual template/query/interface groups. |
| list_scope_graphs | Expand a selected tree scope, including graphs attached through host nodes. |
| get_graph_timeseries | Read standard SNMP interface counter rates, missing data and statistics. |
| get_graph_image | Render an existing Cacti graph for explicit start/end times, returning inline PNG. |
| compare_host_interfaces | Rank authorized interfaces on the reference graph's device, with graph deduplication. |

Tree selectors are returned in each search result's `scope`: `tree_id`,
`node_id`, optionally `graph_template_id`, `query_id`, `snmp_index`.
A zero node selects the entire tree. Query ID zero selects non-query graphs.
Repeated names remain separate candidates. Virtual groups are synthetic
selectors over existing graphs, not newly created tree records.

## Runtime and installation

Required on Linux:

- Cacti 0.8.8a source and its existing database/RRD files.
- PHP 5.6 or a separately configured modern PHP handler for the MCP endpoint,
  with PDO MySQL, JSON, hash and SimpleXML support, plus proc_open.
- **PHP 5.6 CLI** for the original Cacti renderer, including PDO MySQL, JSON,
  hash, XML and session extensions. The original Cacti libraries do not run
  unchanged under PHP 8. The endpoint and renderer can use different binaries.
- RRDTool and GNU timeout. Tests use RRDTool 1.7.2.
- A private writable runtime directory for per-user request locks and rate
  counters. Business data remains read-only.

Install:

1. Copy `plugins/mcp` into the existing Cacti `plugins` directory.
2. Use Cacti Plugin Management to install and enable MCP. Installation registers
   a plugin realm; no monitoring tables are added or modified.
3. Give the mapped Cacti user both **View Graphs** and **Access read-only MCP**
   permissions. Configure its ordinary graph/tree/host/template permissions.
4. Provision a dedicated database account with **SELECT only** on the Cacti
   database. Do not reuse the Cacti administrator database credential.
5. Copy `plugins/mcp/config.example.php` outside the web root, normally to
   `/etc/cacti-mcp.php`. Set database credentials, paths and explicit allowed
   hosts. Set `enabled` to true only after configuration.
6. Generate a random token with at least 256 bits of entropy. Store only its
   SHA-256 digest in `tokens`, with the Cacti `user_id` and an expiry timestamp
   in `expires_at`. Supply the original token privately to the client.
7. Create `runtime_dir`, owned by the PHP service account, mode 0700. Restrict
   the external configuration file to that account. Give the endpoint and
   renderer read access to RRDs and Cacti libraries.
8. Serve the endpoint over HTTPS. Ensure the web server forwards Authorization
   and sets HTTPS correctly. Do not trust client-supplied forwarding headers.
   `allow_plain_http` defaults to false; use it only behind a trusted TLS
   termination boundary or for local tests.
9. Configure the client URL and `Authorization: Bearer <token>`. Verify
   initialize, tools/list, a known graph's data and its image.

If using a non-default configuration path, set `CACTI_MCP_CONFIG` in the
endpoint process environment. The CLI renderer must inherit the same setting.
For PHP-FPM this may require an explicit pool `env[CACTI_MCP_CONFIG]` entry.

The renderer is a CLI subprocess within this installation, not a separate
HTTP service. It loads the existing Cacti drawing libraries without loading
the Cacti browser bootstrap, session login flow or third-party plugin hooks.
No Cacti core source modifications are required.

## Measurement semantics

- Numeric tools support a pair of standard SNMP COUNTER data sources mapped
  through Cacti's query metadata to ifInOctets/ifOutOctets or their HC variants.
  Template IDs and interface names are not hard-coded.
- Numeric results are **bit/s**, derived from RRD byte-counter rates once.
  They are deliberately independent of graph CDEF display transforms, such as
  negative outbound curves. The image preserves the Cacti graph presentation.
- Custom script, aggregate and ambiguous graphs are not silently interpreted
  as an interface. They remain available for image rendering where supported.
- Input start/end values are Unix seconds. Convert calendar inputs using
  Asia/Shanghai. The image renderer explicitly uses Asia/Shanghai.
- Each returned timestamp is the end of a consolidated RRD interval. Points
  satisfy start < timestamp <= end. A boundary interval can extend before a
  non-aligned requested start; select aligned times for precise comparisons.
- Actual step and AVERAGE consolidation are returned. A reported peak is the
  maximum of those averages, not an instantaneous or MAX-archive peak.
- Unknown values become null. Means exclude unknown intervals and include
  missing_ratio and valid-point counts. latest_valid_time reveals staleness.
- Historical detail cannot be recovered once rolled up or expired from RRD.
- Compare uses a shared actual step and includes values at the reference
  graph's peak time, with separate in/out statistics. It does not sum interfaces
  or assert that a ranked interface caused an incident.
- Only already monitored, authorized interfaces are included. The same query
  and interface with conflicting data-source pairs is an error rather than an
  arbitrary choice.

## Access and operational boundaries

Tokens map to an enabled Cacti user; every request rechecks that user, the
plugin enable state and both required realms. Browser cookies are not used.
Tree listing obeys tree ACLs. Direct graph tools obey graph/host/template ACLs;
as with Cacti, tree navigation permission is not an extra direct-graph ACL.

Conflicting graph/host/template rules use **explicit deny precedence**. This
is intentionally stricter than the old Cacti SQL's OR behavior. A 64-case
comparison test confirms the plugin never broadens original access.

The database adapter accepts read queries only, backed by an account whose
database grants prevent UPDATE/INSERT/DELETE. Empty RRD paths are rejected,
not repaired. Real paths must remain inside rrd_root, including symlinks.
RRD files are never updated. Third-party graph/cache hooks are not run; cached
or plugin-modified installations may therefore render differently or show the
last data flushed to disk. Qualify such installations before deployment.

Limits: 64 KiB request body, 100 results per list page, 10,000 persisted tree
items, 2,000 graphs per scope, 256 interfaces per comparison, 366-day time
window, 10,000 exported rows (comparisons request at most about 1,000 intervals per interface), 4 MiB image output, 20-second child execution
limit, 90-second comparison budget. One active request and 60 requests per
minute per mapped user are allowed. Callers receive explicit errors rather
than silently truncated comparisons. Put a total concurrency/rate limit at
the web server for multi-user deployments.

Audit records go to the PHP error log with user, method/tool, duration and
outcome; tokens and request arguments are not recorded. Keep these logs
private. Render failures include bounded diagnostic output in the private log.

## Verification

See [tests/README.md](tests/README.md) for reproducible isolated setup and
[docs/VALIDATION.md](docs/VALIDATION.md) for this version's recorded results.
No production Cacti database, RRDs or network devices are used by the tests.

This version is ready for installation evaluation. Production PHP/RRDTool
versions, local patches, fonts, custom templates and the chosen business MCP
client still need deployment-specific validation.

License: GPL-2.0-or-later. See LICENSE and THIRD_PARTY_NOTICES.md.
