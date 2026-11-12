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
