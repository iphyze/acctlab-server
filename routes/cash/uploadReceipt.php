<?php

declare(strict_types=1);

require_once __DIR__ . '/cashHelpers.php';

try {
    cashRequireMethod('POST');
    $user = cashCurrentUser();
    $account = cashResolveAccount($conn, $user, cashRequestAccountId($_POST));
    cashAssertWriteAccess($user, $account);
    $accountId = (int) $account['id'];

    $transactionId = isset($_POST['transaction_id']) && $_POST['transaction_id'] !== ''
        ? (int) $_POST['transaction_id']
        : null;
    $iouId = isset($_POST['iou_id']) && $_POST['iou_id'] !== ''
        ? (int) $_POST['iou_id']
        : null;
    if (($transactionId ?? 0) <= 0 && ($iouId ?? 0) <= 0) {
        throw new InvalidArgumentException('A transaction_id or iou_id is required.', 422);
    }

    $file = $_FILES['receipt'] ?? $_FILES['file'] ?? null;
    if (!is_array($file)) {
        throw new InvalidArgumentException('A receipt file is required.', 422);
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The receipt exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The receipt exceeds the allowed upload limit.',
            UPLOAD_ERR_PARTIAL => 'The receipt upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'A receipt file is required.',
        ];
        throw new InvalidArgumentException($messages[$code] ?? 'The receipt could not be uploaded.', 422);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Receipt files must be between 1 byte and 10 MB.', 422);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('The uploaded receipt is invalid.', 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $mimeMap = cashReceiptMimeMap();
    if (!isset($mimeMap[$mime])) {
        throw new InvalidArgumentException('Only PDF, JPG, PNG and WebP receipt files are allowed.', 422);
    }

    $documentType = strtoupper(trim((string) ($_POST['document_type'] ?? 'RECEIPT')));
    if (!in_array($documentType, ['RECEIPT', 'INVOICE', 'VOUCHER', 'OTHER'], true)) {
        throw new InvalidArgumentException('document_type must be RECEIPT, INVOICE, VOUCHER or OTHER.', 422);
    }

    $requestedReceiptStatus = isset($_POST['receipt_status']) && trim((string) $_POST['receipt_status']) !== ''
        ? cashValidateIouReceiptStatus($_POST['receipt_status'])
        : null;

    $conn->begin_transaction();
    $absolutePath = null;
    try {
        $linkedTransaction = null;
        $linkedIou = null;

        if ($transactionId !== null && $transactionId > 0) {
            $linkedTransaction = cashFetchTransaction($conn, $transactionId);
            if ((int) $linkedTransaction['account_id'] !== $accountId) {
                throw new RuntimeException('Cash transaction not found.', 404);
            }
        }

        if ($iouId !== null && $iouId > 0) {
            $linkedIou = cashFetchIou($conn, $iouId, $accountId);
            if ($transactionId === null) {
                $transactionId = (int) $linkedIou['source_transaction_id'];
                $linkedTransaction = cashFetchTransaction($conn, $transactionId);
            } elseif ((int) $linkedIou['source_transaction_id'] !== $transactionId) {
                throw new InvalidArgumentException('The selected transaction does not belong to the selected IOU.', 422);
            }
        }

        if (!$linkedTransaction) {
            throw new RuntimeException('Cash transaction not found.', 404);
        }

        $date = new DateTimeImmutable('now');
        $relativeDirectory = 'storage/cash-receipts/' . $date->format('Y/m');
        $storageDirectory = dirname(__DIR__, 2) . '/' . $relativeDirectory;
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Unable to prepare receipt storage.', 500);
        }

        $originalName = cashReceiptPublicName((string) ($file['name'] ?? 'receipt'));
        $storedFilename = bin2hex(random_bytes(16)) . '.' . $mimeMap[$mime];
        $absolutePath = $storageDirectory . '/' . $storedFilename;
        if (!move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Unable to store the receipt file.', 500);
        }
        @chmod($absolutePath, 0640);

        $relativePath = $relativeDirectory . '/' . $storedFilename;
        $stmt = $conn->prepare("INSERT INTO cash_receipts (
                transaction_id, iou_id, document_type, original_filename, stored_filename,
                storage_path, mime_type, file_size, status, uploaded_by_user_id, uploaded_by_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the receipt record.', 500);
        }
        $userId = (int) $user['id'];
        $userEmail = (string) $user['email'];
        $stmt->bind_param(
            'iisssssiis',
            $transactionId,
            $iouId,
            $documentType,
            $originalName,
            $storedFilename,
            $relativePath,
            $mime,
            $size,
            $userId,
            $userEmail
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to save the receipt record: ' . $message, 500);
        }
        $receiptId = (int) $stmt->insert_id;
        $stmt->close();

        cashRefreshReceiptStatus(
            $conn,
            $transactionId,
            $iouId,
            $requestedReceiptStatus ?? ($iouId !== null ? 'PARTIAL' : 'RECEIVED')
        );

        cashLogAction(
            $conn,
            $user,
            sprintf('%s uploaded receipt %s for cash transaction %s.', $user['email'], $originalName, $linkedTransaction['transaction_reference'])
        );

        $receipt = cashFetchReceipt($conn, $receiptId, $accountId);
        $conn->commit();

        jsonResponse([
            'status' => 'Success',
            'message' => 'Receipt uploaded successfully.',
            'data' => [
                'receipt' => $receipt,
                'transaction' => cashFetchTransaction($conn, $transactionId),
                'iou' => $iouId !== null ? cashFetchIou($conn, $iouId, $accountId) : null,
            ],
        ], 201);
    } catch (Throwable $error) {
        $conn->rollback();
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        throw $error;
    }
} catch (Throwable $error) {
    cashHandleError($error, 'Unable to upload the receipt.');
}
