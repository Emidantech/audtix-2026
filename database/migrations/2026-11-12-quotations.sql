-- Run this once in phpMyAdmin (Import) if your database was created before Nov 2026.
-- It only adds the quotation tables; it is safe to run more than once.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

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
