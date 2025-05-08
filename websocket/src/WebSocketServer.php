<?php
namespace WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Exception;


class WebSocketServer implements MessageComponentInterface
{
    protected $clients;
    protected $conn;
    protected $debug;

    public function __construct($dbConnection, $debug = false) 
    {
        $this->clients = new \SplObjectStorage;
        $this->conn = $dbConnection;
        $this->debug = $debug;
        $this->log("WebSocket Server Started!");
    }

    // public function onOpen(ConnectionInterface $conn) 
    // {
    //     $this->clients->attach($conn);
    //     $this->log("New connection! ({$conn->resourceId})");
    // }

    public function onOpen(ConnectionInterface $conn) 
    {
    // Get token from query string
    $query = $conn->httpRequest->getUri()->getQuery();
    parse_str($query, $params);
    $token = $params['token'] ?? null;

    if (!$token) {
        $conn->close();
        return;
    }

    try {
        // Verify token here using your auth middleware
        // If valid, attach the connection
        $this->clients->attach($conn);
        $this->log("New connection! ({$conn->resourceId})");
        
        // Send connection confirmation
        $conn->send(json_encode([
            'type' => 'connection',
            'payload' => [
                'status' => 'connected',
                'message' => 'Successfully connected to WebSocket server'
            ]
        ]));
    } catch (Exception $e) {
        $this->log("Authentication failed: " . $e->getMessage(), 'error');
        $conn->close();
    }
}

    public function onMessage(ConnectionInterface $from, $msg) 
    {
        $this->log("Message received: {$msg}");
        try {
            $data = json_decode($msg, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->broadcastMessage($data['type'] ?? 'message', $data);
            }
        } catch (Exception $e) {
            $this->log("Error processing message: " . $e->getMessage(), 'error');
        }
    }

    public function onClose(ConnectionInterface $conn) 
    {
        $this->clients->detach($conn);
        $this->log("Connection {$conn->resourceId} has disconnected");
    }

    public function onError(ConnectionInterface $conn, Exception $e) 
    {
        $this->log("Error: {$e->getMessage()}", 'error');
        $conn->close();
    }

    public function checkForNewEvents() 
    {
        try {
            $query = "SELECT * FROM events WHERE processed = FALSE ORDER BY created_at ASC LIMIT 10";
            $result = $this->conn->query($query);

            if ($result === false) {
                throw new Exception("Database query failed: " . $this->conn->error);
            }

            while ($event = $result->fetch_assoc()) {
                $this->broadcastMessage($event['type'], json_decode($event['data'], true));
                
                // Mark event as processed
                $updateStmt = $this->conn->prepare("UPDATE events SET processed = TRUE WHERE id = ?");
                $updateStmt->bind_param('i', $event['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
        } catch (Exception $e) {
            $this->log("Error checking for events: " . $e->getMessage(), 'error');
        }
    }

    protected function broadcastMessage($type, $data) 
    {
        $message = json_encode([
            'type' => $type,
            'payload' => $data
        ]);

        foreach ($this->clients as $client) {
            try {
                $client->send($message);
            } catch (Exception $e) {
                $this->log("Error sending message to client: " . $e->getMessage(), 'error');
            }
        }
    }

    protected function log($message, $level = 'info') 
    {
        if ($this->debug) {
            $date = date('Y-m-d H:i:s');
            echo "[$date] [$level] $message\n";
        }
    }
}