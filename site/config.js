// Settings for the live parts of the site. Paste your own values after the backend is deployed.
window.AUDTIX_CONFIG = {
  // Tax request form: the tax-request Edge Function URL, for example
  // https://YOUR-PROJECT-REF.supabase.co/functions/v1/tax-request
  taxRequestEndpoint: "",
  // Audit request form (Audit and assurance page): the audit-request Edge Function URL, for example
  // https://YOUR-PROJECT-REF.supabase.co/functions/v1/audit-request
  auditRequestEndpoint: "",
  // Free tools, Forex desk headlines (optional but recommended): the forex-news Edge Function URL, for example
  // https://YOUR-PROJECT-REF.supabase.co/functions/v1/forex-news
  // Left empty, the page falls back to the public rss2json service.
  newsEndpoint: "",
  // Free tools, Financial insights centre (URA, NSSF, Bank of Uganda and others): the fin-updates Edge Function URL, for example
  // https://YOUR-PROJECT-REF.supabase.co/functions/v1/fin-updates
  // Left empty, the page uses a public news search limited to the main Ugandan institutions.
  updatesEndpoint: "",
  notifyTo: "info@audtix.com",
  notifyCc: "audtixsystem@gmail.com",
  whatsapp: "256701287388"
};
