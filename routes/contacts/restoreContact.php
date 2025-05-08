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
            throw new Exception("Unauthorized: Only Admins can restore contacts", 401);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['contactIds']) || !is_array($data['contactIds']) || count($data['contactIds']) === 0) {
            throw new Exception("Please select contacts to restore first.", 400);
        }

        $contactIds = $data['contactIds'];
        
        // Start transaction
        $conn->begin_transaction();

        try {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            
            // First check if any of these contacts already exist in contacts table
            $checkDuplicateQuery = "SELECT contactId FROM contact_trash 
                                  WHERE contactId IN (
                                      SELECT id FROM contacts
                                  ) AND contactId IN ($placeholders)";
            
            $checkStmt = $conn->prepare($checkDuplicateQuery);
            if (!$checkStmt) {
                throw new Exception("Database error, failed to check duplicates: " . $conn->error, 500);
            }

            $checkStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            $checkStmt->execute();
            $duplicateResult = $checkStmt->get_result();

            if ($duplicateResult->num_rows > 0) {
                $duplicateIds = [];
                while ($row = $duplicateResult->fetch_assoc()) {
                    $duplicateIds[] = $row['contactId'];
                }
                throw new Exception("Some contacts already exist in the main table. IDs: " . implode(', ', $duplicateIds), 400);
            }

            $checkStmt->close();

            // Restore to contacts table
            $restoreQuery = "INSERT INTO contacts (
                id, organization, representative, tel, email,
                projectId, projectTitle, categoryId, categoryName,
                comment, status, createdAt, updatedAt, createdBy, updatedBy, contactType
            )
            SELECT 
                contactId, organization, representative, tel, email,
                projectId, projectTitle, categoryId, categoryName,
                comment, status, createdAt, updatedAt, createdBy, updatedBy, contactType
            FROM contact_trash 
            WHERE contactId IN ($placeholders)";

            $restoreStmt = $conn->prepare($restoreQuery);
            
            if(!$restoreStmt){
                throw new Exception("Database error, failed to prepare restore: " . $conn->error, 500);
            }

            $restoreStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore contacts: " . $restoreStmt->error, 500);
            }

            $restoredCount = $restoreStmt->affected_rows;

            // Then delete from contact_trash table
            $deleteQuery = "DELETE FROM contact_trash WHERE contactId IN ($placeholders)";
            $deleteStmt = $conn->prepare($deleteQuery);
            
            if(!$deleteStmt){
                throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
            }

            $deleteStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to remove from trash: " . $deleteStmt->error, 500);
            }

            // If we got here, commit the transaction
            $conn->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => "Contact(s) have been restored successfully",
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