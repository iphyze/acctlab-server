<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $data = cashReadJsonBody();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($data));
    cashAssertManageAccess($user, $account);

    $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : 0;
    $code = strtoupper(cashRequiredText($data, 'category_code', 'Category code', 40));
    if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
        throw new InvalidArgumentException('Category code may only contain letters, numbers, hyphens and underscores.', 422);
    }
    $name = cashRequiredText($data, 'category_name', 'Category name', 120);
    $description = cashNullableText($data['description'] ?? null, 255);
    $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 100;
    if ($sortOrder < 0 || $sortOrder > 10000) {
        throw new InvalidArgumentException('sort_order must be between 0 and 10000.', 422);
    }
    $isActive = cashParseBoolean($data['is_active'] ?? true, true) ? 1 : 0;

    try {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE cash_categories
                                    SET category_code = ?, category_name = ?, description = ?, is_active = ?, sort_order = ?
                                    WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Unable to update the cash category.', 500);
            }
            $stmt->bind_param('sssiii', $code, $name, $description, $isActive, $sortOrder, $id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $checkStmt = $conn->prepare('SELECT id FROM cash_categories WHERE id = ? LIMIT 1');
                $checkStmt->bind_param('i', $id);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
                if (!$exists) {
                    $stmt->close();
                    throw new RuntimeException('Cash category not found.', 404);
                }
            }
            $stmt->close();
            $categoryId = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO cash_categories (category_code, category_name, description, is_active, sort_order)
                                    VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Unable to create the cash category.', 500);
            }
            $stmt->bind_param('sssii', $code, $name, $description, $isActive, $sortOrder);
            $stmt->execute();
            $categoryId = (int) $stmt->insert_id;
            $stmt->close();
        }
    } catch (mysqli_sql_exception $error) {
        if ((int) $error->getCode() === 1062) {
            throw new InvalidArgumentException('That cash category code or name is already in use.', 409);
        }
        throw $error;
    }

    cashLogAction($conn, $user, sprintf('%s %s Cash Desk category %s.', $user['email'], $id > 0 ? 'updated' : 'created', $name));
    jsonResponse([
        'status' => 'Success',
        'message' => $id > 0 ? 'Cash category updated successfully.' : 'Cash category created successfully.',
        'data' => ['category_id' => $categoryId],
    ], $id > 0 ? 200 : 201);
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to save the cash category.');
}
