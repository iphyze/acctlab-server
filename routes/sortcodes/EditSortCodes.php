<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    $required = ['id', 'bank_name', 'sort_code', 'code_name'];
    foreach ($required as $field) {
        if (empty(trim($data[$field] ?? ''))) {
            throw new Exception("Field '{$field}' is required", 400);
        }
    }

    $id = (int) $data['id'];
    $bank_name = trim($data['bank_name']);
    $sort_code = trim($data['sort_code']);
    $code_name = trim($data['code_name']);

    // Exists?
    $check = $conn->prepare("SELECT id FROM bank_sortcode_tab WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        throw new Exception("Record not found", 404);
    }
    $check->close();

    // Duplicate check (exclude self)
    $dup = $conn->prepare("
        SELECT id FROM bank_sortcode_tab
        WHERE bank_name = ? AND sort_code = ? AND id != ?
    ");
    $dup->bind_param("ssi", $bank_name, $sort_code, $id);
    $dup->execute();

    if ($dup->get_result()->num_rows > 0) {
        throw new Exception("Duplicate bank name & sort code detected", 400);
    }
    $dup->close();

    // Update
    $update = $conn->prepare("
        UPDATE bank_sortcode_tab
        SET bank_name = ?, sort_code = ?, code_name = ?
        WHERE id = ?
    ");
    $update->bind_param("sssi", $bank_name, $sort_code, $code_name, $id);

    if (!$update->execute()) {
        throw new Exception("Update failed: " . $update->error, 500);
    }
    $update->close();

    // Log
    $log = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    $action = "$userEmail updated bank sort code {$bank_name} ({$sort_code}) [ID {$id}]";
    $log->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $log->execute();
    $log->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Bank sort code updated successfully",
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
