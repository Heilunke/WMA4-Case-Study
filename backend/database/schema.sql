-- E-Ticket database schema (MySQL 8 / MariaDB 10.4+)
-- Six entities from the proposal ERD, revised per the panel's comments.
--
-- Design decisions (panel comments):
--   #3  users.role defaults to 'attendee'; the API never lets a public request choose a role.
--   #4  Payment scope: NO online gateway. payments rows are *records* the Administrator marks as paid.
--   #5  Inventory: PENDING and CONFIRMED reservations HOLD seats; CANCELLED releases them.
--       ticket_types.quantity_available = remaining, quantity_sold = held (pending + confirmed).
--       Both are changed only inside a transaction that locks the ticket_types row.
--   #6  attendee_details has exactly ONE row per ticket (enforced by the reservation transaction).
--   #1/#7 attendee_details.ticket_code (UNIQUE) is issued when a reservation is confirmed and is
--       the electronic ticket; checked_in_at / checked_in_by record the check-in.
--
-- All timestamps are stored in UTC.

CREATE DATABASE IF NOT EXISTS eticket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eticket;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS attendee_details;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS ticket_types;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_ID        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_Name      VARCHAR(100) NOT NULL,
  email          VARCHAR(100) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','attendee') NOT NULL DEFAULT 'attendee',
  created_at     DATETIME NOT NULL,
  session_version  INT UNSIGNED NOT NULL DEFAULT 1,  -- bumped on password change: logs out other sessions
  reset_code_hash  CHAR(64) NULL,                    -- SHA-256 of a one-time reset code issued by an Administrator
  reset_expires_at DATETIME NULL,
  PRIMARY KEY (user_ID),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE events (
  event_ID     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_ID      INT UNSIGNED NOT NULL,                 -- the administrator who created the event
  event_name   VARCHAR(150) NOT NULL,
  category     VARCHAR(50)  NOT NULL DEFAULT 'General',
  description  TEXT NULL,
  event_date   DATE NOT NULL,
  event_time   TIME NOT NULL,
  venue        VARCHAR(150) NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (event_ID),
  KEY idx_events_date (event_date, event_time),
  KEY idx_events_category (category),
  CONSTRAINT fk_events_user FOREIGN KEY (user_ID) REFERENCES users (user_ID)
) ENGINE=InnoDB;

CREATE TABLE ticket_types (
  ticket_ID           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_ID            INT UNSIGNED NOT NULL,
  type_name           VARCHAR(50)  NOT NULL,
  price               DECIMAL(8,2) NOT NULL DEFAULT 0.00,   -- 0.00 = free
  quantity_available  INT UNSIGNED NOT NULL,                -- remaining seats
  quantity_sold       INT UNSIGNED NOT NULL DEFAULT 0,      -- seats held (pending + confirmed)
  PRIMARY KEY (ticket_ID),
  UNIQUE KEY uq_ticket_type_per_event (event_ID, type_name),
  CONSTRAINT fk_tickets_event FOREIGN KEY (event_ID) REFERENCES events (event_ID) ON DELETE CASCADE,
  CONSTRAINT chk_ticket_price CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  reservation_ID      INT UNSIGNED NOT NULL AUTO_INCREMENT,   -- shown as RSV-### in the UI
  user_ID             INT UNSIGNED NOT NULL,                  -- the attendee who booked
  ticket_ID           INT UNSIGNED NOT NULL,
  quantity            TINYINT UNSIGNED NOT NULL,
  total_amount        DECIMAL(8,2) NOT NULL,
  reservation_status  ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  reservation_date    DATETIME NOT NULL,
  PRIMARY KEY (reservation_ID),
  KEY idx_res_user (user_ID),
  KEY idx_res_ticket (ticket_ID),
  KEY idx_res_status (reservation_status),
  CONSTRAINT fk_res_user   FOREIGN KEY (user_ID)   REFERENCES users (user_ID),
  CONSTRAINT fk_res_ticket FOREIGN KEY (ticket_ID) REFERENCES ticket_types (ticket_ID),
  CONSTRAINT chk_res_qty CHECK (quantity BETWEEN 1 AND 10)
) ENGINE=InnoDB;

CREATE TABLE attendee_details (
  attendee_ID     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_ID  INT UNSIGNED NOT NULL,
  full_name       VARCHAR(100) NOT NULL,
  contact_number  VARCHAR(20) NULL,
  ticket_code     VARCHAR(30) NULL,                 -- the e-ticket; NULL until confirmed or after it is voided
  status          TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = checked in
  checked_in_at   DATETIME NULL,
  checked_in_by   INT UNSIGNED NULL,                -- administrator who admitted the attendee
  PRIMARY KEY (attendee_ID),
  UNIQUE KEY uq_ticket_code (ticket_code),
  KEY idx_att_res (reservation_ID),
  CONSTRAINT fk_att_res FOREIGN KEY (reservation_ID) REFERENCES reservations (reservation_ID) ON DELETE CASCADE,
  CONSTRAINT fk_att_admin FOREIGN KEY (checked_in_by) REFERENCES users (user_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_ID      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_ID  INT UNSIGNED NOT NULL,            -- one-to-one with reservations
  amount          DECIMAL(8,2) NOT NULL,
  payment_method  VARCHAR(100) NULL,
  payment_status  ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  payment_date    DATETIME NULL,
  recorded_by     INT UNSIGNED NULL,                -- administrator who recorded the payment
  PRIMARY KEY (payment_ID),
  UNIQUE KEY uq_payment_reservation (reservation_ID),
  CONSTRAINT fk_pay_res FOREIGN KEY (reservation_ID) REFERENCES reservations (reservation_ID) ON DELETE CASCADE,
  CONSTRAINT fk_pay_admin FOREIGN KEY (recorded_by) REFERENCES users (user_ID) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Supporting table (not part of the ERD): failed login / password-reset attempts for rate limiting.
-- Rows older than a day are pruned automatically.
CREATE TABLE login_attempts (
  attempt_ID    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope         VARCHAR(20) NOT NULL,             -- 'login' or 'reset'
  identifier    CHAR(64) NOT NULL,                -- SHA-256 of the lower-cased email (no plain emails stored)
  ip            VARCHAR(45) NOT NULL,
  attempted_at  DATETIME NOT NULL,
  PRIMARY KEY (attempt_ID),
  KEY idx_attempt_id (scope, identifier, ip, attempted_at),
  KEY idx_attempt_ip (scope, ip, attempted_at),
  KEY idx_attempt_time (attempted_at)
) ENGINE=InnoDB;
