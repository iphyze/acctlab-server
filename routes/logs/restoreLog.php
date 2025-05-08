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
            throw new Exception("Unauthorized: Only Admins can restore logs", 401);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['messageIds']) || !is_array($data['messageIds']) || count($data['messageIds']) === 0) {
            throw new Exception("Please select messages to restore first.", 400);
        }

        $messageIds = $data['messageIds'];
        
        // Start transaction
        $conn->begin_transaction();

        try {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            
            // First check if any of these messages already exist in logs table
            $checkDuplicateQuery = "SELECT messageId FROM trash 
                                  WHERE messageId IN (
                                      SELECT id FROM logs
                                  ) AND messageId IN ($placeholders)";
            
            $checkStmt = $conn->prepare($checkDuplicateQuery);
            if (!$checkStmt) {
                throw new Exception("Database error, failed to check duplicates: " . $conn->error, 500);
            }

            $checkStmt->bind_param(str_repeat('i', count($messageIds)), ...$messageIds);
            $checkStmt->execute();
            $duplicateResult = $checkStmt->get_result();

            if ($duplicateResult->num_rows > 0) {
                $duplicateIds = [];
                while ($row = $duplicateResult->fetch_assoc()) {
                    $duplicateIds[] = $row['messageId'];
                }
                throw new Exception("Some logs already exist in the main table. IDs: " . implode(', ', $duplicateIds), 400);
            }

            $checkStmt->close();

            // Restore to logs table
            $restoreQuery = "INSERT INTO logs (
                id, message, title, userId, firstName, lastName,
                email, projectId, projectTitle, clientId, clientName,
                createdAt, updatedAt, createdBy, updatedBy
            )
            SELECT 
                messageId, message, title, userId, firstName, lastName,
                email, projectId, projectTitle, clientId, clientName,
                createdAt, updatedAt, createdBy, updatedBy
            FROM trash 
            WHERE messageId IN ($placeholders)";

            $restoreStmt = $conn->prepare($restoreQuery);
            
            if(!$restoreStmt){
                throw new Exception("Database error, failed to prepare restore: " . $conn->error, 500);
            }

            $restoreStmt->bind_param(str_repeat('i', count($messageIds)), ...$messageIds);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore logs: " . $restoreStmt->error, 500);
            }

            $restoredCount = $restoreStmt->affected_rows;

            // Then delete from trash table
            $deleteQuery = "DELETE FROM trash WHERE messageId IN ($placeholders)";
            $deleteStmt = $conn->prepare($deleteQuery);
            
            if(!$deleteStmt){
                throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
            }

            $deleteStmt->bind_param(str_repeat('i', count($messageIds)), ...$messageIds);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to remove from trash: " . $deleteStmt->error, 500);
            }

            // If we got here, commit the transaction
            $conn->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => "Log(s) have been restored successfully",
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