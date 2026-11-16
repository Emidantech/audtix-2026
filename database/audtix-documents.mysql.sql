-- =====================================================================
--  Audtix Systems Ltd: business documents module (upgrade file)
--  Run AFTER audtix.mysql.sql, in phpMyAdmin > Import. Safe to re-run.
--
--  Adds: per-admin permissions, company settings, customers, products and
--  services, the six document types (requisition, quotation, invoice,
--  budget, proforma invoice, receipt) with their lines, a numbering
--  counter, an audit trail and a revision history for edited documents.
--
--  Nothing here deletes or changes the existing tables. Documents are never
--  hard-deleted by the application: they are cancelled with a reason.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS admin_permissions (
  admin_id    INT UNSIGNED NOT NULL,
  permission  VARCHAR(40)  NOT NULL,
  PRIMARY KEY (admin_id, permission),
  CONSTRAINT fk_perm_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
