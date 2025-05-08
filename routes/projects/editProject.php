
<?php
    
    require 'vendor/autoload.php';
    require_once 'includes/connection.php';
    require_once 'includes/authMiddleware.php';
    
    header('Content-Type: application/json');
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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
        
        if (!isset($data['projectId'])) {
            throw new Exception("projectId is required", 400);
        }
    
        if (!intval($data['projectId'])) {
            throw new Exception("projectId must be a number", 400);
        }
    
        if (!isset($data['name'])) {
            throw new Exception("name field is required", 400);
        }
    
        if (!isset($data['userEmail'])) {
            throw new Exception("userEmail field is required", 400);
        }

        
        $projectId = intval(trim($data['projectId']));
        $name = trim($data['name']);
        $description = trim($data['description']);
        $userEmail = trim($data['userEmail']);
        $updatedBy = $userEmail;

        date_default_timezone_set('UTC');
        $datetime = new DateTime('now', new DateTimeZone('Africa/Lagos'));
        $updatedAt = $datetime->format('Y-m-d H:i:s');
        
        // Check if project exists
        $checkId = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $checkId->bind_param("i", $projectId);
        $checkId->execute();
        $checkIdResult = $checkId->get_result();
    
        if ($checkIdResult->num_rows === 0) {
            throw new Exception("Project not found", 404);
        }
    
        // Check for duplicate name
        $checkStmt = $conn->prepare("SELECT * FROM projects WHERE name = ? && id != ?");
        $checkStmt->bind_param("si", $name, $projectId);
        $checkStmt->execute();
        $checkStmtResult = $checkStmt->get_result();
    
        if ($checkStmtResult->num_rows > 0) {
            throw new Exception($name . " already exists as project.", 400);
        }
    
        // Update project
        $stmt = $conn->prepare("UPDATE projects SET name = ?, description = ?, updatedBy = ?, updatedAt = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $description, $updatedBy, $updatedAt, $projectId);
        
        if (!$stmt) {
            throw new Exception("Database error: Failed to prepare statement", 500);
        }
        
        if ($stmt->execute()) {
            // Fetch the updated data with a new query
            $selectStmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
            $selectStmt->bind_param("i", $projectId);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $updatedData = $result->fetch_assoc();
            
            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => $name . " has been updated successfully!",
                "data" => [
                    "id" => $updatedData['id'],
                    "name" => $updatedData['name'],
                    "description" => $updatedData['description'],
                    "createdBy" => $updatedData['createdBy'],
                    "updatedBy" => $updatedData['updatedBy'],
                    "updatedAt" => $updatedData['updatedAt'],
                ],
            ]);
            
            $selectStmt->close();
        } else {
            throw new Exception("Failed to update project!", 500);
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
