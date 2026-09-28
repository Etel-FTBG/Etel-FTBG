-- Migration 040: adds rides.cancel_reason - the reason chip/text a
-- passenger selects when cancelling (js/ride.js's showCancelReasons /
-- doCancel already sends this as body.reason on cancel_ride, but
-- RideController.php's cancel_ride handler was discarding it: the
-- UPDATE only ever set status='cancelled', cancelled_at=NOW(), never
-- writing the reason anywhere. This made cancellation clusters
-- unexplainable after the fact - no way to tell "5 cancellations in one
-- afternoon" apart from a driver-shortage problem vs. an app bug vs.
-- unrelated customer changes of mind, since the data was never kept.

ALTER TABLE `rides`
    ADD COLUMN `cancel_reason` VARCHAR(255) NULL DEFAULT NULL;
