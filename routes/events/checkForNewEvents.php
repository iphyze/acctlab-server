<?php
function checkForNewEvents($conn, $lastEventTime) {
    $events = [];
    
    try {
        // Start transaction
        $conn->begin_transaction();

        // Get unprocessed events with row locking
        $eventQuery = $conn->prepare("
            SELECT id, type, data, created_at 
            FROM events 
            WHERE created_at > ? 
            AND processed = FALSE
            ORDER BY created_at ASC
            LIMIT 10
            FOR UPDATE
        ");
        
        if (!$eventQuery) {
            throw new Exception("Failed to prepare event query: " . $conn->error);
        }

        $eventQuery->bind_param("s", $lastEventTime);
        
        if (!$eventQuery->execute()) {
            throw new Exception("Failed to execute event query: " . $eventQuery->error);
        }

        $result = $eventQuery->get_result();

        while ($event = $result->fetch_assoc()) {
            // Immediately mark event as processed
            $updateStmt = $conn->prepare("
                UPDATE events 
                SET processed = TRUE,
                    processed_at = NOW()
                WHERE id = ?
            ");

            if (!$updateStmt) {
                throw new Exception("Failed to prepare update statement: " . $conn->error);
            }

            $updateStmt->bind_param("i", $event['id']);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to mark event as processed: " . $updateStmt->error);
            }

            $updateStmt->close();

            // Add to events array
            $events[] = [
                'type' => $event['type'],
                'data' => json_decode($event['data'], true)
            ];

            // Update last event time if needed
            if (isset($event['created_at'])) {
                $lastEventTime = $event['created_at'];
            }

            // Log successful processing
            error_log("Event processed successfully - ID: {$event['id']}, Type: {$event['type']}");
        }

        // Commit transaction
        $conn->commit();
        
        $eventQuery->close();

    } catch (Exception $e) {
        // Rollback on error
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
        
        error_log("Error processing events: " . $e->getMessage());
        
        if (isset($eventQuery)) {
            $eventQuery->close();
        }
        if (isset($updateStmt)) {
            $updateStmt->close();
        }
    }

    return $events;
}
?>