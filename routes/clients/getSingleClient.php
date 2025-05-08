
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
        $loggedInUserRole = $userData['role'];
        
        if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can create logs", 401);
        }

        if(!intval($_GET['params'])){
            throw new Exception("ClientId must be an integer", 400);
        }

        $clientId = $_GET['params'];

        $check = $conn->prepare("SELECT * FROM clients WHERE id = ?");

        if(!$check){
            throw new Exception("Failed to prepare statemeent", 400);
        }

        $check->bind_param('s', $clientId);
        $check->execute();
        $checkResult = $check->get_result();
        $numResult = $checkResult->num_rows;


        if($numResult === 0){
            throw new Exception("No results found for " . $clientId, 500);
        }else{
            $clients = $checkResult->fetch_assoc();
        }
        
        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Client has been fetched successfully!",
            "data" => $clients
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
