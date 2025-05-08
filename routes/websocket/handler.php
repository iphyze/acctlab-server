<?php
// Prevent timeout
set_time_limit(0);
ignore_user_abort(true);

// Disable output buffering
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

// Set headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Clear any existing output buffers
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

require_once('includes/connection.php');
require_once('includes/authMiddleware.php');
require_once('routes/events/reminderEvents.php');

try {
    // Verify token
    $token = $_GET['token'] ?? '';

    if (!$token) {
        echo "event: error\n";
        echo "data: " . json_encode(['message' => 'Unauthorized']) . "\n\n";
        exit;
    }

    // Authenticate user
    $userData = authenticateUser();

    // Send initial connection success message
    echo "event: connection\n";
    echo "data: " . json_encode(['status' => 'connected']) . "\n\n";
    flush();

    // Keep connection alive
    $lastEventId = 0;
    while (true) {
        // Check for new events
        $query = "SELECT * FROM events WHERE id > ? AND processed = FALSE ORDER BY created_at ASC LIMIT 10";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $lastEventId);
        $stmt->execute();
        $result = $stmt->get_result();

        $hasEvents = false;
        while ($event = $result->fetch_assoc()) {
            $hasEvents = true;
            $lastEventId = max($lastEventId, $event['id']);

            echo "event: {$event['type']}\n";
            echo "data: {$event['data']}\n\n";
            flush();

            // Mark as processed
            $updateStmt = $conn->prepare("UPDATE events SET processed = TRUE WHERE id = ?");
            $updateStmt->bind_param('i', $event['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
        $stmt->close();

        // Check for reminders
        $reminderEvent = checkAndSendReminders($conn);
        if ($reminderEvent) {
            echo "event: {$reminderEvent['type']}\n";
            echo "data: " . json_encode($reminderEvent['data']) . "\n\n";
            flush();
        }

        // Send keepalive if no events were sent
        if (!$hasEvents) {
            echo ": keepalive " . date('Y-m-d H:i:s') . "\n\n";
            flush();
        }

        // Sleep briefly to prevent CPU overuse
        usleep(500000); // 0.5 seconds

        // Check connection
        if (connection_aborted()) {
            break;
        }
    }
} catch (Exception $e) {
    error_log("SSE Error: " . $e->getMessage());
    echo "event: error\n";
    echo "data: " . json_encode(['message' => $e->getMessage()]) . "\n\n";
    flush();
}

$conn->close();