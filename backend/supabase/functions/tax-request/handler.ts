// Request handler for the public tax request form.
// All outside access (database, email, clock, settings) comes in through `deps`,
// so the same code runs in Supabase Edge Functions and in the tests.
import { buildConfirmation, buildNotification, DOC_VALUES, docLabel, UPLOAD, validateSubmission, type Clean } from "./validate.ts";
import { buildPdf, type UploadedFile } from "./pdf.ts";

export interface InsertRow extends Omit<Clean, "extra"> {
  source_page: string | null;
  user_agent: string | null;
  ip_hash: string;
  payload: Record<string, unknown>;
}

export interface FileRow {
  doc_type: string;
  file_name: string;
  mime_type: string;
  size_bytes: number;
}

export interface Deps {
  // deno-lint-ignore no-explicit-any
  pdfLib: any;
  storage?: { uploadPdf(path: string, bytes: Uint8Array): Promise<void> };
  env: (key: string) => string | undefined;
  fetch: (input: string, init?: RequestInit) => Promise<Response>;
  now: () => Date;
  db: {
    countRecent(ipHash: string, sinceIso: string): Promise<number>;
    insertRequest(row: InsertRow): Promise<{ id: string; reference: string }>;
    updateEmailStatus(id: string, status: "sent" | "failed", error: string | null): Promise<void>;
    logEvent(id: string, action: string, detail: string | null): Promise<void>;
    insertFiles(id: string, rows: FileRow[]): Promise<void>;
    updatePdf(id: string, path: string | null, bytes: number, fileCount: number): Promise<void>;
  };
}

const MIN_FILL_MS = 2500;
const MAX_BODY = 60000; // JSON part of the form (files are limited separately)
const RATE_LIMIT = 8; // submissions per IP per hour

async function sha256Hex(s: string): Promise<string> {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(s));
  return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, "0")).join("");
}

function list(v: string | undefined, fallback: string): string[] {
  return (v && v.trim() ? v : fallback).split(",").map((s) => s.trim()).filter(Boolean);
}

function sniff(b: Uint8Array): string | null {
  if (b.length > 4 && b[0] === 0x25 && b[1] === 0x50 && b[2] === 0x44 && b[3] === 0x46) return "application/pdf";
  if (b.length > 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return "image/jpeg";
  if (b.length > 8 && b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47) return "image/png";
  return null;
}

function safeName(n: string): string {
  // deno-lint-ignore no-control-regex
  const base = n.split(/[\\/]/).pop() || "file";
  return base.replace(/[\u0000-\u001F\u007F<>:"|?*]/g, "_").slice(0, 150) || "file";
}

// Reads parts named file__<document type>. Returns an error message, or null when all files are fine.
async function collectFiles(fd: FormData, out: UploadedFile[]): Promise<string | null> {
  const perType: Record<string, number> = {};
  let total = 0;
  for (const [key, value] of fd.entries()) {
    if (!key.startsWith("file__") || typeof value === "string") continue;
    const docType = key.slice(6);
    if (!DOC_VALUES.includes(docType)) return "One of the document types is not valid.";
    const f = value as File;
    if (f.size === 0) continue;
    if (f.size > UPLOAD.maxFileBytes) return `${f.name || "A file"} is larger than ${Math.round(UPLOAD.maxFileBytes / 1048576)} MB.`;
    perType[docType] = (perType[docType] || 0) + 1;
    if (perType[docType] > UPLOAD.maxFilesPerType) return `Upload at most ${UPLOAD.maxFilesPerType} files for ${docLabel(docType)}.`;
    total += f.size;
    if (total > UPLOAD.maxTotalBytes) return `Together the files must be under ${Math.round(UPLOAD.maxTotalBytes / 1048576)} MB.`;
    const bytes = new Uint8Array(await f.arrayBuffer());
    const real = sniff(bytes);
    if (!real || !UPLOAD.types.includes(real)) return `${f.name || "A file"} must be a PDF, JPG or PNG.`;
    out.push({ docType, name: safeName(f.name || "file"), mime: real, bytes });
  }
  return null;
}

function toBase64(b: Uint8Array): string {
  let s = "";
  for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode(...b.subarray(i, i + 0x8000));
  return btoa(s);
}

export async function handle(req: Request, deps: Deps): Promise<Response> {
  const env = deps.env;
  const allowed = list(env("ALLOWED_ORIGINS"), "*");
  const origin = req.headers.get("origin") || "";
  const open = allowed.includes("*");
  if (origin && !open && !allowed.includes(origin)) {
    return new Response(JSON.stringify({ ok: false, error: "origin_not_allowed" }), { status: 403, headers: { "Content-Type": "application/json" } });
  }
  const cors: Record<string, string> = {
    "Access-Control-Allow-Origin": open ? "*" : origin || allowed[0],
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Access-Control-Allow-Headers": "content-type, authorization, apikey, x-client-info",
    "Access-Control-Max-Age": "86400",
    Vary: "Origin",
  };
  const json = (status: number, body: unknown) =>
    new Response(JSON.stringify(body), { status, headers: { ...cors, "Content-Type": "application/json" } });

  if (req.method === "OPTIONS") return new Response(null, { status: 204, headers: cors });
  if (req.method !== "POST") return json(405, { ok: false, error: "method_not_allowed" });

  let body: Record<string, unknown>;
  const files: UploadedFile[] = [];
  const ctype = req.headers.get("content-type") || "";
  if (ctype.includes("multipart/form-data")) {
    const declared = Number(req.headers.get("content-length") || 0);
    if (declared > UPLOAD.maxTotalBytes + 2 * 1024 * 1024) return json(413, { ok: false, error: "too_large" });
    let fd: FormData;
    try {
      fd = await req.formData();
    } catch {
      return json(400, { ok: false, error: "invalid_form" });
    }
    const dataPart = fd.get("data");
    if (typeof dataPart !== "string" || dataPart.length > MAX_BODY) return json(400, { ok: false, error: "invalid_json" });
    try {
      const parsed = JSON.parse(dataPart);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("not an object");
      body = parsed as Record<string, unknown>;
    } catch {
      return json(400, { ok: false, error: "invalid_json" });
    }
    const fileErrors = await collectFiles(fd, files);
    if (fileErrors) return json(422, { ok: false, error: "validation", errors: { files: fileErrors } });
  } else {
    const raw = await req.text();
    if (raw.length > MAX_BODY) return json(413, { ok: false, error: "too_large" });
    try {
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("not an object");
      body = parsed as Record<string, unknown>;
    } catch {
      return json(400, { ok: false, error: "invalid_json" });
    }
  }

  // Bots fill the hidden field. Pretend it worked and store nothing.
  if (typeof body.website === "string" && body.website.trim() !== "") {
    return json(200, { ok: true, reference: "AUD-0000-00000" });
  }
  const elapsed = Number(body.elapsed_ms);
  if (Number.isFinite(elapsed) && elapsed < MIN_FILL_MS) return json(400, { ok: false, error: "too_fast" });

  const result = validateSubmission(body);
  if (!result.ok) return json(422, { ok: false, error: "validation", errors: result.errors });
  const clean = result.clean;

  const fwd = req.headers.get("cf-connecting-ip") || (req.headers.get("x-forwarded-for") || "").split(",")[0].trim() || "unknown";
  const ipHash = await sha256Hex(`${env("IP_SALT") || "audtix"}|${fwd}`);
  const now = deps.now();
  const since = new Date(now.getTime() - 60 * 60 * 1000).toISOString();
  try {
    if ((await deps.db.countRecent(ipHash, since)) >= RATE_LIMIT) return json(429, { ok: false, error: "rate_limited" });
  } catch (e) {
    console.error("rate limit check failed", e);
  }

  let saved: { id: string; reference: string };
  try {
    const { extra: _extra, ...columns } = clean; // `extra` lives inside payload, not in its own column
    saved = await deps.db.insertRequest({
      ...columns,
      source_page: typeof body.page === "string" ? body.page.slice(0, 200) : null,
      user_agent: (req.headers.get("user-agent") || "").slice(0, 300) || null,
      ip_hash: ipHash,
      payload: { ...clean },
    });
  } catch (e) {
    console.error("insert failed", e);
    return json(500, { ok: false, error: "server" });
  }
  await deps.db.logEvent(saved.id, "received", files.length ? `${files.length} document(s) uploaded` : null).catch((e) => console.error(e));

  // One PDF: the completed form followed by every uploaded document.
  let attachments: Array<{ filename: string; content: string }> = [];
  let pdfBytes = 0;
  try {
    const built = await buildPdf(deps.pdfLib, clean, saved.reference, now, files);
    pdfBytes = built.pdf.length;
    attachments = [{ filename: `${saved.reference}-tax-request.pdf`, content: toBase64(built.pdf) }];
    for (const f of built.notMerged) attachments.push({ filename: f.name, content: toBase64(f.bytes) });
    const storagePath = `${now.getUTCFullYear()}/${saved.reference}.pdf`;
    let stored = false;
    if (deps.storage) {
      await deps.storage.uploadPdf(storagePath, built.pdf).then(() => { stored = true; }).catch((e) => console.error("storage failed", e));
    }
    await deps.db.updatePdf(saved.id, stored ? storagePath : null, pdfBytes, files.length).catch((e) => console.error(e));
    if (files.length) {
      await deps.db.insertFiles(saved.id, files.map((f) => ({ doc_type: f.docType, file_name: f.name, mime_type: f.mime, size_bytes: f.bytes.length }))).catch((e) => console.error(e));
    }
  } catch (e) {
    console.error("pdf failed", e);
    await deps.db.logEvent(saved.id, "pdf_failed", (e instanceof Error ? e.message : String(e)).slice(0, 500)).catch(() => {});
    attachments = files.map((f) => ({ filename: f.name, content: toBase64(f.bytes) }));
  }

  // Notify the team: To info@audtix.com, CC audtixsystem@gmail.com (both can be changed with secrets).
  const to = list(env("NOTIFY_TO"), "info@audtix.com");
  const cc = list(env("NOTIFY_CC"), "audtixsystem@gmail.com");
  const from = env("MAIL_FROM") || "Audtix Systems <noreply@audtix.com>";
  const apiKey = env("RESEND_API_KEY");

  const send = async (payload: Record<string, unknown>): Promise<void> => {
    if (!apiKey) throw new Error("RESEND_API_KEY is not set");
    const res = await deps.fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: { Authorization: `Bearer ${apiKey}`, "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`Email provider answered ${res.status}: ${(await res.text()).slice(0, 300)}`);
  };

  try {
    const m = buildNotification(clean, saved.reference, now, files.length ? `${files.length} file(s) merged into the attached PDF` : "None uploaded; the form is in the attached PDF");
    await send({ from, to, cc, reply_to: clean.email, subject: m.subject, html: m.html, text: m.text, attachments });
    await deps.db.updateEmailStatus(saved.id, "sent", null);
    await deps.db.logEvent(saved.id, "notification_sent", `to ${to.join(", ")}; cc ${cc.join(", ")}`).catch(() => {});
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    console.error("notification failed", msg);
    await deps.db.updateEmailStatus(saved.id, "failed", msg.slice(0, 500)).catch(() => {});
    await deps.db.logEvent(saved.id, "notification_failed", msg.slice(0, 500)).catch(() => {});
  }

  if (env("SEND_CONFIRMATION") !== "false") {
    try {
      const m = buildConfirmation(clean, saved.reference);
      await send({ from, to: [clean.email], reply_to: to[0], subject: m.subject, html: m.html, text: m.text });
      await deps.db.logEvent(saved.id, "confirmation_sent", null).catch(() => {});
    } catch (e) {
      await deps.db.logEvent(saved.id, "confirmation_failed", (e instanceof Error ? e.message : String(e)).slice(0, 500)).catch(() => {});
    }
  }

  return json(200, { ok: true, reference: saved.reference });
}
