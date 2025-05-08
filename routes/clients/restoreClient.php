<?php
    require 'vendor/autoload.php';
    require_once 'includes/connection.php';
    require_once 'includes/authMiddleware.php';
    
    header('Content-Type: application/json');
    date_default_timezone_set('Africa/Lagos');
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Route not found", 400);
        }
    
        // Check if the user is authenticated
        $userData = authenticateUser();
        $loggedInUserId = $userData['id'];
        $loggedInUserRole = $userData['role'];
        
        if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin') {
            throw new Exception("Unauthorized: Only Admins can restore clients", 401);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['clientIds']) || !is_array($data['clientIds']) || count($data['clientIds']) === 0) {
            throw new Exception("Please select clients to restore first.", 400);
        }

        $clientIds = $data['clientIds'];
        
        // Start transaction
        $conn->begin_transaction();

        try {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            
            // First check if any of these clients already exist in clients table
            $checkDuplicateQuery = "SELECT clientId FROM client_trash 
                                  WHERE clientId IN (
                                      SELECT id FROM clients
                                  ) AND clientId IN ($placeholders)";
            
            $checkStmt = $conn->prepare($checkDuplicateQuery);
            if (!$checkStmt) {
                throw new Exception("Database error, failed to check duplicates: " . $conn->error, 500);
            }

            $checkStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            $checkStmt->execute();
            $duplicateResult = $checkStmt->get_result();

            if ($duplicateResult->num_rows > 0) {
                $duplicateIds = [];
                while ($row = $duplicateResult->fetch_assoc()) {
                    $duplicateIds[] = $row['clientId'];
                }
                throw new Exception("Some clients already exist in the main table. IDs: " . implode(', ', $duplicateIds), 400);
            }

            $checkStmt->close();

            // Restore to clients table
            $restoreQuery = "INSERT INTO clients (
                id, firstName, lastName, otherName, title,
                firstContactDate, number, email, company,
                position, contactType, createdAt, updatedAt,
                createdBy, updatedBy, clientCategory, project,
                projectId, agent, status
            )
            SELECT 
                clientId, firstName, lastName, otherName, title,
                firstContactDate, number, email, company,
                position, contactType, createdAt, updatedAt,
                createdBy, updatedBy, clientCategory, project,
                projectId, agent, status
            FROM client_trash 
            WHERE clientId IN ($placeholders)";

            $restoreStmt = $conn->prepare($restoreQuery);
            
            if(!$restoreStmt){
                throw new Exception("Database error, failed to prepare restore: " . $conn->error, 500);
            }

            $restoreStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore clients: " . $restoreStmt->error, 500);
            }

            $restoredCount = $restoreStmt->affected_rows;

            // Then delete from client_trash table
            $deleteQuery = "DELETE FROM client_trash WHERE clientId IN ($placeholders)";
            $deleteStmt = $conn->prepare($deleteQuery);
            
            if(!$deleteStmt){
                throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
            }

            $deleteStmt->bind_param(str_repeat('i', count($clientIds)), ...$clientIds);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to remove from trash: " . $deleteStmt->error, 500);
            }

            // If we got here, commit the transaction
            $conn->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => "Client(s) have been restored successfully",
                "restoredCount" => $restoredCount,
                "userRole" => $loggedInUserRole
            ]);

            $restoreStmt->close();
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