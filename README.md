# Audtix Systems website

The website files are at the top level of this repository, so Hostinger Git can deploy them straight into `public_html`.

| Folder | What it is | Public? |
|---|---|---|
| (top level) | the pages, `request.js`, `audit.js`, `submit.js`, styles | yes |
| `api/submit.php` | receives the Tax and Audit forms: saves to MySQL **and** emails a copy | only `submit.php` |
| `quote.html`, `quote.js`, `quote-forms.json` | one reusable quotation page for every service in the Services menu | yes |
| `api/quote.php` | receives the quotation forms: saves to MySQL **and** emails a copy | only `quote.php` |
| `admin/` | the admin panel, built on AdminLTE 4 (bundled in `admin/assets/vendor`, nothing to install) | yes, behind login |
| `database/audtix.mysql.sql` | the MySQL database file | never served |
| `backend/` | the older Supabase kit, no longer used by the request forms (kept for the optional Forex and insights functions) | never served |

## How a request travels
1. Visitor submits `request-tax.html` or `request-audit.html`. A success card shows the **request ID** (for example `TAX-2026-00001` or `AUDIT-2026-00001`; the first part is the category tag) and a WhatsApp button. Nothing about how data is stored is shown to the visitor.
2. `api/submit.php` validates the data, then does two independent things:
   - saves it to MySQL (tables `requests`, `request_files`, `request_events`);
   - emails a copy to the notify address (info@audtix.com, cc audtixsystem@gmail.com) with the answers and the uploaded documents attached.
   If one fails the other still happens (the email states when the database save failed, and the admin panel flags requests whose email failed). The files and a `request.json` copy are also kept on disk. The visitor only sees an error if **both** fail.
3. Admins open `/admin/` (the dashboard), find the request by ID, change its status, assign it, add notes and download the documents.

## Quotations
Every service in the **Services** menu after Tax and Audit (ERP deployment, Custom fintech systems, URA system integration, Cyber security, Software development, Networking, Enterprise advisory, ICT hardware) opens one page, `quote.html?service=<key>`, with the questions for that service.
- **Service keys:** `erp`, `custom`, `ura`, `cyber`, `dev`, `net`, `adv`, `ict`. Link to a form directly, for example `quote.html?service=ict`.
- **Changing the questions:** edit `quote-forms.json`. The page and the server both read it, so a new field, option or service appears on the website and is accepted by `api/quote.php` without code changes. Field types: text, email, tel, number, date, select, radio, checkboxes, textarea and items (a list of lines, used for ICT hardware). A field can be shown only when another answer is chosen, with `"show": "name=value"`, `"name!=value"` or `"name~value"` (a ticked checkbox).
- **Quote numbers** look like `QT-ICT-2026-00001`: a running number per service and year.
- **Admin panel:** *Quotations* in the left menu lists them by status (Pending, In review, Responded, Approved, Rejected, Cancelled, Completed, Spam) and *By service*. Opening a pending quotation moves it to In review. From a quotation you can assign it, add internal notes, **send the quotation** (amount, currency, valid-until date, a message and optional PDFs, emailed to the client with the team copied; status becomes Responded), mark it approved, declined, completed or cancelled, and edit the answers. *New quotation* records an enquiry that came by phone, email or a visit. Super admins can delete, one at a time or in bulk.
- **Existing database:** if the database was imported before November 2026, import `database/migrations/2026-11-12-quotations.sql` once in phpMyAdmin. It only adds the three quotation tables (`quotes`, `quote_files`, `quote_events`). New installs get them from `database/audtix.mysql.sql`.

## Admin panel: roles, settings, notifications and media (Nov 2026)
- **Before deploying:** import `database/migrations/2026-11-13-roles-settings-media.sql` once in phpMyAdmin (after the quotations migration). New installs get everything from `database/audtix.mysql.sql`.
- **Roles and permissions:** *Administration > Roles and permissions*. Every section (quotations, requests, posts, post types, image library, admin users, roles, branding, email, login log) has its own View / Add / Edit / Delete (plus Respond for quotations and Publish for posts). A super admin can do everything. Assign roles under *Admin users*. Menus, buttons and actions all follow the role, and the server refuses anything a role does not allow.
- **Business settings:** *Administration > Business settings* has cards for Branding, Email and notifications, Roles and permissions, and Admin users.
  - *Branding:* logo, logo for dark backgrounds, favicon, brand colour and dark colour. Saved in the database. The website reads them through `api/site.php` (`brand.js`), and the admin panel uses them directly.
  - *Email and notifications:* choose SMTP (with presets for Hostinger, Gmail, Microsoft 365 and Zoho) or the hosting server's mail, set the from address, the addresses that receive system notifications, and which forms send emails. Includes a test button. Values saved here override the config file. The SMTP password is stored encrypted (optionally set `'app_key'` in the config file to a long random value before saving it).
- **System notifications:** every quotation, tax or audit request adds a notification under the bell (each admin sees only sections their role allows) and emails the recipients set above.
- **Media:** *Blog and stories*, *Post types* and *Image library* in the admin. Posts are written in simple Markdown (toolbar included), can have a cover image and a PDF, and can be drafts, published or archived. The website shows them on `media.html` (tabs per type) and `post.html?slug=...`.
- **Uploads:** logos, images and PDFs go to `/uploads` in the website folder. It must be writable by PHP, and its `.htaccess` only serves images and PDFs.
- **Website:** the Services menu links to the service sections again; ERP and hardware pages have a "Request a quotation" button in the breadcrumb bar; every page (except the forms) has a floating "Request a quote" button; forms show a toast when they are sent.

## Deploying on Hostinger (premium shared hosting)
1. **Code:** hPanel, Websites, Manage, Advanced, Git. Use the SSH address, add the deploy key it shows to the GitHub repo (Settings, Deploy keys), branch `main`, install folder empty. Add the webhook under GitHub Settings, Webhooks for automatic deploys.
2. **Database:** hPanel, Databases, MySQL Databases. Create a database and a user (Hostinger prefixes both, for example `u123456789_audtix`). Open phpMyAdmin for it, Import, choose `database/audtix.mysql.sql`. (Download the file from GitHub; it is not served by the website.)
3. **Email mailbox:** hPanel, Emails, create `no-reply@audtix.com` (SMTP host `smtp.hostinger.com`, port 465, SSL).
4. **Config file (secrets, never in Git):** copy `api/config.sample.php` to a file called `audtix-config.php` placed **one folder above `public_html`** (File Manager, home folder), and fill in the database, mailbox and a long random `setup_token`. If you cannot place it above `public_html`, name it `api/config.php` instead (the `.htaccess` blocks it).
5. **First super admin:** open `https://audtix.com/admin/setup.php`, enter the setup token, your name, username, email and a strong password. The page closes itself. Then delete `setup_token` from the config file.
6. Sign in at `https://audtix.com/admin/`. Under **Admins** you can add more admins (they get a one-time temporary password and must set their own), deactivate or reset them. **Login log** (super admin only) shows every sign-in attempt and admin action.
7. Submit a test request on both forms and confirm it appears in the panel and arrives by email.

Uploaded documents are stored in `audtix-storage/` one folder above `public_html` (or in `private/` with a deny-all rule if that is not writable). Back this folder up with the database.

## Security notes
- Passwords are hashed (bcrypt), sessions are HttpOnly and SameSite=Strict, every form has a CSRF token, and an account locks for 15 minutes after 5 wrong passwords (20 failures per network blocks sign-in attempts for 15 minutes).
- There is no default password anywhere. The SQL file creates no admin.
- Public forms: honeypot, minimum fill time, 10 submissions per hour per network, PDF/JPG/PNG only (checked by file signature), 5 MB per file, 15 MB total.
- Times are stored in UTC and shown in East Africa Time.

## Tests
The PHP code was tested end to end against SQLite (no MySQL server was available while building): form to database to email, database-down, email-down and both-down cases, request numbering, and the full admin flow. `database/audtix.mysql.sql` was written for MySQL 5.7+ / MariaDB 10.4+ but has not been run on a real MySQL server here, so the import in step 2 is the first real check; if phpMyAdmin reports an error, send it over.

---

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
