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
    if (!isset($data['messageId'], $data['message'], $data['title'], $data['date'], $data['time'], $data['email'], $data['name'])) {
        throw new Exception("All fields are required", 400);
    }
    
    $messageId = trim($data['messageId']);
    $message = trim($data['message']);
    $title = trim($data['title']);
    $date = trim($data['date']);
    $time = trim($data['time']);
    $email = trim($data['email']);
    $name = trim($data['name']);
    
    
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO reminder (messageId, message, title, date, time, email, name) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("sssssss", $messageId, $message, $title, $date, $time, $email, $name);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => "The reminder has set successfully!",
            "data" => [
                "id" => $id,
                "messageId" => $messageId,
                "message" => $message,
                "title" => $title,
                "date" => $date,
                "time" => $time,
                "email" => $email,
                "name" => $name,
            ],
        ]);
    
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
