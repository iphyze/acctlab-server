<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/security.php';

// Explicit CORS allowlist, secure API headers, and OPTIONS handling.
applyApiSecurityHeaders();

include_once __DIR__ . '/includes/connection.php';

// Normalize request URI using the configured deploy path.
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = rtrim((string) envValue('API_BASE_PATH', '/acctlab-server/api'), '/');
$relativePath = '/' . ltrim(substr($requestUri, strlen($basePath)), '/');
if ($relativePath === '//') {
    $relativePath = '/';
}

// Defence-in-depth: administrative endpoints are blocked before route dispatch.
$superAdminRoutes = [
    '/auth/register',
    '/users/getFilteredRequest', '/users/createUsers', '/users/editUsers', '/users/deleteUsers',
    '/logs/getFilteredRequest', '/logs/deleteLogs',
];
if (in_array($relativePath, $superAdminRoutes, true)) {
    require_once __DIR__ . '/includes/authMiddleware.php';
    requireSuperAdmin();
}

$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to Acctlab API 😊"]);
    },
    '/welcome' => 'routes/welcome.php',
    '/auth/csrf' => 'routes/auth/csrf.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/refresh' => 'routes/auth/refresh.php',
    '/auth/logout' => 'routes/auth/logout.php',
    '/auth/switch-period' => 'routes/auth/switch-period.php',
    '/auth/register' => 'routes/auth/register.php',

    // Procurement authentication and access control
    '/procurement/auth/csrf' => 'routes/procurement/auth/csrf.php',
    '/procurement/auth/login' => 'routes/procurement/auth/login.php',
    '/procurement/auth/refresh' => 'routes/procurement/auth/refresh.php',
    '/procurement/auth/logout' => 'routes/procurement/auth/logout.php',
    '/procurement/auth/me' => 'routes/procurement/auth/me.php',
    '/procurement/auth/change-password' => 'routes/procurement/auth/changePassword.php',
    '/procurement/access/role-permissions' => 'routes/procurement/access/rolePermissions.php',
    '/procurement/access/users' => 'routes/procurement/access/users.php',
    '/procurement/users' => 'routes/procurement/users/users.php',
    '/procurement/notifications' => 'routes/procurement/notifications.php',
    '/procurement/dashboard' => 'routes/procurement/dashboard/overview.php',
    '/procurement/search/global' => 'routes/procurement/search/global.php',
    '/procurement/reports/supplier-activity' => 'routes/procurement/reports/supplierActivity.php',
    '/procurement/reports/supplier-activity/export' => 'routes/procurement/reports/supplierActivityExport.php',
    '/procurement/reports/supplier-options' => 'routes/procurement/reports/supplierOptions.php',
    '/procurement/reports/account-submission-schedule' => 'routes/procurement/reports/accountSubmissionSchedule.php',
    '/procurement/reports/account-submission-schedule/export' => 'routes/procurement/reports/accountSubmissionScheduleExport.php',

    // ProcureDesk purchase documents / Cloudflare R2
    '/procurement/documents' => 'routes/procurement/documents/index.php',
    '/procurement/documents/upload-intent' => 'routes/procurement/documents/uploadIntent.php',
    '/procurement/documents/complete-upload' => 'routes/procurement/documents/completeUpload.php',
    '/procurement/documents/access-url' => 'routes/procurement/documents/accessUrl.php',
    '/procurement/documents/settings' => 'routes/procurement/documents/settings.php',

    // ProcureDesk Local Final Purchase
    '/procurement/payments/local/final-purchases' => 'routes/procurement/payments/local/finalPurchases.php',
    '/procurement/payments/local/final-purchases/actions' => 'routes/procurement/payments/local/finalPurchaseActions.php',
    '/procurement/payments/local/final-purchases/options' => 'routes/procurement/payments/local/finalPurchaseOptions.php',
    '/procurement/payments/local/final-purchases/summary' => 'routes/procurement/payments/local/finalPurchaseSummary.php',

    // ProcureDesk Foreign / FX Final Purchase
    '/procurement/payments/foreign/final-purchases' => 'routes/procurement/payments/foreign/finalPurchases.php',
    '/procurement/payments/foreign/final-purchases/actions' => 'routes/procurement/payments/foreign/finalPurchaseActions.php',
    '/procurement/payments/foreign/final-purchases/options' => 'routes/procurement/payments/foreign/finalPurchaseOptions.php',
    '/procurement/payments/foreign/final-purchases/summary' => 'routes/procurement/payments/foreign/finalPurchaseSummary.php',
    '/procurement/payments/foreign/advance-purchases' => 'routes/procurement/payments/foreign/advancePurchases.php',
    '/procurement/payments/foreign/advance-purchases/actions' => 'routes/procurement/payments/foreign/advancePurchaseActions.php',
    '/procurement/payments/foreign/advance-purchases/options' => 'routes/procurement/payments/foreign/advancePurchaseOptions.php',
    '/procurement/payments/foreign/advance-purchases/summary' => 'routes/procurement/payments/foreign/advancePurchaseSummary.php',
    '/procurement/payments/foreign/advance-purchases/amendments' => 'routes/procurement/payments/foreign/advancePurchaseAmendments.php',

    // ProcureDesk Local Advance Purchase
    '/procurement/payments/local/advance-purchases' => 'routes/procurement/payments/local/advancePurchases.php',
    '/procurement/payments/local/advance-purchases/actions' => 'routes/procurement/payments/local/advancePurchaseActions.php',
    '/procurement/payments/local/advance-purchases/options' => 'routes/procurement/payments/local/advancePurchaseOptions.php',
    '/procurement/payments/local/advance-purchases/summary' => 'routes/procurement/payments/local/advancePurchaseSummary.php',
    '/procurement/payments/local/advance-purchases/amendments' => 'routes/procurement/payments/local/advancePurchaseAmendments.php',
    
    // Gaps Routes

    // Suppliers Gaps
    '/gaps/supplier/suppliersGaps' => 'routes/gaps/supplier/suppliersGaps.php',
    '/gaps/supplier/editSuppliersGaps' => 'routes/gaps/supplier/editSupplierGaps.php',
    '/gaps/supplier/deleteSuppliersGaps' => 'routes/gaps/supplier/deleteSupplierGaps.php',
    '/gaps/supplier/getAllSuppliersGaps' => 'routes/gaps/supplier/getAllSuppliersGaps.php',
    '/gaps/supplier/report' => 'routes/gaps/supplier/report.php',
    '/gaps/supplier/getFilteredGaps' => 'routes/gaps/supplier/getFilteredGaps.php',

    // Expense Gaps
    '/gaps/expense/expenseGaps' => 'routes/gaps/expense/expenseGaps.php',
    '/gaps/expense/editExpenseGaps' => 'routes/gaps/expense/editExpenseGaps.php',
    '/gaps/expense/deleteExpenseGaps' => 'routes/gaps/expense/deleteExpenseGaps.php',
    '/gaps/expense/getAllExpenseGaps' => 'routes/gaps/expense/getAllExpenseGaps.php',
    '/gaps/expense/report' => 'routes/gaps/expense/report.php',
    '/gaps/expense/getFilteredGaps' => 'routes/gaps/expense/getFilteredGaps.php',
    
    // Advance Gaps
    '/gaps/advance/suppliersAdvanceGaps' => 'routes/gaps/advance/suppliersAdvanceGaps.php',
    '/gaps/advance/editSuppliersAdvanceGaps' => 'routes/gaps/advance/editSuppliersAdvanceGaps.php',
    '/gaps/advance/deleteSuppliersAdvanceGaps' => 'routes/gaps/advance/deleteSuppliersAdvanceGaps.php',
    '/gaps/advance/getAllSuppliersAdvanceGaps' => 'routes/gaps/advance/getAllSuppliersAdvanceGaps.php',
    '/gaps/advance/getFilteredGaps' => 'routes/gaps/advance/getFilteredGaps.php',
    '/gaps/advance/getByDate' => 'routes/gaps/advance/getByDate.php',
    '/gaps/advance/report' => 'routes/gaps/advance/report.php',

    // Union Bank Schedule
    '/union/createSchedule' => 'routes/union-bank-schedule/schedule.php',
    '/union/editSchedule' => 'routes/union-bank-schedule/editSchedule.php',
    '/union/deleteSchedule' => 'routes/union-bank-schedule/deleteSchedule.php',
    '/union/getAllSchedule' => 'routes/union-bank-schedule/getAllSchedule.php',
    '/union/getFilteredSchedule' => 'routes/union-bank-schedule/getFilteredSchedule.php',
    
    // Account read-only access to ProcureDesk purchase documents
    '/request/procurement-documents' => 'routes/request/procurement-documents/index.php',
    '/request/procurement-documents/access-url' => 'routes/request/procurement-documents/accessUrl.php',

    // AcctLab-owned request/payment supporting documents
    '/request/account-documents' => 'routes/request/account-documents/index.php',
    '/request/account-documents/upload-intent' => 'routes/request/account-documents/uploadIntent.php',
    '/request/account-documents/complete-upload' => 'routes/request/account-documents/completeUpload.php',
    '/request/account-documents/access-url' => 'routes/request/account-documents/accessUrl.php',

    // Canonical Payment Processing Workspace
    '/request/payment-processing/items' => 'routes/request/payment-processing/items.php',
    '/request/payment-processing/batches' => 'routes/request/payment-processing/batches.php',
    '/request/payment-processing/actions' => 'routes/request/payment-processing/actions.php',

    // Advance Fund Request Routes
    '/request/advance/create' => 'routes/request/advance/create.php',
    '/request/advance/edit' => 'routes/request/advance/edit.php',
    '/request/advance/getAll' => 'routes/request/advance/getAll.php',
    '/request/advance/delete' => 'routes/request/advance/delete.php',
    '/request/advance/updateStatus' => 'routes/request/advance/updateStatus.php',
    '/request/advance/correctStatus' => 'routes/request/advance/correctStatus.php',
    '/request/advance/bulkCorrectStatus' => 'routes/request/advance/bulkCorrectStatus.php',
    '/request/advance/adjustWht' => 'routes/request/advance/adjustWht.php',
    '/request/advance/reversePaidStatus' => 'routes/request/advance/reversePaidStatus.php',
    '/request/advance/payment-batches' => 'routes/request/advance/paymentBatches.php',
    '/request/advance/payment-batches/review' => 'routes/request/advance/paymentBatchReview.php',
    '/request/advance/payment-batches/actions' => 'routes/request/advance/paymentBatchActions.php',
    '/request/advance/returnToProcurement' => 'routes/request/advance/returnToProcurement.php',
    '/request/advance/po-reconciliations' => 'routes/request/advance/poReconciliations.php',
    '/request/advance/getReports' => 'routes/request/advance/getReports.php',
    '/request/advance/getSummary' => 'routes/request/advance/getSummary.php',
    '/request/advance/getFilteredRequest' => 'routes/request/advance/getFilteredRequest.php',

    // FX Fund Request Routes
    '/request/fx/create' => 'routes/request/fx/create.php',
    '/request/fx/edit' => 'routes/request/fx/edit.php',
    '/request/fx/delete' => 'routes/request/fx/delete.php',
    '/request/fx/processForPayment' => 'routes/request/fx/processForPayment.php',
    '/request/fx/processGroupedForPayment' => 'routes/request/fx/processGroupedForPayment.php',
    '/request/fx/updateStatus' => 'routes/request/fx/updateStatus.php',
    '/request/fx/returnToPending' => 'routes/request/fx/returnToPending.php',
    '/request/fx/returnToProcurement' => 'routes/request/fx/returnToProcurement.php',
    '/request/fx/getFilteredRequest' => 'routes/request/fx/getFilteredRequest.php',

    // Supplier Fund Request Routes
    '/request/supplier/create' => 'routes/request/supplier/create.php',
    '/request/supplier/returnToProcurement' => 'routes/request/supplier/returnToProcurement.php',
    '/request/supplier/edit' => 'routes/request/supplier/edit.php',
    '/request/supplier/getAll' => 'routes/request/supplier/getAll.php',
    '/request/supplier/delete' => 'routes/request/supplier/delete.php',
    '/request/supplier/updateStatus' => 'routes/request/supplier/updateStatus.php',
    '/request/supplier/adjustWht' => 'routes/request/supplier/adjustWht.php',
    '/request/supplier/reversePaidStatus' => 'routes/request/supplier/reversePaidStatus.php',
    '/request/supplier/getReports' => 'routes/request/supplier/getReports.php',
    '/request/supplier/getSummary' => 'routes/request/supplier/getSummary.php',
    '/request/supplier/getFilteredRequest' => 'routes/request/supplier/getFilteredRequest.php',
    '/request/supplier/payment-batches' => 'routes/request/supplier/paymentBatches.php',
    '/request/supplier/payment-batches/review' => 'routes/request/supplier/paymentBatchReview.php',
    '/request/supplier/payment-batches/actions' => 'routes/request/supplier/paymentBatchActions.php',
    '/request/supplier/financialAdjustments' => 'routes/request/supplier/financialAdjustments.php',
    '/account/notifications' => 'routes/account/notifications.php',
    '/account/payment-reminders/reinitiate' => 'routes/account/reinitiatePaymentReminder.php',

    // Expense Fund Request Routes
    '/request/expense/create' => 'routes/request/expense/create.php',
    '/request/expense/edit' => 'routes/request/expense/edit.php',
    '/request/expense/getAll' => 'routes/request/expense/getAll.php',
    '/request/expense/delete' => 'routes/request/expense/delete.php',
    '/request/expense/updateStatus' => 'routes/request/expense/updateStatus.php',
    '/request/expense/getReports' => 'routes/request/expense/getReports.php',
    '/request/expense/getSummary' => 'routes/request/expense/getSummary.php',
    '/request/expense/getFilteredRequest' => 'routes/request/expense/getFilteredRequest.php',

    // Expense Fund Request Routes
    '/request/compass/create' => 'routes/request/compass/create.php',
    '/request/compass/edit' => 'routes/request/compass/edit.php',
    '/request/compass/getAll' => 'routes/request/compass/getAll.php',
    '/request/compass/delete' => 'routes/request/compass/delete.php',
    '/request/compass/updateStatus' => 'routes/request/compass/updateStatus.php',
    '/request/compass/getReports' => 'routes/request/compass/getReports.php',
    '/request/compass/getSummary' => 'routes/request/compass/getSummary.php',
    '/request/compass/getFilteredRequest' => 'routes/request/compass/getFilteredRequest.php',
    '/request/compass/payment-batches' => 'routes/request/compass/paymentBatches.php',

    // Cash Desk
    '/cash/bootstrap' => 'routes/cash/bootstrap.php',
    '/cash/dashboard' => 'routes/cash/dashboard.php',
    '/cash/suggestions' => 'routes/cash/getSuggestions.php',
    '/cash/allocation-options' => 'routes/cash/getAllocationOptions.php',
    '/cash/expense-ledgers/create' => 'routes/cash/createExpenseLedger.php',
    '/cash/projects/create' => 'routes/cash/createProject.php',
    '/cash/transactions' => 'routes/cash/listTransactions.php',
    '/cash/receive' => 'routes/cash/receiveCash.php',
    '/cash/disburse' => 'routes/cash/disburseCash.php',
    '/cash/ious' => 'routes/cash/listIous.php',
    '/cash/ious/details' => 'routes/cash/getIou.php',
    '/cash/ious/retire' => 'routes/cash/retireIou.php',
    '/cash/ious/finalize' => 'routes/cash/finalizeIou.php',
    '/cash/ious/return' => 'routes/cash/recordIouCashReturn.php',
    '/cash/ious/reimburse' => 'routes/cash/payIouReimbursement.php',
    '/cash/transactions/details' => 'routes/cash/getTransaction.php',
    '/cash/transactions/update' => 'routes/cash/updateTransaction.php',
    '/cash/transactions/reverse' => 'routes/cash/reverseTransaction.php',
    '/cash/receipts' => 'routes/cash/listReceipts.php',
    '/cash/receipts/upload' => 'routes/cash/uploadReceipt.php',
    '/cash/receipts/download' => 'routes/cash/downloadReceipt.php',
    '/cash/receipts/archive' => 'routes/cash/archiveReceipt.php',
    '/cash/closures' => 'routes/cash/listClosures.php',
    '/cash/closures/close' => 'routes/cash/closeDay.php',
    '/cash/closures/reopen' => 'routes/cash/reopenDay.php',
    '/cash/reports' => 'routes/cash/getReports.php',
    '/cash/reports/export' => 'routes/cash/exportReports.php',
    '/cash/mutilated/options' => 'routes/cash/getMutilatedOptions.php',
    '/cash/mutilated/record' => 'routes/cash/recordMutilatedCash.php',
    '/cash/mutilated/resolve' => 'routes/cash/resolveMutilatedCash.php',
    '/cash/manage' => 'routes/cash/manageSetup.php',
    '/cash/manage/account' => 'routes/cash/saveAccount.php',
    '/cash/manage/assignment' => 'routes/cash/saveAssignment.php',
    '/cash/manage/category' => 'routes/cash/saveCategory.php',
    '/cash/manage/settings' => 'routes/cash/saveSettings.php',

    // FX Routes
    '/fx/beneficiary/create' => 'routes/fx/beneficiary/create.php',
    '/fx/beneficiary/edit' => 'routes/fx/beneficiary/edit.php',
    '/fx/beneficiary/delete' => 'routes/fx/beneficiary/delete.php',
    '/fx/beneficiary/getAll' => 'routes/fx/beneficiary/getAll.php',
    '/fx/beneficiary/getFilteredRequest' => 'routes/fx/beneficiary/getFilteredRequest.php',
    '/fx/beneficiary/getSingleRequest' => 'routes/fx/beneficiary/getSingleRequest.php',
    
    // Letter Format FX
    '/letter-format/fx/create' => 'routes/letter-format/fx/create.php',
    '/letter-format/fx/edit' => 'routes/letter-format/fx/edit.php',
    '/letter-format/fx/delete' => 'routes/letter-format/fx/delete.php',
    '/letter-format/fx/getFilteredRequest' => 'routes/letter-format/fx/getFilteredRequest.php',

    // Letter Format Local
    '/letter-format/local/create' => 'routes/letter-format/local/create.php',
    '/letter-format/local/edit' => 'routes/letter-format/local/edit.php',
    '/letter-format/local/delete' => 'routes/letter-format/local/delete.php',
    '/letter-format/local/getFilteredRequest' => 'routes/letter-format/local/getFilteredRequest.php',

    // Instruction Letter Suppliers
    '/letter/supplier/getFilteredRequest' => 'routes/letter/supplier/getFilteredRequest.php',
    '/letter/supplier/create' => 'routes/letter/supplier/createRequest.php',
    '/letter/supplier/edit' => 'routes/letter/supplier/editRequest.php',
    '/letter/supplier/delete' => 'routes/letter/supplier/deleteRequest.php',

    // Instruction Letter Inter Bank
    '/letter/inter-bank/getFilteredRequest' => 'routes/letter/inter-bank/getFilteredRequest.php',
    '/letter/inter-bank/create' => 'routes/letter/inter-bank/createRequest.php',
    '/letter/inter-bank/edit' => 'routes/letter/inter-bank/editRequest.php',
    '/letter/inter-bank/delete' => 'routes/letter/inter-bank/deleteRequest.php',
    
    // FX Payments
    '/fx/analytics/overview' => 'routes/fx/analytics/overview.php',
    '/fx/payment/getFilteredRequest' => 'routes/fx/payment/getFilteredRequest.php',
    '/fx/payment/getSingleRequest' => 'routes/fx/payment/getSingleRequest.php',
    '/fx/payment/createPayment' => 'routes/fx/payment/createPayment.php',
    '/fx/payment/editPayment' => 'routes/fx/payment/editPayment.php',
    '/fx/payment/deletePayment' => 'routes/fx/payment/deletePayment.php',
    '/fx/payment/updateStatus' => 'routes/fx/payment/updateStatus.php',

    // Projects
    '/projects/getFilteredRequest' => 'routes/projects/getFilteredRequest.php',
    '/projects/createProjects' => 'routes/projects/CreateProjects.php',
    '/projects/editProjects' => 'routes/projects/EditProjects.php',
    '/projects/deleteProjects' => 'routes/projects/deleteProjects.php',

    // Ledgers
    '/ledgers/getFilteredRequest' => 'routes/ledgers/getFilteredRequest.php',
    '/ledgers/createLedgers' => 'routes/ledgers/CreateLedgers.php',
    '/ledgers/editLedgers' => 'routes/ledgers/EditLedgers.php',
    '/ledgers/deleteLedgers' => 'routes/ledgers/deleteLedgers.php',

    // Account Details
    '/account-details/getFilteredRequest' => 'routes/account-details/getFilteredRequest.php',
    '/account-details/createAccounts' => 'routes/account-details/CreateAccounts.php',
    '/account-details/editAccounts' => 'routes/account-details/EditAccounts.php',
    '/account-details/deleteAccounts' => 'routes/account-details/deleteAccounts.php',

    // Sort Codes
    '/sortcodes/getFilteredRequest' => 'routes/sortcodes/getFilteredRequest.php',
    '/sortcodes/createSortCodes' => 'routes/sortcodes/CreateSortCodes.php',
    '/sortcodes/editSortCodes' => 'routes/sortcodes/EditSortCodes.php',
    '/sortcodes/deleteSortCodes' => 'routes/sortcodes/deleteSortCodes.php',
    

    // Fetch Data
    '/data/fetchSuppliers' => 'routes/data/fetchSuppliers.php',
    '/data/fetchSuppliersAccountDetails' => 'routes/data/fetchSuppliersAccountDetails.php',
    '/data/fetchFxBeneficiaryDetails' => 'routes/data/fetchFxBeneficiayDetails.php',
    '/data/fetchFxBanks' => 'routes/data/fetchFxBanks.php',
    '/data/fetchLocalBanks' => 'routes/data/fetchLocalBanks.php',
    '/data/fetchUsers' => 'routes/data/fetchUsers.php',
    '/data/fetchProjects' => 'routes/data/fetchProjects.php',
    '/data/fetchBanksSortCodes' => 'routes/data/fetchBanksSortCodes.php',
    
    // Bank Reconciliation
    '/bank-recon/list' => 'routes/bank-recon/listReconciliations.php',
    '/bank-recon/create' => 'routes/bank-recon/createReconciliation.php',
    '/bank-recon/get' => 'routes/bank-recon/getReconciliation.php',
    '/bank-recon/match' => 'routes/bank-recon/matchLines.php',
    '/bank-recon/unmatch' => 'routes/bank-recon/unmatchLines.php',
    '/bank-recon/classify' => 'routes/bank-recon/classifyBankLine.php',
    '/bank-recon/export-excel' => 'routes/bank-recon/exportReconExcel.php',
    '/bank-recon/match-selected-lines' => 'routes/bank-recon/matchSelectedLines.php',
    '/bank-recon/classify-selected-lines' => 'routes/bank-recon/classifySelectedLines.php',
    '/bank-recon/update' => 'routes/bank-recon/updateReconciliation.php',
    '/bank-recon/delete' => 'routes/bank-recon/deleteReconciliation.php',
    '/bank-recon/update-line' => 'routes/bank-recon/updateLine.php',
    '/bank-recon/add-line' => 'routes/bank-recon/addLine.php',
    '/bank-recon/delete-line' => 'routes/bank-recon/deleteLine.php',
    '/bank-recon/append-lines' => 'routes/bank-recon/appendLines.php',
    '/bank-recon/unclassify-line' => 'routes/bank-recon/unclassifyLine.php',
    '/bank-recon/auto-rules' => 'routes/bank-recon/autoRules.php',
    '/bank-recon/rule-profiles' => 'routes/bank-recon/ruleProfiles.php',

    // Reports
    '/reports/paymentStatus' => 'routes/reports/paymentStatus.php',
    '/reports/paymentStatusBar' => 'routes/reports/paymentStatusBar.php',
    '/reports/scumlReport' => 'routes/reports/scumlReport.php',
    '/reports/scumlReport/export' => 'routes/reports/exportScumlReport.php',
    '/reports/fetchTotals' => 'routes/reports/fetchTotals.php',
    '/reports/dashboardOverview' => 'routes/reports/dashboardOverview.php',
    '/reports/projectSpend' => 'routes/reports/projectSpend.php',
    '/reports/supplierStatement' => 'routes/reports/supplierStatement.php',
    '/reports/supplierWht' => 'routes/reports/supplierWht.php',
    '/reports/supplierWht/export' => 'routes/reports/exportSupplierWht.php',
    '/reports/supplierStatement/export' => 'routes/reports/exportSupplierStatement.php',

    // AcctLab Receivables module
    '/receivables/bootstrap' => 'routes/receivables/bootstrap.php',
    '/receivables/dashboard' => 'routes/receivables/dashboard.php',
    '/receivables/ageing' => 'routes/receivables/ageing.php',
    '/receivables/ageing/detail' => 'routes/receivables/ageingDetail.php',
    '/receivables/deductions' => 'routes/receivables/deductions.php',
    '/receivables/deductions/detail' => 'routes/receivables/deductionsDetail.php',
    '/receivables/reports' => 'routes/receivables/reports.php',
    '/receivables/reports/management-pack' => 'routes/receivables/exportManagementPack.php',
    '/receivables/invoices' => 'routes/receivables/invoices.php',
    '/analytics/paymentSummary' => 'routes/analytics/paymentSummary.php',

    // Global workspace search
    '/search/global' => 'routes/search/global.php',

    // Users
    '/users/getFilteredRequest' => 'routes/users/getFilteredRequest.php',
    '/users/getSingleUser' => 'routes/users/getSingleUser.php',
    '/users/createUsers' => 'routes/users/CreateUsers.php',
    '/users/editUsers' => 'routes/users/EditUsers.php',
    '/users/deleteUsers' => 'routes/users/deleteUsers.php',
    '/users/updateProfile' => 'routes/users/UpdateProfile.php',

    // Users
    '/logs/getFilteredRequest' => 'routes/logs/getFilteredRequest.php',
    '/logs/deleteLogs' => 'routes/logs/deleteLogs.php',

];

if (array_key_exists($relativePath, $routes)) {
    if (is_callable($routes[$relativePath])) {
        $routes[$relativePath](); // Execute function
    } else {
        include_once($routes[$relativePath]);
    }
    exit;
}

$dynamicRoutes = [
    
    // Gaps Routes
    '/gaps/expense/getSingleExpenseGaps/(.+)' => 'routes/gaps/expense/getSingleExpenseGaps.php',
    '/gaps/supplier/getSingleSuppliersGaps/(.+)' => 'routes/gaps/supplier/getSingleSuppliersGaps.php',
    '/gaps/advance/getSingleSuppliersAdvanceGaps/(.+)' => 'routes/gaps/advance/getSingleSuppliersAdvanceGaps.php',
    '/union/getSingleSchedule/(.+)' => 'routes/union-bank-schedule/getSingleSchedule.php',

    // Fund Request Routes
    '/request/advance/getSingle/(.+)' => 'routes/request/advance/getSingle.php',
    '/request/fx/getSingle/(.+)' => 'routes/request/fx/getSingle.php',
];

foreach ($dynamicRoutes as $pattern => $file) {
    if (preg_match('#^' . $pattern . '$#', $relativePath, $matches)) {
        $params = explode('/', $matches[1]);

        // If there's only one parameter, store it as a string, else store as an array
        $_GET['params'] = count($params) === 1 ? $params[0] : $params;
        include_once($file);
        exit;
    }
}

http_response_code(404);
echo json_encode(["message" => "Page not found!"]);
exit;

// Close connection
mysqli_close($conn);

?>