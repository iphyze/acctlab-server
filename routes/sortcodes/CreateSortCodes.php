<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Super_Admin'])) {
        throw new Exception("Unauthorized", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid JSON body", 400);

    $required = ['bank_name', 'sort_code', 'code_name'];
    foreach ($required as $field) {
        if (empty(trim($data[$field] ?? ''))) {
            throw new Exception("Field '{$field}' is required", 400);
        }
    }

    $bank_name = trim($data['bank_name']);
    $sort_code = trim($data['sort_code']);
    $code_name = trim($data['code_name']);

    // Duplicate check (bank_name + sort_code)
    $dup = $conn->prepare("
        SELECT id FROM bank_sortcode_tab
        WHERE bank_name = ? AND sort_code = ?
        LIMIT 1
    ");
    $dup->bind_param("ss", $bank_name, $sort_code);
    $dup->execute();

    if ($dup->get_result()->num_rows > 0) {
        throw new Exception("Duplicate entry: bank name and sort code already exist", 400);
    }
    $dup->close();

    // Insert
    $insert = $conn->prepare("
        INSERT INTO bank_sortcode_tab (bank_name, sort_code, code_name)
        VALUES (?, ?, ?)
    ");
    $insert->bind_param("sss", $bank_name, $sort_code, $code_name);

    if (!$insert->execute()) {
        throw new Exception("Insert failed: " . $insert->error, 500);
    }

    $id = $insert->insert_id;
    $insert->close();

    // Log
    $log = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail created bank sort code {$bank_name} ({$sort_code})";
    $log->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log->execute();
    $log->close();

    http_response_code(201);
    echo json_encode([
        "status" => "Success",
        "message" => "Bank sort code created successfully",
        "data" => [
            "id" => $id,
            "bank_name" => $bank_name,
            "sort_code" => $sort_code,
            "code_name" => $code_name
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
