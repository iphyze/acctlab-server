<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';


header('Content-Type: application/json');


try{


    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }


    // Check if the user is authenticated
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];
    

    if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
        throw new Exception("Unauthorized: Only Admins can create logs", 401);
    }


    $query = $conn->prepare("SELECT * FROM logs ORDER BY createdAt DESC");
    
    if (!$query) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }

    $query->execute();

    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $logs = $result->fetch_all(MYSQLI_ASSOC);

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Logs retrieved successfully!",
            "data" => $logs,
        ]);
    } else {
        throw new Exception("No logs found for this client", 404);
    }

    $query->close();
    

}catch(Exception $e){
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
