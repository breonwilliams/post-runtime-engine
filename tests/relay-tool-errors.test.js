/**
 * The bundled MCP relay marks a failed tool call with `isError: true`.
 *
 * Every HTTP error became `{ error: true, ... }` and was returned as an
 * ordinary result, so a refused write (429, 403, 500) read as done to the AI
 * (Promptless video dry run, 2026-10). This runs the REAL relay as a child
 * process against a stub HTTP server and speaks JSON-RPC to it.
 *
 * Zero dependencies — Node's built-in test runner:
 *   node --test tests/relay-tool-errors.test.js
 */
const test = require("node:test");
const assert = require("node:assert");
const http = require("node:http");
const path = require("node:path");
const { spawn } = require("node:child_process");

const RELAY = path.resolve(__dirname, "../includes/Connector/assets/post-runtime-connector.js");

function stub(status, body) {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      res.writeHead(status, { "Content-Type": "application/json" });
      res.end(JSON.stringify(body));
    });
    server.listen(0, "127.0.0.1", () => resolve(server));
  });
}

function callTool(port) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [RELAY], {
      env: {
        ...process.env,
        POST_RUNTIME_SITE_URL: `http://127.0.0.1:${port}`,
        POST_RUNTIME_USERNAME: "tester",
        POST_RUNTIME_APP_PASSWORD: "abcd efgh ijkl mnop",
      },
      stdio: ["pipe", "pipe", "pipe"],
    });
    let out = "";
    const timer = setTimeout(() => { child.kill(); reject(new Error("relay did not answer: " + out)); }, 8000);
    child.stdout.on("data", (d) => {
      out += d.toString();
      const line = out.split("\n").find((l) => l.includes('"id":7'));
      if (line) { clearTimeout(timer); child.kill(); resolve(JSON.parse(line)); }
    });
    child.stdin.write(JSON.stringify({ jsonrpc: "2.0", id: 7, method: "tools/call", params: { name: "postruntime_list_cpts", arguments: {} } }) + "\n");
  });
}

async function run(status, body) {
  const server = await stub(status, body);
  try { return await callTool(server.address().port); } finally { server.close(); }
}

test("a 429 rate limit is an error that says when to retry", async () => {
  const reply = await run(429, { code: "rate_limit_exceeded", message: "Rate limit exceeded.", data: { status: 429, retry_after: 60 } });
  assert.strictEqual(reply.result.isError, true);
  const payload = JSON.parse(reply.result.content[0].text);
  assert.strictEqual(payload.status, 429);
  assert.strictEqual(payload.rate_limited, true);
  assert.strictEqual(payload.retry_after_seconds, 60);
  assert.match(payload.message, /Nothing was written/);
});

test("any other HTTP error is an error", async () => {
  const reply = await run(403, { code: "rest_forbidden", message: "Sorry, you are not allowed to do that." });
  assert.strictEqual(reply.result.isError, true);
  assert.strictEqual(JSON.parse(reply.result.content[0].text).status, 403);
});

test("a successful call is not marked", async () => {
  const reply = await run(200, { success: true, items: [] });
  assert.strictEqual(reply.result.isError, undefined);
  assert.strictEqual(JSON.parse(reply.result.content[0].text).success, true);
});
