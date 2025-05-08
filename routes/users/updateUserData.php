<?php

require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php'; // Database connection
use Respect\Validation\Validator as v;
require_once 'includes/authMiddleware.php';



header("Content-Type: application/json");

$userData = authenticateUser();  // This will stop execution if unauthorized


if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}

// Get the request body
$input = json_decode(file_get_contents("php://input"), true);


// // Ensure params exist
// if (!isset($_GET['params'])) {
//     http_response_code(400);
//     echo json_encode(["message" => "Missing user ID in request"]);
//     exit;
// }


// // Ensure 'params' is a valid numeric ID
// $id = $_GET['params'];
// if (!is_numeric($id)) {
//     http_response_code(400);
//     echo json_encode(["message" => "Valid user ID is required"]);
//     exit;
// }

// Extract user ID from the request parameters
// $id = intval($id);

$id = $input['id'] ?? null;
$firstName = $input['firstName'] ?? null;
$lastName = $input['lastName'] ?? null;
$email = $input['email'] ?? null;
$country_code = $input['country_code'] ?? null;
$number = $input['number'] ?? null;




if(!$id){
    http_response_code(400);
    echo json_encode(["message" => "User's ID is required"]);
    exit;
}

// Validate ID
if (!v::intVal()->validate($id)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid user ID"]);
    exit;
}

// Validation
$emailValidator = v::email()->notEmpty();
if (!$emailValidator->validate($email)) {
    http_response_code(405);
    echo json_encode(["message" => "Invalid email format"]);
    exit;
}

// Check if at least one field is provided for update
if (!$firstName && !$lastName && !$email && !$country_code && !$number) {
    http_response_code(400);
    echo json_encode(["message" => "No fields provided for update"]);
    exit;
}

// Check if email or number is already in use by another user
$stmt = $conn->prepare("SELECT * FROM users WHERE (email = ? OR (number = ? AND country_code = ?)) AND id != ?");
$stmt->bind_param("sssi", $email, $number, $country_code, $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["message" => "Number already taken by another user."]);
    exit;
}


// Prepare update fields dynamically
$updateFields = [];
$updateValues = [];

if ($firstName) {
    $updateFields[] = "firstName = ?";
    $updateValues[] = $firstName;
}
if ($lastName) {
    $updateFields[] = "lastName = ?";
    $updateValues[] = $lastName;
}
if ($email) {
    $updateFields[] = "email = ?";
    $updateValues[] = $email;
}
if ($country_code) {
    $updateFields[] = "country_code = ?";
    $updateValues[] = $country_code;
}
if ($number) {
    $updateFields[] = "number = ?";
    $updateValues[] = $number;
}


// Ensure there are fields to update
if (count($updateFields) > 0) {
    $updateQuery = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $updateValues[] = $id;
    $stmt->bind_param(str_repeat("s", count($updateValues) - 1) . "i", ...$updateValues);

    if ($stmt->execute()) {
        // Retrieve updated user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        http_response_code(200);
        echo json_encode([
            "message" => "User details updated successfully",
            "data" => $user
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["message" => "Database error", "error" => $stmt->error]);
    }
} else {
    http_response_code(400);
    echo json_encode(["message" => "No valid fields provided for update"]);
}


$stmt->close();
$updateStmt->close();
$conn->close();


?>