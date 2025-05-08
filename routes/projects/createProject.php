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


    // all fields
    // name, description, userEmail
    // required fields
    // name, userEmail
    
    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['name'], $data['userEmail'])) {
        throw new Exception("All fields are required", 400);
    }
    
    $name = trim($data['name']);
    $description = trim($data['description']);
    $userEmail = trim($data['userEmail']);
    $createdBy = $userEmail;
    $updatedBy = $userEmail;
    

    $checkStmt = $conn->prepare("SELECT * FROM projects WHERE name = ?");
    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();
    $checkStmtResult = $checkStmt->get_result();


    if ($checkStmtResult->num_rows > 0) {
        throw new Exception($name . " already exists as a project.", 400);
    }


    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO projects (name, description, createdBy, updatedBy) 
                            VALUES (?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("ssss", $name, $description, $createdBy, $updatedBy);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => $name . " has been created successfully!",
            "data" => [
                "id" => $id,
                "name" => $name,
                "description" => $description,
                "createdBy" => $createdBy,
                "updatedBy" => $updatedBy
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
