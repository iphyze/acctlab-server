<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';


header('Content-Type: application/json');


try{


    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }


    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];
    

    if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create logs", 401);
    }

    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['logId'], $data['message'], $data['title'], $data['firstName'], $data['lastName'], $data['email'])) {
        throw new Exception("All fields are required", 400);
    }

    date_default_timezone_set('Africa/Lagos');
    
    $logId = trim($data['logId']);
    $message = trim($data['message']);
    $title = trim($data['title']);
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim($data['email']);
    $backupDate = date('Y-m-d H:i:s');
    $createdAt = trim($data['createdAt']) ?: $backupDate;
    $createdAtDateTime = new DateTime($createdAt);
    $createdAtDateTime->modify('+60 seconds');
    $createdAt = $createdAtDateTime->format('Y-m-d H:i:s');
    $updatedAt = date('Y-m-d H:i:s');
    $createdBy = $email;
    $updatedBy = $email;


    
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO log_responses (logId, message, title, firstName, lastName, createdBy, updatedBy, createdAt) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("ssssssss", $logId, $message, $title, $firstName, $lastName, $createdBy, $updatedBy, $createdAt);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        
        $newResponseData = [
            "id" => $id,
            "message" => $message,
            "title" => $title,
            "logId" => intval($logId),
            "firstName" => $firstName,
            "lastName" => $lastName,
            "createdBy" => $createdBy,
            "updatedBy" => $updatedBy,
            "createdAt" => $createdAt,
            "updatedAt" => $updatedAt
        ];

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "The log has been added successfully!",
            "data" => $newResponseData
        ]);
    
        // Store the event
        $eventStmt = $conn->prepare("INSERT INTO events (type, data, created_at) VALUES (?, ?, ?)");
        $eventType = 'newResponse';
        $eventData = json_encode($newResponseData);
        $eventStmt->bind_param("sss", $eventType, $eventData, $createdAt);
        $eventStmt->execute();
        $eventStmt->close();
    
    } else {
        throw new Exception("Failed to create log!", 500);
    }

    $stmt->close();
    

}catch(Exception $e){
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
