<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);

    $code = strtoupper(cashRequiredText($data, 'code', 'Project code', 255));
    $location = cashRequiredText($data, 'location', 'Project name / location', 255);

    $duplicate = $conn->prepare("SELECT id FROM location_table WHERE LOWER(TRIM(location)) = LOWER(TRIM(?)) OR UPPER(TRIM(code)) = UPPER(TRIM(?)) LIMIT 1");
    if (!$duplicate) {
        throw new RuntimeException('Unable to validate the project.', 500);
    }
    $duplicate->bind_param('ss', $location, $code);
    $duplicate->execute();
    $exists = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($exists) {
        throw new InvalidArgumentException('A project with that name/location or code already exists.', 409);
    }

    $stmt = $conn->prepare('INSERT INTO location_table (location, code) VALUES (?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Unable to create the project.', 500);
    }
    $stmt->bind_param('ss', $location, $code);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    cashLogAction($conn, $user, sprintf('%s created project %s (%s) from Cash Desk.', $user['email'], $location, $code));
    jsonResponse([
        'status' => 'Success',
        'message' => 'Project created successfully.',
        'data' => [
            'project' => [
                'id' => $id,
                'code' => $code,
                'location' => $location,
            ],
        ],
    ], 201);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to create the project.');
}
