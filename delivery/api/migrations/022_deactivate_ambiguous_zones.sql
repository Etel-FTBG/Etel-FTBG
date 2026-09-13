-- Deactivates Stadium, Mehal Ketema, Misrak, Hawela Teklehaimanot, and
-- Dore Bafana - these are the 5 legitimate zones that all geocoded to
-- the identical generic city-center fallback point (Google couldn't
-- pin them precisely), and per the account owner, these are old names
-- people don't actually use day to day. GPS-based distance pricing
-- (delivery_calc_fee_by_distance in lib/delivery_pricing.php) now
-- handles delivery fee calculation directly from the customer's
-- location instead of requiring a named-zone match at all.
UPDATE `delivery_zones`
SET `active` = 0
WHERE `name` IN ('Stadium', 'Mehal Ketema', 'Misrak', 'Hawela Teklehaimanot', 'Dore Bafana');
