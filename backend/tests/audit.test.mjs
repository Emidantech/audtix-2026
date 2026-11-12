import assert from "node:assert/strict";
import { createRequire } from "node:module";
import fs from "node:fs";
const req = createRequire("/opt/npm-tools/");
const pdfLib = req("pdf-lib"); const sharp = req("sharp");
import { handle } from "../supabase/functions/audit-request/handler.ts";
import { validateSubmission, rowsFor } from "../supabase/functions/audit-request/validate.ts";

const good = { org_type: "ngo", org_name: "Müller Foundation – “Test”", contact_person: "Jane", has_tin: "yes", tin: "1000 123 456", email: "j@a.co", phone: "+256 701 287 388",
  audit_types: ["donor", "internal", "other"], other_audit: "Grant close-out", audit_period: "FY 2025/26", donor_funded: "yes", donor_names: "War Child",
  bookkeeping: "software", accounting_software: "Odoo", notes: "x ".repeat(300), consent: true, accuracy_confirmed: true, elapsed_ms: 9000 };
async function mkPdf(n) { const d = await pdfLib.PDFDocument.create(); for (let i = 0; i < n; i++) d.addPage([300, 300]); return await d.save(); }
const png = await sharp({ create: { width: 800, height: 600, channels: 3, background: "#0479EE" } }).png().toBuffer();
const pdf3 = await mkPdf(3);

function run(parts, over = {}) {
  const sent = []; let inserted = null, files = null, pdfRow = null, uploaded = null;
  const deps = { pdfLib, env: (k) => ({ RESEND_API_KEY: "k" })[k], now: () => new Date("2026-11-10T08:00:00Z"),
    fetch: async (u, i) => { sent.push(JSON.parse(i.body)); return new Response("{}"); },
    storage: { uploadPdf: async (p, b) => { uploaded = p; } },
    db: { countRecent: async () => 0, insertRequest: async (r) => { inserted = r; return { id: "i", reference: "AUDIT-2026-00007" }; }, updateEmailStatus: async () => {}, logEvent: async () => {},
      insertFiles: async (id, r) => { files = r; }, updatePdf: async (id, p, b, n) => { pdfRow = { p, b, n }; } } };
  const fd = new FormData(); fd.set("data", JSON.stringify({ ...good, ...(over.data || {}) }));
  for (const [k, bytes, name, type] of parts) fd.append(k, new Blob([bytes], { type }), name);
  return handle(new Request("https://x/f", { method: "POST", body: fd }), deps).then((r) => ({ r, sent, inserted, files, pdfRow, uploaded }));
}

// happy path, with a PDF and a photo merged into one PDF
let o = await run([["file__financial_statements", pdf3, "fs.pdf", "application/pdf"], ["file__bank_statements", png, "bank.png", "image/png"]]);
assert.equal(o.r.status, 200, await o.r.clone().text());
assert.equal((await o.r.json()).reference, "AUDIT-2026-00007");
const att = o.sent[0].attachments; assert.equal(att.length, 1); assert.equal(att[0].filename, "AUDIT-2026-00007-audit-request.pdf");
assert.deepEqual(o.sent[0].to, ["info@audtix.com"]); assert.deepEqual(o.sent[0].cc, ["audtixsystem@gmail.com"]);
assert.equal(o.sent[0].reply_to, "j@a.co"); assert.ok(o.sent[0].subject.includes("audit service request"));
assert.ok(o.sent[0].text.includes("TIN: 1000123456"), "TIN present in the email");
assert.deepEqual(o.sent[1].to, ["j@a.co"]);
const merged = await pdfLib.PDFDocument.load(Buffer.from(att[0].content, "base64"));
console.log("merged pages", merged.getPageCount()); assert.ok(merged.getPageCount() >= 1 + 4 + 1);
assert.equal(o.inserted.org_name, good.org_name); assert.deepEqual(o.inserted.audit_types, ["donor", "internal", "other"]);
assert.equal(o.inserted.payload.tin, "1000123456"); assert.equal(o.files.length, 2); assert.equal(o.uploaded, "2026/AUDIT-2026-00007.pdf");
fs.writeFileSync("/tmp/sample-audit.pdf", Buffer.from(att[0].content, "base64"));

// conditional fields: hidden answers are dropped, a typed TIN is kept whatever has_tin says
let v = validateSubmission({ ...good, has_tin: "no", donor_funded: "no", bookkeeping: "none", audit_types: ["statutory"] });
assert.ok(v.ok); assert.equal(v.clean.tin, "1000123456"); assert.equal(v.clean.donor_names, undefined); assert.equal(v.clean.accounting_software, undefined); assert.equal(v.clean.other_audit, undefined);
// has_tin yes with no TIN: shown as Not provided
v = validateSubmission({ ...good, tin: "" }); assert.ok(v.ok); assert.ok(rowsFor(v.clean).some(([k, x]) => k === "TIN" && x === "Not provided"));
// required and format checks
for (const [patch, key] of [[{ org_name: "" }, "org_name"], [{ audit_types: [] }, "audit_types"], [{ audit_types: ["other"], other_audit: "" }, "other_audit"], [{ email: "nope" }, "email"], [{ phone: "12" }, "phone"],
  [{ tin: "123" }, "tin"], [{ org_type: "alien" }, "org_type"], [{ audit_period: "" }, "audit_period"], [{ consent: false }, "consent"], [{ accuracy_confirmed: false }, "accuracy_confirmed"], [{ notes: "a".repeat(2001) }, "notes"]]) {
  const r = validateSubmission({ ...good, ...patch }); assert.equal(r.ok, false, key); assert.ok(r.errors[key], key + " " + JSON.stringify(r.errors));
}
// bots, speed, files
o = await run([], { data: { website: "http://spam" } }); assert.equal(o.r.status, 200); assert.equal(o.inserted, null);
o = await run([], { data: { elapsed_ms: 500 } }); assert.equal(o.r.status, 400);
o = await run([["file__financial_statements", Buffer.from("MZ not a pdf"), "evil.pdf", "application/pdf"]]); assert.equal(o.r.status, 422);
o = await run([["file__tax_request_nope", pdf3, "a.pdf", "application/pdf"]]); assert.equal(o.r.status, 422);
o = await run([]); assert.equal(o.r.status, 200); assert.equal(o.sent[0].attachments.length, 1); // form only, still a PDF
// questionnaire: new fields, conditionals, dates, new upload types, section headings
const qa = { physical_address: "Plot 5, Ntinda", start_date: "2026-12-01", main_activities: "ICT", owners: "A and B", ownership_changes: "yes", ownership_changes_details: "New director",
  customer_types: "other", customer_other: "Diaspora", sales_payment: ["cash", "other"], sales_payment_other: "Cheque", holds_inventory: "yes", stocktake_freq: ["monthly"], last_stocktake: "2026-06-30",
  obsolete: ["write_off", "other"], obsolete_other: "Donated", tax_registrations: ["vat", "paye"], docs_available: ["cashbook", "other"], docs_available_other: "Board minutes", employee_count: "14" };
let r = validateSubmission({ ...good, ...qa }); assert.ok(r.ok, JSON.stringify(r.errors)); assert.equal(r.clean.customer_other, "Diaspora"); assert.deepEqual(r.clean.tax_registrations, ["vat", "paye"]);
r = validateSubmission({ ...good, ownership_changes: "no", ownership_changes_details: "x", customer_types: "businesses", customer_other: "x", sales_payment: ["cash"], sales_payment_other: "x" });
assert.ok(r.ok); assert.equal(r.clean.ownership_changes_details, undefined); assert.equal(r.clean.customer_other, undefined); assert.equal(r.clean.sales_payment_other, undefined);
for (const [patch, key] of [[{ start_date: "2026-13-45" }, "start_date"], [{ start_date: "tomorrow" }, "start_date"], [{ ppe: "perhaps" }, "ppe"], [{ tax_registrations: ["nope"] }, "x"]]) {
  const bad = validateSubmission({ ...good, ...patch }); if (key === "x") assert.ok(bad.ok && !bad.clean.tax_registrations); else { assert.equal(bad.ok, false, key); assert.ok(bad.errors[key], key); }
}
const rows = rowsFor(validateSubmission({ ...good, ...qa }).clean);
assert.ok(rows.some(([k]) => k === "§ A. General information") );
assert.ok(rows.some(([k]) => k === "§ A. General information")); assert.ok(rows.some(([k]) => k === "§ H. Inventory")); assert.ok(!rows.some(([k]) => k === "§ K. Trade payables"), "empty sections have no heading");
o = await run([["file__payroll_records", pdf3, "payroll.pdf", "application/pdf"], ["file__inventory_listing", png, "stock.png", "image/png"], ["file__management_letter", pdf3, "ml.pdf", "application/pdf"]], { data: qa });
assert.equal(o.r.status, 200, await o.r.clone().text()); assert.equal(o.inserted.payload.physical_address, "Plot 5, Ntinda"); assert.equal(o.files.length, 3);
assert.ok(o.sent[0].text.includes("== H. Inventory =="), "email carries section headings");
fs.writeFileSync("/tmp/sample-audit-q.pdf", Buffer.from(o.sent[0].attachments[0].content, "base64"));
console.log("audit tests passed");
