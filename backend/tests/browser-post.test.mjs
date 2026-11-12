// Feeds the exact multipart request the browser produced into the real handler.
import assert from "node:assert/strict"; import fs from "node:fs"; import { createRequire } from "node:module";
const pdfLib = createRequire("/opt/npm-tools/")("pdf-lib");
import { handle } from "../supabase/functions/tax-request/handler.ts";
const [bin, ct] = process.argv.slice(2);
const sent = []; let row;
const deps = { pdfLib, env: (k) => ({ RESEND_API_KEY: "k" })[k], now: () => new Date(), fetch: async (u, i) => { sent.push(JSON.parse(i.body)); return new Response("{}"); },
  db: { countRecent: async () => 0, insertRequest: async (r) => { row = r; return { id: "i", reference: "AUD-2026-00011" }; }, updateEmailStatus: async () => {}, logEvent: async () => {}, insertFiles: async () => {}, updatePdf: async () => {} } };
const r = await handle(new Request("https://x/f", { method: "POST", headers: { "content-type": fs.readFileSync(ct, "utf8") }, body: fs.readFileSync(bin) }), deps);
assert.equal(r.status, 200, await r.text());
assert.equal(row.business_sector, "ict"); assert.equal(row.stopped_on, "2026-03-01"); assert.deepEqual(row.records_available, ["bank_statements"]);
assert.equal(row.outstanding_years, "2"); assert.deepEqual(row.ura_communications, ["penalty"]); assert.equal(row.payroll_monthly, "yes"); assert.equal(row.accounting_software, "QuickBooks"); assert.equal(row.biggest_challenge, "late_filing");
const doc = await pdfLib.PDFDocument.load(Buffer.from(sent[0].attachments[0].content, "base64"));
console.log("browser upload accepted; combined PDF pages:", doc.getPageCount(), "to", sent[0].to, "cc", sent[0].cc);
