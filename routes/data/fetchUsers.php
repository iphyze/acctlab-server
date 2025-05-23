
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


        $get = $conn->prepare("SELECT * FROM user_table ORDER BY fname ASC");
        
        if(!$get){
            throw new Exception("Failed to prepare statement: " . $conn->error, 500);
        }

        $get->execute();
        $result = $get->get_result();
        $rawUsers = $result->fetch_all(MYSQLI_ASSOC);
        
        $users = array_map(function($user) {
        return [
            'id' => $user['id'],
            'fname' => $user['fname'],
            'lname' => $user['lname'],
            'email' => $user['email'],
            'integrity' => $user['integrity']
        ];
        }, $rawUsers);


        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Users have been fetched successfully!",
            "data" => $users
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
