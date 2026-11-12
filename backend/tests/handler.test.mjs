import assert from "node:assert/strict";
import { handle } from "../supabase/functions/tax-request/handler.ts";
import { createRequire } from "node:module";
const pdfLib = createRequire("/opt/npm-tools/")("pdf-lib");
import { validateSubmission } from "../supabase/functions/tax-request/validate.ts";

const good = () => ({
  applicant_type: "business", display_name: "Acme Traders Ltd", contact_person: "Jane Doe", has_tin: "yes", tin: "1000123456",
  reg_number: "80020001", email: "Jane@Acme.co.ug", phone: "+256 701 287 388", preferred_contact: "whatsapp", location: "Kampala",
  services: ["annual_return", "vat_returns"], tax_periods: "FY 2025/26", timeline: "month", year_end: "30_june", turnover_band: "50m_150m",
  employees_band: "6_20", vat_registered: "yes", bookkeeping: "software", ura_notice: "no", notes: "<b>hi</b>", heard_from: "search",
  consent: true, accuracy_confirmed: true, elapsed_ms: 9000, website: "", extra: { "Branch count": "2" },
  business_sector: "retail", operating_status: "no", stopped_on: "2026-03-01", years_operating: "4_7", accounting_software: "QuickBooks", records_available: ["bank_statements","none","sales_invoices"], filing_status: "some_missing", outstanding_years: "2", ura_communications: ["penalty"], payroll_monthly: "yes", biggest_challenge: "late_filing",
});
function mk(over = {}) {
  const sent = [], rows = [], events = [], status = {};
  let count = 0, emailFail = false, dbFail = false;
  const deps = {
    pdfLib,
    env: (k) => ({ RESEND_API_KEY: "k", ...over.env })[k],
    fetch: async (u, i) => { sent.push(JSON.parse(i.body)); return over.emailFail ? new Response("nope", { status: 403 }) : new Response("{}", { status: 200 }); },
    now: () => new Date("2026-11-05T08:00:00Z"),
    db: {
      countRecent: async () => over.count ?? 0,
      insertRequest: async (r) => { if (over.dbFail) throw new Error("db"); rows.push(r); return { id: "id1", reference: "AUD-2026-00001" }; },
      updateEmailStatus: async (id, s, e) => { status.s = s; status.e = e; },
      logEvent: async (id, a) => { events.push(a); },
      insertFiles: async () => {},
      updatePdf: async () => {},
    },
  };
  return { deps, sent, rows, events, status };
}
const post = (body, headers = {}) => new Request("https://x/f", { method: "POST", headers: { "content-type": "application/json", ...headers }, body: JSON.stringify(body) });
const j = async (r) => r.json();

// validator
let v = validateSubmission(good()); assert.ok(v.ok); assert.equal(v.clean.email, "jane@acme.co.ug");
assert.equal(validateSubmission({}).ok, false);
assert.ok(validateSubmission({ ...good(), tin: "123" }).errors.tin);
assert.ok(validateSubmission({ ...good(), services: ["x"] }).errors.services);
assert.ok(validateSubmission({ ...good(), services: ["other"] }).errors.other_service);
assert.ok(validateSubmission({ ...good(), consent: false }).errors.consent);
assert.ok(validateSubmission({ ...good(), contact_person: "" }).errors.contact_person);
assert.ok(validateSubmission({ ...good(), applicant_type: "individual", contact_person: "", reg_number: "zz" }).ok);
assert.ok(validateSubmission({ ...good(), phone: "abc" }).errors.phone);
assert.ok(validateSubmission({ ...good(), timeline: "bogus" }).errors.timeline);

// success: to/cc correct, escaped html, confirmation
let t = mk(); let r = await handle(post(good()), t.deps);
assert.equal(r.status, 200); assert.equal((await j(r)).reference, "AUD-2026-00001");
assert.deepEqual(t.sent[0].to, ["info@audtix.com"]); assert.deepEqual(t.sent[0].cc, ["audtixsystem@gmail.com"]);
assert.equal(t.sent[0].reply_to, "jane@acme.co.ug"); assert.ok(!t.sent[0].html.includes("<b>hi</b>")); assert.ok(t.sent[0].html.includes("&lt;b&gt;"));
assert.ok(t.sent[0].text.includes("Branch count: 2")); assert.deepEqual(t.sent[1].to, ["jane@acme.co.ug"]);
assert.equal(t.status.s, "sent"); assert.equal(t.rows.length, 1);
// email failure still saves
t = mk({ emailFail: true }); r = await handle(post(good()), t.deps);
assert.equal(r.status, 200); assert.equal(t.status.s, "failed"); assert.equal(t.rows.length, 1);
// no api key
t = mk({ env: { RESEND_API_KEY: "" } }); r = await handle(post(good()), t.deps); assert.equal(r.status, 200); assert.equal(t.status.s, "failed");
// honeypot, too fast, validation, rate, db fail, method, json, origin, size
t = mk(); r = await handle(post({ ...good(), website: "http://spam" }), t.deps); assert.equal(r.status, 200); assert.equal(t.rows.length, 0);
r = await handle(post({ ...good(), elapsed_ms: 500 }), mk().deps); assert.equal(r.status, 400);
r = await handle(post({ ...good(), email: "bad" }), mk().deps); assert.equal(r.status, 422);
r = await handle(post(good()), mk({ count: 8 }).deps); assert.equal(r.status, 429);
r = await handle(post(good()), mk({ dbFail: true }).deps); assert.equal(r.status, 500);
r = await handle(new Request("https://x/f", { method: "GET" }), mk().deps); assert.equal(r.status, 405);
r = await handle(new Request("https://x/f", { method: "OPTIONS" }), mk().deps); assert.equal(r.status, 204);
r = await handle(new Request("https://x/f", { method: "POST", body: "{bad" }), mk().deps); assert.equal(r.status, 400);
r = await handle(post(good(), { origin: "https://evil.com" }), mk({ env: { ALLOWED_ORIGINS: "https://audtix.com" } }).deps); assert.equal(r.status, 403);
r = await handle(post(good(), { origin: "https://audtix.com" }), mk({ env: { ALLOWED_ORIGINS: "https://audtix.com" } }).deps); assert.equal(r.status, 200);
r = await handle(post({ ...good(), notes: "x".repeat(70000) }), mk().deps); assert.equal(r.status, 413);
console.log("handler tests passed");
{ const t = mk(); await handle(post(good()), t.deps); assert.equal("extra" in t.rows[0], false); assert.equal(t.rows[0].payload.extra["Branch count"], "2"); console.log("row shape ok"); }
