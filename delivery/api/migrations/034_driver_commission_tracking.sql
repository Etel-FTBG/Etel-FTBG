-- Migration 034: cash-ride commission tracking columns on ride_drivers.
--
-- Fixes a real payout bug: total_earnings (the driver's cashout-eligible
-- balance) was being credited with the FULL fare for every completed
-- ride regardless of payment method. For a cash ride the rider already
-- pays the driver directly in physical cash - crediting the same amount
-- into total_earnings let a driver cash out money they had already
-- physically collected, a real double-payment risk.
--
-- Fix (in RideController.php's customer_confirm_arrival): only
-- wallet-paid rides (money the platform actually collected into its own
-- merchant account) now credit total_earnings. Cash rides instead
-- accrue into the two columns added here:
--   cash_fares_total  - running total of cash-ride fare volume per driver
--   commission_owed   - running debt the driver owes the platform on that
--                        cash volume, computed at whatever commission
--                        rate is eventually decided (currently accrues
--                        at $0 since no rate has been set yet - the
--                        tracking is in place for when one is)
--
-- Same self-heal pattern already exists in RideController.php's
-- rides_add_missing_cols() call inside customer_confirm_arrival, so this
-- migration is belt-and-suspenders (matches the convention used by
-- migrations 025/026/029/030/031/032/033).

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ride_drivers'
      AND COLUMN_NAME = 'commission_owed'
);

SET @ddl = IF(
    @col_exists = 0,
    'ALTER TABLE `ride_drivers` ADD COLUMN `commission_owed` DECIMAL(12,2) DEFAULT 0, ADD COLUMN `cash_fares_total` DECIMAL(12,2) DEFAULT 0',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
