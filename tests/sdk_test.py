"""Smoke test using the official MCP Python SDK, pinned in requirements.txt."""
import asyncio
import json
import os

import httpx
from mcp import ClientSession
from mcp.client.streamable_http import streamable_http_client

async def main():
    with open(os.path.join(os.environ["MCP_TEST_RUNTIME"], "client.json")) as f:
        fixture = json.load(f)
    url = os.environ.get("MCP_TEST_URL", "http://127.0.0.1:18765/plugins/mcp/endpoint.php")
    async with httpx.AsyncClient(headers={"Authorization": "Bearer " + fixture["token"]}, timeout=60) as http:
        async with streamable_http_client(url, http_client=http) as (read, write, _):
            async with ClientSession(read, write) as session:
                init = await session.initialize()
                assert init.protocolVersion == "2025-06-18"
                print("PASS SDK initialize and version negotiation")
                await session.send_ping()
                tools = await session.list_tools()
                assert len(tools.tools) == 5
                print("PASS SDK ping and tool discovery")
                cases = [
                    ("search_tree_nodes", {"query": "External"}),
                    ("list_scope_graphs", {"tree_id": 2, "node_id": 101}),
                    ("get_graph_timeseries", {"graph_id": 100, "start": fixture["start"], "end": fixture["end"]}),
                    ("compare_host_interfaces", {"graph_id": 100, "start": fixture["start"], "end": fixture["end"]}),
                    ("get_graph_image", {"graph_id": 100, "start": fixture["start"], "end": fixture["end"]}),
                ]
                for name, args in cases:
                    result = await session.call_tool(name, args)
                    assert not result.isError, result
                    if name == "get_graph_image":
                        assert any(c.type == "image" for c in result.content)
                    print("PASS SDK", name)
    print("Completed 7 SDK checks.")

asyncio.run(main())
