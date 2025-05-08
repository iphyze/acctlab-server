
<?php
    
    require 'vendor/autoload.php';
    require_once 'includes/connection.php';
    require_once 'includes/authMiddleware.php';
    
    header('Content-Type: application/json');
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            throw new Exception("Route not found", 400);
        }
    
        // Check if the user is authenticated
        $userData = authenticateUser();
        $loggedInUserId = $userData['id'];
        $loggedInUserRole = $userData['role'];
        
        if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can create logs", 401);
        }


        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['projectIds']) || !is_array($data['projectIds']) || count($data['projectIds']) === 0) {
            throw new Exception("Please select a project to delete first.", 400);
        }

        $projectIds = $data['projectIds'];
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        $query = "DELETE FROM projects WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($query);
        
        if(!$stmt){
            throw new Exception("Database error, failed to delete: " . $conn->error, 500);
        }

        $stmt->bind_param(str_repeat('i', count($projectIds)), ...$projectIds);


        if ($stmt->execute()) {
            if ($stmt->affected_rows === 0) {
                throw new Exception("No projects found with the provided IDs: " . $conn->error, 404);
            } else {
                http_response_code(200);
                echo json_encode([
                    "status" => "Success",
                    "message" => "projects have been deleted successfully",
                    "deletedCount" => $stmt->affected_rows
                ]);
            }
        } else {
            throw new Exception("Database error " . $stmt->error, 500);
        }
    
        $stmt->close();
        
    } catch(Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code($e->getCode() ?: 500);
        echo json_encode([
            "status" => "Failed",
            "message" => $e->getMessage()
        ]);
    }
?>
