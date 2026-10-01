<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    cashAssertWriteAccess($user, $account);

    $ledgerStmt = $conn->prepare("SELECT id, supplier_name, supplier_number, summary
                                  FROM expense_ledger
                                  ORDER BY supplier_name ASC, id ASC");
    if (!$ledgerStmt) {
        throw new RuntimeException('Unable to load expense ledgers.', 500);
    }
    $ledgerStmt->execute();
    $ledgers = $ledgerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ledgerStmt->close();

    $projectStmt = $conn->prepare("SELECT id, code, location
                                   FROM location_table
                                   WHERE TRIM(COALESCE(code, '')) <> ''
                                      OR TRIM(COALESCE(location, '')) <> ''
                                   ORDER BY code ASC, location ASC, id ASC");
    if (!$projectStmt) {
        throw new RuntimeException('Unable to load projects.', 500);
    }
    $projectStmt->execute();
    $projects = $projectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $projectStmt->close();

    foreach ($ledgers as &$ledger) {
        $ledger['id'] = (int) $ledger['id'];
    }
    unset($ledger);

    foreach ($projects as &$project) {
        $project['id'] = (int) $project['id'];
    }
    unset($project);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk allocation options loaded successfully.',
        'data' => [
            'ledgers' => $ledgers,
            'projects' => $projects,
        ],
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load Cash Desk allocation options.');
}
