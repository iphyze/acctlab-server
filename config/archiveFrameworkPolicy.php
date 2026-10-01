<?php

declare(strict_types=1);

/**
 * Archive framework policy.
 *
 * read_mode:
 *   - active_only: reads only from the active database.
 *   - combined: reads active + archive, with active rows taking precedence.
 *
 * archive_strategy is intentionally descriptive in this foundation batch.
 * The cutover runner consumes these values in the next archive-cleanup batch.
 * Unknown future tables default to active_only + manual_review until classified.
 */
return [
    'version' => 1,
    'default' => [
        'read_mode' => 'active_only',
        'archive_strategy' => 'manual_review',
    ],
    'tables' => [
        // Canonical payment / workflow history.
        'account_advance_po_reconciliation_allocations' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'procurement_local_advance_po_revision_reconciliations', 'parent_fk' => 'account_reconciliation_id'],
        'account_payment_artifacts' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'account_payment_batches', 'parent_fk' => 'batch_id'],
        'account_payment_batches' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'status', 'keep_statuses' => ['Processing', 'Pending', 'Awaiting Confirmation']],
        'account_payment_batch_items' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'account_payment_batches', 'parent_fk' => 'batch_id'],
        'account_payment_reminders' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'lifecycle_status', 'keep_statuses' => ['Pending', 'Active', 'Processing']],
        'account_payment_reminder_runs' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],

        // Account operational requests and schedules.
        'advance_payment_request' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing']],
        'advance_payment_schedule_tab' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at', 'retain_artifacts' => [['artifact_type' => 'GAPS Schedule', 'request_types' => ['local_advance_purchase']]]],
        'compass_fund_request_table' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing']],
        'expense_fund_request_table' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing']],
        'fx_fund_request_table' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing', 'Unconfirmed']],
        'fx_instruction_letter_table' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Unconfirmed']],
        'instruction_letter' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'local_purchase_vat_wht_reconciliation_log' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'local_transfer' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at', 'retain_artifacts' => [['artifact_type' => 'Bank Instruction', 'request_types' => ['local_final_purchase', 'local_advance_purchase']]]],
        'logs' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'notifications' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'other_payment_schedule' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'payment_schedule_tab' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at', 'retain_artifacts' => [['artifact_type' => 'GAPS Schedule', 'request_types' => ['local_final_purchase']]]],
        'site_claim' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'supplier_fund_request_table' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing']],
        'union_payment_schedule' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at', 'retain_artifacts' => [['artifact_type' => 'Union Bank Schedule', 'request_types' => ['local_final_purchase', 'local_advance_purchase']]]],
        'workflow_events' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],

        // Bank reconciliation. Rules/profiles/learned patterns stay current; reconciliations are historical.
        'bank_recons' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'status', 'keep_statuses' => ['Unbalanced', 'Open', 'Draft', 'In Progress']],
        'bank_recon_bank_lines' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'bank_recons', 'parent_fk' => 'recon_id'],
        'bank_recon_difference_explanations' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'bank_recons', 'parent_fk' => 'recon_id'],
        'bank_recon_ledger_lines' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'bank_recons', 'parent_fk' => 'recon_id'],
        'bank_recon_matches' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'bank_recons', 'parent_fk' => 'recon_id'],

        // Cash transaction history. Open operational items stay active during cutover.
        'cash_daily_closures' => ['read_mode' => 'combined', 'archive_strategy' => 'cutoff', 'cutoff_column' => 'created_at'],
        'cash_ious' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'status', 'keep_statuses' => ['OPEN', 'PENDING', 'PARTIAL']],
        'cash_iou_actions' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'cash_ious', 'parent_fk' => 'iou_id'],
        'cash_iou_retirements' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'cash_ious', 'parent_fk' => 'iou_id'],
        'cash_mutilated_cash' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'status', 'keep_statuses' => ['PENDING_RETURN', 'OPEN', 'PENDING']],
        'cash_receipts' => ['read_mode' => 'combined', 'archive_strategy' => 'dependency_aware', 'cutoff_column' => 'created_at'],
        'cash_transactions' => ['read_mode' => 'combined', 'archive_strategy' => 'dependency_aware', 'cutoff_column' => 'created_at'],
        'cash_transaction_edits' => ['read_mode' => 'combined', 'archive_strategy' => 'dependency_aware', 'cutoff_column' => 'edited_at'],

        // Procurement canonical history.
        'procurement_local_advance_pos' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'po_status', 'keep_statuses' => ['Open', 'Pending', 'Processing', 'Active']],
        'procurement_local_advance_po_revisions' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'procurement_local_advance_pos', 'parent_fk' => 'po_id'],
        'procurement_local_advance_po_revision_reconciliations' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'reconciliation_status', 'keep_statuses' => ['Pending', 'Processing', 'Open']],
        'procurement_requests' => ['read_mode' => 'combined', 'archive_strategy' => 'carry_forward', 'cutoff_column' => 'created_at', 'status_column' => 'payment_status', 'keep_statuses' => ['Pending', 'Processing']],
        'procurement_request_handoffs' => ['read_mode' => 'combined', 'archive_strategy' => 'parent', 'parent_table' => 'procurement_requests', 'parent_fk' => 'request_id'],

        // Active-only authentication/session state.
        'auth_login_attempts' => ['read_mode' => 'active_only', 'archive_strategy' => 'transient'],
        'auth_refresh_tokens' => ['read_mode' => 'active_only', 'archive_strategy' => 'transient'],
        'procurement_auth_login_attempts' => ['read_mode' => 'active_only', 'archive_strategy' => 'transient'],
        'procurement_auth_refresh_tokens' => ['read_mode' => 'active_only', 'archive_strategy' => 'transient'],

        // Master/reference/configuration data always comes from ACTIVE.
        'bank_beneficiary_details_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'bank_recon_auto_rules' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'bank_recon_learned_patterns' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'bank_recon_upload_profiles' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'bank_sortcode_tab' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'cash_accounts' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'cash_account_users' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'cash_categories' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'cash_settings' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'expense_ledger' => ['read_mode' => 'active_only', 'archive_strategy' => 'business_review'],
        'expense_user_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'business_review'],
        'fx_banks_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'local_banks' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'location_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'procurement_permissions' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'procurement_role_permissions' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'procurement_user_access' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'procurement_user_permissions' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'suppliers_account_details' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'suppliers_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
        'user_table' => ['read_mode' => 'active_only', 'archive_strategy' => 'never'],
    ],
];
