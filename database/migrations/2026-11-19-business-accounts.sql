-- Business accounts: other businesses subscribe to document services (quotation, invoice, proforma, receipt) in their own space.
-- Completely separate from the Audtix documents. Safe to re-run: only creates what is missing.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS biz_accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(190) NOT NULL,
  doc_prefix    VARCHAR(8)   NULL,
  owner_name    VARCHAR(190) NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60)  NULL,
  address       VARCHAR(500) NULL,
  web           VARCHAR(190) NULL,
  tin           VARCHAR(20)  NULL,
  bank_details  VARCHAR(1000) NULL,
  terms_default VARCHAR(2000) NULL,
  logo          VARCHAR(80)  NULL,
  currency      VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  plan          VARCHAR(40)  NOT NULL DEFAULT 'Trial',
  status        VARCHAR(20)  NOT NULL DEFAULT 'trial',
  trial_ends    DATE         NULL,
  paid_until    DATE         NULL,
  monthly_doc_limit INT UNSIGNED NOT NULL DEFAULT 0,
  admin_notes   VARCHAR(1000) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_acc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  full_name     VARCHAR(190) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(12)  NOT NULL DEFAULT 'owner',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw TINYINT(1)  NOT NULL DEFAULT 0,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME     NULL,
  last_login    DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_biz_user_email (email),
  KEY ix_biz_user_acc (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_customers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(190) NOT NULL,
  contact_person VARCHAR(190) NULL,
  phone         VARCHAR(60)  NULL,
  email         VARCHAR(190) NULL,
  address       VARCHAR(500) NULL,
  tin           VARCHAR(20)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_biz_cust_acc (account_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(190) NOT NULL,
  unit          VARCHAR(30)  NULL,
  unit_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_rate      DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_biz_item_acc (account_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_documents (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id       INT UNSIGNED NOT NULL,
  doc_type         VARCHAR(12)  NOT NULL,
  doc_no           VARCHAR(30)  NOT NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'draft',
  customer_id      INT UNSIGNED NULL,
  customer_name    VARCHAR(190) NOT NULL,
  customer_contact VARCHAR(190) NULL,
  customer_tin     VARCHAR(20)  NULL,
  customer_address VARCHAR(500) NULL,
  customer_phone   VARCHAR(60)  NULL,
  customer_email   VARCHAR(190) NULL,
  customer_logo    VARCHAR(80)  NULL,
  doc_date         DATE         NOT NULL,
  due_date         DATE         NULL,
  currency         VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  payment_method   VARCHAR(60)  NULL,
  payment_ref      VARCHAR(120) NULL,
  salesperson      VARCHAR(120) NULL,
  reference_text   VARCHAR(190) NULL,
  parent_id        INT UNSIGNED NULL,
  subtotal         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_total   DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  total            DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes            VARCHAR(2000) NULL,
  terms            VARCHAR(4000) NULL,
  created_by       INT UNSIGNED NULL,
  posted_by        INT UNSIGNED NULL,
  posted_at        DATETIME     NULL,
  cancelled_by     INT UNSIGNED NULL,
  cancelled_at     DATETIME     NULL,
  cancel_reason    VARCHAR(500) NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_biz_doc_no (account_id, doc_no),
  KEY ix_biz_doc_type (account_id, doc_type, status, doc_date),
  KEY ix_biz_doc_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biz_document_lines (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id  INT UNSIGNED NOT NULL,
  line_no      SMALLINT UNSIGNED NOT NULL,
  description  VARCHAR(1000) NOT NULL,
  quantity     DECIMAL(15,3) NOT NULL DEFAULT 1,
  unit         VARCHAR(30)  NULL,
  unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0,
  gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0,
  tax_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_biz_lines_doc (document_id, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
