-- Migration 028: adds geocode_cache, a local cache of Google Geocoding
-- API results for AgentController's named-destination lookups.
--
-- Every "take me to <place name>" agent quote/booking previously hit
-- Google's Geocoding API fresh, with no caching - flagged by an audit
-- as unnecessary latency + billing cost for repeated queries (the same
-- handful of popular destinations get asked about over and over).
-- Caching by normalized query text avoids re-billing/re-waiting on a
-- lookup that's already been resolved, while never affecting pricing -
-- this only caches the geocoding step, not the fare calculation.
--
-- Same formatting convention as prior migrations - one statement per
-- block, semicolon last on its line, for api/migrate.php's naive
-- line-based statement splitter.

CREATE TABLE IF NOT EXISTS `geocode_cache` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `query_norm` VARCHAR(191) NOT NULL,
    `lat` DECIMAL(10,7) NOT NULL,
    `lng` DECIMAL(10,7) NOT NULL,
    `formatted_address` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_geocode_cache_query_norm` (`query_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

