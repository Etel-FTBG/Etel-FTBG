-- Migration 111: ETEL app (the hub app on phones) - which apps the owner shows or hides.
--
-- One row per app the owner has changed. An app with no row keeps its built-in default (live apps visible, coming-soon apps hidden).
-- mode: on = visible to everyone who may open it, admins = visible to admin accounts only (test before launch), off = hidden for everyone.
-- url: the address of an app that has no built-in address yet (EtelPay, EtelMadeya, EtelGebeya); empty = use the built-in one.
-- Additive and idempotent: one CREATE TABLE IF NOT EXISTS, no ALTER, no seed rows. Safe to run twice.

CREATE TABLE IF NOT EXISTS `app_hub_apps` (
  `app_key`    VARCHAR(32)  NOT NULL,
  `mode`       VARCHAR(8)   NOT NULL DEFAULT 'on',
  `url`        VARCHAR(255) NOT NULL DEFAULT '',
  `updated_by` INT(11)      NOT NULL DEFAULT 0,
  `updated_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
