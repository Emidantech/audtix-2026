-- =====================================================================
--  Audtix Systems Ltd: website database (MySQL 5.7+ / MariaDB 10.4+)
--  Import this file in hPanel > Databases > phpMyAdmin > Import,
--  after creating an empty database and user. It is safe to re-run:
--  every table is created only if it does not exist.
--
--  Tables
--    admins            panel users (super admin, admin) and their details
--    admin_login_log   every sign-in attempt, successful or not
--    admin_activity    what admins did (status changes, notes, new admins)
--    id_counters       running number per category and year (request ids)
--    requests          every Tax / Audit submission, one row each
--    request_files     documents uploaded with a request (files live on disk)
--    request_events    status changes and internal notes on a request
--    submission_log    one row per attempt, used for rate limiting
--    quotes            every quotation request (website or entered by an admin)
--    quote_files       documents on a quotation (client uploads, quotation sent)
--    quote_events      status changes, notes and replies on a quotation
--    roles, role_permissions   what each admin role may do
--    settings          branding and email settings edited in the admin
--    notifications, notification_reads   the admin bell
--    post_types, posts, media   the website's Media section (blog, stories, publications)
--
--  No admin account is created here and no password is stored in this
--  file. Create the first super admin with /admin/setup.php (see README).
--  All times are stored in UTC.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
--  Roles and permissions (added Nov 2026). A super admin can do
--  everything; every other admin gets the permissions of their role.
--  Permission keys look like "quotes.view", "posts.delete".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id     INT UNSIGNED NOT NULL,
  permission  VARCHAR(60)  NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_rperm_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username         VARCHAR(40)  NOT NULL,
  email            VARCHAR(190) NOT NULL,
  full_name        VARCHAR(120) NOT NULL,
  phone            VARCHAR(40)  NULL,
  job_title        VARCHAR(120) NULL,
  role             ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
  role_id          INT UNSIGNED NULL,                  -- permissions for role = admin (see roles)
  password_hash    VARCHAR(255) NOT NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw   TINYINT(1)   NOT NULL DEFAULT 0,
  failed_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until     DATETIME     NULL,
  last_login_at    DATETIME     NULL,
  last_login_ip    VARCHAR(45)  NULL,
  password_changed_at DATETIME  NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username),
  UNIQUE KEY uq_admins_email (email),
  KEY ix_admins_role (role, is_active),
  KEY ix_admins_role_id (role_id),
  CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id      INT UNSIGNED NULL,
  username_tried VARCHAR(190) NOT NULL,
  success       TINYINT(1)   NOT NULL,
  reason        VARCHAR(40)  NOT NULL DEFAULT '',
  ip            VARCHAR(45)  NOT NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_admin (admin_id, created_at),
  KEY ix_login_ip (ip, created_at),
  KEY ix_login_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,
  target      VARCHAR(80)  NULL,
  detail      VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_act_admin (admin_id, created_at),
  KEY ix_act_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS id_counters (
  category     VARCHAR(10)  NOT NULL,
  yr           SMALLINT UNSIGNED NOT NULL,
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (category, yr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id    VARCHAR(30)  NOT NULL,                       -- e.g. TAX-2026-00001, AUDIT-2026-00001
  category      ENUM('tax','audit') NOT NULL,                -- the category tag
  status        ENUM('new','in_review','awaiting_client','quoted','in_progress','completed','closed','spam') NOT NULL DEFAULT 'new',
  client_name   VARCHAR(190) NOT NULL,
  contact_person VARCHAR(190) NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(60)  NOT NULL,
  service_summary VARCHAR(500) NULL,
  data_json     LONGTEXT     NOT NULL,                       -- raw answers as submitted
  rows_json     LONGTEXT     NOT NULL,                       -- labelled answers for reading
  files_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  email_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  email_error   VARCHAR(500) NULL,
  assigned_to   INT UNSIGNED NULL,
  ip            VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requests_request_id (request_id),
  KEY ix_requests_cat_status (category, status, created_at),
  KEY ix_requests_status (status, created_at),
  KEY ix_requests_email (email),
  KEY ix_requests_assigned (assigned_to),
  CONSTRAINT fk_requests_admin FOREIGN KEY (assigned_to) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_pk    INT UNSIGNED NOT NULL,
  field_key     VARCHAR(60)  NOT NULL,
  doc_label     VARCHAR(160) NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(80)  NOT NULL,
  mime          VARCHAR(80)  NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64)     NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_files_request (request_pk),
  CONSTRAINT fk_files_request FOREIGN KEY (request_pk) REFERENCES requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_pk  INT UNSIGNED NOT NULL,
  admin_id    INT UNSIGNED NULL,
  event_type  ENUM('status','note','assign','system') NOT NULL,
  note        VARCHAR(2000) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_events_request (request_pk, created_at),
  CONSTRAINT fk_events_request FOREIGN KEY (request_pk) REFERENCES requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45) NOT NULL,
  category    VARCHAR(10) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_sub_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Quotations (added Nov 2026): one row per quotation request from
--  quote.html?service=..., plus its documents and history.
--  Quote numbers look like QT-ICT-2026-00001 (counter per service/year
--  in id_counters, category "qt_<service>").
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_no        VARCHAR(30)  NOT NULL,
  service         VARCHAR(20)  NOT NULL,                     -- erp, custom, ura, cyber, dev, net, adv, ict
  status          ENUM('pending','in_review','responded','approved','rejected','cancelled','completed','spam') NOT NULL DEFAULT 'pending',
  source          ENUM('website','admin') NOT NULL DEFAULT 'website',
  client_type     VARCHAR(20)  NULL,
  client_name     VARCHAR(160) NOT NULL,
  company         VARCHAR(190) NULL,
  email           VARCHAR(190) NOT NULL,
  phone           VARCHAR(40)  NOT NULL,
  location        VARCHAR(120) NULL,
  summary         VARCHAR(500) NULL,                         -- short line for the list
  budget          VARCHAR(30)  NULL,
  needed_by       DATE         NULL,
  data_json       LONGTEXT     NOT NULL,                     -- answers as submitted
  rows_json       LONGTEXT     NOT NULL,                     -- labelled answers, built by the server
  files_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  quoted_amount   DECIMAL(15,2) NULL,
  currency        CHAR(3)      NULL,
  valid_until     DATE         NULL,
  response_message TEXT        NULL,
  responded_at    DATETIME     NULL,
  responded_by    INT UNSIGNED NULL,
  assigned_to     INT UNSIGNED NULL,
  email_status    ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  email_error     VARCHAR(500) NULL,
  ip              VARCHAR(45)  NULL,
  user_agent      VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quotes_no (quote_no),
  KEY ix_quotes_status (status, created_at),
  KEY ix_quotes_service (service, status, created_at),
  KEY ix_quotes_email (email),
  KEY ix_quotes_assigned (assigned_to),
  CONSTRAINT fk_quotes_assignee FOREIGN KEY (assigned_to) REFERENCES admins (id) ON DELETE SET NULL,
  CONSTRAINT fk_quotes_responder FOREIGN KEY (responded_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_pk      INT UNSIGNED NOT NULL,
  kind          ENUM('client','response') NOT NULL DEFAULT 'client',
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(80)  NOT NULL,
  mime          VARCHAR(80)  NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64)     NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_qfiles_quote (quote_pk),
  CONSTRAINT fk_qfiles_quote FOREIGN KEY (quote_pk) REFERENCES quotes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  quote_pk    INT UNSIGNED NOT NULL,
  admin_id    INT UNSIGNED NULL,
  event_type  ENUM('status','note','assign','response','edit','system') NOT NULL,
  note        VARCHAR(2000) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_qevents_quote (quote_pk, created_at),
  CONSTRAINT fk_qevents_quote FOREIGN KEY (quote_pk) REFERENCES quotes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Business settings edited in the admin panel (branding, email). Values in the
-- database win over the config file; secrets (SMTP password) are stored encrypted.
CREATE TABLE IF NOT EXISTS settings (
  k           VARCHAR(80)  NOT NULL,
  v           TEXT         NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System notifications shown in the admin bell, one row per event;
-- notification_reads records who has seen it.
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(40)  NOT NULL,              -- quote, tax_request, audit_request, system
  module      VARCHAR(30)  NOT NULL,              -- permission module needed to see it: quotes, requests, settings
  title       VARCHAR(190) NOT NULL,
  body        VARCHAR(500) NULL,
  link        VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id INT UNSIGNED NOT NULL,
  admin_id        INT UNSIGNED NOT NULL,
  read_at         DATETIME     NOT NULL,
  PRIMARY KEY (notification_id, admin_id),
  KEY ix_nreads_admin (admin_id),
  CONSTRAINT fk_nreads_notif FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
  CONSTRAINT fk_nreads_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media section of the website: post types (Blog, Stories, Publications, ...) and posts.
CREATE TABLE IF NOT EXISTS post_types (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(80)  NOT NULL,
  slug         VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) NULL,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptypes_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id         INT UNSIGNED NOT NULL,
  title           VARCHAR(200) NOT NULL,
  slug            VARCHAR(200) NOT NULL,
  excerpt         VARCHAR(500) NULL,
  body            MEDIUMTEXT   NOT NULL,              -- Markdown; rendered safely by the server
  cover_image     VARCHAR(255) NULL,                  -- path under /uploads
  attachment      VARCHAR(255) NULL,                  -- optional PDF (publications)
  attachment_name VARCHAR(255) NULL,
  author_name     VARCHAR(120) NULL,
  status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  is_featured     TINYINT(1)   NOT NULL DEFAULT 0,
  published_at    DATETIME     NULL,
  views           INT UNSIGNED NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  updated_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug),
  KEY ix_posts_public (status, published_at),
  KEY ix_posts_type (type_id, status, published_at),
  CONSTRAINT fk_posts_type FOREIGN KEY (type_id) REFERENCES post_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images uploaded in the admin (post covers, pictures inside posts). Files live in /uploads/media.
CREATE TABLE IF NOT EXISTS media (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_name VARCHAR(255) NOT NULL,
  path          VARCHAR(255) NOT NULL,
  mime          VARCHAR(80)  NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  width         SMALLINT UNSIGNED NULL,
  height        SMALLINT UNSIGNED NULL,
  alt_text      VARCHAR(255) NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_media_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starting data: three post types and three example roles (edit them in the admin panel).
INSERT IGNORE INTO post_types (name, slug, description, sort_order, is_active, created_at, updated_at) VALUES
 ('Blog', 'blog', 'Articles and guides from the team', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('Stories', 'stories', 'Client stories and project highlights', 2, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('Publications', 'publications', 'Reports, guides and downloadable documents', 3, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT IGNORE INTO roles (name, description, created_at, updated_at) VALUES
 ('Manager', 'Everything except managing roles', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('Sales', 'Quotations and service requests', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
 ('Content editor', 'Blog, stories, publications and images', UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT IGNORE INTO role_permissions (role_id, permission)
 SELECT r.id, p.perm FROM roles r JOIN (
   SELECT 'dashboard.view' perm UNION ALL SELECT 'quotes.view' UNION ALL SELECT 'quotes.add' UNION ALL SELECT 'quotes.edit' UNION ALL SELECT 'quotes.respond' UNION ALL SELECT 'quotes.delete'
   UNION ALL SELECT 'requests.view' UNION ALL SELECT 'requests.edit' UNION ALL SELECT 'requests.delete'
   UNION ALL SELECT 'posts.view' UNION ALL SELECT 'posts.add' UNION ALL SELECT 'posts.edit' UNION ALL SELECT 'posts.publish' UNION ALL SELECT 'posts.delete'
   UNION ALL SELECT 'post_types.view' UNION ALL SELECT 'post_types.add' UNION ALL SELECT 'post_types.edit' UNION ALL SELECT 'post_types.delete'
   UNION ALL SELECT 'media.view' UNION ALL SELECT 'media.add' UNION ALL SELECT 'media.delete'
   UNION ALL SELECT 'admins.view' UNION ALL SELECT 'admins.add' UNION ALL SELECT 'admins.edit'
   UNION ALL SELECT 'settings.view' UNION ALL SELECT 'settings.edit' UNION ALL SELECT 'mail.view' UNION ALL SELECT 'mail.edit' UNION ALL SELECT 'log.view'
 ) p WHERE r.name = 'Manager';
INSERT IGNORE INTO role_permissions (role_id, permission)
 SELECT r.id, p.perm FROM roles r JOIN (
   SELECT 'dashboard.view' perm UNION ALL SELECT 'quotes.view' UNION ALL SELECT 'quotes.add' UNION ALL SELECT 'quotes.edit' UNION ALL SELECT 'quotes.respond'
   UNION ALL SELECT 'requests.view' UNION ALL SELECT 'requests.edit'
 ) p WHERE r.name = 'Sales';
INSERT IGNORE INTO role_permissions (role_id, permission)
 SELECT r.id, p.perm FROM roles r JOIN (
   SELECT 'dashboard.view' perm UNION ALL SELECT 'posts.view' UNION ALL SELECT 'posts.add' UNION ALL SELECT 'posts.edit' UNION ALL SELECT 'posts.publish'
   UNION ALL SELECT 'post_types.view' UNION ALL SELECT 'media.view' UNION ALL SELECT 'media.add'
 ) p WHERE r.name = 'Content editor';

-- ---------------------------------------------------------------------
--  Business documents module (16 Nov 2026)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  skey        VARCHAR(60)  NOT NULL,
  svalue      TEXT         NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(190) NOT NULL,
  contact_person VARCHAR(190) NULL,
  tin            VARCHAR(20)  NULL,
  address        VARCHAR(500) NULL,
  phone          VARCHAR(60)  NULL,
  email          VARCHAR(190) NULL,
  logo_file      VARCHAR(80)  NULL,
  notes          VARCHAR(1000) NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_customers_name (name),
  KEY ix_customers_tin (tin),
  KEY ix_customers_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_type   ENUM('product','service') NOT NULL DEFAULT 'product',
  name        VARCHAR(190) NOT NULL,
  description VARCHAR(1000) NULL,
  category    VARCHAR(80)  NULL,
  unit        VARCHAR(30)  NULL,
  unit_price  DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_rate    DECIMAL(5,2)  NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_items_name (name),
  KEY ix_items_cat (category),
  KEY ix_items_active (is_active, item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_counters (
  doc_type     VARCHAR(12)  NOT NULL,
  yr           SMALLINT UNSIGNED NOT NULL,
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, yr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  doc_type         ENUM('requisition','quotation','invoice','budget','proforma','receipt') NOT NULL,
  doc_no           VARCHAR(30)  NOT NULL,                 -- e.g. INV-2026-00007
  status           ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  customer_id      INT UNSIGNED NULL,
  customer_name    VARCHAR(190) NOT NULL,                 -- snapshot, so a posted document never changes
  customer_contact VARCHAR(190) NULL,
  customer_tin     VARCHAR(20)  NULL,
  customer_address VARCHAR(500) NULL,
  customer_phone   VARCHAR(60)  NULL,
  customer_email   VARCHAR(190) NULL,
  customer_logo    VARCHAR(80)  NULL,
  doc_date         DATE         NOT NULL,
  due_date         DATE         NULL,                     -- valid until / due date
  currency         VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  payment_method   VARCHAR(60)  NULL,
  payment_ref      VARCHAR(120) NULL,
  salesperson      VARCHAR(120) NULL,
  reference_text   VARCHAR(190) NULL,                     -- customer PO, project, or the transaction a receipt is for
  parent_id        INT UNSIGNED NULL,                     -- source document (conversion) or the invoice a receipt pays
  subtotal         DECIMAL(15,2) NOT NULL DEFAULT 0,      -- before discount
  discount_total   DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  total            DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes            VARCHAR(2000) NULL,
  terms            VARCHAR(4000) NULL,
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  updated_by       INT UNSIGNED NULL,
  posted_by        INT UNSIGNED NULL,
  posted_at        DATETIME     NULL,
  cancelled_by     INT UNSIGNED NULL,
  cancelled_at     DATETIME     NULL,
  cancel_reason    VARCHAR(500) NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_no (doc_no),
  KEY ix_documents_type (doc_type, status, doc_date),
  KEY ix_documents_customer (customer_id, doc_date),
  KEY ix_documents_parent (parent_id),
  KEY ix_documents_created (created_by, created_at),
  KEY ix_documents_date (doc_date),
  CONSTRAINT fk_documents_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_documents_parent   FOREIGN KEY (parent_id)   REFERENCES documents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_lines (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id  INT UNSIGNED NOT NULL,
  line_no      SMALLINT UNSIGNED NOT NULL,
  item_id      INT UNSIGNED NULL,
  description  VARCHAR(1000) NOT NULL,
  quantity     DECIMAL(15,3) NOT NULL DEFAULT 1,
  unit         VARCHAR(30)  NULL,
  unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0,
  gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0,          -- quantity x unit price
  discount     DECIMAL(15,2) NOT NULL DEFAULT 0,          -- amount taken off the line
  tax_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,
  tax_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(15,2) NOT NULL DEFAULT 0,          -- gross - discount + tax
  PRIMARY KEY (id),
  KEY ix_lines_doc (document_id, line_no),
  KEY ix_lines_item (item_id),
  CONSTRAINT fk_lines_doc  FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_lines_item FOREIGN KEY (item_id)     REFERENCES items (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id  INT UNSIGNED NOT NULL,
  admin_id     INT UNSIGNED NULL,
  event_type   ENUM('created','edited','revised','posted','converted','cancelled','printed','downloaded','note') NOT NULL,
  detail       VARCHAR(600) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_devents_doc (document_id, created_at),
  CONSTRAINT fk_devents_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_revisions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id   INT UNSIGNED NOT NULL,
  version       INT UNSIGNED NOT NULL,                    -- the version that was replaced
  snapshot_json LONGTEXT     NOT NULL,                    -- the document and its lines before the change
  admin_id      INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_drev_doc (document_id, version),
  CONSTRAINT fk_drev_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starting company details. Edit them in Admin > System settings (super admin).
INSERT IGNORE INTO app_settings (skey, svalue, updated_at) VALUES
  ('company_name',    'Audtix Systems Ltd', UTC_TIMESTAMP()),
  ('company_address', 'Ntinda, Kampala, Uganda', UTC_TIMESTAMP()),
  ('company_phone',   '+256 701 287 388', UTC_TIMESTAMP()),
  ('company_email',   'info@audtix.com', UTC_TIMESTAMP()),
  ('company_web',     'audtix.com', UTC_TIMESTAMP()),
  ('company_tin',     '', UTC_TIMESTAMP()),
  ('bank_details',    '', UTC_TIMESTAMP()),
  ('default_currency','UGX', UTC_TIMESTAMP()),
  ('default_vat',     '18', UTC_TIMESTAMP()),
  ('quote_valid_days','30', UTC_TIMESTAMP()),
  ('invoice_due_days','14', UTC_TIMESTAMP()),
  ('terms_default',   'Payment is due by the date shown. Prices are in the currency shown and exclude any tax not listed. Goods remain the property of Audtix Systems Ltd until paid in full.', UTC_TIMESTAMP());

-- Per-type permissions (16 Nov 2026): roles that held the earlier combined "documents.*" permissions
-- keep the same access on every document type; the old keys are then removed. Safe to re-run.
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.view' FROM role_permissions WHERE permission = 'documents.view';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.add' FROM role_permissions WHERE permission = 'documents.add';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.edit' FROM role_permissions WHERE permission = 'documents.edit';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.post' FROM role_permissions WHERE permission = 'documents.post';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.print' FROM role_permissions WHERE permission = 'documents.print';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_receipt.cancel' FROM role_permissions WHERE permission = 'documents.cancel';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_requisition.edit_posted' FROM role_permissions WHERE permission = 'documents.edit_posted';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_quotation.edit_posted' FROM role_permissions WHERE permission = 'documents.edit_posted';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_invoice.edit_posted' FROM role_permissions WHERE permission = 'documents.edit_posted';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_budget.edit_posted' FROM role_permissions WHERE permission = 'documents.edit_posted';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'doc_proforma.edit_posted' FROM role_permissions WHERE permission = 'documents.edit_posted';
DELETE FROM role_permissions WHERE permission LIKE 'documents.%';

-- Starter roles for the documents module (edit them under Roles and permissions). Safe to re-run.
INSERT IGNORE INTO roles (name, description, created_at, updated_at) VALUES ('Documents: sales', 'Creates and edits drafts, manages customers', UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT IGNORE INTO roles (name, description, created_at, updated_at) VALUES ('Documents: accountant', 'Posts, voids, reports, manages customers and price list', UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT IGNORE INTO roles (name, description, created_at, updated_at) VALUES ('Documents: read only', 'Views and prints documents and reports', UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.print' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.add' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.edit' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'items.view' FROM roles WHERE name = 'Documents: sales';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.post' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.print' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.cancel' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'items.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'items.add' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'items.edit' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_reports.view' FROM roles WHERE name = 'Documents: accountant';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_requisition.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_quotation.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_invoice.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_budget.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_proforma.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_receipt.print' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'customers.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'items.view' FROM roles WHERE name = 'Documents: read only';
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'doc_reports.view' FROM roles WHERE name = 'Documents: read only';
