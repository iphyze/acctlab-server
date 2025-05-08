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

    if(!isset($data['firstName']) || empty($data['firstName'])) {
        throw new Exception("First name is required", 400);
    }

    if(!isset($data['lastName']) || empty($data['lastName'])) {
        throw new Exception("Last name is required", 400);
    }

    if(!isset($data['firstContactDate']) || empty($data['firstContactDate'])) {
        throw new Exception("First date of contact is required", 400);
    }

    if(!isset($data['number']) || empty($data['number'])) {
        throw new Exception("Number field is required", 400);
    }

    if(!isset($data['userEmail']) || empty($data['userEmail'])) {
        throw new Exception("UserEmail is required", 400);
    }

    
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $otherName = trim($data['otherName']);
    $title = trim($data['title']);
    $firstContactDate = trim($data['firstContactDate']);
    $number = trim($data['number']);
    $email = trim($data['email']);
    $company = trim($data['company']);
    $position = trim($data['position']);
    $clientCategory = trim($data['clientCategory']);
    $agent = trim($data['agent']) ?: 'N/A';
    $project = trim($data['project']);
    $projectId = trim($data['projectId']);
    $userEmail = trim($data['userEmail']);
    $status = trim($data['status']) ?: 'Pending';
    $contactType = 'Secondary';
    $createdBy = $userEmail;
    $updatedBy = $userEmail;


    $checkStmt = $conn->prepare("SELECT * FROM clients WHERE firstName = ? && lastName = ? && number = ? && projectId = ?");
    
    if (!$checkStmt) {
        throw new Exception("Database error: Failed to prepare check statement", 500);
    }

    $checkStmt->bind_param("ssss", $firstName, $lastName, $number, $projectId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception("A client with the same details already exists!", 400);
    }

    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO clients (firstName, lastName, otherName, title, firstContactDate, number, email, 
    company, position, clientCategory, project, projectId, createdBy, updatedBy, contactType, agent, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("sssssssssssssssss", $firstName, $lastName, $otherName, $title, $firstContactDate, $number, 
    $email, $company, $position, $clientCategory, $project, $projectId, $createdBy, $updatedBy, $contactType, $agent, $status);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => "The client has successfully been created!",
            "data" => [
                "id" => $id,
                "firstName" => $firstName,
                "lastName" => $lastName,
                "otherName" => $otherName,
                "title" => $title,
                "firstContactDate" => $firstContactDate,
                "number" => $number,
                "email" => $email,
                "company" => $company,
                "clientCategory" => $clientCategory,
                "project" => $project,
                "projectId" => $projectId,
                "position" => $position,
                "createdBy" => $createdBy,
                "updatedBy" => $createdBy,
                "contactType" => $contactType,
                "agent" => $agent,
                "status" => $status
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
