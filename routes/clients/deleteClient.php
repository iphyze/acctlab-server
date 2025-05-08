<?php
    require 'vendor/autoload.php';
    require_once 'includes/connection.php';
    require_once 'includes/authMiddleware.php';
    
    header('Content-Type: application/json');
    date_default_timezone_set('Africa/Lagos');
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            throw new Exception("Route not found", 400);
        }
    
        // Check if the user is authenticated
        $userData = authenticateUser();
        $loggedInUserId = $userData['id'];
        $loggedInUserRole = $userData['role'];
        
        if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can move clients to trash", 401);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['clientIds']) || !is_array($data['clientIds']) || count($data['clientIds']) === 0) {
            throw new Exception("Please select a client first.", 400);
        }

        $clientIds = $data['clientIds'];

        // If not Super_Admin, check creation time of clients
        if ($loggedInUserRole !== 'Super_Admin') {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $checkStmt = $conn->prepare("SELECT id, createdAt FROM clients WHERE id IN ($placeholders)");
            
            if (!$checkStmt) {
                throw new Exception("Database error, failed to check clients: " . $conn->error, 500);
            }

            $checkStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            $now = new DateTime('now', new DateTimeZone('Africa/Lagos'));
            $oldClients = [];

            while ($client = $result->fetch_assoc()) {
                $createdAt = new DateTime($client['createdAt'], new DateTimeZone('Africa/Lagos'));
                $timeDiff = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

                if ($timeDiff > 5) {
                    $oldClients[] = $client['id'];
                }
            }

            $checkStmt->close();

            if (!empty($oldClients)) {
                throw new Exception("Cannot move clients older than 5 minutes to trash", 400);
            }
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // First, copy the clients to client_trash
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            
            // Insert into client_trash table
            $insertQuery = "INSERT INTO client_trash (
                firstName, lastName, otherName, title, 
                firstContactDate, number, email, company, 
                position, contactType, createdAt, updatedAt, 
                createdBy, updatedBy, clientCategory, project, 
                projectId, agent, status, clientId
            ) 
            SELECT 
                firstName, lastName, otherName, title,
                firstContactDate, number, email, company,
                position, contactType, createdAt, updatedAt,
                createdBy, updatedBy, clientCategory, project,
                projectId, agent, status, id
            FROM clients 
            WHERE id IN ($placeholders)";

            $insertStmt = $conn->prepare($insertQuery);
            
            if(!$insertStmt){
                throw new Exception("Database error, failed to prepare insert: " . $conn->error, 500);
            }

            $insertStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to move clients to trash: " . $insertStmt->error, 500);
            }

            $movedCount = $insertStmt->affected_rows;

            // Then delete from clients table
            $deleteQuery = "DELETE FROM clients WHERE id IN ($placeholders)";
            $deleteStmt = $conn->prepare($deleteQuery);
            
            if(!$deleteStmt){
                throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
            }

            $deleteStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to delete original clients: " . $deleteStmt->error, 500);
            }

            // If we got here, commit the transaction
            $conn->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => "Client(s) have been moved to trash successfully",
                "movedCount" => $movedCount,
                "userRole" => $loggedInUserRole
            ]);

            $insertStmt->close();
            $deleteStmt->close();

        } catch (Exception $e) {
            // If anything went wrong, rollback the transaction
            $conn->rollback();
            throw $e;
        }
        
    } catch(Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code($e->getCode() ?: 500);
        echo json_encode([
            "status" => "Failed",
            "message" => $e->getMessage()
        ]);
    }
?>