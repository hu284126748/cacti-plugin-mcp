"""HTTP contract checks against an isolated server; reads token from a private fixture."""
import base64
import json
import os
import urllib.error
import urllib.request

with open(os.path.join(os.environ["MCP_TEST_RUNTIME"], "client.json")) as f:
    fixture = json.load(f)
url = os.environ.get("MCP_TEST_URL", "http://127.0.0.1:18765/plugins/mcp/endpoint.php")
count = 0

def check(ok, name):
    global count
    assert ok, name
    count += 1
    print("PASS", name)

def request(body=None, method="POST", headers=None):
    defaults = {
        "Authorization": "Bearer " + fixture["token"],
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "MCP-Protocol-Version": "2025-06-18",
    }
    if headers:
        defaults.update(headers)
    data = json.dumps(body).encode() if body is not None else b"{"
    req = urllib.request.Request(url, data=data if method == "POST" else None, headers=defaults, method=method)
    try:
        res = urllib.request.urlopen(req, timeout=60)
    except urllib.error.HTTPError as exc:
        res = exc
    raw = res.read()
    return res.status, json.loads(raw) if raw else None

status, res = request({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
    "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "test", "version": "1"}
}})
check(status == 200 and res["result"]["protocolVersion"] == "2025-06-18", "initialize handshake")
check(request({"jsonrpc": "2.0", "method": "notifications/initialized"}) == (202, None), "initialized notification")
check(request(None)[1]["error"]["code"] == -32700, "parse error")
check(request([])[1]["error"]["code"] == -32600, "batch rejected by negotiated protocol")
check(request({"jsonrpc": "2.0", "id": 2, "method": "ping"})[1]["result"] == {}, "ping empty object")
check(request({}, headers={"Authorization": ""})[0] == 401, "missing bearer token")
check(request({}, headers={"Origin": "https://evil.invalid"})[0] == 403, "hostile origin")
check(request({}, headers={"Host": "evil.invalid"})[0] == 403, "host validation")
check(request({}, headers={"Accept": "text/html"})[0] == 406, "content negotiation")
check(request({}, headers={"Content-Type": "text/plain"})[0] == 415, "content type")
check(request({}, headers={"MCP-Protocol-Version": "1900-01-01"})[0] == 400, "unsupported version")
check(request(method="GET")[0] == 405, "optional SSE stream returns 405")
tools = request({"jsonrpc": "2.0", "id": 3, "method": "tools/list"})[1]["result"]["tools"]
check(len(tools) == 5 and all(t["annotations"]["readOnlyHint"] for t in tools), "five read-only tools")

def call(name, args):
    status, response = request({"jsonrpc": "2.0", "id": 10, "method": "tools/call", "params": {"name": name, "arguments": args}})
    check(status == 200 and not response["result"]["isError"], "tool " + name)
    return response["result"]["content"]

call("search_tree_nodes", {"query": "External"})
call("list_scope_graphs", {"tree_id": 2, "node_id": 101})
window = {"graph_id": 100, "start": fixture["start"], "end": fixture["end"]}
call("get_graph_timeseries", window)
call("compare_host_interfaces", window)
image = call("get_graph_image", window)
check(any(item["type"] == "image" and base64.b64decode(item["data"]).startswith(b"\x89PNG\r\n\x1a\n") for item in image), "inline image result")
window["graph_id"] = 103
status, response = request({"jsonrpc": "2.0", "id": 11, "method": "tools/call", "params": {"name": "get_graph_image", "arguments": window}})
check(status == 200 and response["result"]["isError"], "direct denied graph cannot bypass authentication")
print("Completed", count, "HTTP assertions.")
