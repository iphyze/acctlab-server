<?php

require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php'; // Database connection
use Respect\Validation\Validator as v;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();


header('Content-Type: application/json');


try{

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(400);
        echo json_encode(["message" => "Route not found"]);
        exit;
    }
    
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['firstName'], $data['lastName'], $data['email'], $data['password'], $data['role'], $data['userEmail'])) {
        throw new Exception("All fields are required", 400);
    }
    
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim(strtolower($data['email']));
    $password = trim($data['password']);
    $role = trim($data['role']);
    $userEmail = trim($data['userEmail']);
    
    
    // Validation
    $emailValidator = v::email()->notEmpty();
    $passwordValidator = v::stringType()->length(6, null);
    if (!$emailValidator->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }
    if (!$passwordValidator->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }
    
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $role = 'Admin';
    
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        throw new Exception("User already exists", 400);
    }
    
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (firstName, lastName, email, password, role, createdBy, updatedBy) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    // Make sure the number of placeholders matches the number of bind parameters
    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $hashedPassword, $role, $userEmail, $userEmail);
    
    
    if (!$stmt) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }
    
    
    if ($stmt->execute()) {
        $userId = $stmt->insert_id;
    
        $secretKey = $_ENV["JWT_SECRET"] ?: "your_default_secret";
        $payload = [
            "userId" => $userId,
            "email" => $email,
            "role" => $role,
            "exp" => time() + ($_ENV["JWT_EXPIRES_IN"] ?: 5 * 24 * 60 * 60)
        ];
        $token = JWT::encode($payload, $secretKey, 'HS256');
        
            http_response_code(200);
            echo json_encode([
            "status" => "Success",
            "message" => "The user has been created successfully",
            "data" => [
                "id" => $userId,
                "firstName" => $firstName,
                "lastName" => $lastName,
                "email" => $email,
                "role" => $role,
                "token" => $token,
                "createdBy" => $userEmail,
                "updatedBy" => $userEmail,
            ],
        ]);
    
    } else {
        throw new Exception("Failed to register user", 500);
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
