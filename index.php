<?php

require_once __DIR__ . '/vendor/autoload.php';

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once('includes/connection.php');


// Normalize request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/acctlab-server/api';
$relativePath = str_replace($basePath, '', $requestUri);



$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to Acctlab API 😊"]);
    },
    '/welcome' => 'routes/welcome.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/register' => 'routes/auth/register.php',
    
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
    
    // Advance Fund Request Routes
    '/request/advance/create' => 'routes/request/advance/create.php',
    '/request/advance/edit' => 'routes/request/advance/edit.php',
    '/request/advance/getAll' => 'routes/request/advance/getAll.php',
    '/request/advance/delete' => 'routes/request/advance/delete.php',
    '/request/advance/updateStatus' => 'routes/request/advance/updateStatus.php',
    '/request/advance/getReports' => 'routes/request/advance/getReports.php',
    '/request/advance/getSummary' => 'routes/request/advance/getSummary.php',
    '/request/advance/getFilteredRequest' => 'routes/request/advance/getFilteredRequest.php',

    // Supplier Fund Request Routes
    '/request/supplier/create' => 'routes/request/supplier/create.php',
    '/request/supplier/edit' => 'routes/request/supplier/edit.php',
    '/request/supplier/getAll' => 'routes/request/supplier/getAll.php',
    '/request/supplier/delete' => 'routes/request/supplier/delete.php',
    '/request/supplier/updateStatus' => 'routes/request/supplier/updateStatus.php',
    '/request/supplier/getReports' => 'routes/request/supplier/getReports.php',
    '/request/supplier/getSummary' => 'routes/request/supplier/getSummary.php',
    '/request/supplier/getFilteredRequest' => 'routes/request/supplier/getFilteredRequest.php',

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


    // FX Routes
    '/fx/beneficiary/create' => 'routes/fx/beneficiary/create.php',
    '/fx/beneficiary/edit' => 'routes/fx/beneficiary/edit.php',
    '/fx/beneficiary/delete' => 'routes/fx/beneficiary/delete.php',


    // Fetch Data
    '/data/fetchSuppliers' => 'routes/data/fetchSuppliers.php',
    '/data/fetchSuppliersAccountDetails' => 'routes/data/fetchSuppliersAccountDetails.php',
    '/data/fetchUsers' => 'routes/data/fetchUsers.php',
    '/data/fetchProjects' => 'routes/data/fetchProjects.php',

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

    // Fund Request Routes
    '/request/advance/getSingle/(.+)' => 'routes/request/advance/getSingle.php',
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