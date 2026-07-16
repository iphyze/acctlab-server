<?php

declare(strict_types=1);

require_once __DIR__ . '/cashReportHelpers.php';

try {
    cashRequireMethod('GET');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId());
    [$startDate, $endDate] = cashReportRange($user);
    $detailLimit = isset($_GET['detail_limit']) ? (int) $_GET['detail_limit'] : 500;
    if ($detailLimit < 0 || $detailLimit > 5000) {
        throw new InvalidArgumentException('detail_limit must be between 0 and 5000.', 422);
    }

    jsonResponse([
        'status' => 'Success',
        'message' => 'Cash Desk report loaded successfully.',
        'data' => cashBuildReportData($conn, $user, $account, $startDate, $endDate, $detailLimit),
    ]);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to load the Cash Desk report.');
}
