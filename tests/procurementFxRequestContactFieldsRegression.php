<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('database/20260810_procurement_fx_request_contact_fields.sql');
$finalService = $read('includes/procurementFxFinalPurchaseService.php');
$advanceService = $read('includes/procurementFxAdvancePurchaseService.php');
$finalRoute = $read('routes/procurement/payments/foreign/finalPurchases.php');
$advanceRoute = $read('routes/procurement/payments/foreign/advancePurchases.php');
$accountListRoute = $read('routes/request/fx/getFilteredRequest.php');

$checks = [
    'migration_adds_optional_contact_fields_to_procurement_requests' => str_contains($migration, 'ALTER TABLE `procurement_requests`')
        && str_contains($migration, '`contact_person` VARCHAR(255) NULL')
        && str_contains($migration, '`phone_number` VARCHAR(80) NULL'),
    'migration_adds_optional_contact_fields_to_fx_fund_requests' => str_contains($migration, 'ALTER TABLE `fx_fund_request_table`')
        && substr_count($migration, '`contact_person` VARCHAR(255) NULL') >= 2
        && substr_count($migration, '`phone_number` VARCHAR(80) NULL') >= 2,
    'fx_final_payload_accepts_optional_contact_fields' => str_contains($finalService, "procurementLocalFinalOptionalText(\$data, 'contact_person', 255)")
        && str_contains($finalService, "procurementLocalFinalOptionalText(\$data, 'phone_number', 80)"),
    'fx_advance_payload_accepts_optional_contact_fields' => str_contains($advanceService, "procurementLocalAdvanceOptionalText(\$data, 'contact_person', 255)")
        && str_contains($advanceService, "procurementLocalAdvanceOptionalText(\$data, 'phone_number', 80)"),
    'fx_final_create_and_edit_persist_contact_fields' => str_contains($finalRoute, 'contact_person, phone_number, wht_status')
        && str_contains($finalRoute, "contact_person = NULLIF(?, ''), phone_number = NULLIF(?, '')")
        && substr_count($finalRoute, "\$payload['contact_person']") >= 2
        && substr_count($finalRoute, "\$payload['phone_number']") >= 2,
    'fx_advance_create_and_edit_persist_contact_fields' => str_contains($advanceRoute, 'expected_payment, remark, contact_person, phone_number')
        && str_contains($advanceRoute, "contact_person = NULLIF(?, ''), phone_number = NULLIF(?, '')")
        && substr_count($advanceRoute, "\$payload['contact_person']") >= 2
        && substr_count($advanceRoute, "\$payload['phone_number']") >= 2,
    'fx_final_handoff_carries_contact_fields_to_acctlab' => str_contains($finalService, 'suppliers_id, contact_person, phone_number, invoice_number')
        && str_contains($finalService, "\$contactPerson = trim((string) (\$purchase['contact_person'] ?? ''))")
        && str_contains($finalService, "\$phoneNumber = trim((string) (\$purchase['phone_number'] ?? ''))"),
    'fx_advance_handoff_carries_contact_fields_to_acctlab' => str_contains($advanceService, 'suppliers_id, contact_person, phone_number, invoice_number')
        && str_contains($advanceService, "\$contactPerson = trim((string) (\$purchase['contact_person'] ?? ''))")
        && str_contains($advanceService, "\$phoneNumber = trim((string) (\$purchase['phone_number'] ?? ''))"),
    'supplementary_fx_advance_inherits_parent_contact_fields' => str_contains($advanceService, "\$parentRequest['contact_person']")
        && str_contains($advanceService, "\$parentRequest['phone_number']")
        && str_contains($advanceService, 'contact_person, phone_number,'),
    'register_search_includes_contact_fields' => str_contains($finalRoute, 'p.contact_person LIKE ? OR p.phone_number LIKE ?')
        && str_contains($advanceRoute, 'r.contact_person LIKE ? OR r.phone_number LIKE ?')
        && str_contains($accountListRoute, 'ffr.contact_person LIKE ? OR ffr.phone_number LIKE ?'),
    'contact_fields_remain_request_level_not_shared_po_fields' => !str_contains($migration, 'ALTER TABLE `procurement_local_advance_pos`')
        && !str_contains($migration, 'ALTER TABLE `procurement_local_advance_po_revisions`'),
];

$failed = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['healthy' => $failed === [], 'checks' => $checks, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
