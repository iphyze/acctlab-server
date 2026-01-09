<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    /**
     * Authenticate user
     */
    $userData = authenticateUser();
    $integrity = $userData['integrity'];

    if (!in_array($integrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized: Only Admins can view this report", 401);
    }

    /**
     * Read year from frontend
     * Allowed: numeric year | all | All | null
     */
    $year = $_GET['year'] ?? 'all';
    $year = strtolower(trim($year));

    /**
     * Define tables & year capability
     */
    $queries = [
        'suppliers' => [
            'sql'  => "SELECT COUNT(*) AS total FROM suppliers_table",
            'year' => true
        ],
        'supplierAccounts' => [
            'sql'  => "SELECT COUNT(*) AS total FROM suppliers_account_details",
            'year' => true
        ],
        'locations' => [
            'sql'  => "SELECT COUNT(*) AS total FROM location_table",
            'year' => false
        ],
        'users' => [
            'sql'  => "SELECT COUNT(*) AS total FROM user_table",
            'year' => false
        ],
    ];

    $data = [];

    foreach ($queries as $key => $config) {
        $sql    = $config['sql'];
        $params = [];
        $types  = "";

        /**
         * Apply year filter only when:
         * - table supports year
         * - year is numeric
         */
        if ($config['year'] && $year !== 'all') {
            if (!ctype_digit($year)) {
                throw new Exception("Invalid year parameter", 400);
            }

            $sql .= " WHERE YEAR(created_at) = ?";
            $params[] = (int) $year;
            $types .= "i";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare query for {$key}", 500);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $data[$key] = (int) $result['total'];
    }

    echo json_encode([
        "status" => "Success",
        "year"   => ($year === 'all') ? 'all' : (int)$year,
        "data"   => $data
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
