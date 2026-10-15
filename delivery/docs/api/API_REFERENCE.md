# ETEL Delivery & Ride API reference

Generated from the source by `tools/gen_api_docs.py` (434 actions). Machine-readable: `openapi.json`.

**Call:** `POST /delivery/api/boot.php` with JSON `{"action": "<name>", ...fields}`; header `Authorization: Bearer <token>` for everything except the *public* actions.
**Reply:** `{success, data, message, v}`. **Login:** `login` / `etelid_login` (apps) or `admin_etelid_login` (admin; add `totp_code` when the reply says `requires_2fa`).

| Who can call | Actions |
|---|---|
| admin | 205 |
| any signed-in user | 178 |
| courier | 16 |
| public | 13 |
| public (this call signs you in) | 6 |
| service key (X-Etel-Service-Key) | 16 |

## Admin

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_add_canary_tester` | admin · owner.only | `app`, `user_id` | - |
| `admin_add_item` | admin · items.manage | `category`, `description`, `image_url`, `name`, `original_price`, `preparation_time`, `price`, `service_giver_id`, `stock_quantity` | - |
| `admin_add_vehicle_type` | admin · pricing.manage | `base_fare`, `min_fare`, `min_fare_short_trip`, `per_km_rate`, `per_km_rate_open`, `vehicle_type`, `wait_rate_per_min` | - |
| `admin_add_zone` | admin · settings.manage | `base_fare`, `est_minutes`, `name`, `sort_order` | - |
| `admin_analytics` | admin · analytics.view | - | `period` |
| `admin_approve_etel_topup` | admin · finance.manage | `id` | - |
| `admin_assign_service_giver` | admin · service_givers.manage | `order_id`, `service_giver_id` | - |
| `admin_backfill_cash_commission` | admin · finance.manage | - | - |
| `admin_backfill_order_service_giver_ids` | admin · orders.manage | - | - |
| `admin_create_announcement` | admin · settings.manage | `audience`, `body`, `category`, `title` | - |
| `admin_create_user` | admin · users.manage | `name`, `password`, `phone`, `role` | - |
| `admin_delete_announcement` | admin · settings.manage | `id` | - |
| `admin_delete_goal_tier` | admin · pricing.manage | `id` | - |
| `admin_delete_item` | admin · items.manage | `item_id` | - |
| `admin_delete_role_permissions` | admin · owner.only | `id` | - |
| `admin_delete_vehicle_type` | admin · pricing.manage | `vehicle_type` | - |
| `admin_delete_zone` | admin · settings.manage | `id` | - |
| `admin_force_confirm` | admin · orders.manage | `conf_id` | - |
| `admin_get_all_items` | admin · items.view | - | `category`, `service_giver_id` |
| `admin_get_announcements` | admin · settings.view | - | - |
| `admin_get_audit_settings` | admin · owner.only | - | - |
| `admin_get_bank_accounts` | admin · finance.view | - | - |
| `admin_get_confirmations` | admin · orders.view | - | - |
| `admin_get_delivery_tiers` | admin · settings.view | - | - |
| `admin_get_goal_tiers` | admin · pricing.manage | - | - |
| `admin_get_launch_checklist_state` | admin · settings.view | - | - |
| `admin_get_my_capabilities` | admin | - | - |
| `admin_get_order_issues` | admin · orders.view | - | `status` |
| `admin_get_order_receipt` | admin · orders.view | `order_id` | `order_id` |
| `admin_get_pending_etel_topups` | admin · finance.view | - | - |
| `admin_get_ride_fare_rules` | admin · pricing.manage | - | - |
| `admin_get_ride_issues` | admin · rides.view | - | `status` |
| `admin_get_ride_peak_windows` | admin · pricing.manage | - | - |
| `admin_get_service_giver` | admin · service_givers.view | - | `id` |
| `admin_get_service_giver_statistics` | admin · service_givers.view | - | - |
| `admin_get_sos_alerts` | admin · sos.view | - | `status` |
| `admin_get_support_thread` | admin · orders.view | `user_id` | `user_id` |
| `admin_get_zones` | admin · settings.view | - | - |
| `admin_list_etelpay_overrides` | admin · owner.only | - | - |
| `admin_list_release_channels` | admin · owner.only | - | - |
| `admin_list_role_permissions` | admin · owner.only | - | - |
| `admin_list_support_threads` | admin · orders.view | `unread_only` | `unread_only` |
| `admin_promote_canary_to_stable` | admin · owner.only | `app` | - |
| `admin_reject_etel_topup` | admin · finance.manage | `id`, `reason` | - |
| `admin_remove_canary_tester` | admin · owner.only | `app`, `user_id` | - |
| `admin_remove_etelpay_override` | admin · owner.only | `user_id` | - |
| `admin_reply_support_message` | admin · orders.view | `body`, `subject`, `user_id` | - |
| `admin_resolve_order_issue` | admin · orders.manage | `id` | - |
| `admin_resolve_ride_issue` | admin · rides.manage | `id` | - |
| `admin_resolve_sos` | admin · sos.manage | `id` | - |
| `admin_ride_growth` | admin · analytics.view | `date_from`, `date_to` | - |
| `admin_ride_payments` | admin · finance.view | - | - |
| `admin_rollback_stable` | admin · owner.only | `app` | - |
| `admin_run_code_audit` | admin · owner.only | `provider` | - |
| `admin_run_system_test` | admin · rides.manage | - | - |
| `admin_save_audit_settings` | admin · owner.only | `default_provider`, `gemini_key`, `openrouter_key`, `openrouter_model` | - |
| `admin_save_goal_tier` | admin · pricing.manage | `active`, `bonus_etb`, `id`, `sort_order`, `trips_required` | - |
| `admin_search_canary_candidates` | admin · owner.only | `q` | - |
| `admin_set_etelpay_override` | admin · owner.only | `note`, `phone`, `user_id`, `value` | - |
| `admin_set_release_channel` | admin · owner.only | `app`, `channel`, `version` | - |
| `admin_toggle_feature` | admin · owner.only | `flag`, `value` | - |
| `admin_toggle_item` | admin · items.manage | `is_available`, `item_id` | - |
| `admin_toggle_launch_checklist_item` | admin · settings.manage | `checked`, `item_key` | - |
| `admin_topup_etel_money` | admin · finance.manage | `amount`, `driver_id`, `note` | - |
| `admin_update_bank_account` | admin · finance.manage | `account_name`, `account_number`, `active`, `display_name`, `id` | - |
| `admin_update_delivery_tier` | admin · settings.manage | `active`, `eta_delta_min`, `icon`, `id`, `label`, `multiplier`, `sort_order` | - |
| `admin_update_item` | admin · items.manage | `item_id` | - |
| `admin_update_order` | admin · orders.manage | `order_id`, `status` | - |
| `admin_update_ride_fare_rule` | admin · pricing.manage | `active`, `vehicle_type` | - |
| `admin_update_ride_peak_windows` | admin · pricing.manage | `windows` | - |
| `admin_update_ride_surge_multiplier` | admin · pricing.manage | `value` | - |
| `admin_update_role_permissions` | admin · owner.only | `capabilities`, `role_name` | - |
| `admin_update_zone` | admin · settings.manage | `active`, `base_fare`, `est_minutes`, `id`, `name`, `sort_order` | - |
| `get_feature_flags` | public | - | - |
| `get_service_givers` | admin · service_givers.view | - | `limit`, `status` |

## Agent

| Action | Auth | Fields | Query |
|---|---|---|---|
| `agent_book_ride` | service key (X-Etel-Service-Key) | `destination_name`, `distance_km`, `etel_sso_token`, `payment_method`, `pickup_lat`, `pickup_lng`, `vehicle_type` | - |
| `agent_cancel_ride` | service key (X-Etel-Service-Key) | - | - |
| `agent_find_and_quote` | service key (X-Etel-Service-Key) | `items`, `pickup_lat`, `pickup_lng`, `to_zone` | - |
| `agent_order_quote` | service key (X-Etel-Service-Key) | `items`, `pickup_lat`, `pickup_lng`, `to_zone` | - |
| `agent_order_status` | service key (X-Etel-Service-Key) | - | - |
| `agent_place_order` | service key (X-Etel-Service-Key) | `etel_sso_token`, `items`, `payment_method`, `pickup_lat`, `pickup_lng`, `recipient_name`, `recipient_phone`, `to_zone` | - |
| `agent_ride_quote` | service key (X-Etel-Service-Key) | `destination_name`, `distance_km`, `pickup_lat`, `pickup_lng`, `vehicle_type` | - |
| `agent_ride_status` | service key (X-Etel-Service-Key) | - | - |
| `agent_search_items` | service key (X-Etel-Service-Key) | `limit`, `query` | - |

## Auth

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_etelid_login` | public (this call signs you in) | `code`, `identifier`, `password`, `phone`, `totp_code` | - |
| `admin_reset_user_2fa` | admin · users.manage | `phone` | - |
| `change_password` | any signed-in user | `current_password`, `new_password` | - |
| `etelid_login` | public (this call signs you in) | `app`, `identifier`, `password`, `phone`, `role`, `totp_code` | - |
| `login` | public (this call signs you in) | `app`, `password`, `phone`, `role` | - |
| `register` | public (this call signs you in) | `name`, `password`, `phone`, `referral_code`, `role`, `vehicle`, `zone` | - |
| `sg_login` | public (this call signs you in) | `password`, `phone` | - |

## Cash

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_mark_order_paid` | admin · finance.manage | `note`, `order_ids` | - |
| `admin_unpaid_delivered` | admin · finance.view | - | - |

## Chapa

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_list_chapa_payments` | admin · finance.view | `days` | - |
| `chapa_init` | any signed-in user | `id`, `kind` | - |
| `chapa_status` | any signed-in user | `tx_ref` | - |

## CourierFuel

| Action | Auth | Fields | Query |
|---|---|---|---|
| `courier_add_fuel_log` | public | `cost_etb`, `liters`, `odometer_km` | - |
| `courier_delete_fuel_log` | public | `id` | - |
| `courier_get_fuel_summary` | public | `period` | `period` |
| `courier_list_fuel_logs` | public | - | - |

## CourierPay

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_get_courier_pay` | admin · pricing.manage | - | - |
| `admin_get_order_pay` | admin · pricing.manage | `order_code`, `order_id` | - |
| `admin_preview_courier_pay` | admin · pricing.manage | `drop_km`, `night`, `pickup_km`, `tier` | - |
| `admin_save_courier_pay` | admin · pricing.manage | - | - |
| `admin_set_order_pay` | admin · pricing.manage | `amount`, `order_id`, `reason` | - |
| `get_tip_options` | any signed-in user | - | - |

## Dispatch

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_get_stale_assign` | admin · orders.manage | - | - |
| `admin_save_stale_assign` | admin · orders.manage | `job_max_age`, `minutes`, `pickup_minutes` | - |

## DisplayPrefs

| Action | Auth | Fields | Query |
|---|---|---|---|
| `get_display_prefs` | any signed-in user | - | - |
| `save_display_prefs` | any signed-in user | `prefs` | - |

## Documents

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_docs_create_text` | admin · documents.manage | `category`, `content`, `description`, `filename`, `title` | - |
| `admin_docs_delete` | admin · documents.manage | `document_id` | - |
| `admin_docs_get` | admin · documents.view | `document_id` | `document_id` |
| `admin_docs_list` | admin · documents.view | `category`, `search` | `category`, `search` |
| `admin_docs_restore_version` | admin · documents.manage | `document_id`, `version_id` | - |
| `admin_docs_update_meta` | admin · documents.manage | `category`, `description`, `document_id`, `title` | - |
| `admin_docs_update_text` | admin · documents.manage | `change_note`, `content`, `document_id` | - |

## DriverFuel

| Action | Auth | Fields | Query |
|---|---|---|---|
| `driver_add_fuel_log` | any signed-in user | `cost_etb`, `liters`, `odometer_km` | - |
| `driver_delete_fuel_log` | any signed-in user | `id` | - |
| `driver_get_fuel_summary` | any signed-in user | `period` | - |
| `driver_list_fuel_logs` | any signed-in user | - | - |

## Equb

| Action | Auth | Fields | Query |
|---|---|---|---|
| `equb_admin_add_member` | admin · equb.manage | `group_id`, `phone` | - |
| `equb_admin_create_package` | admin · equb.manage | `contribution_amount`, `description`, `frequency`, `max_members`, `name`, `payout_order`, `tier` | - |
| `equb_admin_force_process_round` | admin · equb.manage | `group_id` | - |
| `equb_admin_grant_access` | admin · equb.manage | `phone` | - |
| `equb_admin_list_groups` | admin · equb.view | - | - |
| `equb_admin_list_packages` | admin · equb.view | - | - |
| `equb_admin_list_participants` | admin · equb.view | - | - |
| `equb_admin_revoke_access` | admin · equb.manage | `user_id` | - |
| `equb_admin_update_package` | admin · equb.manage | `active`, `id` | - |
| `equb_am_i_permitted` | any signed-in user | - | - |
| `equb_create_group` | admin · equb.manage | `contribution_amount`, `frequency`, `max_members`, `name`, `payout_order` | - |
| `equb_get_group_detail` | any signed-in user | `group_id` | `group_id` |
| `equb_get_my_groups` | any signed-in user | - | - |
| `equb_join_group` | any signed-in user | `group_id` | - |
| `equb_leave_group` | any signed-in user | `group_id` | - |
| `equb_list_open_groups` | any signed-in user | - | - |
| `equb_list_packages` | any signed-in user | - | - |
| `equb_select_package` | any signed-in user | `package_id` | - |
| `equb_start_group` | admin · equb.manage | `group_id` | - |

## Equity

| Action | Auth | Fields | Query |
|---|---|---|---|
| `equity_add_investor` | admin · equity.manage | `agreement_status`, `amount_invested`, `contact`, `invested_at`, `name`, `notes`, `payment_status`, `receipt_number` | - |
| `equity_delete_investor` | admin · equity.manage | `id` | - |
| `equity_distribute_profit` | admin · equity.manage | `note`, `profit_amount` | - |
| `equity_get_dashboard` | admin · equity.view | - | - |
| `equity_get_distribution` | admin · equity.view | `id` | `id` |
| `equity_get_settings` | admin · equity.view | - | - |
| `equity_link_investor_user` | admin · equity.manage | `investor_id`, `phone` | - |
| `equity_list_distributions` | admin · equity.view | - | - |
| `equity_list_investors` | admin · equity.view | - | - |
| `equity_preview_distribution` | admin · equity.view | `profit_amount` | - |
| `equity_regenerate_investor_token` | admin · equity.manage | `id` | - |
| `equity_unlink_investor_user` | admin · equity.manage | `investor_id` | - |
| `equity_update_investor` | admin · equity.manage | `agreement_status`, `amount_invested`, `can_view_captable`, `can_view_distributions`, `can_view_growth_planner`, `contact`, `id`, `name`, `notes`, `payment_status`, `receipt_number` | - |
| `equity_update_settings` | admin · equity.manage | `founder_capital_contributed`, `investor_equity_pct`, `target_amount`, `total_shares` | - |
| `investor_portal_am_i_investor` | any signed-in user | - | - |
| `investor_portal_get_my_growth_planner_data` | any signed-in user | - | - |
| `investor_portal_get_my_view` | any signed-in user | - | - |

## Integration

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_integrations_add` | admin · integrations.manage | `kind`, `name` | - |
| `admin_integrations_get` | admin · integrations.manage | - | - |
| `admin_integrations_remove` | admin · integrations.manage | `provider` | - |
| `admin_integrations_save` | admin · integrations.manage | `provider`, `values` | - |
| `admin_integrations_test` | admin · integrations.manage | `provider` | - |

## InvestorPortal

| Action | Auth | Fields | Query |
|---|---|---|---|
| `investor_portal_get_growth_planner_data` | public | `token` | - |
| `investor_portal_get_view` | public | `token` | - |

## ManualDispatch

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_assign_courier` | admin · orders.manage | `courier_id`, `order_id` | - |
| `admin_cancel_order` | admin · orders.manage | `note`, `order_id`, `reason` | - |
| `admin_dispatch_board` | admin · orders.view | - | - |
| `admin_take_back_order` | admin · orders.manage | `order_id` | - |

## MoneyLedger

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_cash_remit` | admin · finance.manage | `amount`, `courier_id`, `note` | - |
| `admin_courier_cash_report` | admin · finance.view | - | - |
| `admin_refund_mark` | admin · finance.manage | `amount`, `method`, `note`, `order_id`, `reference` | - |
| `admin_refunds_report` | admin · finance.view | `days` | `days` |

## Nfc

| Action | Auth | Fields | Query |
|---|---|---|---|
| `nfc_confirm_delivery` | service key (X-Etel-Service-Key) | `confirmer_etel_id`, `confirmer_name`, `confirmer_phone`, `courier_etel_id`, `courier_phone`, `order_id` | - |
| `nfc_confirm_pickup` | service key (X-Etel-Service-Key) | `confirmer_etel_id`, `confirmer_name`, `confirmer_phone`, `courier_etel_id`, `courier_phone`, `order_id` | - |
| `nfc_driver_status` | service key (X-Etel-Service-Key) | `etel_id`, `phone` | - |
| `nfc_list_courier_orders` | service key (X-Etel-Service-Key) | `confirmer_etel_id`, `confirmer_name`, `confirmer_phone`, `courier_etel_id`, `courier_phone`, `stage` | - |
| `nfc_start_ride` | service key (X-Etel-Service-Key) | `customer_etel_id`, `customer_name`, `customer_phone`, `driver_etel_id`, `driver_phone` | - |

## Order

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_confirm_bank_payment` | any signed-in user | `decision`, `payment_id` | - |
| `admin_confirm_telebirr_payment` | any signed-in user | `decision`, `payment_id` | - |
| `admin_financial_report` | any signed-in user | `days` | `days` |
| `admin_list_bank_payments` | any signed-in user | - | - |
| `admin_list_telebirr_payments` | any signed-in user | - | - |
| `admin_set_payment_logo` | any signed-in user | `logo_url`, `method_key` | - |
| `cancel_scheduled_order` | any signed-in user | `scheduled_id` | - |
| `check_delivery_confirmation` | courier | `order_id` | `order_id` |
| `check_pickup_confirmation` | courier | `order_id` | `order_id` |
| `check_telebirr_payment` | any signed-in user | `payment_id` | - |
| `confirm_bank_payment` | any signed-in user | `amount`, `bank`, `order_id`, `reference_no` | - |
| `courier_accept_offer` | courier | `order_id` | - |
| `courier_accept_order` | courier | `order_id` | - |
| `courier_decline_offer` | courier | `order_id` | - |
| `courier_earnings_summary` | courier | - | - |
| `courier_get_available_orders` | courier | - | - |
| `courier_get_kpi_summary` | courier | `period` | `period` |
| `courier_get_my_orders` | courier | - | `offers`, `status` |
| `courier_get_profile` | courier | - | - |
| `courier_go_offline` | courier | - | - |
| `courier_go_online` | courier | `lat`, `lng` | - |
| `courier_update_location` | courier | `lat`, `lng` | - |
| `courier_update_order_status` | courier | `order_id`, `status` | - |
| `customer_add_address` | any signed-in user | `address_line`, `is_default`, `label`, `lat`, `lng`, `zone` | - |
| `customer_cancel_order` | any signed-in user | `confirm_fee`, `note`, `order_id`, `reason` | - |
| `customer_confirm_delivery` | any signed-in user | `confirm_payment_method`, `order_id`, `payment_confirmed`, `wallet_tx_ref` | - |
| `customer_create_order` | any signed-in user | `_allow_chapa`, `customer_lat`, `customer_lng`, `delivery_lat`, `delivery_lng`, `delivery_photo_url`, `delivery_tier`, `items`, `notes`, `payment_method`, `promo_code`, `recipient_name`, `recipient_phone`, `tip`, `to_zone`, `use_points` | - |
| `customer_delete_address` | any signed-in user | `id` | - |
| `customer_get_addresses` | any signed-in user | - | - |
| `customer_get_favorites` | any signed-in user | - | - |
| `customer_get_messages` | any signed-in user | - | - |
| `customer_get_order_issues` | any signed-in user | - | - |
| `customer_get_orders` | any signed-in user | - | `status` |
| `customer_get_referral_info` | any signed-in user | - | - |
| `customer_get_settings` | any signed-in user | - | - |
| `customer_pending_confirmations` | any signed-in user | - | - |
| `customer_reorder_items` | any signed-in user | `order_id` | `order_id` |
| `customer_send_message` | any signed-in user | `body`, `subject` | - |
| `customer_toggle_favorite` | any signed-in user | `service_giver_id` | - |
| `customer_update_address` | any signed-in user | `address_line`, `id`, `is_default`, `label`, `lat`, `lng`, `zone` | - |
| `customer_update_settings` | any signed-in user | - | - |
| `customer_wallet_history` | any signed-in user | - | - |
| `get_order_ratings` | admin · orders.view | - | `limit` |
| `get_payment_method_logos` | any signed-in user | - | - |
| `get_scheduled_orders` | any signed-in user | - | - |
| `get_shop_reviews` | any signed-in user | - | `limit`, `service_giver_id` |
| `initiate_telebirr_payment` | any signed-in user | `amount`, `order_id`, `phone` | - |
| `rate_order` | any signed-in user | `comment`, `courier_rating`, `food_rating`, `order_id`, `stars`, `tags` | - |
| `request_delivery_confirmation` | courier | `order_id` | - |
| `request_pickup_confirmation` | courier | `order_id` | - |
| `schedule_order` | any signed-in user | `order_template`, `repeat_daily`, `scheduled_at` | - |
| `sg_confirm_pickup` | any signed-in user | `order_id` | - |
| `sg_pending_confirmations` | any signed-in user | - | - |
| `submit_order_issue` | any signed-in user | `category`, `details`, `order_id` | - |
| `track_order` | any signed-in user | `order_code`, `order_id` | `order_code`, `order_id` |

## Package

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_package_rates_get` | admin · orders.view | - | - |
| `admin_package_rates_save` | admin · orders.manage | `vehicle_class` | - |
| `admin_package_setting_save` | admin · orders.manage | `key`, `value` | - |
| `admin_package_type_add` | admin · orders.manage | - | - |
| `customer_package_create` | any signed-in user | `_allow_chapa` | - |
| `customer_package_options` | any signed-in user | - | - |
| `customer_package_quote` | any signed-in user | `drop`, `pickup` | - |

## Photo

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_photo_coverage` | admin · items.view | - | - |
| `admin_set_shop_branding` | admin · items.manage | `service_giver_id` | - |

## Planning

| Action | Auth | Fields | Query |
|---|---|---|---|
| `planning_add_hire` | admin · planning.manage | `headcount`, `monthly_salary`, `notes`, `role_name` | - |
| `planning_delete_hire` | admin · planning.manage | `id` | - |
| `planning_get_notes` | admin · planning.view | - | - |
| `planning_list_hires` | admin · planning.view | - | - |
| `planning_reset_and_seed_demo` | admin · planning.manage | - | - |
| `planning_save_notes` | admin · planning.manage | `content` | - |
| `planning_update_hire` | admin · planning.manage | `headcount`, `id`, `monthly_salary`, `notes`, `role_name` | - |

## PlatformEconomics

| Action | Auth | Fields | Query |
|---|---|---|---|
| `platform_economics_get_costs` | admin · platform_economics.view | - | - |
| `platform_economics_get_growth_assumptions` | admin · platform_economics.view | - | - |
| `platform_economics_get_summary` | admin · platform_economics.view | `period` | - |
| `platform_economics_reset_growth_assumptions` | admin · platform_economics.manage | - | - |
| `platform_economics_save_growth_assumptions` | admin · platform_economics.manage | - | - |
| `platform_economics_update_costs` | admin · platform_economics.manage | - | - |

## Push

| Action | Auth | Fields | Query |
|---|---|---|---|
| `remove_push_subscription` | any signed-in user | `endpoint` | - |
| `remove_push_token` | any signed-in user | `token` | - |
| `save_push_subscription` | any signed-in user | `subscription` | - |
| `save_push_token` | any signed-in user | `token` | - |
| `test_send_push` | any signed-in user | - | - |

## RatingsAdmin

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_ratings_report` | admin · orders.view | `days` | `days` |

## Ride

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_approve_compliance_document` | admin · users.manage | `request_id` | - |
| `admin_approve_driver_identity` | admin · users.manage | `user_id` | - |
| `admin_approve_vehicle_change` | admin · rides.manage | `request_id` | - |
| `admin_backup_download` | admin · system.backup | - | - |
| `admin_cancel_ride` | admin · rides.manage | `ride_id` | - |
| `admin_get_2fa_rule` | admin · system.backup | - | - |
| `admin_get_drivers` | admin · users.view | `q` | `q`, `search` |
| `admin_get_flagged_identities` | admin · users.manage | - | - |
| `admin_get_live_drivers` | admin · users.view | `lat`, `lng` | - |
| `admin_get_pending_compliance_requests` | admin · users.manage | - | - |
| `admin_get_pending_vehicle_changes` | admin · rides.view | - | - |
| `admin_get_ride_stats` | admin · rides.view | - | - |
| `admin_get_rides` | admin · rides.view | `limit`, `offset`, `search`, `status` | - |
| `admin_get_suspended_drivers` | admin · users.view | - | - |
| `admin_list_driver_compliance` | admin · users.view | - | - |
| `admin_manual_complete_ride` | admin · rides.manage | `dropoff_address`, `dropoff_lat`, `dropoff_lng`, `note`, `payment_method`, `pickup_address`, `pickup_lat`, `pickup_lng`, `ride_id`, `waypoints` | - |
| `admin_reject_compliance_document` | admin · users.manage | `reason`, `request_id` | - |
| `admin_reject_driver_identity` | admin · users.manage | `reason`, `user_id` | - |
| `admin_reject_vehicle_change` | admin · rides.manage | `reason`, `request_id` | - |
| `admin_ride_watch` | admin · rides.view | - | - |
| `admin_set_2fa_required` | admin · system.backup | `required` | - |
| `admin_set_ride_pin_required` | admin · system.backup | `required` | - |
| `admin_suspend_driver` | admin · users.manage | `reason`, `user_id` | - |
| `admin_system_health` | admin · rides.view | - | - |
| `admin_unsuspend_driver` | admin · users.manage | `user_id` | - |
| `admin_update_driver_compliance` | admin · users.manage | `user_id` | - |
| `book_ride` | any signed-in user | `dropoff_addr`, `dropoff_lat`, `dropoff_lng`, `manual_km`, `payment_method`, `pickup_addr`, `pickup_lat`, `pickup_lng`, `quote_token`, `route_km`, `vehicle_type` | - |
| `cancel_ride` | any signed-in user | `id`, `reason`, `ride_id` | - |
| `customer_confirm_arrival` | any signed-in user | `payment_method`, `ride_id`, `wallet_tx_ref` | - |
| `driver_accept_ride` | any signed-in user | `request_id` | - |
| `driver_arrived` | any signed-in user | `ride_id` | - |
| `driver_beacon_ping` | any signed-in user | `lat`, `lng` | - |
| `driver_cancel_ride` | any signed-in user | `ride_id` | - |
| `driver_cashout` | any signed-in user | `amount` | - |
| `driver_complete_ride` | any signed-in user | `ride_id` | - |
| `driver_get_announcements` | any signed-in user | `category` | `category` |
| `driver_get_compliance_status` | any signed-in user | - | - |
| `driver_get_demand` | any signed-in user | `lat`, `lng` | - |
| `driver_get_earnings_for_date` | any signed-in user | `date` | - |
| `driver_get_goal_tiers` | any signed-in user | - | - |
| `driver_get_kpi_summary` | any signed-in user | `period` | - |
| `driver_get_nearby_requests` | any signed-in user | `radius_km` | - |
| `driver_get_pending_vehicle_change` | any signed-in user | - | - |
| `driver_get_profile` | any signed-in user | - | - |
| `driver_get_referral_info` | any signed-in user | - | - |
| `driver_get_ride_history` | any signed-in user | - | - |
| `driver_get_topup_history` | any signed-in user | - | - |
| `driver_go_offline` | any signed-in user | - | - |
| `driver_go_online` | any signed-in user | `lat`, `lng` | - |
| `driver_hail_customer` | any signed-in user | `km`, `lat`, `lng`, `open`, `phone` | - |
| `driver_mark_announcement_read` | any signed-in user | `announcement_id` | - |
| `driver_start_ride` | any signed-in user | `pin`, `ride_id` | - |
| `driver_submit_compliance_document` | any signed-in user | `doc_type`, `expiry_date`, `receipt_url` | - |
| `driver_submit_manual_topup` | any signed-in user | `amount`, `note`, `receipt_image_url` | - |
| `driver_toggle_wait` | any signed-in user | `ride_id` | - |
| `driver_topup_etel_money` | any signed-in user | `amount`, `receipt_image_url`, `tx_ref` | - |
| `driver_update_location` | any signed-in user | `accuracy`, `lat`, `lng`, `ride_id` | - |
| `driver_update_profile` | any signed-in user | `license_dob`, `license_name`, `license_photo_url`, `selfie_url`, `vehicle_plate`, `vehicle_type` | - |
| `get_etelpay_merchant` | any signed-in user | - | - |
| `get_ride_messages` | any signed-in user | `ride_id`, `since_id` | `ride_id`, `since_id` |
| `get_ride_path` | any signed-in user | `ride_id` | - |
| `my_active_ride` | any signed-in user | - | - |
| `passenger_get_nearby_drivers` | any signed-in user | `lat`, `lng`, `radius_km`, `vehicle_type` | `lat`, `lng`, `radius_km`, `vehicle_type` |
| `passenger_get_ride_history` | any signed-in user | - | - |
| `ping_ride_typing` | any signed-in user | `ride_id` | - |
| `rate_ride` | any signed-in user | `comment`, `rating`, `ride_id` | - |
| `rate_rider` | any signed-in user | `checkins`, `comment`, `contacts`, `rating`, `ride_id` | - |
| `ride_add_favorite_route` | any signed-in user | `dropoff_address`, `dropoff_lat`, `dropoff_lng`, `label`, `pickup_address`, `pickup_lat`, `pickup_lng` | - |
| `ride_cancel_scheduled` | any signed-in user | `id` | - |
| `ride_chat_unread` | any signed-in user | `ride_id` | `ride_id` |
| `ride_delete_favorite_route` | any signed-in user | `id` | - |
| `ride_estimate_fare` | any signed-in user | `distance_km`, `open_trip`, `vehicle_type` | `distance_km`, `open_trip`, `vehicle_type` |
| `ride_get_favorite_routes` | any signed-in user | - | - |
| `ride_get_messages` | any signed-in user | - | - |
| `ride_get_scheduled` | any signed-in user | - | - |
| `ride_get_share_link` | any signed-in user | `ride_id` | `ride_id` |
| `ride_get_vehicle_types` | any signed-in user | - | - |
| `ride_history` | any signed-in user | - | - |
| `ride_pin_get` | any signed-in user | - | - |
| `ride_receipt` | any signed-in user | `id` | - |
| `ride_safety_checkin_missed` | any signed-in user | `ride_id` | - |
| `ride_schedule` | any signed-in user | `dropoff_addr`, `dropoff_lat`, `dropoff_lng`, `payment_method`, `pickup_addr`, `pickup_lat`, `pickup_lng`, `route_km`, `scheduled_at`, `vehicle_type` | - |
| `ride_status` | any signed-in user | `id` | `id` |
| `ride_stop_sharing` | any signed-in user | `ride_id` | - |
| `rider_get_announcements` | any signed-in user | - | - |
| `rider_mark_announcement_read` | any signed-in user | `announcement_id` | - |
| `send_ride_message` | any signed-in user | `body`, `ride_id` | - |
| `sos_trigger` | any signed-in user | `lat`, `lng`, `message`, `ride_id` | - |
| `submit_ride_issue` | any signed-in user | `category`, `details`, `ride_id` | - |

## ServiceGiver

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_list_payout_requests` | any signed-in user | - | - |
| `admin_review_payout_request` | any signed-in user | `decision`, `note`, `request_id` | - |
| `sg_accept_order` | any signed-in user | `order_id` | - |
| `sg_add_item` | any signed-in user | `category`, `description`, `description_am`, `image_url`, `name`, `name_am`, `original_price`, `portion`, `preparation_time`, `price`, `quality`, `stock_quantity`, `suggest_catalog` | - |
| `sg_add_staff` | any signed-in user | `name`, `password`, `phone`, `role_id` | - |
| `sg_catalog_add` | any signed-in user | `picks` | - |
| `sg_catalog_import` | any signed-in user | `rows` | - |
| `sg_catalog_list` | any signed-in user | - | `category`, `q` |
| `sg_complete_order` | any signed-in user | `order_id` | - |
| `sg_delete_item` | any signed-in user | `item_id` | - |
| `sg_delete_role` | any signed-in user | `role_id` | - |
| `sg_financial_report` | any signed-in user | `days`, `sg_id` | `days`, `sg_id` |
| `sg_get_kitchen_ticket` | any signed-in user | `order_id` | `order_id` |
| `sg_get_my_items` | any signed-in user | - | `category` |
| `sg_get_pending_payout_request` | any signed-in user | `sg_id` | `sg_id` |
| `sg_get_staff` | any signed-in user | `sg_id` | `sg_id` |
| `sg_list_roles` | any signed-in user | - | - |
| `sg_my_role` | any signed-in user | - | - |
| `sg_notifications` | any signed-in user | - | - |
| `sg_order_detail` | any signed-in user | - | `order_id` |
| `sg_orders` | any signed-in user | - | `status` |
| `sg_profile` | any signed-in user | - | - |
| `sg_reject_order` | any signed-in user | `note`, `order_id`, `reason` | - |
| `sg_remove_staff` | any signed-in user | `active`, `staff_id` | - |
| `sg_reply_review` | any signed-in user | `rating_id`, `reply` | - |
| `sg_reviews` | any signed-in user | `sg_id` | `sg_id` |
| `sg_sales_report` | any signed-in user | `days`, `sg_id` | `days`, `sg_id` |
| `sg_save_role` | any signed-in user | `capabilities`, `role_id`, `role_name` | - |
| `sg_scan_qr` | any signed-in user | `order_id`, `qr_type` | - |
| `sg_set_hours` | any signed-in user | `closes_at`, `opens_at` | - |
| `sg_set_online` | any signed-in user | `is_online` | - |
| `sg_sold_out` | any signed-in user | `item_id`, `sold_out` | - |
| `sg_stats` | any signed-in user | - | - |
| `sg_update_item` | any signed-in user | `description`, `description_am`, `image_url`, `is_available`, `item_id`, `name`, `name_am`, `original_price`, `portion`, `preparation_time`, `price`, `quality`, `stock_quantity` | - |
| `sg_update_order_status` | any signed-in user | `new_status`, `order_id` | - |
| `sg_update_profile` | any signed-in user | `abysinia_account`, `abysinia_account_name`, `address`, `bank_account`, `business_name`, `cbe_account`, `cbe_account_name`, `city`, `cover_url`, `lat`, `lng`, `logo_url`, `payout_bank`, `service_type`, `sg_id`, `telebirr_account_name`, `telebirr_number`, `vehicle_plate`, `vehicle_type`, `zone` | `sg_id` |
| `sg_update_staff_role` | any signed-in user | `role_id`, `staff_id` | - |

## SystemReset

| Action | Auth | Fields | Query |
|---|---|---|---|
| `system_reset_backup` | admin · system.reset | - | - |
| `system_reset_execute` | admin · system.reset | `backup_token`, `confirm_phrase`, `keep_phones` | - |
| `system_reset_preview` | admin · system.reset | `keep_phones` | - |

## boot

| Action | Auth | Fields | Query |
|---|---|---|---|
| `admin_catalog_copy` | admin · items.manage | `from_sg`, `include_hidden`, `percent`, `to_sg` | - |
| `admin_catalog_delete` | admin · items.manage | `id` | - |
| `admin_catalog_list` | admin · items.manage | - | `q`, `status` |
| `admin_catalog_save` | admin · items.manage | `category`, `cuisine`, `description`, `description_am`, `id`, `image_url`, `name`, `name_am`, `packs`, `prep_time`, `status` | - |
| `admin_chat_threads` | admin · orders.view | `filter`, `q` | `filter`, `q` |
| `admin_create_service_giver` | admin | `address`, `business_name`, `identification_number`, `lat`, `lng`, `name`, `password`, `phone`, `service_type`, `vehicle_type`, `zone` | - |
| `admin_dashboard_stats` | admin | - | - |
| `admin_delete_placeholder_shop` | admin · owner.only | `delete_items`, `inspect`, `shop_id` | - |
| `admin_duplicate_phones` | admin | - | - |
| `admin_get_courier_offers` | admin · orders.manage | - | - |
| `admin_get_couriers` | admin | - | `limit` |
| `admin_get_live_couriers` | admin | - | - |
| `admin_get_orders` | admin | - | `status` |
| `admin_get_users` | admin | - | `limit`, `role` |
| `admin_list_promos` | admin | - | - |
| `admin_platform_pulse` | admin | `days` | `days` |
| `admin_purge_cache` | admin | - | - |
| `admin_save_courier_offers` | admin · orders.manage | `max_attempts`, `seconds` | - |
| `admin_save_promo` | admin · owner.only | `code`, `description`, `discount_type`, `first_order_only`, `id`, `is_active`, `max_discount`, `max_uses`, `min_subtotal`, `per_user_limit`, `value` | - |
| `admin_suspend_service_giver` | admin | `id` | - |
| `admin_toggle_promo` | admin · owner.only | `id`, `is_active` | - |
| `admin_update_courier` | admin | `active`, `courier_id`, `home_zone`, `plate_number`, `vehicle_type`, `zone` | `active`, `courier_id`, `home_zone`, `plate_number`, `vehicle_type`, `zone` |
| `admin_verify_service_giver` | admin | `id` | - |
| `customer_loyalty` | any signed-in user | - | - |
| `customer_validate_promo` | any signed-in user | `code`, `delivery_fee`, `subtotal` | - |
| `etelid_member_detail` | service key (X-Etel-Service-Key) | `id` | `id`, `key` |
| `etelid_members_list` | service key (X-Etel-Service-Key) | - | `limit` |
| `get_bank_accounts` | public | - | - |
| `get_config` | public | - | - |
| `get_delivery_tiers` | public | - | - |
| `get_my_notifications` | any signed-in user | - | - |
| `get_orders` | admin | - | `status` |
| `get_patch_info` | any signed-in user | `app`, `current_version` | `app`, `current_version` |
| `get_zones` | public | - | - |
| `logout` | public | - | - |
| `order_chat_get` | any signed-in user | `after_id`, `order_id` | `after_id`, `order_id` |
| `order_chat_inbox` | any signed-in user | - | - |
| `order_chat_send` | any signed-in user | `body`, `order_id` | - |
| `reset_password` | public (this call signs you in) | `new_password`, `otp`, `phone`, `reset_token` | - |
| `set_user_language` | admin | `lang` | `limit`, `status` |
| `sse_admin_delivery_system_test` | any signed-in user | - | `id` |
| `sse_admin_system_test` | any signed-in user | - | `id` |
| `sse_driver_stream` | any signed-in user | `otp`, `phone` | `radius_km` |
| `sse_ride_status` | any signed-in user | - | `id` |
| `version_check` | public | - | - |

