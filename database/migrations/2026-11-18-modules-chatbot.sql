-- HR and payroll, finance, CRM, marketing, procurement, EFRIS log and the website chatbot (added 18 Nov 2026).
-- Safe to re-run: only creates what is missing, never changes or deletes existing data.
-- Run it from Admin > Modules database setup (modules-setup.php) or import it in phpMyAdmin.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS hr_employees (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  emp_no        VARCHAR(20)  NULL,
  full_name     VARCHAR(190) NOT NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60)  NULL,
  job_title     VARCHAR(120) NULL,
  department    VARCHAR(120) NULL,
  emp_type      VARCHAR(30)  NOT NULL DEFAULT 'permanent',
  hire_date     DATE         NULL,
  basic_salary  DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowances    DECIMAL(15,2) NOT NULL DEFAULT 0,
  nssf_no       VARCHAR(40)  NULL,
  tin           VARCHAR(20)  NULL,
  bank_details  VARCHAR(255) NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'active',
  notes         VARCHAR(1000) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_hr_emp_status (status, full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id  INT UNSIGNED NOT NULL,
  leave_type   VARCHAR(30)  NOT NULL DEFAULT 'annual',
  start_date   DATE         NOT NULL,
  end_date     DATE         NOT NULL,
  days         DECIMAL(5,1) NOT NULL DEFAULT 1,
  status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
  reason       VARCHAR(500) NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_hr_leave_emp (employee_id),
  KEY ix_hr_leave_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payroll_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period        VARCHAR(7)   NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'draft',
  gross_total   DECIMAL(15,2) NOT NULL DEFAULT 0,
  paye_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  nssf_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_total     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_hr_run_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_payslips (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id        INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,
  emp_name      VARCHAR(190) NOT NULL,
  basic         DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowances    DECIMAL(15,2) NOT NULL DEFAULT 0,
  gross         DECIMAL(15,2) NOT NULL DEFAULT 0,
  paye          DECIMAL(15,2) NOT NULL DEFAULT 0,
  nssf_employee DECIMAL(15,2) NOT NULL DEFAULT 0,
  nssf_employer DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
  net           DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_hr_slip_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_expenses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exp_date    DATE         NOT NULL,
  category    VARCHAR(80)  NOT NULL DEFAULT 'General',
  payee       VARCHAR(190) NULL,
  description VARCHAR(500) NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency    VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  method      VARCHAR(40)  NULL,
  reference   VARCHAR(120) NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'recorded',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_fin_exp_date (exp_date),
  KEY ix_fin_exp_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_deals (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(190) NOT NULL,
  customer_id   INT UNSIGNED NULL,
  company       VARCHAR(190) NULL,
  contact_name  VARCHAR(190) NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60)  NULL,
  source        VARCHAR(40)  NULL,
  stage         VARCHAR(20)  NOT NULL DEFAULT 'lead',
  deal_value    DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_close DATE        NULL,
  owner_name    VARCHAR(120) NULL,
  next_step     VARCHAR(255) NULL,
  notes         VARCHAR(2000) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_crm_stage (stage),
  KEY ix_crm_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_activities (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deal_id     INT UNSIGNED NOT NULL,
  act_type    VARCHAR(20)  NOT NULL DEFAULT 'note',
  body        VARCHAR(1000) NOT NULL,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_crm_act_deal (deal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_campaigns (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(190) NOT NULL,
  channel     VARCHAR(40)  NOT NULL DEFAULT 'email',
  status      VARCHAR(20)  NOT NULL DEFAULT 'planned',
  start_date  DATE         NULL,
  end_date    DATE         NULL,
  budget      DECIMAL(15,2) NOT NULL DEFAULT 0,
  spend       DECIMAL(15,2) NOT NULL DEFAULT 0,
  audience    VARCHAR(190) NULL,
  leads       INT UNSIGNED NOT NULL DEFAULT 0,
  conversions INT UNSIGNED NOT NULL DEFAULT 0,
  notes       VARCHAR(2000) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_mkt_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proc_suppliers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(190) NOT NULL,
  contact_name  VARCHAR(190) NULL,
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60)  NULL,
  tin           VARCHAR(20)  NULL,
  address       VARCHAR(500) NULL,
  category      VARCHAR(80)  NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'active',
  notes         VARCHAR(1000) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_proc_sup_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proc_orders (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_no         VARCHAR(30)  NOT NULL,
  supplier_id   INT UNSIGNED NULL,
  supplier_name VARCHAR(190) NOT NULL,
  order_date    DATE         NOT NULL,
  expected_date DATE         NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'draft',
  currency      VARCHAR(3)   NOT NULL DEFAULT 'UGX',
  lines_text    VARCHAR(4000) NULL,
  total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes         VARCHAR(1000) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_proc_po_no (po_no),
  KEY ix_proc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS efris_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id   INT UNSIGNED NOT NULL,
  doc_no        VARCHAR(30)  NOT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'queued',
  fdn           VARCHAR(60)  NULL,
  verification_code VARCHAR(60) NULL,
  qr_code       VARCHAR(1000) NULL,
  request_json  LONGTEXT     NULL,
  response_json LONGTEXT     NULL,
  error_text    VARCHAR(1000) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_efris_doc (document_id),
  KEY ix_efris_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token        VARCHAR(64)  NOT NULL,
  customer_id  INT UNSIGNED NULL,
  name         VARCHAR(190) NULL,
  email        VARCHAR(190) NULL,
  phone        VARCHAR(60)  NULL,
  company      VARCHAR(190) NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'open',
  handoff_reason VARCHAR(255) NULL,
  handoff_at   DATETIME     NULL,
  page_url     VARCHAR(255) NULL,
  ip           VARCHAR(64)  NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_chat_token (token),
  KEY ix_chat_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id   INT UNSIGNED NOT NULL,
  role         VARCHAR(12)  NOT NULL,
  body         VARCHAR(4000) NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_chat_msg_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
