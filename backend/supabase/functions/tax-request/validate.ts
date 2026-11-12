// Validation and email wording for tax service requests.
// Pure functions only (no Deno or network calls) so they can be tested anywhere.
import spec from "./form-spec.json" with { type: "json" };

type Pair = [string, string];
const enums = spec.enums as unknown as Record<string, Pair[]>;
const services = spec.services as unknown as Pair[];
export const DOCUMENTS = spec.documents as unknown as Pair[];
export const DOC_VALUES: string[] = DOCUMENTS.map((p) => p[0]);
export const UPLOAD = spec.upload as { maxFileBytes: number; maxTotalBytes: number; maxFilesPerType: number; types: string[] };

export const ENUM_VALUES: Record<string, string[]> = Object.fromEntries(
  Object.entries(enums).map(([k, v]) => [k, v.map((p) => p[0])]),
);
export const SERVICE_VALUES: string[] = services.map((p) => p[0]);

export function labelOf(group: string, value: string | null | undefined): string {
  if (!value) return "";
  const hit = (enums[group] || []).find((p) => p[0] === value);
  return hit ? hit[1] : value;
}
export function docLabel(value: string): string {
  const hit = DOCUMENTS.find((p) => p[0] === value);
  return hit ? hit[1] : value;
}
export function serviceLabel(value: string): string {
  const hit = services.find((p) => p[0] === value);
  return hit ? hit[1] : value;
}

export interface Clean {
  applicant_type: string;
  display_name: string;
  contact_person: string | null;
  has_tin: string;
  tin: string | null;
  reg_number: string | null;
  email: string;
  phone: string;
  preferred_contact: string;
  location: string | null;
  services: string[];
  other_service: string | null;
  tax_periods: string;
  timeline: string | null;
  year_end: string | null;
  turnover_band: string | null;
  employees_band: string | null;
  vat_registered: string | null;
  bookkeeping: string | null;
  ura_notice: string | null;
  ura_notice_details: string | null;
  notes: string | null;
  heard_from: string | null;
  business_sector: string | null;
  operating_status: string | null;
  stopped_on: string | null;
  years_operating: string | null;
  accounting_software: string | null;
  records_available: string[] | null;
  filing_status: string | null;
  outstanding_years: string | null;
  ura_communications: string[] | null;
  payroll_monthly: string | null;
  biggest_challenge: string | null;
  accuracy_confirmed: true;
  consent: true;
  extra: Record<string, string>;
}

export type Result = { ok: true; clean: Clean } | { ok: false; errors: Record<string, string> };

// eslint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g;

export function validateSubmission(input: unknown): Result {
  const o: Record<string, unknown> =
    input && typeof input === "object" && !Array.isArray(input) ? (input as Record<string, unknown>) : {};
  const errors: Record<string, string> = {};

  const clean = (v: unknown): string => (typeof v === "string" ? v.replace(CONTROL, "").trim() : "");

  const text = (key: string, label: string, opts: { min?: number; max: number; required?: boolean }): string | null => {
    const s = clean(o[key]);
    if (!s) {
      if (opts.required) errors[key] = `${label} is required.`;
      return null;
    }
    if (opts.min && s.length < opts.min) errors[key] = `${label} is too short.`;
    else if (s.length > opts.max) errors[key] = `${label} is too long.`;
    return s;
  };

  const pick = (key: string, label: string, required: boolean, fallback: string | null = null): string | null => {
    const s = clean(o[key]);
    if (!s) {
      if (required) errors[key] = `Please choose ${label}.`;
      return fallback;
    }
    if (!ENUM_VALUES[key].includes(s)) {
      errors[key] = `Please choose ${label}.`;
      return null;
    }
    return s;
  };

  const applicant_type = pick("applicant_type", "who is applying", true);
  const isIndividual = applicant_type === "individual";
  const display_name = text("display_name", "Name", { min: 2, max: 200, required: true });
  const contact_person = text("contact_person", "Contact person", { min: 2, max: 200, required: !!applicant_type && !isIndividual });

  const has_tin = pick("has_tin", "whether you have a TIN", true);
  let tin: string | null = null;
  const tinRaw = clean(o.tin).replace(/\s+/g, "");
  if (tinRaw) { // keep any valid TIN the client typed, whatever they answered above it
    if (/^[0-9]{10}$/.test(tinRaw)) tin = tinRaw;
    else errors.tin = "A TIN has 10 digits.";
  }
  const reg_number = isIndividual ? null : text("reg_number", "Registration number", { max: 60 });

  let email = text("email", "Email", { max: 254, required: true });
  if (email) {
    email = email.toLowerCase();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) errors.email = "Enter a valid email address.";
  }
  const phone = text("phone", "Phone or WhatsApp number", { max: 30, required: true });
  if (phone && !errors.phone) {
    const digits = phone.replace(/\D/g, "");
    if (!/^[+\d\s().-]+$/.test(phone) || digits.length < 7 || digits.length > 15) {
      errors.phone = "Enter a valid phone number.";
    }
  }
  const preferred_contact = pick("preferred_contact", "how we should contact you", false, "email") as string;
  const location = text("location", "Location", { max: 120 });

  // services
  let svc: string[] = [];
  if (Array.isArray(o.services)) {
    svc = Array.from(new Set(o.services.filter((x): x is string => typeof x === "string").map((x) => x.trim())));
  }
  if (svc.length === 0) errors.services = "Choose at least one service.";
  else if (svc.length > SERVICE_VALUES.length || svc.some((x) => !SERVICE_VALUES.includes(x))) errors.services = "One of the services is not valid.";
  const other_service = svc.includes("other") ? text("other_service", "Other service", { min: 2, max: 200, required: true }) : null;
  const tax_periods = text("tax_periods", "Tax periods", { max: 200, required: true });
  const timeline = pick("timeline", "a timeline", false);

  const year_end = pick("year_end", "a year end", false);
  const turnover_band = pick("turnover_band", "a turnover range", false);
  const employees_band = pick("employees_band", "the number of employees", false);
  const vat_registered = pick("vat_registered", "an answer", false);
  const bookkeeping = pick("bookkeeping", "an answer", false);
  const ura_notice = pick("ura_notice", "an answer", false);
  const ura_notice_details = ura_notice === "yes" ? text("ura_notice_details", "Notice details", { max: 2000 }) : null;
  const notes = text("notes", "Notes", { max: 2000 });
  const heard_from = pick("heard_from", "an answer", false);

  const multi = (key: string, label: string): string[] | null => {
    if (!Array.isArray(o[key])) return null;
    const vals = Array.from(new Set((o[key] as unknown[]).filter((x): x is string => typeof x === "string").map((x) => x.trim())));
    if (vals.some((x) => !ENUM_VALUES[key].includes(x))) {
      errors[key] = `One of the ${label} is not valid.`;
      return null;
    }
    const out = vals.includes("none") && vals.length > 1 ? vals.filter((x) => x !== "none") : vals;
    return out.length ? out : null;
  };

  const business_sector = pick("business_sector", "a business sector", false);
  const operating_status = pick("operating_status", "an answer", false);
  let stopped_on: string | null = null;
  if (operating_status && operating_status !== "yes") {
    const d = clean(o.stopped_on);
    if (d) {
      const t = Date.parse(d + "T00:00:00Z");
      if (!/^\d{4}-\d{2}-\d{2}$/.test(d) || Number.isNaN(t) || new Date(t).toISOString().slice(0, 10) !== d) errors.stopped_on = "Enter a valid date.";
      else if (t > Date.now() + 86400000) errors.stopped_on = "The date cannot be in the future.";
      else stopped_on = d;
    }
  }
  const years_operating = pick("years_operating", "how long the business has operated", false);
  const accounting_software = bookkeeping === "software" ? text("accounting_software", "Accounting software", { max: 120 }) : null;
  const records_available = multi("records_available", "records");
  const filing_status = pick("filing_status", "an answer", false);
  const outstanding_years = filing_status && filing_status !== "yes" ? pick("outstanding_years", "an answer", false) : null;
  const ura_communications = ura_notice === "yes" ? multi("ura_communications", "notices") : null;
  const payroll_monthly = employees_band && employees_band !== "none" ? pick("payroll_monthly", "an answer", false) : null;
  const biggest_challenge = pick("biggest_challenge", "your biggest challenge", false);

  if (o.accuracy_confirmed !== true) errors.accuracy_confirmed = "Please confirm that the information is true and complete.";
  if (o.consent !== true) errors.consent = "Please confirm that we may contact you about this request.";

  // extra questions that have no column of their own yet
  const extra: Record<string, string> = {};
  if (o.extra && typeof o.extra === "object" && !Array.isArray(o.extra)) {
    const entries = Object.entries(o.extra as Record<string, unknown>);
    if (entries.length > 20) errors.extra = "Too many extra answers.";
    for (const [k, v] of entries.slice(0, 20)) {
      const key = clean(k).slice(0, 80);
      const val = clean(v);
      if (!key || !val) continue;
      if (val.length > 1000) errors.extra = "An extra answer is too long.";
      else extra[key] = val;
    }
  }

  if (Object.keys(errors).length) return { ok: false, errors };

  return {
    ok: true,
    clean: {
      applicant_type: applicant_type as string,
      display_name: display_name as string,
      contact_person,
      has_tin: has_tin as string,
      tin,
      reg_number,
      email: email as string,
      phone: phone as string,
      preferred_contact,
      location,
      services: svc,
      other_service,
      tax_periods: tax_periods as string,
      timeline,
      year_end,
      turnover_band,
      employees_band,
      vat_registered,
      bookkeeping,
      ura_notice,
      ura_notice_details,
      notes,
      heard_from,
      business_sector,
      operating_status,
      stopped_on,
      years_operating,
      accounting_software,
      records_available,
      filing_status,
      outstanding_years,
      ura_communications,
      payroll_monthly,
      biggest_challenge,
      accuracy_confirmed: true,
      consent: true,
      extra,
    },
  };
}

// ---------- Email wording ----------

export function escapeHtml(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

export function kampalaTime(d: Date): string {
  return new Intl.DateTimeFormat("en-GB", { timeZone: "Africa/Kampala", dateStyle: "medium", timeStyle: "short" }).format(d) + " (Kampala)";
}

export function rowsFor(c: Clean, reference: string, when: Date): Array<[string, string]> {
  const r: Array<[string, string | null | undefined]> = [
    ["Reference", reference],
    ["Received", kampalaTime(when)],
    ["Applying as", labelOf("applicant_type", c.applicant_type)],
    ["Name", c.display_name],
    ["Contact person", c.contact_person],
    ["Has a TIN", labelOf("has_tin", c.has_tin)],
    ["TIN", c.tin],
    ["Registration number", c.reg_number],
    ["Email", c.email],
    ["Phone or WhatsApp", c.phone],
    ["Preferred contact", labelOf("preferred_contact", c.preferred_contact)],
    ["Location", c.location],
    ["Services needed", c.services.map(serviceLabel).join(", ")],
    ["Other service", c.other_service],
    ["Tax periods", c.tax_periods],
    ["Timeline", labelOf("timeline", c.timeline)],
    ["Year end", labelOf("year_end", c.year_end)],
    ["Annual turnover", labelOf("turnover_band", c.turnover_band)],
    ["Employees", labelOf("employees_band", c.employees_band)],
    ["VAT registered", labelOf("vat_registered", c.vat_registered)],
    ["Records kept in", labelOf("bookkeeping", c.bookkeeping)],
    ["URA notice or assessment", labelOf("ura_notice", c.ura_notice)],
    ["Notice details", c.ura_notice_details],
    ["Notes", c.notes],
    ["Heard about us from", labelOf("heard_from", c.heard_from)],
    ["Business sector", labelOf("business_sector", c.business_sector)],
    ["Currently operating", labelOf("operating_status", c.operating_status)],
    ["Stopped operating on", c.stopped_on],
    ["Time in operation", labelOf("years_operating", c.years_operating)],
    ["Accounting software", c.accounting_software],
    ["Records available", (c.records_available || []).map((v) => labelOf("records_available", v)).join(", ")],
    ["Filing returns regularly", labelOf("filing_status", c.filing_status)],
    ["Years of returns outstanding", labelOf("outstanding_years", c.outstanding_years)],
    ["URA communication received", (c.ura_communications || []).map((v) => labelOf("ura_communications", v)).join(", ")],
    ["Payroll processed monthly", labelOf("payroll_monthly", c.payroll_monthly)],
    ["Biggest challenge", labelOf("biggest_challenge", c.biggest_challenge)],
    ["Information confirmed true and complete", "Yes"],
  ];
  for (const [k, v] of Object.entries(c.extra)) r.push([k, v]);
  return r.filter((x): x is [string, string] => !!x[1]);
}

export function buildNotification(c: Clean, reference: string, when: Date, documents?: string) {
  const rows = rowsFor(c, reference, when);
  if (documents) rows.push(["Documents", documents]);
  const subject = `New tax service request ${reference}: ${c.display_name}`;
  const text =
    `New tax service request from the Audtix website.\n\n` +
    rows.map(([k, v]) => `${k}: ${v}`).join("\n") +
    `\n\nReply to this email to reach the client directly.`;
  const html =
    `<div style="font-family:Arial,Helvetica,sans-serif;color:#0B1B33;max-width:640px">` +
    `<h2 style="margin:0 0 4px;color:#0366D0">New tax service request</h2>` +
    `<p style="margin:0 0 16px;color:#475A75">Reference <b>${escapeHtml(reference)}</b>. Reply to this email to reach the client.</p>` +
    `<table style="border-collapse:collapse;width:100%">` +
    rows
      .map(
        ([k, v]) =>
          `<tr><td style="padding:8px 12px;border-top:1px solid #D5E2F2;color:#475A75;width:200px;vertical-align:top">${escapeHtml(k)}</td>` +
          `<td style="padding:8px 12px;border-top:1px solid #D5E2F2;vertical-align:top">${escapeHtml(v).replace(/\n/g, "<br>")}</td></tr>`,
      )
      .join("") +
    `</table></div>`;
  return { subject, text, html };
}

export function buildConfirmation(c: Clean, reference: string) {
  const subject = `We received your tax service request ${reference}`;
  const list = c.services.map(serviceLabel).join(", ");
  const text =
    `Hello ${c.display_name},\n\n` +
    `Thank you for contacting Audtix Systems. We have received your tax service request.\n\n` +
    `Reference: ${reference}\nServices: ${list}\nTax periods: ${c.tax_periods}\n\n` +
    `Our team will review it and contact you by ${labelOf("preferred_contact", c.preferred_contact).toLowerCase()}. ` +
    `Quote the reference above if you write to us. You can also reach us on WhatsApp at +256 701 287 388.\n\n` +
    `Please do not send passwords by email or WhatsApp.\n\nAudtix Systems`;
  const html =
    `<div style="font-family:Arial,Helvetica,sans-serif;color:#0B1B33;max-width:560px">` +
    `<h2 style="color:#0366D0;margin:0 0 12px">We received your request</h2>` +
    `<p>Hello ${escapeHtml(c.display_name)},</p>` +
    `<p>Thank you for contacting Audtix Systems. We have received your tax service request.</p>` +
    `<p><b>Reference:</b> ${escapeHtml(reference)}<br><b>Services:</b> ${escapeHtml(list)}<br><b>Tax periods:</b> ${escapeHtml(c.tax_periods)}</p>` +
    `<p>Our team will review it and contact you by ${escapeHtml(labelOf("preferred_contact", c.preferred_contact).toLowerCase())}. Quote the reference above if you write to us. You can also reach us on WhatsApp at +256 701 287 388.</p>` +
    `<p style="color:#475A75">Please do not send passwords by email or WhatsApp.</p><p>Audtix Systems</p></div>`;
  return { subject, text, html };
}
