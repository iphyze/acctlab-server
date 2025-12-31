<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");


if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}


// Authenticate user
$userData = authenticateUser();
$loggedInUserRole = $userData['role'];

// Ensure the user is an Admin
if ($loggedInUserRole !== "Admin") {
    http_response_code(403);
    echo json_encode(["message" => "Access denied. Unauthorized user"]);
    exit;
}

// Get the request body
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['userIds']) || !is_array($data['userIds']) || count($data['userIds']) === 0) {
    http_response_code(400);
    echo json_encode(["message" => "Please provide an array of user IDs to delete."]);
    exit;
}

$userIds = $data['userIds'];
$placeholders = implode(',', array_fill(0, count($userIds), '?'));

// Prepare DELETE query
$query = "DELETE FROM users WHERE id IN ($placeholders)";
$stmt = $conn->prepare($query);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["message" => "Database error", "error" => $conn->error]);
    exit;
}

// Bind parameters dynamically
$stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);

if ($stmt->execute()) {
    if ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(["message" => "No users found with the provided IDs."]);
    } else {
        http_response_code(200);
        echo json_encode([
            "message" => "User(s) deleted successfully",
            "deletedCount" => $stmt->affected_rows
        ]);
    }
} else {
    http_response_code(500);
    echo json_encode(["message" => "Database error", "error" => $stmt->error]);
}

$stmt->close();
$conn->close();
?>
