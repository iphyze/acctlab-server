<?php

declare(strict_types=1);

require_once 'includes/connection.php';
require_once 'includes/procurementAuthMiddleware.php';
require_once 'includes/procurementDocumentStorageService.php';

try {
    procurementEnsureAuthenticationTables($conn);
    procurementDocumentAssertStorageReady($conn);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        procurementRequirePermission($conn, 'documents.view');
        jsonResponse(['status' => 'Success', 'data' => procurementDocumentSettings($conn)]);
    }

    if ($method === 'PUT') {
        procurementRequireCsrfToken();
        $actor = procurementRequireSuperAdmin($conn);
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid request body.', 400);
        }
        $settings = procurementDocumentSaveSettings($conn, $payload, $actor);
        if (function_exists('procurementWriteAuditLog')) {
            procurementWriteAuditLog(
                $conn,
                (int) $actor['id'],
                (string) $actor['email'],
                (string) $actor['email'] . ' updated procurement document upload configuration.'
            );
        }
        jsonResponse([
            'status' => 'Success',
            'message' => 'Document configuration updated successfully.',
            'data' => $settings,
        ]);
    }

    jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status >= 500) {
        error_log('Procurement document settings error: ' . $error->getMessage());
    }
    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to manage procurement document configuration.' : $error->getMessage(),
    ], $status);
}
