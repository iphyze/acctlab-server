
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
        
        if($loggedInUserIntegrity !== 'Admin' && $loggedInUserIntegrity !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can create logs", 401);
        }


        $get = $conn->prepare("SELECT * FROM other_payment_schedule WHERE userId = $loggedInUserId ORDER BY created_at DESC LIMIT 1000");
        
        if(!$get){
            throw new Exception("Failed to prepare statement: " . $conn->error, 500);
        }

        $get->execute();
        $result = $get->get_result();
        $payments = $result->fetch_all(MYSQLI_ASSOC);
        
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Payments have been fetched successfully!",
            "data" => $payments
        ]);
    
        $get->close();
        
    } catch(Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code($e->getCode() ?: 500);
        echo json_encode([
            "status" => "Failed",
            "message" => $e->getMessage()
        ]);
    }
?>
