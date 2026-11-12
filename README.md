# Audtix Systems website and backend kit

The website files are at the top level of this repository, so Hostinger Git can deploy them straight into `public_html`.
The `backend/` folder (Supabase functions, migrations, tests) is never served: `.htaccess` returns 404 for it, for `.git`,
and for documentation, SQL and TypeScript files.

**Deploying on Hostinger:** hPanel, Websites, Manage, Advanced, Git. Use the SSH address, add the deploy key it shows to the
GitHub repo (Settings, Deploy keys), branch `main`, and leave the install folder empty. Add the webhook it gives you under
GitHub Settings, Webhooks for automatic deploys.

---

# Audtix tax request form: database and email setup

Clients press **Request tax service**, fill `request-tax.html` (no sign-up) and submit. The request is saved in a database and emailed to **info@audtix.com** with a copy to **audtixsystem@gmail.com**. The email carries **one PDF**: the completed form followed by every document the client uploaded (PDF pages appended, photos placed one per page). The client also gets a confirmation with a reference such as AUD-2026-00001.

## What is in this folder
- `` the website (request-tax.html, request.js, request.css, config.js and all other pages)
- `backend/supabase/migrations/20261105000000_tax_requests.sql` the database (tables tax_requests and tax_request_events)
- `backend/supabase/migrations/20261106000000_sme_assessment.sql` the SME assessment questions, the tax_request_files table and a private Storage bucket for the combined PDFs (run it second)
- `backend/supabase/functions/tax-request/` the function that validates, saves and emails (index.ts, handler.ts, validate.ts, pdf.ts, form-spec.json, .env.example)
- `backend/supabase/functions/forex-news/` optional headline fetcher for the Forex desk
- `backend/supabase/functions/fin-updates/` collects Uganda and East Africa institution updates; `_shared/` holds the feed reader and the institution list
- `backend/tests/` tests that were run while building

## Setup (about 30 minutes)
1. Create a free project at supabase.com.
2. Open SQL Editor, paste the first migration and run it, then the second.
3. Create an account at resend.com, add and verify the domain audtix.com (add the DNS records they show), create an API key.
4. Install the Supabase CLI, then in a folder containing `backend/supabase`:
   - `supabase link --project-ref YOUR_REF`
   - copy `.env.example` to `.env`, fill in RESEND_API_KEY and IP_SALT, set ALLOWED_ORIGINS to your live site address
   - `supabase secrets set --env-file supabase/functions/tax-request/.env`
   - `supabase functions deploy tax-request --no-verify-jwt`
   - (optional) `supabase functions deploy forex-news --no-verify-jwt`
   - (optional) `supabase functions deploy fin-updates --no-verify-jwt`
5. Edit `config.js`: set `taxRequestEndpoint` to `https://YOUR_REF.supabase.co/functions/v1/tax-request`, then upload the site.

Until step 5 is done, the form shows a ready-to-send summary with Email (to info@audtix.com, cc audtixsystem@gmail.com), WhatsApp and Copy buttons, so no request is lost.

## Financial insights centre (Free tools page)
A section under the tools collects the latest posts from Ugandan and East African institutions: URA, MoFPED, Bank of Uganda, NSSF, URBRA, URSB, UBOS, UNBS, PPDA, KCCA, Uganda Investment Authority, CMA, USE, IRA, plus Kenya (CBK, Treasury, KRA, KNBS), Tanzania (BoT, TRA), Rwanda (BNR, RRA) and the EAC. Visitors can filter by topic and country, and the three newest items also show in the Forex desk sidebar. It needs no attention once set up.
- Deploy `supabase functions deploy fin-updates --no-verify-jwt`, then put its URL in `config.js` as `updatesEndpoint`.
- The function reads each institution's own news feed (trying the usual feed addresses). If an institution has no readable feed it falls back to a news search limited to that institution's own website. Results are cached for 20 minutes, so it stays quick and polite to their servers.
- Not every government website publishes a feed, so some institutions may show nothing. Open the function URL in a browser: the `sources` list in the answer shows, for each institution, whether it returned posts and the error if not. Tell me which are empty and I can add the right feed address in `functions/_shared/sources.json`.
- To add or remove an institution, edit `functions/_shared/sources.json` and regenerate the site file `fin-sources.js` (or ask me to).
- Without the function the page uses one public news search across the main Ugandan institutions, which shows fewer items.

## Free tools: live Forex desk
The Free tools page loads live data every time it is opened and refreshes every 5 minutes. It shows today's date from the visitor's own device, so it never goes out of date.
- Rates: ExchangeRate-API (open.er-api.com), with a backup from the currency-api project on jsDelivr. The page shows when the source last updated.
- Trend insights are calculated from those rates plus 7, 30 and 90 day history (currency-api). Nothing in them is hand-written.
- Headlines: Google News search results, kept only when the publisher is on the trusted list in `tools.js` (Reuters, Bloomberg, BBC, Daily Monitor, New Vision, central banks and similar) and published in the last 21 days. Official sources (Bank of Uganda, Federal Reserve, ECB, Bank of England) are linked directly.
- Recommended: deploy `supabase functions deploy forex-news --no-verify-jwt` and put its URL in `config.js` as `newsEndpoint`. Without it the page uses the public rss2json service, which has usage limits.
- If a feed cannot be reached the page says so. It never shows old figures as current. Last good rates and headlines are kept in the visitor's browser for up to 3 days and 6 hours, and labelled as saved.
- These feeds are called straight from the visitor's browser, so they only work on the real website. The preview page inside Claude blocks outside connections and shows the "could not be loaded" state.

## Uploads and the combined PDF
- Accepted: PDF, JPG, PNG. Up to 5 MB per file, 3 files per document type, 15 MB in total (limits are in `form-spec.json`). Word and Excel files are not accepted; clients are told to convert them to PDF.
- A password-protected or damaged PDF cannot be merged. It is attached to the email separately and the combined PDF says so.
- Non-Latin characters (for example Chinese names) show as "?" inside the PDF only. The database and the email text keep them.
- A copy of every combined PDF is kept in the private Storage bucket `tax-request-pdfs` (path in `pdf_path`), so it survives if an email is lost.
- Resend allows up to 40 MB per email, so the limits above fit comfortably.

## Staff use
Supabase, Table Editor, `tax_requests`: filter by `status` (new, contacted, in_progress, awaiting_documents, filed, closed, spam), set `assigned_to` and `staff_notes`. `email_status` shows whether the team email went out. `tax_request_events` is the audit trail.

## Changing questions
Options for dropdowns and services live in `form-spec.json` (keep the SQL CHECK lists in step). A new question with no column can be stored in `payload.extra` without changing the database.

## Notes
- Questions come from the Google Form content you supplied plus the SME Tax Assessment PDF. Questions already on the form (TIN, employees, records kept, URA notice, services) were not repeated.
- Tested: SQL on local Postgres 16 (both migrations), handler and PDF merge with simulated database and email, and the form in a real browser sending real files to the real handler code. Not yet tested on a live Supabase project or with real email delivery.
- Without the backend switched on, uploaded files cannot travel by mailto or WhatsApp, so the page asks the client to email them separately.
- Collecting TINs and contact details falls under Uganda's Data Protection and Privacy Act 2019. Publish a privacy notice and limit staff access to the tables.


## Request for Audit Service form (added November 2026)

A second form, separate from the tax form. It lives at `request-audit.html` and is reached only from the
"Request for Audit Service" button inside the Audit and assurance card on the home page.

Setup is the same as for the tax form:

1. Run `backend/supabase/migrations/20261110000000_audit_requests.sql` in the Supabase SQL editor (run the two tax migrations first).
2. Deploy the function: `supabase functions deploy audit-request --no-verify-jwt`. It uses the same secrets as `tax-request`
   (RESEND_API_KEY, MAIL_FROM, NOTIFY_TO, NOTIFY_CC, ALLOWED_ORIGINS, IP_SALT), so there is nothing new to set.
3. Paste the function URL into `config.js` as `auditRequestEndpoint`.

Submissions go to info@audtix.com with a copy to audtixsystem@gmail.com. The form and every uploaded file arrive as one PDF.
Staff can read the data in the `audit_requests` table. The questions live in `functions/audit-request/form-spec.json`
and drive the web page, the checks and the emails together. Run `node --experimental-strip-types tests/audit.test.mjs` to test it.

Note: the Google Form behind the audit link could not be read automatically, so the questions are a standard audit
intake. Send the real question list and they can be matched exactly.
