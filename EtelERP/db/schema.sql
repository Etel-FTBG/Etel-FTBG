-- EtelERP (lean edition) schema. MariaDB 10.x / MySQL 5.7+, InnoDB, utf8mb4.
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS). Money is DECIMAL(14,2);
-- every business-owned table carries business_id and every query filters on it.

CREATE TABLE IF NOT EXISTS members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  etel_id     VARCHAR(60)  NOT NULL,
  full_name   VARCHAR(160) NOT NULL DEFAULT '',
  phone       VARCHAR(40)  NOT NULL DEFAULT '',
  last_login  DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_etel (etel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS businesses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160) NOT NULL,
  tin             VARCHAR(40)  NOT NULL DEFAULT '',
  vat_registered  TINYINT(1)   NOT NULL DEFAULT 0,
  vat_rate        DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  address         VARCHAR(255) NOT NULL DEFAULT '',
  phone           VARCHAR(40)  NOT NULL DEFAULT '',
  invoice_prefix  VARCHAR(12)  NOT NULL DEFAULT 'INV',
  next_invoice_no INT UNSIGNED NOT NULL DEFAULT 1,
  allow_negative_stock TINYINT(1) NOT NULL DEFAULT 0,
  plan            VARCHAR(30)  NOT NULL DEFAULT 'free',
  plan_expires_at DATE NULL,
  status          VARCHAR(20)  NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS memberships (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  INT UNSIGNED NOT NULL,
  member_id    INT UNSIGNED NOT NULL,
  role         VARCHAR(12)  NOT NULL DEFAULT 'cashier',   -- owner | manager | cashier
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_membership (business_id, member_id),
  KEY idx_membership_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash  CHAR(64)     NOT NULL,                      -- sha256 of the cookie token; the token itself is never stored
  member_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  expires_at  DATETIME NOT NULL,
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_token (token_hash),
  KEY idx_session_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  k           VARCHAR(100) NOT NULL,
  hits        INT UNSIGNED NOT NULL DEFAULT 0,
  window_end  DATETIME NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  phone        VARCHAR(40)  NOT NULL DEFAULT '',
  email        VARCHAR(160) NOT NULL DEFAULT '',
  tin          VARCHAR(40)  NOT NULL DEFAULT '',
  address      VARCHAR(255) NOT NULL DEFAULT '',
  notes        VARCHAR(500) NOT NULL DEFAULT '',
  archived     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_customers_biz (business_id, archived, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id   INT UNSIGNED NOT NULL,
  sku           VARCHAR(60)  NOT NULL DEFAULT '',
  name          VARCHAR(160) NOT NULL,
  unit          VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  price         DECIMAL(14,2) NOT NULL DEFAULT 0,        -- selling price, VAT-exclusive
  cost          DECIMAL(14,2) NOT NULL DEFAULT 0,
  track_stock   TINYINT(1)   NOT NULL DEFAULT 0,         -- 0 = a service
  stock_qty     DECIMAL(14,3) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(14,3) NOT NULL DEFAULT 0,
  vat_exempt    TINYINT(1)   NOT NULL DEFAULT 0,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_biz (business_id, active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_moves (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  INT UNSIGNED NOT NULL,
  item_id      INT UNSIGNED NOT NULL,
  qty_change   DECIMAL(14,3) NOT NULL,
  reason       VARCHAR(12)  NOT NULL,                    -- sale | void | purchase | adjust
  ref_type     VARCHAR(20)  NOT NULL DEFAULT '',
  ref_id       INT UNSIGNED NOT NULL DEFAULT 0,
  note         VARCHAR(255) NOT NULL DEFAULT '',
  created_by   INT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_moves_item (business_id, item_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id   INT UNSIGNED NOT NULL,
  number        VARCHAR(40)  NULL,                          -- assigned when issued (NULL while draft)
  customer_id   INT UNSIGNED NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'draft',     -- draft | issued | void
  issue_date    DATE NOT NULL,
  due_date      DATE NULL,
  subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes         VARCHAR(500) NOT NULL DEFAULT '',
  client_ref    VARCHAR(60)  NULL,                          -- idempotency key from the app
  created_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  issued_at     DATETIME NULL,
  voided_at     DATETIME NULL,
  void_reason   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_number (business_id, number),
  UNIQUE KEY uq_invoice_client_ref (business_id, client_ref),
  KEY idx_invoices_biz (business_id, status, issue_date),
  KEY idx_invoices_customer (business_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id    INT UNSIGNED NOT NULL,
  business_id   INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL,
  unit_price    DECIMAL(14,2) NOT NULL,
  vat_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0,
  line_subtotal DECIMAL(14,2) NOT NULL,
  line_vat      DECIMAL(14,2) NOT NULL,
  line_total    DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lines_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id   INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  method        VARCHAR(12)  NOT NULL DEFAULT 'cash',       -- cash | telebirr | cbe | chapa | bank | other
  reference     VARCHAR(80)  NOT NULL DEFAULT '',
  client_ref    VARCHAR(60)  NULL,
  paid_at       DATETIME NOT NULL,
  voided        TINYINT(1)   NOT NULL DEFAULT 0,
  voided_at     DATETIME NULL,
  created_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_client_ref (business_id, client_ref),
  KEY idx_payments_invoice (invoice_id),
  KEY idx_payments_biz (business_id, paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id   INT UNSIGNED NOT NULL,
  category      VARCHAR(60)  NOT NULL DEFAULT 'general',
  description   VARCHAR(255) NOT NULL DEFAULT '',
  vendor        VARCHAR(160) NOT NULL DEFAULT '',
  amount        DECIMAL(14,2) NOT NULL,
  method        VARCHAR(12)  NOT NULL DEFAULT 'cash',
  spent_at      DATE NOT NULL,
  voided        TINYINT(1)   NOT NULL DEFAULT 0,
  created_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_expenses_biz (business_id, spent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id  INT UNSIGNED NULL,
  member_id    INT UNSIGNED NULL,
  action       VARCHAR(40)  NOT NULL,
  entity       VARCHAR(30)  NOT NULL DEFAULT '',
  entity_id    INT UNSIGNED NOT NULL DEFAULT 0,
  detail       VARCHAR(500) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_biz (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
