-- Migration 068: make dishes added through the menu catalog / CSV import orderable.
-- They were saved with stock_quantity = 0, which the customer app and reorder treat as sold out.
-- This sets 100 (the Service app's default) for those rows only: catalog-linked items, and custom items whose
-- code has the 4 letters + 5 digits shape that the catalog / import code generates (the normal Add Item
-- form makes 4 letters + 4 digits).
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

UPDATE `items` SET `stock_quantity` = 100 WHERE `stock_quantity` = 0 AND `is_available` = 1 AND (`catalog_id` IS NOT NULL OR `item_code` REGEXP '^[A-Z]{4}[0-9]{5}$');
