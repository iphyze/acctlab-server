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

        if(!isset($_GET['params']) || !intval($_GET['params'])){
            throw new Exception("PaymentId must be an integer", 400);
        }

        $paymentId = $_GET['params'];

        // Updated table name and column name (userId -> user_id)
        $check = $conn->prepare("SELECT * FROM union_payment_schedule WHERE id = ? AND user_id = ?");

        if(!$check){
            throw new Exception("Failed to prepare statement", 500);
        }

        $check->bind_param('ii', $paymentId, $loggedInUserId);
        $check->execute();
        $checkResult = $check->get_result();
        $numResult = $checkResult->num_rows;


        if($numResult === 0){
            throw new Exception("No results found for " . $paymentId, 404);
        }else{
            $payment_schedule = $checkResult->fetch_assoc();
        }
        
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Payment has been fetched successfully!",
            "data" => $payment_schedule
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