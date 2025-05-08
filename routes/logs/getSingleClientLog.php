<?php

use Respect\Validation\Rules\Length;

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

    
    $clientId = $_GET['params'][0];

    if (!is_numeric($clientId)) {
        throw new Exception("Valid user ID is required", 400);
    }


    if(!isset($_GET['params'][1]) || empty($_GET['params'][1])){
        throw new Exception("ContactType is required", 400);
    }else{
        $contactType = $_GET['params'][1];
    }


    $clientId = intval($clientId);


    $query = $conn->prepare("SELECT * FROM logs WHERE clientId = ? && contactType = ? ORDER BY createdAt DESC");
    
    if (!$query) {
        throw new Exception("Database error: Failed to prepare statement", 500);
        exit;
    }

    $query->bind_param("is", $clientId, $contactType);
    $query->execute();

    $result = $query->get_result();

    $num = $result->num_rows;

        $logs = $result->fetch_all(MYSQLI_ASSOC);
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => $num > 0 ? "Logs retrieved successfully!" : "No logs found for this client!",
            "data" => $logs,
        ]);

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
