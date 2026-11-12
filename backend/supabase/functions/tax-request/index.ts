// Supabase Edge Function entry point for the public tax request form.
// Deploy with:  supabase functions deploy tax-request --no-verify-jwt
// (the form is public, so the browser does not send a login token).
import { createClient } from "npm:@supabase/supabase-js@2";
import { PDFDocument, StandardFonts, rgb } from "npm:pdf-lib@1.17.1";
import { handle, type Deps } from "./handler.ts";

// deno-lint-ignore no-explicit-any
declare const Deno: any;

const supabase = createClient(Deno.env.get("SUPABASE_URL")!, Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!, {
  auth: { persistSession: false },
});

const deps: Deps = {
  pdfLib: { PDFDocument, StandardFonts, rgb },
  storage: {
    async uploadPdf(path, bytes) {
      const { error } = await supabase.storage.from("tax-request-pdfs").upload(path, bytes, { contentType: "application/pdf", upsert: true });
      if (error) throw error;
    },
  },
  env: (k) => Deno.env.get(k),
  fetch: (input, init) => fetch(input, { ...init, signal: AbortSignal.timeout(15000) }),
  now: () => new Date(),
  db: {
    async countRecent(ipHash, sinceIso) {
      const { count, error } = await supabase
        .from("tax_requests")
        .select("id", { count: "exact", head: true })
        .eq("ip_hash", ipHash)
        .gte("created_at", sinceIso);
      if (error) throw error;
      return count ?? 0;
    },
    async insertRequest(row) {
      const { data, error } = await supabase.from("tax_requests").insert(row).select("id, reference").single();
      if (error) throw error;
      return data as { id: string; reference: string };
    },
    async updateEmailStatus(id, status, err) {
      const { error } = await supabase.from("tax_requests").update({ email_status: status, email_error: err }).eq("id", id);
      if (error) throw error;
    },
    async insertFiles(id, rows) {
      const { error } = await supabase.from("tax_request_files").insert(rows.map((r) => ({ request_id: id, ...r })));
      if (error) throw error;
    },
    async updatePdf(id, path, bytes, fileCount) {
      const { error } = await supabase.from("tax_requests").update({ pdf_path: path, pdf_bytes: bytes, file_count: fileCount }).eq("id", id);
      if (error) throw error;
    },
    async logEvent(id, action, detail) {
      const { error } = await supabase.from("tax_request_events").insert({ request_id: id, action, detail });
      if (error) throw error;
    },
  },
};

Deno.serve((req: Request) => handle(req, deps));
