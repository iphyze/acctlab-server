<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

// Set Nigerian timezone
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];
    
    if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can edit logs", 401);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['responseId'], $data['logId'], $data['title'], $data['message'], $data['firstName'], $data['lastName'], $data['email'])) {
        throw new Exception("All fields are required", 400);
    }
    
    // Check if the log exists and get its creation time
    $checkStmt = $conn->prepare("SELECT * FROM log_responses WHERE id = ?");
    if (!$checkStmt) {
        throw new Exception("Database error: Failed to prepare check statement", 500);
    }
    
    $checkStmt->bind_param("s", $data['responseId']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Response not found", 404);
    }
    
    $log = $result->fetch_assoc();
    $checkStmt->close();
    
    // // Time restriction check - only apply to non-Super_Admin users
    // if ($loggedInUserRole !== 'Super_Admin') {
    //     $updatedAt = new DateTime($log['updatedAt'], new DateTimeZone('Africa/Lagos'));
    //     $now = new DateTime('now', new DateTimeZone('Africa/Lagos'));
        
    //     // Calculate time difference in minutes
    //     $timeDiff = ($now->getTimestamp() - $updatedAt->getTimestamp()) / 60;
        
    //     if ($timeDiff > 5) { // 5 minutes
    //         throw new Exception("You can no longer edit this log as it's more than 5 minutes old", 400);
    //     }
    // }
    

    // Prepare data for update
    $message = trim($data['message']);
    $title = trim($data['title']);
    $logId = intval(trim($data['logId']));
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim($data['email']);
    $updatedBy = $email;
    $responseId = intval($data['responseId']);
    

    // Update the log
    $updateStmt = $conn->prepare("
        UPDATE log_responses 
        SET message = ?, 
            title = ?, 
            logId = ?, 
            firstName = ?, 
            lastName = ?, 
            updatedBy = ?,
            updatedAt = NOW() WHERE id = ?
    ");
    
    if (!$updateStmt) {
        throw new Exception("Database error: Failed to prepare update statement", 500);
    }
    
    
    
    $updateStmt->bind_param("ssisssi", $message, $title, $logId, $firstName, $lastName, $updatedBy, $responseId);
    
    if ($updateStmt->execute()) {
        
        $updatedAt = new DateTime('now', new DateTimeZone('Africa/Lagos'));

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "The response has been updated successfully!",
            "data" => [
                "id" => $responseId,
                "message" => $message,
                "title" => $title,
                "logId" => $logId,
                "firstName" => $firstName,
                "lastName" => $lastName,
                "updatedBy" => $updatedBy,
                "updatedAt" => $updatedAt->format('Y-m-d H:i:s')
            ],
        ]);
    } else {
        throw new Exception("Failed to update log!", 500);
    }

    $updateStmt->close();

} catch(Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>