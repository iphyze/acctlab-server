<?php
// Disable output buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (@ob_end_flush());

// Set execution time to unlimited
set_time_limit(0);
ignore_user_abort(true);

require_once 'includes/connection.php';
require_once 'routes/events/reminderEvents.php';
require_once 'routes/events/checkForNewEvents.php';

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Function to send SSE message
function sendSSE($eventType, $data) {
    $output = sprintf(
        "event: %s\ndata: %s\n\n",
        $eventType,
        json_encode($data, JSON_UNESCAPED_UNICODE)
    );
    echo $output;
    
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    
    // Log event for debugging
    error_log("SSE Event sent - Type: {$eventType}, Data: " . json_encode($data));
}
// Send initial connection message
sendSSE('connected', ['status' => 'connected']);

// Keep track of last event time
$lastEventTime = isset($_GET['lastEventTime']) ? $_GET['lastEventTime'] : date('Y-m-d H:i:s');

// Main event loop
while (true) {
    // Send keep-alive comment every 30 seconds
    echo ": keepalive\n\n";
    flush();

    try {
        $reminderEvent = checkAndSendReminders($conn);
        if ($reminderEvent) {
            if ($reminderEvent['type'] === 'reminderError') {
                error_log("Reminder error: " . json_encode($reminderEvent['data']));
            }
            sendSSE($reminderEvent['type'], $reminderEvent['data']);
        }

        // Check for new logs and responses
        $newEvents = checkForNewEvents($conn, $lastEventTime);
        foreach ($newEvents as $event) {
            sendSSE($event['type'], $event['data']);
            if (isset($event['data']['createdAt'])) {
                $lastEventTime = $event['data']['createdAt'];
            }
        }
    } catch (Exception $e) {
        error_log("Error in SSE loop: " . $e->getMessage());
        sendSSE('error', ['message' => 'Internal server error']);
    }

    // Check if client is still connected
    if (connection_aborted()) {
        error_log("Client disconnected");
        break;
    }

    // Sleep for a short time to prevent CPU overuse
    sleep(2);
}

// Close connection when client disconnects
if (isset($conn)) {
    $conn->close();
}
?>