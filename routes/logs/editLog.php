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
    if (!isset($data['messageId'], $data['message'], $data['title'], $data['userId'], $data['firstName'], $data['lastName'], $data['email'])) {
        throw new Exception("All fields are required", 400);
    }
    
    // Check if the log exists and get its creation time
    $checkStmt = $conn->prepare("SELECT id, updatedAt FROM logs WHERE id = ?");
    if (!$checkStmt) {
        throw new Exception("Database error: Failed to prepare check statement", 500);
    }
    
    $checkStmt->bind_param("s", $data['messageId']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Log not found", 404);
    }
    
    $log = $result->fetch_assoc();
    $checkStmt->close();
    
    // Time restriction check - only apply to non-Super_Admin users
    if ($loggedInUserRole !== 'Super_Admin') {
        $updatedAt = new DateTime($log['updatedAt'], new DateTimeZone('Africa/Lagos'));
        $now = new DateTime('now', new DateTimeZone('Africa/Lagos'));
        
        // Calculate time difference in minutes
        $timeDiff = ($now->getTimestamp() - $updatedAt->getTimestamp()) / 60;
        
        if ($timeDiff > 5) { // 5 minutes
            throw new Exception("You can no longer edit this log as it's more than 5 minutes old", 400);
        }
    }
    
    // Prepare data for update
    $message = trim($data['message']);
    $title = trim($data['title']);
    $userId = trim($data['userId']);
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim($data['email']);
    $projectId = trim($data['projectId'] ?? '');
    $projectTitle = trim($data['projectTitle'] ?? '');
    $clientId = trim($data['clientId'] ?? '');
    $clientName = trim($data['clientName'] ?? '');
    $updatedBy = $email;
    $messageId = $data['messageId'];
    
    // Update the log
    $updateStmt = $conn->prepare("
        UPDATE logs 
        SET message = ?, 
            title = ?, 
            userId = ?, 
            firstName = ?, 
            lastName = ?, 
            email = ?, 
            projectId = ?, 
            projectTitle = ?, 
            clientId = ?, 
            clientName = ?, 
            updatedBy = ?,
            updatedAt = NOW()
        WHERE id = ?
    ");
    
    if (!$updateStmt) {
        throw new Exception("Database error: Failed to prepare update statement", 500);
    }
    
    $updateStmt->bind_param("ssssssssssss", $message, $title, $userId, $firstName, $lastName, $email, $projectId, $projectTitle, $clientId, $clientName, $updatedBy, $messageId);
    
    if ($updateStmt->execute()) {

        $currentTime = new DateTime('now', new DateTimeZone('Africa/Lagos'));

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "The log has been updated successfully!",
            "data" => [
                "id" => $messageId,
                "message" => $message,
                "title" => $title,
                "userId" => $userId,
                "firstName" => $firstName,
                "lastName" => $lastName,
                "email" => $email,
                "projectId" => $projectId,
                "projectTitle" => $projectTitle,
                "clientId" => $clientId,
                "clientName" => $clientName,
                "updatedBy" => $updatedBy,
                "updatedAt" => $loggedInUserRole !== 'Super_Admin' ? $updatedAt->format('Y-m-d H:i:s') : $currentTime->format('Y-m-d H:i:s'),
                "currentTime" => $loggedInUserRole !== 'Super_Admin' ? $now->format('Y-m-d H:i:s') : null,
                "timeDiffMinutes" => $loggedInUserRole !== 'Super_Admin' ? $timeDiff : null,
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