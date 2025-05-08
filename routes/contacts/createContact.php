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
    // organization, representative, tel, email, projectId, projectTitle, categoryId, categoryName, comment, userEmail
    // required fields
    // organization, representative, tel, email, projectId, projectTitle, categoryId, categoryName, userEmail
    
    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['organization'])) {
        throw new Exception("Organization's name is required!", 400);
    }

    if (!isset($data['representative'])) {
        throw new Exception("Representative's name is required!", 400);
    }

    if (!isset($data['tel'])) {
        throw new Exception("Phone number is required!", 400);
    }

    if (!isset($data['userEmail'])) {
        throw new Exception("User Email is required!", 400);
    }

    
    $organization = trim($data['organization']);
    $representative = trim($data['representative']);
    $tel = trim($data['tel']);
    $email = trim($data['email']);
    $projectId = trim($data['projectId']);
    $projectTitle = trim($data['projectTitle']);
    $categoryId = trim($data['categoryId']);
    $categoryName = trim($data['categoryName']);
    $comment = trim($data['comment']);
    $status = trim($data['status']) ?: 'Pending';
    $userEmail = trim($data['userEmail']);
    $createdBy = $userEmail;
    $updatedBy = $userEmail;
    $contactType = "Primary";
    

    $checkStmt = $conn->prepare("SELECT * FROM contacts WHERE organization = ? && projectId = ?");
    $checkStmt->bind_param("si", $organization, $projectId);
    $checkStmt->execute();
    $checkStmtResult = $checkStmt->get_result();


    if ($checkStmtResult->num_rows > 0) {
        throw new Exception($organization . " already exists as an agent for " . $projectTitle, 401);
    }


    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO contacts (organization, representative, tel, email, projectId, projectTitle, categoryId, categoryName, comment, createdBy, updatedBy, contactType, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("ssssisissssss", $organization, $representative, $tel, $email, $projectId, $projectTitle, $categoryId, $categoryName, $comment, $createdBy, $updatedBy, $contactType, $status);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => "Agent has been created successfully!",
            "data" => [
                "id" => $id,
                "organization" => $organization,
                "representative" => $representative,
                "tel" => $tel,
                "email" => $email,
                "projectId" => $projectId,
                "projectTitle" => $projectTitle,
                "categoryId" => $categoryId,
                "categoryName" => $categoryName,
                "comment" => $comment,
                "createdBy" => $createdBy,
                "updatedBy" => $updatedBy,
                "contactType" => $contactType,
                "status" => $status
            ],
        ]);
    
    } else {
        throw new Exception("Failed to create agent!", 500);
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
