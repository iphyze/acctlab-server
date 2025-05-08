
<?php
    
    require 'vendor/autoload.php';
    require_once 'includes/connection.php';
    require_once 'includes/authMiddleware.php';
    
    header('Content-Type: application/json');
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new Exception("Route not found", 400);
        }
    
        // Check if the user is authenticated
        $userData = authenticateUser();
        $loggedInUserId = $userData['id'];
        $loggedInUserIntegrity = $userData['integrity'];
        $loggedInUserEmail = $userData['email'];
        
        if($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can fetch payment", 401);
        }

        if(!intval($_GET['params'])){
            throw new Exception("requestId must be an integer", 400);
        }

        $requestId = $_GET['params'];

        $check = $conn->prepare("SELECT * FROM advance_payment_request WHERE id = ?");

        if(!$check){
            throw new Exception("Failed to prepare statemeent", 400);
        }

        $check->bind_param('i', $requestId);
        $check->execute();
        $checkResult = $check->get_result();
        $numResult = $checkResult->num_rows;


        if($numResult === 0){
            throw new Exception("No results found for " . $requestId, 404);
        }else{
            $advance_payment_request = $checkResult->fetch_assoc();
        }
        
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Request has been fetched successfully!",
            "data" => $advance_payment_request
        ]);
    
        $check->close();
        
    } catch(Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code($e->getCode() ?: 500);
        echo json_encode([
            "status" => "Failed",
            "message" => $e->getMessage()
        ]);
    }
?>
