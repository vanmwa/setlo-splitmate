-- Setlo database schema (MySQL / MariaDB)
-- Import in phpMyAdmin, or run: php database/setup.php

CREATE DATABASE IF NOT EXISTS setlo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE setlo;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts, notifications, settlement_events, settlements, item_assignments, receipt_items, receipt_photos, receipts, bill_payments, bill_members, bills, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NULL UNIQUE,       -- NULL for guests
  password_hash   VARCHAR(255) NOT NULL,
  google_sub      VARCHAR(64) NULL UNIQUE,        -- Google account id when linked via "Continue with Google"
  pay_code        CHAR(12) NULL UNIQUE,           -- personal "Pay me" QR code (pages/pay.php?u=…); NULL for guests
  payment_method  ENUM('GCash','Maya','Bank Transfer','Cash') NOT NULL DEFAULT 'GCash',
  payment_account VARCHAR(60) NULL,               -- e.g. GCash number / account name, shown to people who owe you
  avatar_color    CHAR(7) NOT NULL DEFAULT '#0d9488',
  role            ENUM('user','admin','guest') NOT NULL DEFAULT 'user',  -- guest: name-only bill member, cannot sign in
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A Bill is one shared food expense event.
-- draft: created, no items yet · active: items being reviewed/assigned
-- settling: settlements generated, items locked · closed: every settlement confirmed
CREATE TABLE bills (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  creator_id      INT UNSIGNED NOT NULL,
  payer_id        INT UNSIGNED NOT NULL,          -- who paid the restaurant; receives all settlements
  status          ENUM('draft','active','settling','closed') NOT NULL DEFAULT 'draft',
  tax             DECIMAL(10,2) NOT NULL DEFAULT 0,
  service_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,  -- discount printed on the receipt (Senior/PWD, promo), deducted from the total
  receipt_total   DECIMAL(10,2) NULL,             -- total printed on the receipt, for the match check
  receipt_image   VARCHAR(255) NULL,              -- legacy single photo; photos now live in receipt_photos
  ocr_raw         MEDIUMTEXT NULL,                -- text of the first receipt (its first line names the place in Stats)
  ocr_status      ENUM('none','ok','failed','skipped') NOT NULL DEFAULT 'none',
  invite_code     CHAR(12) NULL UNIQUE,           -- join-by-link code; NULL when the link is off
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settling_at     DATETIME NULL,
  closed_at       DATETIME NULL,
  FOREIGN KEY (creator_id) REFERENCES users(id),
  FOREIGN KEY (payer_id)   REFERENCES users(id),
  INDEX (status)
) ENGINE=InnoDB;

CREATE TABLE bill_members (
  bill_id   INT UNSIGNED NOT NULL,
  user_id   INT UNSIGNED NOT NULL,
  discount_type ENUM('none','senior','pwd') NOT NULL DEFAULT 'none',  -- receives the receipt discount first
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (bill_id, user_id),
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Who paid the restaurant, when more than one person did. No rows = bills.payer_id paid the whole total.
CREATE TABLE bill_payments (
  bill_id  INT UNSIGNED NOT NULL,
  user_id  INT UNSIGNED NOT NULL,
  amount   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (bill_id, user_id),
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Receipts scanned into a bill. A bill can have several, and one photo can hold several receipts.
-- Their tax / service charge / discount / total were added into the bill's when scanned (and are taken out on removal).
CREATE TABLE receipts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  store_name      VARCHAR(120) NULL,
  receipt_no      VARCHAR(60) NULL,               -- OR / invoice / transaction no., for the duplicate check
  txn_date        VARCHAR(40) NULL,               -- as read, YYYY-MM-DD HH:MM
  subtotal        DECIMAL(10,2) NULL,
  tax             DECIMAL(10,2) NOT NULL DEFAULT 0,
  service_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount        DECIMAL(10,2) NOT NULL DEFAULT 0,
  total           DECIMAL(10,2) NULL,
  ocr_raw         MEDIUMTEXT NULL,
  ocr_status      ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  dup_note        VARCHAR(200) NULL,              -- looks like the same transaction as another receipt
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Photos of a receipt (uploads/receipts): one, or several sections of a long receipt. Receipts read from the same
-- photo each get a row for it, so a file is deleted only when no row uses it any more.
CREATE TABLE receipt_photos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id      INT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  image           VARCHAR(255) NOT NULL,
  sha1            CHAR(40) NULL,                  -- exact copy check
  dhash           CHAR(64) NULL,                  -- perceptual hash (includes/uploads.php image_dhash) for near-identical photos
  FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
  INDEX (sha1)
) ENGINE=InnoDB;

-- Line items extracted by OCR (or typed manually) and corrected on the Review screen.
CREATE TABLE receipt_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  receipt_id      INT UNSIGNED NULL,              -- NULL: typed in manually
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  name            VARCHAR(120) NOT NULL,
  qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price      DECIMAL(10,2) NOT NULL,
  source          ENUM('ocr','manual') NOT NULL DEFAULT 'manual',
  ocr_name        VARCHAR(120) NULL,              -- what OCR originally read, to measure correction rate
  printed_name    VARCHAR(120) NULL,              -- the text exactly as printed (ocr_name is the plain-English version)
  details         VARCHAR(255) NULL,              -- a meal set's contents, e.g. "Chicken, Rice, Iced Tea"
  needs_review    TINYINT(1) NOT NULL DEFAULT 0,  -- low-confidence read, must be confirmed on the Review screen
  suggestion      VARCHAR(120) NULL,              -- likely correct name for a misread item
  dup_note        VARCHAR(160) NULL,              -- same item and price on another receipt, or listed twice
  was_corrected   TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which members share each item (split equally among them).
CREATE TABLE item_assignments (
  item_id  INT UNSIGNED NOT NULL,
  user_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (item_id, user_id),
  FOREIGN KEY (item_id) REFERENCES receipt_items(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- pending → awaiting (sender marked paid) → settled (receiver confirmed)
--                                        ↘ disputed (receiver rejected) → pending (sender resends)
CREATE TABLE settlements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id         INT UNSIGNED NOT NULL,
  from_user_id    INT UNSIGNED NOT NULL,
  to_user_id      INT UNSIGNED NOT NULL,
  amount          DECIMAL(10,2) NOT NULL,
  status          ENUM('pending','awaiting','settled','disputed') NOT NULL DEFAULT 'pending',
  settle_method   ENUM('online','transfer','cash') NULL, -- online = PayMongo checkout, transfer = ref/screenshot, cash = receiver settled in person
  paid_at         DATETIME NULL,
  confirmed_at    DATETIME NULL,
  disputed_at     DATETIME NULL,
  dispute_reason  VARCHAR(500) NULL,
  payment_ref     VARCHAR(60) NULL,               -- reference number the sender entered when marking paid
  proof_image     VARCHAR(255) NULL,              -- proof-of-payment screenshot (uploads/proofs)
  paymongo_session VARCHAR(80) NULL,              -- latest PayMongo checkout session opened for this settlement
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id)      REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user_id) REFERENCES users(id),
  FOREIGN KEY (to_user_id)   REFERENCES users(id),
  INDEX (status)
) ENGINE=InnoDB;

-- Immutable audit trail of every settlement status change.
CREATE TABLE settlement_events (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id  INT UNSIGNED NOT NULL,
  actor_id       INT UNSIGNED NULL,
  event          ENUM('created','marked_paid','confirmed','disputed','resent','nudged') NOT NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id)      REFERENCES users(id)
) ENGINE=InnoDB;

-- Failed sign-in attempts, for throttling password guessing (see api/auth.php).
CREATE TABLE login_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  ip            VARCHAR(45) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('added','paid','confirmed','disputed','resent','settling','closed','nudge','admin') NOT NULL,
  message     VARCHAR(300) NOT NULL,
  link        VARCHAR(200) NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, is_read)
) ENGINE=InnoDB;
