<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

try {
    // Accept both PUT and POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(["status" => "Failed", "message" => "Method not allowed. Use PUT or POST"]);
        exit;
    }

    // Handle both JSON and FormData input
    $inputData = [];
    if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $inputData = json_decode(file_get_contents("php://input"), true) ?? [];
    } else {
        $inputData = $_POST;
    }

    // Validate userId from request body
    if (empty($inputData['userId']) || !filter_var($inputData['userId'], FILTER_VALIDATE_INT)) {
        throw new Exception("Invalid or missing userId.");
    }

    $userId = intval($inputData['userId']);

    // Prevent unauthorized access
    if ($loggedInUserRole !== "Admin" && $userId !== $loggedInUserId) {
        throw new Exception("Access denied. You can only update your own profile.");
    }

    // Prepare update data
    $updateData = [];
    $uploadPath = 'utils/imageUploads/mahjong-uploads/';
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/avif'];

    // Check if image upload is requested
    $isImageUpdate = isset($_SERVER['HTTP_X_UPDATE_IMAGE']) && $_SERVER['HTTP_X_UPDATE_IMAGE'] === 'true';

    // Check if an image is uploaded
    if ($isImageUpdate && !empty($_FILES['image'])) {
        $imageFile = $_FILES['image'];

        // Validate file type
        if (!in_array($imageFile['type'], $allowedTypes)) {
            throw new Exception("Only JPEG, JPG, PNG, WEBP, and AVIF files are allowed!");
        }

        // Ensure upload directory exists
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        // Generate a unique file name
        $fileExtension = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
        $uniqueFileName = "profile_{$userId}_" . time() . ".$fileExtension";
        $fileDestination = $uploadPath . $uniqueFileName;

        // Move file to upload directory
        if (!move_uploaded_file($imageFile['tmp_name'], $fileDestination)) {
            throw new Exception("File upload failed.");
        }

        // Store image URL for database update
        $imageUrl = "https://mahjong-db.goldenrootscollectionsltd.com/imageUploads/mahjong-uploads/" . $uniqueFileName;
        // $updateData['image'] = $imageUrl;
        $updateData['image'] = $uniqueFileName;
    }

    // Process text updates
    if (!empty($inputData['firstName'])) {
        $updateData['firstName'] = trim($inputData['firstName']);
    }
    if (!empty($inputData['lastName'])) {
        $updateData['lastName'] = trim($inputData['lastName']);
    }

    // Ensure there's data to update
    if (empty($updateData)) {
        throw new Exception("No valid fields to update.");
    }

    // Prepare SQL query dynamically
    $setClause = implode(", ", array_map(fn($key) => "$key = ?", array_keys($updateData)));
    $sql = "UPDATE users SET $setClause WHERE id = ?";

    // Prepare and bind parameters
    $stmt = $conn->prepare($sql);
    $types = str_repeat("s", count($updateData)) . "i";
    $values = array_values($updateData);
    $values[] = $userId;
    $stmt->bind_param($types, ...$values);

    // Execute the update
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Profile updated successfully!",
            "user" => $updateData
        ]);
    } else {
        throw new Exception("Database error: " . $stmt->error);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
}