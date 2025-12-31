<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");



// Ensure the request method is GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}


// // Ensure params exist
// if (!isset($_GET['params'])) {
//     http_response_code(400);
//     echo json_encode(["message" => "Missing user ID in request"]);
//     exit;
// }

// Ensure 'params' is a valid numeric ID
$userId = $_GET['params'];
if (!is_numeric($userId)) {
    http_response_code(400);
    echo json_encode(["message" => "Valid user ID is required"]);
    exit;
}

$userId = intval($userId); // Convert to integer

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

// Prevent unauthorized access
if ($loggedInUserRole !== "Admin" && $userId !== $loggedInUserId) {
    http_response_code(403);
    echo json_encode(["message" => "Access denied. You can only view your own details."]);
    exit;
}

// Fetch user from database
$query = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["message" => "User not found"]);
    exit;
}

$user = $result->fetch_assoc();

// Format the response in the required pattern
$formattedUser = [
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

// Respond with formatted user data
http_response_code(200);
echo json_encode([
    "message" => "User retrieved successfully",
    "data" => $formattedUser
]);

$stmt->close();
$conn->close();
?>
