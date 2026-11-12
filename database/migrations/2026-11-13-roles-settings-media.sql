-- Run this once in phpMyAdmin (Import) if your database was created before 13 Nov 2026.
-- Adds roles and permissions, business settings, system notifications and the Media section.
-- Run 2026-11-12-quotations.sql first if you have not.
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

-- Business settings edited in the admin panel (branding, email). Values in the
-- database win over the config file; secrets (SMTP password) are stored encrypted.
ALTER TABLE admins ADD COLUMN role_id INT UNSIGNED NULL AFTER role, ADD KEY ix_admins_role_id (role_id),
  ADD CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL;

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
