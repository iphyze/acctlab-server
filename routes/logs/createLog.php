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
    if (!isset($data['message'], $data['title'], $data['userId'], $data['firstName'], $data['lastName'], $data['email'])) {
        throw new Exception("All fields are required", 400);
    }
    
    $message = trim($data['message']);
    $title = trim($data['title']);
    $userId = trim($data['userId']);
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim($data['email']);
    $projectId = trim($data['projectId']);
    $projectTitle = trim($data['projectTitle']);
    $clientId = trim($data['clientId']);
    $clientName = trim($data['clientName']);
    $contactType = trim($data['contactType']);
    $createdBy = $email;
    $updatedBy = $email;


    date_default_timezone_set('Africa/Lagos');
    $createdAt = date('Y-m-d H:i:s');
    
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO logs (message, title, userId, firstName, lastName, email, projectId, projectTitle, clientId, clientName, createdBy, updatedBy, contactType) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("sssssssssssss", $message, $title, $userId, $firstName, $lastName, $email, $projectId, $projectTitle, $clientId, $clientName, $createdBy, $updatedBy, $contactType);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;

        $newLogData = [
            "id" => $id,
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
            "createdBy" => $createdBy,
            "updatedBy" => $updatedBy,
            "createdAt" => $createdAt,
            "contactType" => $contactType
        ];
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => "The log has been added successfully!",
            "data" => $newLogData,
        ]);

        $eventStmt = $conn->prepare("INSERT INTO events (type, data, created_at) VALUES (?, ?, ?)");
        $eventType = 'newLog';
        $eventData = json_encode($newLogData);
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
