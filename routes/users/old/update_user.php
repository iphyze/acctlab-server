<?php
include_once('authMiddleware.php'); // Include the auth middleware

// Verify token before proceeding
// verifyToken();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // Check if the user ID is provided in the URL
    if (!preg_match('/\/metaccord\/update\/([0-9]+)/', $_SERVER['REQUEST_URI'], $matches)) {
        echo json_encode(["message" => "Please provide a user ID."]);
        http_response_code(400); // Bad Request
        exit;
    }

    $userId = intval($matches[1]); // Extract user ID from the URL

    // Get the request body
    $data = json_decode(file_get_contents("php://input"), true);

    // Extract fields from the request body
    $fname = $data['fname'] ?? null;
    $lname = $data['lname'] ?? null;
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;
    $confirmPassword = $data['confirmPassword'] ?? null;
    $oldPassword = $data['oldPassword'] ?? null;
    $integrity = $data['integrity'] ?? null;
    $updated_by = $data['updated_by'] ?? null;

    // Validate required fields
    if (!$fname || !$lname || !$email || !$integrity) {
        echo json_encode(["message" => "First name, last name, email, and integrity are required."]);
        http_response_code(400); // Bad Request
        exit;
    }

    // Check if the email format is valid
    $emailRegex = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/'; // Simple email validation regex
    if (!preg_match($emailRegex, $email)) {
        echo json_encode(["message" => "Please provide a valid email address."]);
        http_response_code(400); // Bad Request
        exit;
    }

    // Check if the user exists
    $sqlCheckUser = 'SELECT * FROM user_table WHERE id = ?';
    $stmt = mysqli_prepare($conn, $sqlCheckUser);
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if (!$user) {
        echo json_encode(["message" => "User not found."]);
        http_response_code(404); // Not Found
        exit;
    }

    // Prepare data for updating
    $updatedFields = [
        'fname' => $fname,
        'lname' => $lname,
        'email' => $email,
        'integrity' => $integrity,
        'updated_by' => $updated_by
    ];

    // Handle password update if provided
    if ($password) {
        // Check if old password is provided
        if (!$oldPassword) {
            echo json_encode(["message" => "Old password is required to change the password."]);
            http_response_code(400); // Bad Request
            exit;
        }

        // Verify the old password
        if (!password_verify($oldPassword, $user['password'])) {
            echo json_encode(["message" => "Old password is incorrect."]);
            http_response_code(401); // Unauthorized
            exit;
        }

        // Ensure the new password and confirmation match
        if ($password !== $confirmPassword) {
            echo json_encode(["message" => "Password and confirm password do not match."]);
            http_response_code(400); // Bad Request
            exit;
        }

        // Hash the new password
        $updatedFields['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    // Construct the SQL update query
    $sqlUpdate = "UPDATE user_table SET 
    fname = ?, 
    lname = ?, 
    email = ?, 
    integrity = ?, 
    updated_by = ?" . ($password ? ', password = ?' : '') . 
    " WHERE id = ?";

    // Prepare values array
    $values = [$fname, $lname, $email, $integrity, $updated_by];
    if ($password) {
        $values[] = password_hash($password, PASSWORD_DEFAULT); // Add hashed password if provided
    }
    $values[] = $userId; // User ID is always added at the end

    // Determine the types for binding
    $types = str_repeat('s', count($values) - 1) . 'i'; // All are strings except for userId which is an integer

    // Execute the update query
    $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
    mysqli_stmt_bind_param($stmtUpdate, $types, ...$values);
    if (!mysqli_stmt_execute($stmtUpdate)) {
        echo json_encode(["error" => mysqli_error($conn)]);
        http_response_code(500); // Internal Server Error
        exit;
    }

    // Return updated user data
    echo json_encode([
        "message" => "User details updated successfully.",
        "data" => [
        "id" => $userId,
        "fname" => $fname,
        "lname" => $lname,
        "email" => $email,
        "integrity" => $integrity,
        "updated_by" => $updated_by
    ]
    ]);
    exit; // Exit after sending response
}
else {
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}

// Close connection
// mysqli_close($conn);
?>
