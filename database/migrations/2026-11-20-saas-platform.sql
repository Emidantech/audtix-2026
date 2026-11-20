-- SaaS platform layer for the Business accounts portal (/biz). Additive only: new tables, no ALTER, safe to run again.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS biz_plans (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  price_ugx     DECIMAL(15,2) NOT NULL DEFAULT 0,
  price_usd     DECIMAL(15,2) NOT NULL DEFAULT 0,
  duration_days INT UNSIGNED NOT NULL DEFAULT 30,
  max_users     INT UNSIGNED NOT NULL DEFAULT 0,
  max_branches  INT UNSIGNED NOT NULL DEFAULT 0,
  max_docs_month INT UNSIGNED NOT NULL DEFAULT 0,
  storage_mb    INT UNSIGNED NOT NULL DEFAULT 0,
  modules       VARCHAR(255) NOT NULL DEFAULT '',
  features      VARCHAR(1000) NULL,
  is_public     TINYINT(1)   NOT NULL DEFAULT 1,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY ux_biz_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_account_ext (
  account_id    INT UNSIGNED NOT NULL,
  category      VARCHAR(80)  NULL,
  facebook      VARCHAR(255) NULL,
  instagram     VARCHAR(255) NULL,
  linkedin      VARCHAR(255) NULL,
  x_url         VARCHAR(255) NULL,
  tiktok        VARCHAR(255) NULL,
  primary_color VARCHAR(7)   NULL,
  secondary_color VARCHAR(7) NULL,
  theme         VARCHAR(20)  NULL,
  plan_id       INT UNSIGNED NULL,
  grace_days    INT UNSIGNED NULL,
  approval      VARCHAR(12)  NOT NULL DEFAULT 'approved',
  PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_branches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(190) NOT NULL,
  location      VARCHAR(500) NULL,
  phone         VARCHAR(60)  NULL,
  email         VARCHAR(190) NULL,
  manager_user_id INT UNSIGNED NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_branch_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_roles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  perms         VARCHAR(1000) NOT NULL DEFAULT '',
  all_branches  TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_biz_role_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_user_ext (
  user_id       INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NULL,
  branch_id     INT UNSIGNED NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_doc_meta (
  document_id   INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  branch_id     INT UNSIGNED NULL,
  PRIMARY KEY (document_id),
  KEY ix_biz_dm_branch (account_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_categories (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_cat_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_item_meta (
  item_id       INT UNSIGNED NOT NULL,
  account_id    INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'service',
  stock_qty     DECIMAL(15,3) NULL,
  PRIMARY KEY (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_audit (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NULL,
  admin_id      INT UNSIGNED NULL,
  action        VARCHAR(60)  NOT NULL,
  detail        VARCHAR(500) NULL,
  ip            VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_audit_acc (account_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_login_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NULL,
  user_id       INT UNSIGNED NULL,
  email         VARCHAR(190) NULL,
  ok            TINYINT(1)   NOT NULL DEFAULT 0,
  ip            VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_ll_acc (account_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_imp_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id      INT UNSIGNED NOT NULL,
  admin_name    VARCHAR(190) NULL,
  account_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  reason        VARCHAR(300) NOT NULL,
  token_hash    VARCHAR(64)  NULL,
  token_expires DATETIME     NULL,
  started_at    DATETIME     NULL,
  ended_at      DATETIME     NULL,
  ip            VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_imp_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_reset_tokens (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    VARCHAR(64)  NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_biz_rt_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  plan_id       INT UNSIGNED NOT NULL,
  reference     VARCHAR(40)  NOT NULL,
  provider      VARCHAR(20)  NOT NULL,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency      VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  status        VARCHAR(12)  NOT NULL DEFAULT 'pending',
  provider_ref  VARCHAR(120) NULL,
  note          VARCHAR(300) NULL,
  created_at    DATETIME     NOT NULL,
  verified_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_biz_pay_ref (reference),
  KEY ix_biz_pay_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_announcements (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(190) NOT NULL,
  body          VARCHAR(2000) NOT NULL,
  expires_on    DATE         NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_support (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  subject       VARCHAR(190) NOT NULL,
  message       VARCHAR(3000) NOT NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',
  reply         VARCHAR(3000) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_sup_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
