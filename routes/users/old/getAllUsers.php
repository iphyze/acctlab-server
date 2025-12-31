<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}


// Authenticate user
$userData = authenticateUser();
$loggedInUserRole = $userData['role'];

// Ensure the user is an Admin
if ($loggedInUserRole !== "Admin") {
    http_response_code(403);
    echo json_encode(["message" => "Unauthorized, Access denied!"]);
    exit;
}

// Fetch all users
$query = "SELECT * FROM users";
$result = $conn->query($query);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        "message" => "Database error",
        "error" => $conn->error
    ]);
    exit;
}

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["message" => "No users found"]);
    exit;
}

$users = [];

while ($user = $result->fetch_assoc()) {
    $users[] = [
        "id" => $user["id"],
        "firstName" => $user["firstName"],
        "lastName" => $user["lastName"],
        "email" => $user["email"],
        "userName" => $user["userName"],
        "image" => $user["image"],
        "skillLevel" => $user["skillLevel"],
        "isEmailVerified" => $user["isEmailVerified"],
        "emailVerification" => [
            "emailCode" => $user["emailCode"],
            "expiresAt" => $user["expiresAt"]
        ],
        "payments" => [
            "membership" => [
                "membershipPayment" => $user["membershipPayment"],
                "membershipPaymentAmount" => $user["membershipPaymentAmount"],
                "membershipPaymentDate" => $user["membershipPaymentDate"],
                "membershipPaymentDuration" => $user["membershipPaymentDuration"],
            ],
            "tutorship" => [
                "tutorshipPayment" => $user["tutorshipPayment"],
                "tutorshipPaymentAmount" => $user["tutorshipPaymentAmount"],
                "tutorshipPaymentDate" => $user["tutorshipPaymentDate"],
                "tutorshipPaymentDuration" => $user["tutorshipPaymentDuration"],
            ]
        ],
        "role" => $user["role"],
        "country_code" => $user["country_code"],
        "number" => $user["number"],
        "createdAt" => $user["createdAt"],
        "updatedBy" => $user["updatedBy"],
    ];
}

// Respond with users list
http_response_code(200);
echo json_encode([
    "message" => "Users retrieved successfully",
    "data" => $users
]);

$conn->close();
?>
