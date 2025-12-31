<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
use Respect\Validation\Validator as v;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();


require_once 'utils/email_template.php';



header("Content-Type: application/json");


$userData = authenticateUser();  // This will stop execution if unauthorized


function sendPasswordUpdateEmail($to, $firstName) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        // $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPSecure = 'ssl';
        $mail->Port = $_ENV['SMTP_PORT'];
        $mail->setFrom($_ENV['SMTP_USER'], 'Mahjong Nigeria Clinic');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Password Update Notification';
        $mail->Body = passwordUpdateTemplate($firstName);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error sending email: " . $mail->ErrorInfo);
        return false;
    }
}


if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}

// Get the request body
$input = json_decode(file_get_contents("php://input"), true);


$id = $input['id'] ?? null;
$currentPassword = $input['currentPassword'] ?? null;
$newPassword = $input['newPassword'] ?? null;


$userIdFromToken = $userData['id'] ?? null;



if(!$id){
    http_response_code(400);
    echo json_encode(["message" => "User's ID is required"]);
    exit;
}


// Ensure the user can only update their own password
if ($id !== $userIdFromToken) {
    http_response_code(403);
    echo json_encode(["message" => "Forbidden: You can only update your own password!"]);
    exit;
}


$passwordValidator = v::stringType()->length(6, null);

// Validate ID
if (!v::intVal()->validate($id)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid user ID"]);
    exit;
}


if (!$passwordValidator->validate($currentPassword)) {
    http_response_code(400);
    echo json_encode(["message" => "Password must be at least 6 characters long"]);
    exit;
}

if (!$passwordValidator->validate($newPassword)) {
    http_response_code(400);
    echo json_encode(["message" => "New Password must be at least 6 characters long"]);
    exit;
}


if (!$currentPassword || !$newPassword) {
    http_response_code(400);
    echo json_encode(["message" => "All fields are required"]);
    exit;
}

$query = "SELECT * FROM users WHERE id = ?"; $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["message" => "User not found"]);
        exit;
    }

    $user = $result->fetch_assoc();
    
    if (!password_verify($currentPassword, $user['password'])) {
        http_response_code(400);
        echo json_encode(["message" => "Current password is incorrect"]);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateQuery = "UPDATE users SET password = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("si", $hashedPassword, $id);

    if ($updateStmt->execute()) {
        sendPasswordUpdateEmail($user['email'], $user['firstName']);
        echo json_encode(["message" => "Password updated successfully. A notification has been sent to your email."]);
    } else {
        echo json_encode(["message" => "Database error while updating password"]);
    }


$stmt->close();
$updateStmt->close();
$conn->close();


?>