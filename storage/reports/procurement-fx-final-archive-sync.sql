-- Archive framework additive schema sync + unified read facade.

-- Generated at 2026-08-09T23:02:35+02:00.

-- No ACTIVE or ARCHIVE rows are modified by this migration.

CREATE DATABASE IF NOT EXISTS `lambert2_acctlab_read` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_advance_po_reconciliation_allocations` AS
SELECT a.`id`, a.`account_reconciliation_id`, a.`procurement_purchase_id`, a.`advance_payment_request_id`, a.`request_type`, a.`allocation_action`, a.`payment_status`, a.`exact_po_revision_id`, a.`exact_po_revision_number`, a.`current_po_revision_id`, a.`current_po_revision_number`, a.`previous_expected_amount`, a.`revised_expected_amount`, a.`amount_paid`, a.`amount_processing`, a.`amount_pending`, a.`recovery_allocated_amount`, a.`created_at`
FROM `lambert2_acctlab_db`.`account_advance_po_reconciliation_allocations` AS a
UNION ALL
SELECT ar.`id`, ar.`account_reconciliation_id`, ar.`procurement_purchase_id`, ar.`advance_payment_request_id`, ar.`request_type`, ar.`allocation_action`, ar.`payment_status`, ar.`exact_po_revision_id`, ar.`exact_po_revision_number`, ar.`current_po_revision_id`, ar.`current_po_revision_number`, ar.`previous_expected_amount`, ar.`revised_expected_amount`, ar.`amount_paid`, ar.`amount_processing`, ar.`amount_pending`, ar.`recovery_allocated_amount`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`account_advance_po_reconciliation_allocations` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_advance_po_reconciliation_allocations` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_payment_artifacts` AS
SELECT a.`id`, a.`batch_id`, a.`legacy_batch_id`, a.`request_type`, a.`legacy_source_table`, a.`legacy_source_id`, a.`artifact_type`, a.`artifact_id`, a.`artifact_reference`, a.`artifact_route`, a.`request_ids_json`, a.`artifact_status`, a.`removed_at`, a.`removed_by`, a.`removal_reason`, a.`created_by`, a.`created_at`
FROM `lambert2_acctlab_db`.`account_payment_artifacts` AS a
UNION ALL
SELECT ar.`id`, ar.`batch_id`, ar.`legacy_batch_id`, ar.`request_type`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`artifact_type`, ar.`artifact_id`, ar.`artifact_reference`, ar.`artifact_route`, ar.`request_ids_json`, ar.`artifact_status`, ar.`removed_at`, ar.`removed_by`, ar.`removal_reason`, ar.`created_by`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`account_payment_artifacts` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_payment_artifacts` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_payment_batches` AS
SELECT a.`id`, a.`request_type`, a.`legacy_source_table`, a.`legacy_source_id`, a.`batch_reference`, a.`processing_method`, a.`processing_reference`, a.`processing_business_days`, a.`expected_completion_at`, a.`completion_mode`, a.`status`, a.`item_count`, a.`total_amount`, a.`account_remarks`, a.`notification_created_at`, a.`created_by`, a.`created_at`, a.`updated_by`, a.`updated_at`, a.`completed_at`
FROM `lambert2_acctlab_db`.`account_payment_batches` AS a
UNION ALL
SELECT ar.`id`, ar.`request_type`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`batch_reference`, ar.`processing_method`, ar.`processing_reference`, ar.`processing_business_days`, ar.`expected_completion_at`, ar.`completion_mode`, ar.`status`, ar.`item_count`, ar.`total_amount`, ar.`account_remarks`, ar.`notification_created_at`, ar.`created_by`, ar.`created_at`, ar.`updated_by`, ar.`updated_at`, ar.`completed_at`
FROM `lambert2_acctlab_archive`.`account_payment_batches` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_payment_batches` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_payment_batch_items` AS
SELECT a.`id`, a.`batch_id`, a.`legacy_batch_id`, a.`request_type`, a.`request_id`, a.`legacy_source_table`, a.`legacy_source_id`, a.`amount`, a.`amount_paid`, a.`status`, a.`status_reason`, a.`processing_started_at`, a.`expected_completion_at`, a.`paid_at`, a.`payment_reference`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`account_payment_batch_items` AS a
UNION ALL
SELECT ar.`id`, ar.`batch_id`, ar.`legacy_batch_id`, ar.`request_type`, ar.`request_id`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`amount`, ar.`amount_paid`, ar.`status`, ar.`status_reason`, ar.`processing_started_at`, ar.`expected_completion_at`, ar.`paid_at`, ar.`payment_reference`, ar.`updated_by`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`account_payment_batch_items` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_payment_batch_items` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_payment_reminders` AS
SELECT a.`id`, a.`reminder_reference`, a.`idempotency_key`, a.`request_type`, a.`request_id`, a.`batch_id`, a.`batch_item_id`, a.`reminder_kind`, a.`lifecycle_status`, a.`delivery_status`, a.`scheduled_at`, a.`next_reminder_at`, a.`reminder_interval_minutes`, a.`attempt_count`, a.`delivery_count`, a.`failure_count`, a.`last_attempt_at`, a.`last_delivered_at`, a.`last_error`, a.`escalation_level`, a.`escalated_at`, a.`source_expected_completion_at`, a.`source_status_snapshot`, a.`context_json`, a.`lease_token`, a.`lease_expires_at`, a.`completed_at`, a.`completion_reason`, a.`completed_by`, a.`cancelled_at`, a.`cancellation_reason`, a.`created_by`, a.`created_at`, a.`updated_by`, a.`updated_at`
FROM `lambert2_acctlab_db`.`account_payment_reminders` AS a
UNION ALL
SELECT ar.`id`, ar.`reminder_reference`, ar.`idempotency_key`, ar.`request_type`, ar.`request_id`, ar.`batch_id`, ar.`batch_item_id`, ar.`reminder_kind`, ar.`lifecycle_status`, ar.`delivery_status`, ar.`scheduled_at`, ar.`next_reminder_at`, ar.`reminder_interval_minutes`, ar.`attempt_count`, ar.`delivery_count`, ar.`failure_count`, ar.`last_attempt_at`, ar.`last_delivered_at`, ar.`last_error`, ar.`escalation_level`, ar.`escalated_at`, ar.`source_expected_completion_at`, ar.`source_status_snapshot`, ar.`context_json`, ar.`lease_token`, ar.`lease_expires_at`, ar.`completed_at`, ar.`completion_reason`, ar.`completed_by`, ar.`cancelled_at`, ar.`cancellation_reason`, ar.`created_by`, ar.`created_at`, ar.`updated_by`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`account_payment_reminders` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_payment_reminders` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`account_payment_reminder_runs` AS
SELECT a.`id`, a.`run_reference`, a.`host_name`, a.`process_id`, a.`status`, a.`process_limit`, a.`started_at`, a.`finished_at`, a.`duration_ms`, a.`summary_json`, a.`error_message`, a.`created_at`
FROM `lambert2_acctlab_db`.`account_payment_reminder_runs` AS a
UNION ALL
SELECT ar.`id`, ar.`run_reference`, ar.`host_name`, ar.`process_id`, ar.`status`, ar.`process_limit`, ar.`started_at`, ar.`finished_at`, ar.`duration_ms`, ar.`summary_json`, ar.`error_message`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`account_payment_reminder_runs` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`account_payment_reminder_runs` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`advance_payment_request` AS
SELECT a.`id`, a.`suppliers_name`, a.`supplier_id`, a.`site`, a.`po_number`, a.`date_received`, a.`percentage`, a.`amount`, a.`discount`, a.`net_amount`, a.`vat`, a.`vat_rate`, a.`vat_status`, a.`wht_status`, a.`wht_rate`, a.`wht`, a.`wht_override_status`, a.`wht_override_rate`, a.`wht_override_amount`, a.`wht_override_reason`, a.`wht_override_by`, a.`wht_override_at`, a.`amount_payable`, a.`other_charges`, a.`advance_payment`, a.`payment_status`, a.`procurement_source`, a.`procurement_purchase_id`, a.`procurement_root_po_id`, a.`procurement_request_type`, a.`procurement_parent_purchase_id`, a.`procurement_reconciliation_id`, a.`procurement_revision`, a.`procurement_po_revision_id`, a.`procurement_po_revision_number`, a.`procurement_po_snapshot_json`, a.`procurement_current_po_revision_id`, a.`procurement_current_po_revision_number`, a.`processing_method`, a.`processing_reference`, a.`processing_started_at`, a.`processing_business_days`, a.`expected_completion_at`, a.`completion_mode`, a.`payment_confirmation_status`, a.`amount_paid`, a.`paid_at`, a.`payment_reference`, a.`account_remarks`, a.`payment_batch_id`, a.`payment_updated_by`, a.`payment_updated_at`, a.`note`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`advance_payment_request` AS a
UNION ALL
SELECT ar.`id`, ar.`suppliers_name`, ar.`supplier_id`, ar.`site`, ar.`po_number`, ar.`date_received`, ar.`percentage`, ar.`amount`, ar.`discount`, ar.`net_amount`, ar.`vat`, ar.`vat_rate`, ar.`vat_status`, ar.`wht_status`, ar.`wht_rate`, ar.`wht`, ar.`wht_override_status`, ar.`wht_override_rate`, ar.`wht_override_amount`, ar.`wht_override_reason`, ar.`wht_override_by`, ar.`wht_override_at`, ar.`amount_payable`, ar.`other_charges`, ar.`advance_payment`, ar.`payment_status`, ar.`procurement_source`, ar.`procurement_purchase_id`, ar.`procurement_root_po_id`, ar.`procurement_request_type`, ar.`procurement_parent_purchase_id`, ar.`procurement_reconciliation_id`, ar.`procurement_revision`, ar.`procurement_po_revision_id`, ar.`procurement_po_revision_number`, ar.`procurement_po_snapshot_json`, ar.`procurement_current_po_revision_id`, ar.`procurement_current_po_revision_number`, ar.`processing_method`, ar.`processing_reference`, ar.`processing_started_at`, ar.`processing_business_days`, ar.`expected_completion_at`, ar.`completion_mode`, ar.`payment_confirmation_status`, ar.`amount_paid`, ar.`paid_at`, ar.`payment_reference`, ar.`account_remarks`, ar.`payment_batch_id`, ar.`payment_updated_by`, ar.`payment_updated_at`, ar.`note`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`advance_payment_request` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`advance_payment_request` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`advance_payment_schedule_tab` AS
SELECT a.`id`, a.`userId`, a.`payment_amount`, a.`payment_date`, a.`remark`, a.`suppliers_name`, a.`suppliers_id`, a.`bank_name`, a.`account_name`, a.`account_number`, a.`sort_code`, a.`percentages`, a.`po_numbers`, a.`batch`, a.`created_at`
FROM `lambert2_acctlab_db`.`advance_payment_schedule_tab` AS a
UNION ALL
SELECT ar.`id`, ar.`userId`, ar.`payment_amount`, ar.`payment_date`, ar.`remark`, ar.`suppliers_name`, ar.`suppliers_id`, ar.`bank_name`, ar.`account_name`, ar.`account_number`, ar.`sort_code`, ar.`percentages`, ar.`po_numbers`, ar.`batch`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`advance_payment_schedule_tab` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`advance_payment_schedule_tab` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`auth_login_attempts` AS
SELECT a.`id`, a.`email_hash`, a.`ip_address`, a.`attempted_at`, a.`successful`
FROM `lambert2_acctlab_db`.`auth_login_attempts` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`auth_refresh_tokens` AS
SELECT a.`id`, a.`user_id`, a.`token_hash`, a.`family_id`, a.`accounting_period`, a.`expires_at`, a.`created_at`, a.`last_used_at`, a.`revoked_at`, a.`replaced_by_hash`, a.`ip_address`, a.`user_agent`
FROM `lambert2_acctlab_db`.`auth_refresh_tokens` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_beneficiary_details_table` AS
SELECT a.`id`, a.`beneficiary_name`, a.`beneficiary_address`, a.`beneficiary_bank`, a.`beneficiary_bank_address`, a.`swift_code`, a.`beneficiary_account_number`, a.`bank_code`, a.`account`, a.`sort_code`, a.`intermediary_bank`, a.`intermediary_bank_swift_code`, a.`intermediary_bank_iban`, a.`domiciliation`, a.`code_guichet`, a.`compte_no`, a.`cle_rib`, a.`created_at`
FROM `lambert2_acctlab_db`.`bank_beneficiary_details_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recons` AS
SELECT a.`id`, a.`recon_number`, a.`company_name`, a.`bank_name`, a.`account_name`, a.`account_number`, a.`currency`, a.`period_from`, a.`period_to`, a.`bank_opening`, a.`bank_closing`, a.`ledger_opening`, a.`ledger_closing`, a.`adjusted_bank_balance`, a.`adjusted_ledger_balance`, a.`unreconciled_difference`, a.`tolerance_days`, a.`tolerance_amount`, a.`status`, a.`bank_file_name`, a.`ledger_file_name`, a.`notes`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`bank_recons` AS a
UNION ALL
SELECT ar.`id`, ar.`recon_number`, ar.`company_name`, ar.`bank_name`, ar.`account_name`, ar.`account_number`, ar.`currency`, ar.`period_from`, ar.`period_to`, ar.`bank_opening`, ar.`bank_closing`, ar.`ledger_opening`, ar.`ledger_closing`, ar.`adjusted_bank_balance`, ar.`adjusted_ledger_balance`, ar.`unreconciled_difference`, ar.`tolerance_days`, ar.`tolerance_amount`, ar.`status`, ar.`bank_file_name`, ar.`ledger_file_name`, ar.`notes`, ar.`created_by`, ar.`updated_by`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`bank_recons` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`bank_recons` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_auto_rules` AS
SELECT a.`id`, a.`rule_name`, a.`source`, a.`match_field`, a.`match_type`, a.`keywords`, a.`direction`, a.`category_name`, a.`recon_classification`, a.`suggested_dr_ledger`, a.`suggested_cr_ledger`, a.`priority`, a.`is_active`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`bank_recon_auto_rules` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_bank_lines` AS
SELECT a.`id`, a.`recon_id`, a.`txn_date`, a.`description`, a.`reference`, a.`amount`, a.`matched_amount`, a.`direction`, a.`running_balance`, a.`match_status`, a.`match_group`, a.`auto_matched`, a.`bank_only_type`, a.`category_name`, a.`recon_classification`, a.`suggested_dr_ledger`, a.`suggested_cr_ledger`, a.`journal_note`, a.`classification_origin`, a.`classification_rule_id`, a.`classification_locked`, a.`line_hash`
FROM `lambert2_acctlab_db`.`bank_recon_bank_lines` AS a
UNION ALL
SELECT ar.`id`, ar.`recon_id`, ar.`txn_date`, ar.`description`, ar.`reference`, ar.`amount`, ar.`matched_amount`, ar.`direction`, ar.`running_balance`, ar.`match_status`, ar.`match_group`, ar.`auto_matched`, ar.`bank_only_type`, ar.`category_name`, ar.`recon_classification`, ar.`suggested_dr_ledger`, ar.`suggested_cr_ledger`, ar.`journal_note`, ar.`classification_origin`, ar.`classification_rule_id`, ar.`classification_locked`, ar.`line_hash`
FROM `lambert2_acctlab_archive`.`bank_recon_bank_lines` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`bank_recon_bank_lines` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_difference_explanations` AS
SELECT a.`id`, a.`recon_id`, a.`difference_amount`, a.`status`, a.`explanation_json`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`bank_recon_difference_explanations` AS a
UNION ALL
SELECT ar.`id`, ar.`recon_id`, ar.`difference_amount`, ar.`status`, ar.`explanation_json`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`bank_recon_difference_explanations` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`bank_recon_difference_explanations` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_learned_patterns` AS
SELECT a.`id`, a.`source`, a.`bank_name`, a.`account_number`, a.`currency`, a.`direction`, a.`pattern_key`, a.`pattern_text`, a.`category_name`, a.`recon_classification`, a.`suggested_dr_ledger`, a.`suggested_cr_ledger`, a.`confidence`, a.`use_count`, a.`accepted_count`, a.`last_recon_id`, a.`last_line_id`, a.`last_seen_at`, a.`is_active`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`bank_recon_learned_patterns` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_ledger_lines` AS
SELECT a.`id`, a.`recon_id`, a.`txn_date`, a.`description`, a.`reference`, a.`ledger_name`, a.`amount`, a.`matched_amount`, a.`direction`, a.`running_balance`, a.`match_status`, a.`match_group`, a.`auto_matched`, a.`category_name`, a.`recon_classification`, a.`suggested_dr_ledger`, a.`suggested_cr_ledger`, a.`journal_note`, a.`classification_origin`, a.`classification_rule_id`, a.`classification_locked`, a.`line_hash`
FROM `lambert2_acctlab_db`.`bank_recon_ledger_lines` AS a
UNION ALL
SELECT ar.`id`, ar.`recon_id`, ar.`txn_date`, ar.`description`, ar.`reference`, ar.`ledger_name`, ar.`amount`, ar.`matched_amount`, ar.`direction`, ar.`running_balance`, ar.`match_status`, ar.`match_group`, ar.`auto_matched`, ar.`category_name`, ar.`recon_classification`, ar.`suggested_dr_ledger`, ar.`suggested_cr_ledger`, ar.`journal_note`, ar.`classification_origin`, ar.`classification_rule_id`, ar.`classification_locked`, ar.`line_hash`
FROM `lambert2_acctlab_archive`.`bank_recon_ledger_lines` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`bank_recon_ledger_lines` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_matches` AS
SELECT a.`id`, a.`recon_id`, a.`match_group`, a.`bank_line_id`, a.`ledger_line_id`, a.`bank_allocated_amount`, a.`ledger_allocated_amount`, a.`is_partial`, a.`match_note`, a.`match_type`, a.`confidence`, a.`amount_difference`, a.`day_difference`, a.`matched_by`, a.`matched_at`
FROM `lambert2_acctlab_db`.`bank_recon_matches` AS a
UNION ALL
SELECT ar.`id`, ar.`recon_id`, ar.`match_group`, ar.`bank_line_id`, ar.`ledger_line_id`, ar.`bank_allocated_amount`, ar.`ledger_allocated_amount`, ar.`is_partial`, ar.`match_note`, ar.`match_type`, ar.`confidence`, ar.`amount_difference`, ar.`day_difference`, ar.`matched_by`, ar.`matched_at`
FROM `lambert2_acctlab_archive`.`bank_recon_matches` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`bank_recon_matches` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_recon_upload_profiles` AS
SELECT a.`id`, a.`source`, a.`bank_name`, a.`account_number`, a.`currency`, a.`file_extension`, a.`header_signature`, a.`original_headers`, a.`normalized_headers`, a.`column_mapping`, a.`first_seen_recon_id`, a.`last_seen_recon_id`, a.`use_count`, a.`last_file_name`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`bank_recon_upload_profiles` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`bank_sortcode_tab` AS
SELECT a.`id`, a.`bank_name`, a.`sort_code`, a.`code_name`
FROM `lambert2_acctlab_db`.`bank_sortcode_tab` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_accounts` AS
SELECT a.`id`, a.`account_code`, a.`account_name`, a.`currency`, a.`custodian_user_id`, a.`allow_negative_balance`, a.`status`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_accounts` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_account_users` AS
SELECT a.`id`, a.`account_id`, a.`user_id`, a.`access_level`, a.`is_active`, a.`assigned_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_account_users` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_categories` AS
SELECT a.`id`, a.`category_code`, a.`category_name`, a.`description`, a.`is_active`, a.`sort_order`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_categories` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_daily_closures` AS
SELECT a.`id`, a.`account_id`, a.`closure_date`, a.`opening_balance`, a.`cash_received`, a.`cash_returned`, a.`cash_disbursed`, a.`reimbursements_paid`, a.`system_closing_balance`, a.`physical_cash_counted`, a.`difference_amount`, a.`difference_note`, a.`status`, a.`closed_by_user_id`, a.`closed_by_email`, a.`closed_at`, a.`reopened_by_user_id`, a.`reopened_by_email`, a.`reopened_at`, a.`reopen_reason`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_daily_closures` AS a
UNION ALL
SELECT ar.`id`, ar.`account_id`, ar.`closure_date`, ar.`opening_balance`, ar.`cash_received`, ar.`cash_returned`, ar.`cash_disbursed`, ar.`reimbursements_paid`, ar.`system_closing_balance`, ar.`physical_cash_counted`, ar.`difference_amount`, ar.`difference_note`, ar.`status`, ar.`closed_by_user_id`, ar.`closed_by_email`, ar.`closed_at`, ar.`reopened_by_user_id`, ar.`reopened_by_email`, ar.`reopened_at`, ar.`reopen_reason`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`cash_daily_closures` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_daily_closures` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_ious` AS
SELECT a.`id`, a.`account_id`, a.`source_transaction_id`, a.`iou_reference`, a.`recipient_name`, a.`amount_advanced`, a.`actual_amount_spent`, a.`amount_returned`, a.`reimbursement_paid`, a.`outstanding_amount`, a.`reason`, a.`description`, a.`expected_retirement_date`, a.`status`, a.`receipt_status`, a.`accounting_year`, a.`created_by_user_id`, a.`created_by_email`, a.`closed_by_user_id`, a.`closed_by_email`, a.`closed_at`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_ious` AS a
UNION ALL
SELECT ar.`id`, ar.`account_id`, ar.`source_transaction_id`, ar.`iou_reference`, ar.`recipient_name`, ar.`amount_advanced`, ar.`actual_amount_spent`, ar.`amount_returned`, ar.`reimbursement_paid`, ar.`outstanding_amount`, ar.`reason`, ar.`description`, ar.`expected_retirement_date`, ar.`status`, ar.`receipt_status`, ar.`accounting_year`, ar.`created_by_user_id`, ar.`created_by_email`, ar.`closed_by_user_id`, ar.`closed_by_email`, ar.`closed_at`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`cash_ious` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_ious` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_iou_actions` AS
SELECT a.`id`, a.`iou_id`, a.`action_reference`, a.`action_type`, a.`action_date`, a.`amount_spent`, a.`cash_returned`, a.`reimbursement_paid`, a.`linked_transaction_id`, a.`is_final_submission`, a.`note`, a.`idempotency_key`, a.`created_by_user_id`, a.`created_by_email`, a.`created_at`
FROM `lambert2_acctlab_db`.`cash_iou_actions` AS a
UNION ALL
SELECT ar.`id`, ar.`iou_id`, ar.`action_reference`, ar.`action_type`, ar.`action_date`, ar.`amount_spent`, ar.`cash_returned`, ar.`reimbursement_paid`, ar.`linked_transaction_id`, ar.`is_final_submission`, ar.`note`, ar.`idempotency_key`, ar.`created_by_user_id`, ar.`created_by_email`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`cash_iou_actions` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_iou_actions` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_iou_retirements` AS
SELECT a.`id`, a.`iou_id`, a.`retirement_date`, a.`actual_amount_spent`, a.`cash_returned`, a.`reimbursement_paid`, a.`note`, a.`created_by_user_id`, a.`created_by_email`, a.`created_at`
FROM `lambert2_acctlab_db`.`cash_iou_retirements` AS a
UNION ALL
SELECT ar.`id`, ar.`iou_id`, ar.`retirement_date`, ar.`actual_amount_spent`, ar.`cash_returned`, ar.`reimbursement_paid`, ar.`note`, ar.`created_by_user_id`, ar.`created_by_email`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`cash_iou_retirements` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_iou_retirements` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_mutilated_cash` AS
SELECT a.`id`, a.`account_id`, a.`source_transaction_id`, a.`source_receipt_transaction_id`, a.`linked_disbursement_transaction_id`, a.`set_aside_transaction_id`, a.`replacement_transaction_id`, a.`resolution_transaction_id`, a.`amount`, a.`discovered_date`, a.`note`, a.`status`, a.`resolution_type`, a.`return_date`, a.`bank_reference`, a.`resolution_note`, a.`accounting_year`, a.`discovered_by_user_id`, a.`discovered_by_email`, a.`resolved_by_user_id`, a.`resolved_by_email`, a.`resolved_at`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_mutilated_cash` AS a
UNION ALL
SELECT ar.`id`, ar.`account_id`, ar.`source_transaction_id`, ar.`source_receipt_transaction_id`, ar.`linked_disbursement_transaction_id`, ar.`set_aside_transaction_id`, ar.`replacement_transaction_id`, ar.`resolution_transaction_id`, ar.`amount`, ar.`discovered_date`, ar.`note`, ar.`status`, ar.`resolution_type`, ar.`return_date`, ar.`bank_reference`, ar.`resolution_note`, ar.`accounting_year`, ar.`discovered_by_user_id`, ar.`discovered_by_email`, ar.`resolved_by_user_id`, ar.`resolved_by_email`, ar.`resolved_at`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`cash_mutilated_cash` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_mutilated_cash` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_receipts` AS
SELECT a.`id`, a.`transaction_id`, a.`iou_id`, a.`document_type`, a.`original_filename`, a.`stored_filename`, a.`storage_path`, a.`mime_type`, a.`file_size`, a.`status`, a.`uploaded_by_user_id`, a.`uploaded_by_email`, a.`created_at`, a.`deleted_at`, a.`deleted_by_email`
FROM `lambert2_acctlab_db`.`cash_receipts` AS a
UNION ALL
SELECT ar.`id`, ar.`transaction_id`, ar.`iou_id`, ar.`document_type`, ar.`original_filename`, ar.`stored_filename`, ar.`storage_path`, ar.`mime_type`, ar.`file_size`, ar.`status`, ar.`uploaded_by_user_id`, ar.`uploaded_by_email`, ar.`created_at`, ar.`deleted_at`, ar.`deleted_by_email`
FROM `lambert2_acctlab_archive`.`cash_receipts` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_receipts` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_settings` AS
SELECT a.`id`, a.`account_id`, a.`low_balance_threshold`, a.`default_iou_due_days`, a.`require_receipt_for_direct_expense`, a.`allow_backdated_entries`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_settings` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_transactions` AS
SELECT a.`id`, a.`account_id`, a.`transaction_reference`, a.`transaction_date`, a.`transaction_type`, a.`direction`, a.`person_name`, a.`amount`, a.`reason`, a.`description`, a.`category_id`, a.`external_reference`, a.`disbursement_type`, a.`receipt_status`, a.`status`, a.`reversal_of_transaction_id`, a.`idempotency_key`, a.`accounting_year`, a.`created_by_user_id`, a.`created_by_email`, a.`metadata`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`cash_transactions` AS a
WHERE (a.`idempotency_key` IS NULL OR a.`idempotency_key` NOT LIKE 'archive-cutover:%')
UNION ALL
SELECT ar.`id`, ar.`account_id`, ar.`transaction_reference`, ar.`transaction_date`, ar.`transaction_type`, ar.`direction`, ar.`person_name`, ar.`amount`, ar.`reason`, ar.`description`, ar.`category_id`, ar.`external_reference`, ar.`disbursement_type`, ar.`receipt_status`, ar.`status`, ar.`reversal_of_transaction_id`, ar.`idempotency_key`, ar.`accounting_year`, ar.`created_by_user_id`, ar.`created_by_email`, ar.`metadata`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`cash_transactions` AS ar
WHERE (ar.`idempotency_key` IS NULL OR ar.`idempotency_key` NOT LIKE 'archive-cutover:%')
  AND NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_transactions` AS a2
    WHERE (a2.`idempotency_key` IS NULL OR a2.`idempotency_key` NOT LIKE 'archive-cutover:%') AND a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`cash_transaction_edits` AS
SELECT a.`id`, a.`account_id`, a.`transaction_id`, a.`correction_reason`, a.`old_values`, a.`new_values`, a.`edited_by_user_id`, a.`edited_by_email`, a.`edited_at`
FROM `lambert2_acctlab_db`.`cash_transaction_edits` AS a
UNION ALL
SELECT ar.`id`, ar.`account_id`, ar.`transaction_id`, ar.`correction_reason`, ar.`old_values`, ar.`new_values`, ar.`edited_by_user_id`, ar.`edited_by_email`, ar.`edited_at`
FROM `lambert2_acctlab_archive`.`cash_transaction_edits` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`cash_transaction_edits` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`compass_fund_request_table` AS
SELECT a.`id`, a.`suppliers_name`, a.`supplier_id`, a.`invoice_number`, a.`invoice_date`, a.`date_received`, a.`project_code`, a.`description`, a.`classification`, a.`percentage`, a.`net_value`, a.`vat_policy`, a.`vat`, a.`wht`, a.`discount`, a.`other_charges`, a.`amount`, a.`note`, a.`payment_status`, a.`created_at`
FROM `lambert2_acctlab_db`.`compass_fund_request_table` AS a
UNION ALL
SELECT ar.`id`, ar.`suppliers_name`, ar.`supplier_id`, ar.`invoice_number`, ar.`invoice_date`, ar.`date_received`, ar.`project_code`, ar.`description`, ar.`classification`, ar.`percentage`, ar.`net_value`, ar.`vat_policy`, ar.`vat`, ar.`wht`, ar.`discount`, ar.`other_charges`, ar.`amount`, ar.`note`, ar.`payment_status`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`compass_fund_request_table` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`compass_fund_request_table` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`expense_fund_request_table` AS
SELECT a.`id`, a.`suppliers_name`, a.`supplier_id`, a.`invoice_number`, a.`invoice_date`, a.`date_received`, a.`project_code`, a.`description`, a.`classification`, a.`percentage`, a.`net_value`, a.`vat_policy`, a.`vat`, a.`wht`, a.`discount`, a.`other_charges`, a.`amount`, a.`note`, a.`payment_status`, a.`created_at`
FROM `lambert2_acctlab_db`.`expense_fund_request_table` AS a
UNION ALL
SELECT ar.`id`, ar.`suppliers_name`, ar.`supplier_id`, ar.`invoice_number`, ar.`invoice_date`, ar.`date_received`, ar.`project_code`, ar.`description`, ar.`classification`, ar.`percentage`, ar.`net_value`, ar.`vat_policy`, ar.`vat`, ar.`wht`, ar.`discount`, ar.`other_charges`, ar.`amount`, ar.`note`, ar.`payment_status`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`expense_fund_request_table` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`expense_fund_request_table` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`expense_ledger` AS
SELECT a.`id`, a.`supplier_name`, a.`summary`, a.`supplier_number`, a.`created_at`
FROM `lambert2_acctlab_db`.`expense_ledger` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`expense_user_table` AS
SELECT a.`id`, a.`fname`, a.`lname`, a.`email`, a.`password`, a.`integrity`, a.`project`, a.`created_by`, a.`updated_by`
FROM `lambert2_acctlab_db`.`expense_user_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`fx_banks_table` AS
SELECT a.`id`, a.`bank_name`, a.`account_number`, a.`currency`, a.`bank_code`, a.`letter_header`, a.`salutation`, a.`attention`, a.`letter_title`, a.`letter_format`, a.`created_at`
FROM `lambert2_acctlab_db`.`fx_banks_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`fx_fund_request_table` AS
SELECT a.`id`, a.`request_type`, a.`suppliers_name`, a.`suppliers_id`, a.`invoice_number`, a.`purchase_number`, a.`po_number`, a.`invoice_date`, a.`purchase_date`, a.`date_received`, a.`project_code`, a.`currency`, a.`sub_total`, a.`discount`, a.`other_charges`, a.`vat_rate`, a.`vat_amount`, a.`wht_rate`, a.`wht_amount`, a.`percentage`, a.`payable_amount`, a.`payment_currency`, a.`payment_amount`, a.`exchange_rate`, a.`payment_status`, a.`fx_instruction_letter_id`, a.`processed_by`, a.`processed_at`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`fx_fund_request_table` AS a
UNION ALL
SELECT ar.`id`, ar.`request_type`, ar.`suppliers_name`, ar.`suppliers_id`, ar.`invoice_number`, ar.`purchase_number`, ar.`po_number`, ar.`invoice_date`, ar.`purchase_date`, ar.`date_received`, ar.`project_code`, ar.`currency`, ar.`sub_total`, ar.`discount`, ar.`other_charges`, ar.`vat_rate`, ar.`vat_amount`, ar.`wht_rate`, ar.`wht_amount`, ar.`percentage`, ar.`payable_amount`, ar.`payment_currency`, ar.`payment_amount`, ar.`exchange_rate`, ar.`payment_status`, ar.`fx_instruction_letter_id`, ar.`processed_by`, ar.`processed_at`, ar.`created_by`, ar.`updated_by`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`fx_fund_request_table` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`fx_fund_request_table` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`fx_instruction_letter_table` AS
SELECT a.`id`, a.`beneficiary_name`, a.`beneficiary_address`, a.`beneficiary_bank`, a.`beneficiary_bank_address`, a.`swift_code`, a.`beneficiary_account_number`, a.`reference`, a.`payment_purpose`, a.`amount_figure`, a.`amount_words`, a.`payment_account_number`, a.`payment_bank`, a.`currency`, a.`currency_table`, a.`payment_date`, a.`bank_code`, a.`account`, a.`sort_code`, a.`intermediary_bank`, a.`intermediary_bank_swift_code`, a.`intermediary_bank_iban`, a.`payment_status`, a.`created_at`, a.`updated_at`, a.`domiciliation`, a.`code_guichet`, a.`compte_no`, a.`cle_rib`
FROM `lambert2_acctlab_db`.`fx_instruction_letter_table` AS a
UNION ALL
SELECT ar.`id`, ar.`beneficiary_name`, ar.`beneficiary_address`, ar.`beneficiary_bank`, ar.`beneficiary_bank_address`, ar.`swift_code`, ar.`beneficiary_account_number`, ar.`reference`, ar.`payment_purpose`, ar.`amount_figure`, ar.`amount_words`, ar.`payment_account_number`, ar.`payment_bank`, ar.`currency`, ar.`currency_table`, ar.`payment_date`, ar.`bank_code`, ar.`account`, ar.`sort_code`, ar.`intermediary_bank`, ar.`intermediary_bank_swift_code`, ar.`intermediary_bank_iban`, ar.`payment_status`, ar.`created_at`, ar.`updated_at`, ar.`domiciliation`, ar.`code_guichet`, ar.`compte_no`, ar.`cle_rib`
FROM `lambert2_acctlab_archive`.`fx_instruction_letter_table` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`fx_instruction_letter_table` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`instruction_letter` AS
SELECT a.`id`, a.`letter_heading`, a.`instruction_type`, a.`payment_to`, a.`payment_bank_name`, a.`tax_beneficiary`, a.`tax_type`, a.`tax_tin`, a.`tax_date_from`, a.`tax_date_to`, a.`payment_amount`, a.`words`, a.`letter_body`, a.`payment_account_number`, a.`bank_code`, a.`payment_date`, a.`created_at`
FROM `lambert2_acctlab_db`.`instruction_letter` AS a
UNION ALL
SELECT ar.`id`, ar.`letter_heading`, ar.`instruction_type`, ar.`payment_to`, ar.`payment_bank_name`, ar.`tax_beneficiary`, ar.`tax_type`, ar.`tax_tin`, ar.`tax_date_from`, ar.`tax_date_to`, ar.`payment_amount`, ar.`words`, ar.`letter_body`, ar.`payment_account_number`, ar.`bank_code`, ar.`payment_date`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`instruction_letter` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`instruction_letter` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`local_banks` AS
SELECT a.`id`, a.`bank_name`, a.`name`, a.`account_number`, a.`bank_code`, a.`currency`, a.`letter_header`, a.`attention`, a.`salutation`, a.`letter_title`, a.`created_at`
FROM `lambert2_acctlab_db`.`local_banks` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`local_purchase_vat_wht_reconciliation_log` AS
SELECT a.`id`, a.`run_id`, a.`source_type`, a.`source_id`, a.`account_request_id`, a.`action`, a.`result_status`, a.`reason`, a.`before_json`, a.`after_json`, a.`created_at`
FROM `lambert2_acctlab_db`.`local_purchase_vat_wht_reconciliation_log` AS a
UNION ALL
SELECT ar.`id`, ar.`run_id`, ar.`source_type`, ar.`source_id`, ar.`account_request_id`, ar.`action`, ar.`result_status`, ar.`reason`, ar.`before_json`, ar.`after_json`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`local_purchase_vat_wht_reconciliation_log` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`local_purchase_vat_wht_reconciliation_log` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`local_transfer` AS
SELECT a.`id`, a.`beneficiary_name`, a.`account_number`, a.`ben_bank_name`, a.`payment_account_number`, a.`payment_category`, a.`batch`, a.`amount`, a.`created_at`, a.`created_by`, a.`date`
FROM `lambert2_acctlab_db`.`local_transfer` AS a
UNION ALL
SELECT ar.`id`, ar.`beneficiary_name`, ar.`account_number`, ar.`ben_bank_name`, ar.`payment_account_number`, ar.`payment_category`, ar.`batch`, ar.`amount`, ar.`created_at`, ar.`created_by`, ar.`date`
FROM `lambert2_acctlab_archive`.`local_transfer` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`local_transfer` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`location_table` AS
SELECT a.`id`, a.`location`, a.`code`
FROM `lambert2_acctlab_db`.`location_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`logs` AS
SELECT a.`id`, a.`userId`, a.`action`, a.`created_by`, a.`created_at`
FROM `lambert2_acctlab_db`.`logs` AS a
UNION ALL
SELECT ar.`id`, ar.`userId`, ar.`action`, ar.`created_by`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`logs` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`logs` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`notifications` AS
SELECT a.`id`, a.`inbox_app`, a.`inbox_notification_id`, a.`recipient_user_id`, a.`source_app`, a.`notification_type`, a.`category`, a.`severity`, a.`action_key`, a.`title`, a.`message`, a.`actor_user_id`, a.`actor_email`, a.`entity_type`, a.`entity_id`, a.`route`, a.`payload_json`, a.`dedupe_key`, a.`read_at`, a.`created_at`
FROM `lambert2_acctlab_db`.`notifications` AS a
UNION ALL
SELECT ar.`id`, ar.`inbox_app`, ar.`inbox_notification_id`, ar.`recipient_user_id`, ar.`source_app`, ar.`notification_type`, ar.`category`, ar.`severity`, ar.`action_key`, ar.`title`, ar.`message`, ar.`actor_user_id`, ar.`actor_email`, ar.`entity_type`, ar.`entity_id`, ar.`route`, ar.`payload_json`, ar.`dedupe_key`, ar.`read_at`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`notifications` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`notifications` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`other_payment_schedule` AS
SELECT a.`id`, a.`userId`, a.`payment_amount`, a.`payment_date`, a.`remark`, a.`suppliers_name`, a.`suppliers_id`, a.`bank_name`, a.`account_name`, a.`account_number`, a.`sort_code`, a.`invoice_numbers`, a.`batch`, a.`created_at`
FROM `lambert2_acctlab_db`.`other_payment_schedule` AS a
UNION ALL
SELECT ar.`id`, ar.`userId`, ar.`payment_amount`, ar.`payment_date`, ar.`remark`, ar.`suppliers_name`, ar.`suppliers_id`, ar.`bank_name`, ar.`account_name`, ar.`account_number`, ar.`sort_code`, ar.`invoice_numbers`, ar.`batch`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`other_payment_schedule` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`other_payment_schedule` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`payment_schedule_tab` AS
SELECT a.`id`, a.`userId`, a.`payment_amount`, a.`payment_date`, a.`remark`, a.`suppliers_name`, a.`supplier_id`, a.`bank_name`, a.`account_name`, a.`account_number`, a.`sort_code`, a.`invoice_numbers`, a.`po_numbers`, a.`batch`, a.`created_at`
FROM `lambert2_acctlab_db`.`payment_schedule_tab` AS a
UNION ALL
SELECT ar.`id`, ar.`userId`, ar.`payment_amount`, ar.`payment_date`, ar.`remark`, ar.`suppliers_name`, ar.`supplier_id`, ar.`bank_name`, ar.`account_name`, ar.`account_number`, ar.`sort_code`, ar.`invoice_numbers`, ar.`po_numbers`, ar.`batch`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`payment_schedule_tab` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`payment_schedule_tab` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_auth_login_attempts` AS
SELECT a.`id`, a.`email_hash`, a.`ip_address`, a.`attempted_at`, a.`successful`
FROM `lambert2_acctlab_db`.`procurement_auth_login_attempts` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_auth_refresh_tokens` AS
SELECT a.`id`, a.`user_id`, a.`token_hash`, a.`family_id`, a.`expires_at`, a.`created_at`, a.`last_used_at`, a.`revoked_at`, a.`replaced_by_hash`, a.`ip_address`, a.`user_agent`
FROM `lambert2_acctlab_db`.`procurement_auth_refresh_tokens` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_local_advance_pos` AS
SELECT a.`id`, a.`po_number`, a.`po_number_normalized`, a.`project_id`, a.`project_code`, a.`project_name`, a.`supplier_id`, a.`supplier_name`, a.`supplier_ledger`, a.`wht_status`, a.`wht_rate`, a.`wht_amount`, a.`purchase_value`, a.`po_subtotal`, a.`po_discount`, a.`po_other_charges`, a.`po_vat_status`, a.`po_vat_rate`, a.`po_vat_amount`, a.`po_value`, a.`advance_base_amount`, a.`po_status`, a.`created_by`, a.`created_at`, a.`updated_by`, a.`updated_at`, a.`version`, a.`current_revision_id`, a.`current_revision_number`, a.`amendment_status`
FROM `lambert2_acctlab_db`.`procurement_local_advance_pos` AS a
UNION ALL
SELECT ar.`id`, ar.`po_number`, ar.`po_number_normalized`, ar.`project_id`, ar.`project_code`, ar.`project_name`, ar.`supplier_id`, ar.`supplier_name`, ar.`supplier_ledger`, ar.`wht_status`, ar.`wht_rate`, ar.`wht_amount`, ar.`purchase_value`, ar.`po_subtotal`, ar.`po_discount`, ar.`po_other_charges`, ar.`po_vat_status`, ar.`po_vat_rate`, ar.`po_vat_amount`, ar.`po_value`, ar.`advance_base_amount`, ar.`po_status`, ar.`created_by`, ar.`created_at`, ar.`updated_by`, ar.`updated_at`, ar.`version`, ar.`current_revision_id`, ar.`current_revision_number`, ar.`amendment_status`
FROM `lambert2_acctlab_archive`.`procurement_local_advance_pos` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`procurement_local_advance_pos` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_local_advance_po_revisions` AS
SELECT a.`id`, a.`po_id`, a.`revision_number`, a.`revision_reference`, a.`previous_revision_id`, a.`revision_status`, a.`amendment_type`, a.`amendment_reason`, a.`po_number`, a.`po_number_normalized`, a.`project_id`, a.`project_code`, a.`project_name`, a.`supplier_id`, a.`supplier_name`, a.`supplier_ledger`, a.`wht_status`, a.`wht_rate`, a.`wht_amount`, a.`purchase_value`, a.`po_subtotal`, a.`po_discount`, a.`po_other_charges`, a.`po_vat_status`, a.`po_vat_rate`, a.`po_vat_amount`, a.`po_value`, a.`advance_base_amount`, a.`po_status`, a.`commercial_snapshot_json`, a.`change_summary_json`, a.`snapshot_hash`, a.`is_locked`, a.`locked_at`, a.`locked_reason`, a.`created_by`, a.`created_at`, a.`submitted_by`, a.`submitted_at`, a.`approved_by`, a.`approved_at`, a.`rejected_by`, a.`rejected_at`, a.`rejection_reason`, a.`cancelled_by`, a.`cancelled_at`, a.`cancellation_reason`, a.`superseded_at`, a.`updated_by`, a.`updated_at`, a.`version`
FROM `lambert2_acctlab_db`.`procurement_local_advance_po_revisions` AS a
UNION ALL
SELECT ar.`id`, ar.`po_id`, ar.`revision_number`, ar.`revision_reference`, ar.`previous_revision_id`, ar.`revision_status`, ar.`amendment_type`, ar.`amendment_reason`, ar.`po_number`, ar.`po_number_normalized`, ar.`project_id`, ar.`project_code`, ar.`project_name`, ar.`supplier_id`, ar.`supplier_name`, ar.`supplier_ledger`, ar.`wht_status`, ar.`wht_rate`, ar.`wht_amount`, ar.`purchase_value`, ar.`po_subtotal`, ar.`po_discount`, ar.`po_other_charges`, ar.`po_vat_status`, ar.`po_vat_rate`, ar.`po_vat_amount`, ar.`po_value`, ar.`advance_base_amount`, ar.`po_status`, ar.`commercial_snapshot_json`, ar.`change_summary_json`, ar.`snapshot_hash`, ar.`is_locked`, ar.`locked_at`, ar.`locked_reason`, ar.`created_by`, ar.`created_at`, ar.`submitted_by`, ar.`submitted_at`, ar.`approved_by`, ar.`approved_at`, ar.`rejected_by`, ar.`rejected_at`, ar.`rejection_reason`, ar.`cancelled_by`, ar.`cancelled_at`, ar.`cancellation_reason`, ar.`superseded_at`, ar.`updated_by`, ar.`updated_at`, ar.`version`
FROM `lambert2_acctlab_archive`.`procurement_local_advance_po_revisions` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`procurement_local_advance_po_revisions` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_local_advance_po_revision_reconciliations` AS
SELECT a.`id`, a.`po_id`, a.`revision_id`, a.`previous_revision_id`, a.`previous_po_value`, a.`revised_po_value`, a.`po_value_delta`, a.`previous_advance_base_amount`, a.`revised_advance_base_amount`, a.`advance_base_delta`, a.`allocated_percentage_at_revision`, a.`previous_committed_amount`, a.`revised_committed_amount`, a.`total_paid_at_revision`, a.`total_processing_at_revision`, a.`total_pending_at_revision`, a.`pending_reallocated_amount`, a.`supplementary_amount`, a.`supplementary_amount_paid`, a.`recovery_amount`, a.`reconciliation_amount`, a.`reconciliation_direction`, a.`reconciliation_status`, a.`resolution_type`, a.`supplementary_purchase_id`, a.`supplementary_advance_payment_request_id`, a.`account_reconciliation_id`, a.`account_sync_status`, a.`account_synced_by`, a.`account_synced_at`, a.`account_sync_error`, a.`recovery_reference`, a.`resolution_notes`, a.`allocation_snapshot_json`, a.`created_by`, a.`created_at`, a.`resolved_by`, a.`resolved_at`, a.`updated_by`, a.`updated_at`
FROM `lambert2_acctlab_db`.`procurement_local_advance_po_revision_reconciliations` AS a
UNION ALL
SELECT ar.`id`, ar.`po_id`, ar.`revision_id`, ar.`previous_revision_id`, ar.`previous_po_value`, ar.`revised_po_value`, ar.`po_value_delta`, ar.`previous_advance_base_amount`, ar.`revised_advance_base_amount`, ar.`advance_base_delta`, ar.`allocated_percentage_at_revision`, ar.`previous_committed_amount`, ar.`revised_committed_amount`, ar.`total_paid_at_revision`, ar.`total_processing_at_revision`, ar.`total_pending_at_revision`, ar.`pending_reallocated_amount`, ar.`supplementary_amount`, ar.`supplementary_amount_paid`, ar.`recovery_amount`, ar.`reconciliation_amount`, ar.`reconciliation_direction`, ar.`reconciliation_status`, ar.`resolution_type`, ar.`supplementary_purchase_id`, ar.`supplementary_advance_payment_request_id`, ar.`account_reconciliation_id`, ar.`account_sync_status`, ar.`account_synced_by`, ar.`account_synced_at`, ar.`account_sync_error`, ar.`recovery_reference`, ar.`resolution_notes`, ar.`allocation_snapshot_json`, ar.`created_by`, ar.`created_at`, ar.`resolved_by`, ar.`resolved_at`, ar.`updated_by`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`procurement_local_advance_po_revision_reconciliations` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`procurement_local_advance_po_revision_reconciliations` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_permissions` AS
SELECT a.`code`, a.`name`, a.`description`, a.`category`, a.`sort_order`, a.`is_active`, a.`is_delegable`, a.`is_role_assignable`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`procurement_permissions` AS a;

ALTER TABLE `lambert2_acctlab_archive`.`procurement_requests` ADD COLUMN `currency` char(3) DEFAULT NULL AFTER `purchase_number`;

ALTER TABLE `lambert2_acctlab_archive`.`procurement_requests` ADD KEY `idx_procurement_request_currency` (`request_type`,`currency`,`created_at`);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_requests` AS
SELECT a.`id`, a.`request_type`, a.`request_number`, a.`legacy_source_table`, a.`legacy_source_id`, a.`po_number`, a.`purchase_number`, a.`currency`, a.`project_id`, a.`project_code`, a.`project_name`, a.`supplier_id`, a.`supplier_name`, a.`supplier_ledger`, a.`wht_status`, a.`wht_rate`, a.`wht_amount`, a.`account_wht_override_status`, a.`account_wht_override_rate`, a.`account_wht_override_amount`, a.`account_payable_amount`, a.`account_wht_adjustment_reason`, a.`account_wht_adjusted_by`, a.`account_wht_adjusted_at`, a.`remark`, a.`transaction_date`, a.`date_received`, a.`po_status`, a.`payment_status`, a.`payment_status_source`, a.`payment_status_updated_at`, a.`approval_status`, a.`handoff_status`, a.`handoff_revision`, a.`account_request_type`, a.`account_request_id`, a.`previous_account_request_id`, a.`approved_by`, a.`approved_at`, a.`approval_reversed_by`, a.`approval_reversed_at`, a.`retrieved_by`, a.`retrieved_at`, a.`retrieval_reason`, a.`retrieval_source`, a.`account_amount_paid`, a.`account_processing_method`, a.`account_processing_reference`, a.`account_processing_started_at`, a.`account_expected_completion_at`, a.`account_completion_mode`, a.`account_confirmation_status`, a.`account_payment_reference`, a.`account_paid_at`, a.`account_payment_remarks`, a.`account_payment_batch_id`, a.`created_by`, a.`created_at`, a.`updated_by`, a.`updated_at`, a.`deleted_by`, a.`deleted_at`, a.`version`, a.`purchase_number_normalized`, a.`grn_ref`, a.`material_type`, a.`invoice_number`, a.`invoice_date`, a.`purchase_date`, a.`purchase_subtotal`, a.`purchase_discount`, a.`purchase_other_charges`, a.`purchase_vat_status`, a.`purchase_vat_rate`, a.`purchase_vat_amount`, a.`purchase_value`, a.`po_subtotal`, a.`po_discount`, a.`po_other_charges`, a.`po_vat_status`, a.`po_vat_rate`, a.`po_vat_amount`, a.`po_value`, a.`po_id`, a.`po_revision_id`, a.`po_revision_number`, a.`request_variant`, a.`parent_request_id`, a.`legacy_parent_purchase_id`, a.`revision_reconciliation_id`, a.`po_snapshot_json`, a.`po_percentage`, a.`expected_payment`, a.`account_expected_payment`
FROM `lambert2_acctlab_db`.`procurement_requests` AS a
UNION ALL
SELECT ar.`id`, ar.`request_type`, ar.`request_number`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`po_number`, ar.`purchase_number`, ar.`currency`, ar.`project_id`, ar.`project_code`, ar.`project_name`, ar.`supplier_id`, ar.`supplier_name`, ar.`supplier_ledger`, ar.`wht_status`, ar.`wht_rate`, ar.`wht_amount`, ar.`account_wht_override_status`, ar.`account_wht_override_rate`, ar.`account_wht_override_amount`, ar.`account_payable_amount`, ar.`account_wht_adjustment_reason`, ar.`account_wht_adjusted_by`, ar.`account_wht_adjusted_at`, ar.`remark`, ar.`transaction_date`, ar.`date_received`, ar.`po_status`, ar.`payment_status`, ar.`payment_status_source`, ar.`payment_status_updated_at`, ar.`approval_status`, ar.`handoff_status`, ar.`handoff_revision`, ar.`account_request_type`, ar.`account_request_id`, ar.`previous_account_request_id`, ar.`approved_by`, ar.`approved_at`, ar.`approval_reversed_by`, ar.`approval_reversed_at`, ar.`retrieved_by`, ar.`retrieved_at`, ar.`retrieval_reason`, ar.`retrieval_source`, ar.`account_amount_paid`, ar.`account_processing_method`, ar.`account_processing_reference`, ar.`account_processing_started_at`, ar.`account_expected_completion_at`, ar.`account_completion_mode`, ar.`account_confirmation_status`, ar.`account_payment_reference`, ar.`account_paid_at`, ar.`account_payment_remarks`, ar.`account_payment_batch_id`, ar.`created_by`, ar.`created_at`, ar.`updated_by`, ar.`updated_at`, ar.`deleted_by`, ar.`deleted_at`, ar.`version`, ar.`purchase_number_normalized`, ar.`grn_ref`, ar.`material_type`, ar.`invoice_number`, ar.`invoice_date`, ar.`purchase_date`, ar.`purchase_subtotal`, ar.`purchase_discount`, ar.`purchase_other_charges`, ar.`purchase_vat_status`, ar.`purchase_vat_rate`, ar.`purchase_vat_amount`, ar.`purchase_value`, ar.`po_subtotal`, ar.`po_discount`, ar.`po_other_charges`, ar.`po_vat_status`, ar.`po_vat_rate`, ar.`po_vat_amount`, ar.`po_value`, ar.`po_id`, ar.`po_revision_id`, ar.`po_revision_number`, ar.`request_variant`, ar.`parent_request_id`, ar.`legacy_parent_purchase_id`, ar.`revision_reconciliation_id`, ar.`po_snapshot_json`, ar.`po_percentage`, ar.`expected_payment`, ar.`account_expected_payment`
FROM `lambert2_acctlab_archive`.`procurement_requests` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`procurement_requests` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_request_handoffs` AS
SELECT a.`id`, a.`request_id`, a.`request_type`, a.`legacy_source_table`, a.`legacy_source_id`, a.`revision`, a.`po_revision_id`, a.`po_revision_number`, a.`po_snapshot_json`, a.`account_request_type`, a.`account_request_id`, a.`handoff_status`, a.`sent_by`, a.`sent_at`, a.`retrieved_by`, a.`retrieved_at`, a.`retrieval_source`, a.`retrieval_reason`, a.`account_request_snapshot_json`
FROM `lambert2_acctlab_db`.`procurement_request_handoffs` AS a
UNION ALL
SELECT ar.`id`, ar.`request_id`, ar.`request_type`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`revision`, ar.`po_revision_id`, ar.`po_revision_number`, ar.`po_snapshot_json`, ar.`account_request_type`, ar.`account_request_id`, ar.`handoff_status`, ar.`sent_by`, ar.`sent_at`, ar.`retrieved_by`, ar.`retrieved_at`, ar.`retrieval_source`, ar.`retrieval_reason`, ar.`account_request_snapshot_json`
FROM `lambert2_acctlab_archive`.`procurement_request_handoffs` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`procurement_request_handoffs` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_role_permissions` AS
SELECT a.`role`, a.`permission_code`, a.`is_enabled`, a.`updated_by`, a.`updated_at`
FROM `lambert2_acctlab_db`.`procurement_role_permissions` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_user_access` AS
SELECT a.`user_id`, a.`role`, a.`is_active`, a.`created_by`, a.`updated_by`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`procurement_user_access` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`procurement_user_permissions` AS
SELECT a.`user_id`, a.`permission_code`, a.`is_enabled`, a.`updated_by`, a.`updated_at`
FROM `lambert2_acctlab_db`.`procurement_user_permissions` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`site_claim` AS
SELECT a.`id`, a.`invoice_number`, a.`expense_description`, a.`activity`, a.`amount`, a.`nature_of_expense`, a.`expense_project`, a.`expense_category`, a.`msr_number`, a.`expense_claim_number`, a.`msr`, a.`receipient`, a.`expense_date`, a.`created_at`, a.`created_by`, a.`updated_at`, a.`updated_by`
FROM `lambert2_acctlab_db`.`site_claim` AS a
UNION ALL
SELECT ar.`id`, ar.`invoice_number`, ar.`expense_description`, ar.`activity`, ar.`amount`, ar.`nature_of_expense`, ar.`expense_project`, ar.`expense_category`, ar.`msr_number`, ar.`expense_claim_number`, ar.`msr`, ar.`receipient`, ar.`expense_date`, ar.`created_at`, ar.`created_by`, ar.`updated_at`, ar.`updated_by`
FROM `lambert2_acctlab_archive`.`site_claim` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`site_claim` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`suppliers_account_details` AS
SELECT a.`id`, a.`account_name`, a.`account_number`, a.`bank_name`, a.`created_at`
FROM `lambert2_acctlab_db`.`suppliers_account_details` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`suppliers_table` AS
SELECT a.`id`, a.`supplier_name`, a.`supplier_number`, a.`wht_status`, a.`created_at`
FROM `lambert2_acctlab_db`.`suppliers_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`supplier_fund_request_table` AS
SELECT a.`id`, a.`suppliers_name`, a.`supplier_id`, a.`invoice_number`, a.`purchase_number`, a.`po_number`, a.`invoice_date`, a.`purchase_date`, a.`date_received`, a.`invoice_month`, a.`purchase_month`, a.`project_code`, a.`description`, a.`vat_policy`, a.`vat`, a.`wht`, a.`wht_override_status`, a.`wht_override_rate`, a.`wht_override_amount`, a.`wht_override_reason`, a.`wht_override_by`, a.`wht_override_at`, a.`payment_percentage`, a.`net_value`, a.`discount`, a.`other_charges`, a.`amount`, a.`note`, a.`payment_status`, a.`processing_method`, a.`processing_reference`, a.`processing_started_at`, a.`processing_business_days`, a.`expected_completion_at`, a.`completion_mode`, a.`payment_confirmation_status`, a.`amount_paid`, a.`paid_at`, a.`payment_reference`, a.`account_remarks`, a.`payment_batch_id`, a.`payment_updated_by`, a.`payment_updated_at`, a.`procurement_source`, a.`procurement_purchase_id`, a.`procurement_revision`, a.`created_at`, a.`updated_at`
FROM `lambert2_acctlab_db`.`supplier_fund_request_table` AS a
UNION ALL
SELECT ar.`id`, ar.`suppliers_name`, ar.`supplier_id`, ar.`invoice_number`, ar.`purchase_number`, ar.`po_number`, ar.`invoice_date`, ar.`purchase_date`, ar.`date_received`, ar.`invoice_month`, ar.`purchase_month`, ar.`project_code`, ar.`description`, ar.`vat_policy`, ar.`vat`, ar.`wht`, ar.`wht_override_status`, ar.`wht_override_rate`, ar.`wht_override_amount`, ar.`wht_override_reason`, ar.`wht_override_by`, ar.`wht_override_at`, ar.`payment_percentage`, ar.`net_value`, ar.`discount`, ar.`other_charges`, ar.`amount`, ar.`note`, ar.`payment_status`, ar.`processing_method`, ar.`processing_reference`, ar.`processing_started_at`, ar.`processing_business_days`, ar.`expected_completion_at`, ar.`completion_mode`, ar.`payment_confirmation_status`, ar.`amount_paid`, ar.`paid_at`, ar.`payment_reference`, ar.`account_remarks`, ar.`payment_batch_id`, ar.`payment_updated_by`, ar.`payment_updated_at`, ar.`procurement_source`, ar.`procurement_purchase_id`, ar.`procurement_revision`, ar.`created_at`, ar.`updated_at`
FROM `lambert2_acctlab_archive`.`supplier_fund_request_table` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`supplier_fund_request_table` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`union_payment_schedule` AS
SELECT a.`id`, a.`payment_amount`, a.`payment_date`, a.`narration`, a.`supplier_name`, a.`supplier_id`, a.`bank_name`, a.`account_name`, a.`account_number`, a.`sort_code`, a.`user_id`, a.`batch`, a.`invoice_number`, a.`po_number`, a.`created_at`
FROM `lambert2_acctlab_db`.`union_payment_schedule` AS a
UNION ALL
SELECT ar.`id`, ar.`payment_amount`, ar.`payment_date`, ar.`narration`, ar.`supplier_name`, ar.`supplier_id`, ar.`bank_name`, ar.`account_name`, ar.`account_number`, ar.`sort_code`, ar.`user_id`, ar.`batch`, ar.`invoice_number`, ar.`po_number`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`union_payment_schedule` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`union_payment_schedule` AS a2
    WHERE a2.`id` <=> ar.`id`
);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`user_table` AS
SELECT a.`id`, a.`fname`, a.`lname`, a.`email`, a.`password`, a.`integrity`, a.`department`, a.`status`, a.`created_by`, a.`updated_by`
FROM `lambert2_acctlab_db`.`user_table` AS a;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW `lambert2_acctlab_read`.`workflow_events` AS
SELECT a.`id`, a.`source_app`, a.`event_scope`, a.`request_type`, a.`entity_type`, a.`entity_id`, a.`related_entity_type`, a.`related_entity_id`, a.`secondary_entity_type`, a.`secondary_entity_id`, a.`tertiary_entity_type`, a.`tertiary_entity_id`, a.`batch_id`, a.`event_type`, a.`event_key`, a.`actor_user_id`, a.`actor_email`, a.`details_json`, a.`legacy_source_table`, a.`legacy_source_id`, a.`source_event_id`, a.`created_at`
FROM `lambert2_acctlab_db`.`workflow_events` AS a
UNION ALL
SELECT ar.`id`, ar.`source_app`, ar.`event_scope`, ar.`request_type`, ar.`entity_type`, ar.`entity_id`, ar.`related_entity_type`, ar.`related_entity_id`, ar.`secondary_entity_type`, ar.`secondary_entity_id`, ar.`tertiary_entity_type`, ar.`tertiary_entity_id`, ar.`batch_id`, ar.`event_type`, ar.`event_key`, ar.`actor_user_id`, ar.`actor_email`, ar.`details_json`, ar.`legacy_source_table`, ar.`legacy_source_id`, ar.`source_event_id`, ar.`created_at`
FROM `lambert2_acctlab_archive`.`workflow_events` AS ar
WHERE NOT EXISTS (
    SELECT 1 FROM `lambert2_acctlab_db`.`workflow_events` AS a2
    WHERE a2.`id` <=> ar.`id`
);
