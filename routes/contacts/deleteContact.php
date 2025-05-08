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
            throw new Exception("Unauthorized: Only Admins can move contacts to trash", 401);
        }

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['contactIds']) || !is_array($data['contactIds']) || count($data['contactIds']) === 0) {
            throw new Exception("Please select a contact first.", 400);
        }

        $contactIds = $data['contactIds'];

        // If not Super_Admin, check creation time of contacts
        if ($loggedInUserRole !== 'Super_Admin') {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $checkStmt = $conn->prepare("SELECT id, createdAt FROM contacts WHERE id IN ($placeholders)");
            
            if (!$checkStmt) {
                throw new Exception("Database error, failed to check contacts: " . $conn->error, 500);
            }

            $checkStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            $checkStmt->execute();
            $result = $checkStmt->get_result();

            $now = new DateTime('now', new DateTimeZone('Africa/Lagos'));
            $oldContacts = [];

            while ($contact = $result->fetch_assoc()) {
                $createdAt = new DateTime($contact['createdAt'], new DateTimeZone('Africa/Lagos'));
                $timeDiff = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

                if ($timeDiff > 5) {
                    $oldContacts[] = $contact['id'];
                }
            }

            $checkStmt->close();

            if (!empty($oldContacts)) {
                throw new Exception("Cannot move contacts older than 5 minutes to trash", 400);
            }
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // First, copy the contacts to contact_trash
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            
            // Insert into contact_trash table
            $insertQuery = "INSERT INTO contact_trash (
                organization, representative, tel, email, 
                projectId, projectTitle, categoryId, categoryName, 
                comment, status, createdAt, updatedAt, createdBy, updatedBy, contactType, contactId
            ) 
            SELECT 
                organization, representative, tel, email,
                projectId, projectTitle, categoryId, categoryName,
                comment, status, createdAt, updatedAt, createdBy, updatedBy, contactType, id
            FROM contacts 
            WHERE id IN ($placeholders)";

            $insertStmt = $conn->prepare($insertQuery);
            
            if(!$insertStmt){
                throw new Exception("Database error, failed to prepare insert: " . $conn->error, 500);
            }

            $insertStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to move contacts to trash: " . $insertStmt->error, 500);
            }

            $movedCount = $insertStmt->affected_rows;

            // Then delete from contacts table
            $deleteQuery = "DELETE FROM contacts WHERE id IN ($placeholders)";
            $deleteStmt = $conn->prepare($deleteQuery);
            
            if(!$deleteStmt){
                throw new Exception("Database error, failed to prepare delete: " . $conn->error, 500);
            }

            $deleteStmt->bind_param(str_repeat('i', count($contactIds)), ...$contactIds);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to delete original contacts: " . $deleteStmt->error, 500);
            }

            // If we got here, commit the transaction
            $conn->commit();

            http_response_code(200);
            echo json_encode([
                "status" => "Success",
                "message" => "Contact(s) have been moved to trash successfully",
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