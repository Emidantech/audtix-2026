// Spec-driven validation and wording for the public audit request form.
// The same form-spec.json builds the web form, so the page, the checks and the emails cannot drift apart.
import specJson from "./form-spec.json" with { type: "json" };

type Pair = [string, string];
export interface Field {
  name: string;
  label: string;
  type: "text" | "email" | "phone" | "tin" | "select" | "radio" | "checks" | "textarea";
  required: boolean;
  max: number;
  enum?: string;
  show?: { field: string; equals?: string; includes?: string };
}
const spec = specJson as unknown as {
  enums: Record<string, Pair[]>;
  fields: Field[];
  documents: Pair[];
  upload: { maxFileBytes: number; maxTotalBytes: number; maxFilesPerType: number; types: string[] };
};
export const FIELDS = spec.fields;
export const DOCUMENTS = spec.documents;
export const DOC_VALUES = DOCUMENTS.map((p) => p[0]);
export const UPLOAD = spec.upload;

export type Value = string | string[];
export type Clean = Record<string, Value>;

export function enumLabel(group: string | undefined, v: string): string {
  if (!group) return v;
  const hit = spec.enums[group]?.find((p) => p[0] === v);
  return hit ? hit[1] : v;
}
export function docLabel(v: string): string {
  return DOCUMENTS.find((p) => p[0] === v)?.[1] ?? v;
}
export function str(c: Clean, k: string): string {
  const v = c[k];
  return typeof v === "string" ? v : "";
}

function visible(f: Field, raw: Record<string, unknown>): boolean {
  if (!f.show) return true;
  const v = raw[f.show.field];
  if (f.show.equals !== undefined) return v === f.show.equals;
  if (f.show.includes !== undefined) return Array.isArray(v) && v.includes(f.show.includes);
  return true;
}

export type Result = { ok: true; clean: Clean } | { ok: false; errors: Record<string, string> };

export function validateSubmission(input: unknown): Result {
  const raw = (input && typeof input === "object" ? input : {}) as Record<string, unknown>;
  const errors: Record<string, string> = {};
  const clean: Clean = {};
  for (const f of FIELDS) {
    const shown = visible(f, raw);
    const v = raw[f.name];
    if (f.type === "checks") {
      const allowed = (spec.enums[f.enum!] || []).map((p) => p[0]);
      const arr = Array.isArray(v) ? [...new Set(v.filter((x): x is string => typeof x === "string" && allowed.includes(x)))] : [];
      if (shown) {
        if (arr.length) clean[f.name] = arr;
        else if (f.required) errors[f.name] = "Choose at least one.";
      }
      continue;
    }
    let s = typeof v === "string" ? v.trim() : "";
    if (f.type === "tin") s = s.replace(/\s+/g, ""); // a TIN that was typed is always kept
    else if (!shown) s = "";
    if (s.length > f.max) {
      errors[f.name] = `Please keep this under ${f.max} characters.`;
      continue;
    }
    if (!s) {
      if (f.required && shown) errors[f.name] = "This is required.";
      continue;
    }
    if ((f.type === "select" || f.type === "radio") && !(spec.enums[f.enum!] || []).some((p) => p[0] === s)) {
      errors[f.name] = "Please choose one of the options.";
      continue;
    }
    if (f.type === "email" && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s)) { errors[f.name] = "Enter a valid email address."; continue; }
    if (f.type === "phone") {
      const d = s.replace(/\D/g, "");
      if (!/^[+\d\s().-]+$/.test(s) || d.length < 7 || d.length > 15) { errors[f.name] = "Enter a valid phone number."; continue; }
    }
    if (f.type === "tin" && !/^[0-9]{10}$/.test(s)) { errors[f.name] = "A TIN has 10 digits."; continue; }
    clean[f.name] = s;
  }
  if (raw.accuracy_confirmed !== true) errors.accuracy_confirmed = "Please confirm that the information is true and complete.";
  if (raw.consent !== true) errors.consent = "Please confirm so we can contact you.";
  return Object.keys(errors).length ? { ok: false, errors } : { ok: true, clean };
}

export function escapeHtml(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}
export function kampalaTime(d: Date): string {
  return new Intl.DateTimeFormat("en-GB", { timeZone: "Africa/Kampala", dateStyle: "medium", timeStyle: "short" }).format(d) + " (Kampala)";
}

export function rowsFor(c: Clean): Array<[string, string]> {
  const out: Array<[string, string]> = [];
  for (const f of FIELDS) {
    const v = c[f.name];
    if (f.name === "tin") {
      if (str(c, "has_tin") === "yes" || v) out.push(["TIN", typeof v === "string" && v ? v : "Not provided"]);
      continue;
    }
    if (Array.isArray(v)) out.push([f.label, v.map((x) => enumLabel(f.enum, x)).join(", ")]);
    else if (typeof v === "string" && v) out.push([f.label, f.enum ? enumLabel(f.enum, v) : v]);
  }
  return out;
}

export function buildNotification(c: Clean, reference: string, when: Date, documents?: string) {
  const rows: Array<[string, string]> = [["Reference", reference], ["Received", kampalaTime(when)], ...rowsFor(c)];
  if (documents) rows.push(["Documents", documents]);
  const subject = `New audit service request ${reference}: ${str(c, "org_name")}`;
  const text = `New audit service request from the Audtix website.\n\n` + rows.map(([k, v]) => `${k}: ${v}`).join("\n") + `\n\nReply to this email to reach the client directly.`;
  const html =
    `<div style="font-family:Arial,Helvetica,sans-serif;color:#0B1B33;max-width:640px">` +
    `<h2 style="margin:0 0 4px;color:#0366D0">New audit service request</h2>` +
    `<p style="margin:0 0 16px;color:#475A75">Reference <b>${escapeHtml(reference)}</b>. Reply to this email to reach the client.</p>` +
    `<table style="border-collapse:collapse;width:100%">` +
    rows.map(([k, v]) => `<tr><td style="padding:8px 12px;border-top:1px solid #D5E2F2;color:#475A75;width:200px;vertical-align:top">${escapeHtml(k)}</td><td style="padding:8px 12px;border-top:1px solid #D5E2F2;vertical-align:top">${escapeHtml(v).replace(/\n/g, "<br>")}</td></tr>`).join("") +
    `</table></div>`;
  return { subject, text, html };
}

export function buildConfirmation(c: Clean, reference: string) {
  const subject = `We received your audit service request ${reference}`;
  const list = ((c.audit_types as string[]) || []).map((x) => enumLabel("audit_types", x)).join(", ");
  const how = (str(c, "preferred_contact") ? enumLabel("preferred_contact", str(c, "preferred_contact")) : "email").toLowerCase();
  const name = str(c, "org_name");
  const text =
    `Hello ${name},\n\nThank you for contacting Audtix Systems. We have received your audit service request.\n\n` +
    `Reference: ${reference}\nServices: ${list}\nPeriod: ${str(c, "audit_period")}\n\n` +
    `Our audit team will review it and contact you by ${how}. Quote the reference above if you write to us. You can also reach us on WhatsApp at +256 701 287 388.\n\n` +
    `Please do not send passwords by email or WhatsApp.\n\nAudtix Systems`;
  const html =
    `<div style="font-family:Arial,Helvetica,sans-serif;color:#0B1B33;max-width:560px"><h2 style="color:#0366D0;margin:0 0 12px">We received your request</h2>` +
    `<p>Hello ${escapeHtml(name)},</p><p>Thank you for contacting Audtix Systems. We have received your audit service request.</p>` +
    `<p><b>Reference:</b> ${escapeHtml(reference)}<br><b>Services:</b> ${escapeHtml(list)}<br><b>Period:</b> ${escapeHtml(str(c, "audit_period"))}</p>` +
    `<p>Our audit team will review it and contact you by ${escapeHtml(how)}. Quote the reference above if you write to us. You can also reach us on WhatsApp at +256 701 287 388.</p>` +
    `<p style="color:#475A75">Please do not send passwords by email or WhatsApp.</p><p>Audtix Systems</p></div>`;
  return { subject, text, html };
}
