-- Migration 027: widens pricing_rules.rule_value from DECIMAL(8,2) to
-- TEXT.
--
-- rule_value was only ever designed to hold a single number (like the
-- 1.30 surge multiplier). The peak-hour schedule feature tries to store
-- a JSON array there instead - MySQL silently coerces a non-numeric
-- string into a DECIMAL column (truncating it to 0.00 rather than
-- erroring in non-strict mode), so every save of the schedule appeared
-- to succeed but actually stored nothing, and the panel kept falling
-- back to the built-in defaults. TEXT holds both cases fine: existing
-- numeric rows like '1.30' still parse correctly with floatval() (PHP
-- casts a TEXT '1.30' to 1.3 exactly like it did a DECIMAL column), and
-- JSON blobs now survive intact.
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

SET @col_type = (
    SELECT DATA_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pricing_rules'
      AND COLUMN_NAME = 'rule_value'
);

SET @ddl = IF(
    @col_type IS NOT NULL AND @col_type <> 'text' AND @col_type <> 'longtext' AND @col_type <> 'mediumtext',
    'ALTER TABLE `pricing_rules` MODIFY COLUMN `rule_value` TEXT NOT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;

EXECUTE stmt;

DEALLOCATE PREPARE stmt;
