-- Migration 029: adds fare_breakdown_json to rides.
--
-- The driver's own screen has shown a full itemized fare breakdown
-- (base/distance/wait/surge/minimum-fare-applied/total) since earlier
-- this session, but the customer's "Trip Complete" screen only ever
-- showed a single Total number - the same kind of opaque-total problem
-- the breakdown feature was originally built to solve, just on the
-- other side of the same ride.
--
-- Storing the exact breakdown array (from rides_fare_breakdown_for_ui())
-- at the moment the driver taps "Arrived" - rather than recomputing it
-- later when the customer's screen asks for it - avoids a real
-- correctness risk: surge eligibility is time-window based, so a
-- recompute done even a few minutes after arrival could land in a
-- different peak/off-peak window than what was actually charged,
-- silently showing the customer a DIFFERENT number than what they're
-- being billed. Persisting the actual breakdown used at billing time
-- means the customer always sees the exact numbers that produced their
-- total, not a best-effort re-derivation of them.

ALTER TABLE `rides`
    ADD COLUMN `fare_breakdown_json` TEXT NULL AFTER `fare_final`;
