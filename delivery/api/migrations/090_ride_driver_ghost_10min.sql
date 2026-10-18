-- A driver silent for 120 minutes was still shown online. Offers already skip anyone silent for 3 minutes; this makes the online flag itself catch up after 10. Only changes the value if the owner never edited it.
UPDATE pricing_rules SET rule_value = 10 WHERE rule_key = 'ride_driver_ghost_minutes' AND rule_value = 120;
