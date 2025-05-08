<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/connection.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use WebSocket\WebSocketServer;

// Add these headers before creating the server
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');



try {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Get the event loop
    $loop = Loop::get();

    // Create WebSocket server instance with debug mode enabled
    $webSocket = new WebSocketServer($conn, true);

    // Create periodic timer to check for new events
    $loop->addPeriodicTimer(1, function () use ($webSocket) {
        $webSocket->checkForNewEvents();
    });

    // Update the socket server creation
    $socket = new SocketServer('tcp://0.0.0.0:8080', [
        'allowed_origins' => ['http://localhost:3000'] // Add your React app URL
    ]);
    
    $server = new IoServer(
        new HttpServer(
            new WsServer($webSocket)
        ),
        $socket
    );

    echo "WebSocket Server running at ws://0.0.0.0:8080\n";

    // Run the server
    $loop->run();

} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}