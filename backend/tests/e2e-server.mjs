// Tiny local server that runs the REAL handler with fake database and email. Used for browser end-to-end tests.
import http from "node:http"; import fs from "node:fs"; import { createRequire } from "node:module";
const pdfLib = createRequire("/opt/npm-tools/")("pdf-lib");
import { handle } from "../supabase/functions/tax-request/handler.ts";
const out = process.argv[2];
const deps = { pdfLib, env: (k) => ({ RESEND_API_KEY: "k" })[k], now: () => new Date(),
  fetch: async (u, i) => { const b = JSON.parse(i.body); if (b.attachments) { fs.writeFileSync(out + ".json", JSON.stringify({ to: b.to, cc: b.cc, subject: b.subject, n: b.attachments.length })); fs.writeFileSync(out + ".pdf", Buffer.from(b.attachments[0].content, "base64")); } return new Response("{}"); },
  db: { countRecent: async () => 0, insertRequest: async (r) => { fs.writeFileSync(out + ".row.json", JSON.stringify(r)); return { id: "i", reference: "AUD-2026-00012" }; }, updateEmailStatus: async () => {}, logEvent: async () => {}, insertFiles: async () => {}, updatePdf: async () => {} } };
http.createServer(async (rq, rs) => {
  const chunks = []; for await (const c of rq) chunks.push(c);
  const req = new Request("http://x" + rq.url, { method: rq.method, headers: rq.headers, body: ["GET", "HEAD"].includes(rq.method) ? undefined : Buffer.concat(chunks) });
  const r = await handle(req, deps); rs.writeHead(r.status, Object.fromEntries(r.headers)); rs.end(Buffer.from(await r.arrayBuffer()));
}).listen(8767, () => console.log("up"));
