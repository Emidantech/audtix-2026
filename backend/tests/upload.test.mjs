import assert from "node:assert/strict";
import { createRequire } from "node:module";
import fs from "node:fs";
const req = createRequire("/opt/npm-tools/");
const pdfLib = req("pdf-lib"); const sharp = req("sharp");
import { handle } from "../supabase/functions/tax-request/handler.ts";

const good = { applicant_type: "business", display_name: "Müller Ltd – “Test” 日本", contact_person: "Jane", has_tin: "yes", tin: "1000123456", email: "j@a.co", phone: "+256701287388",
  services: ["annual_return","training","tax_health_check"], tax_periods: "FY26", consent: true, accuracy_confirmed: true, elapsed_ms: 9000,
  records_available: ["bank_statements"], business_sector: "ict", notes: "x ".repeat(600) + "a".repeat(300) };
async function mkPdf(n) { const d = await pdfLib.PDFDocument.create(); for (let i = 0; i < n; i++) d.addPage([300, 300]); return await d.save(); }
const png = await sharp({ create: { width: 800, height: 600, channels: 3, background: "#0479EE" } }).png().toBuffer();
const jpg = await sharp({ create: { width: 3000, height: 4000, channels: 3, background: "#ff8800" } }).jpeg().toBuffer();
const pdf3 = await mkPdf(3), pdf1 = await mkPdf(1);

function run(parts, over = {}) {
  const sent = []; let pdfRow = null, files = null, uploaded = null;
  const deps = { pdfLib, env: (k) => ({ RESEND_API_KEY: "k" })[k], now: () => new Date("2026-11-06T08:00:00Z"),
    fetch: async (u, i) => { sent.push(JSON.parse(i.body)); return new Response("{}"); },
    storage: { uploadPdf: async (p, b) => { uploaded = { p, n: b.length }; } },
    db: { countRecent: async () => 0, insertRequest: async () => ({ id: "i", reference: "AUD-2026-00009" }), updateEmailStatus: async () => {}, logEvent: async () => {},
      insertFiles: async (id, r) => { files = r; }, updatePdf: async (id, p, b, n) => { pdfRow = { p, b, n }; } } };
  const fd = new FormData(); fd.set("data", JSON.stringify({ ...good, ...(over.data || {}) }));
  for (const [k, bytes, name, type] of parts) fd.append(k, new Blob([bytes], { type }), name);
  return handle(new Request("https://x/f", { method: "POST", body: fd }), deps).then((r) => ({ r, sent, pdfRow, files, uploaded }));
}

let o = await run([["file__tin_certificate", pdf3, "tin.pdf", "application/pdf"], ["file__bank_statements", png, "bank.png", "image/png"], ["file__sales_records", jpg, "sales.jpg", "image/jpeg"], ["file__ura_notices", pdf1, "notice.pdf", "application/pdf"]]);
assert.equal(o.r.status, 200, await o.r.clone().text());
const att = o.sent[0].attachments; assert.equal(att.length, 1); assert.equal(att[0].filename, "AUD-2026-00009-tax-request.pdf");
assert.deepEqual(o.sent[0].to, ["info@audtix.com"]); assert.deepEqual(o.sent[0].cc, ["audtixsystem@gmail.com"]);
assert.equal(o.sent[1].attachments, undefined);
const merged = await pdfLib.PDFDocument.load(Buffer.from(att[0].content, "base64"));
const formPages = merged.getPageCount() - (1 + 3) - 1 - 1 - 1 - 1; // pdf3: divider+3, png:1, jpg:1, pdf1: divider+1
console.log("pages", merged.getPageCount(), "form pages", formPages); assert.ok(formPages >= 1 && formPages <= 3);
assert.equal(o.files.length, 4); assert.equal(o.pdfRow.n, 4); assert.equal(o.uploaded.p, "2026/AUD-2026-00009.pdf");
fs.writeFileSync("/tmp/sample-merged.pdf", Buffer.from(att[0].content, "base64"));

// no files: still a PDF
o = await run([]); assert.equal(o.r.status, 200); assert.equal(o.sent[0].attachments.length, 1);
// rejects
for (const [parts, msg] of [
  [[["file__tin_certificate", Buffer.from("MZ not a pdf"), "evil.pdf", "application/pdf"]], "PDF, JPG or PNG"],
  [[["file__nope", pdf1, "a.pdf", "application/pdf"]], "document types"],
  [[["file__bank_statements", Buffer.alloc(5.5 * 1024 * 1024, 1), "big.pdf", "application/pdf"]], "larger than"],
  [[1, 2, 3, 4].map(() => ["file__bank_statements", pdf1, "a.pdf", "application/pdf"]), "at most"],
]) { o = await run(parts); assert.equal(o.r.status, 422); assert.ok(JSON.stringify(await o.r.json()).includes(msg), msg); }
// total limit
{ const big = Buffer.concat([Buffer.from("%PDF-1.4\n"), Buffer.alloc(4.9 * 1024 * 1024)]);
  o = await run([["file__tin_certificate", big, "a.pdf", "application/pdf"], ["file__bank_statements", big, "b.pdf", "application/pdf"], ["file__sales_records", big, "c.pdf", "application/pdf"], ["file__ura_notices", big, "d.pdf", "application/pdf"]]);
  assert.equal(o.r.status, 422); assert.ok((await o.r.json()).errors.files.includes("Together")); }
// corrupt pdf (valid header) is attached separately, not lost
o = await run([["file__tin_certificate", Buffer.from("%PDF-1.4 garbage garbage"), "bad.pdf", "application/pdf"]]);
assert.equal(o.r.status, 200); assert.equal(o.sent[0].attachments.length, 2); assert.equal(o.sent[0].attachments[1].filename, "bad.pdf");
// missing new confirmation
o = await run([], { data: { accuracy_confirmed: false } }); assert.equal(o.r.status, 422);
// conditional fields dropped server side
o = await run([], { data: { operating_status: "yes", stopped_on: "2026-01-01", filing_status: "yes", outstanding_years: "3", ura_notice: "no", ura_communications: ["penalty"] } }); assert.equal(o.r.status, 200);
console.log("upload tests passed");
