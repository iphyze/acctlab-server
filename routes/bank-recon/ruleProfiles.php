<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/reconMatchingHelpers.php';

header('Content-Type: application/json');

function brProfileFail(string $message, int $code = 400): void { throw new Exception($message, $code); }

function brProfileBody(): array
{
    $raw = json_decode(file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function brProfileBool($value): int
{
    if (is_bool($value)) return $value ? 1 : 0;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
}

function brProfileContext(array $source): array
{
    return [
        'company_name' => trim((string)($source['company_name'] ?? '')),
        'bank_name' => trim((string)($source['bank_name'] ?? '')),
        'account_name' => trim((string)($source['account_name'] ?? '')),
        'account_number' => trim((string)($source['account_number'] ?? '')),
        'currency' => strtoupper(trim((string)($source['currency'] ?? ''))),
    ];
}

function brProfileMatchScore(array $profile, array $context): int
{
    if (!brReconRuleProfileCompatible($profile, $context)) return -1;
    $score = 0;
    if (trim((string)($profile['company_name'] ?? '')) !== '') $score += 10;
    if (trim((string)($profile['bank_name'] ?? '')) !== '') $score += 30;
    if (trim((string)($profile['account_name'] ?? '')) !== '') $score += 5;
    if (trim((string)($profile['account_number'] ?? '')) !== '') $score += 50;
    if (trim((string)($profile['currency'] ?? '')) !== '') $score += 15;
    return $score;
}

function brProfileList(mysqli $conn, array $context = []): array
{
    brReconEnsureRuleSchema($conn);
    $sql = "SELECT p.*,
                   COUNT(r.id) AS rule_count,
                   COALESCE(SUM(CASE WHEN r.is_active=1 THEN 1 ELSE 0 END),0) AS active_rule_count
            FROM bank_recon_rule_profiles p
            LEFT JOIN bank_recon_auto_rules r ON r.profile_id=p.id
            GROUP BY p.id
            ORDER BY p.is_active DESC, p.profile_name ASC, p.id ASC";
    $res = $conn->query($sql);
    $profiles = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $score = $context ? brProfileMatchScore($row, $context) : 0;
            $row['compatible'] = $context ? $score >= 0 : true;
            $row['match_score'] = $score;
            $row['rule_count'] = (int)($row['rule_count'] ?? 0);
            $row['active_rule_count'] = (int)($row['active_rule_count'] ?? 0);
            $row['is_active'] = (int)($row['is_active'] ?? 0);
            $profiles[] = $row;
        }
    }

    usort($profiles, static function(array $a, array $b): int {
        $compatibleDiff = (int)($b['compatible'] ?? 0) <=> (int)($a['compatible'] ?? 0);
        if ($compatibleDiff !== 0) return $compatibleDiff;
        $scoreDiff = (int)($b['match_score'] ?? 0) <=> (int)($a['match_score'] ?? 0);
        if ($scoreDiff !== 0) return $scoreDiff;
        return strcasecmp((string)$a['profile_name'], (string)$b['profile_name']);
    });

    return $profiles;
}

try {
    $user = requireAdmin();
    $by = $user['email'] ?? $user['username'] ?? 'system';
    brReconEnsureRuleSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $context = brProfileContext($_GET);
        $hasContext = implode('', $context) !== '';
        $profiles = brProfileList($conn, $hasContext ? $context : []);
        $legacyRes = $conn->query('SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END),0) active FROM bank_recon_auto_rules WHERE profile_id IS NULL');
        $legacy = $legacyRes ? $legacyRes->fetch_assoc() : ['total' => 0, 'active' => 0];
        echo json_encode([
            'status' => 'Success',
            'data' => $profiles,
            'legacy' => [
                'rule_count' => (int)($legacy['total'] ?? 0),
                'active_rule_count' => (int)($legacy['active'] ?? 0),
            ],
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') brProfileFail('Route not found', 404);
    $body = brProfileBody();
    $action = strtolower(trim((string)($body['action'] ?? 'save')));

    if ($action === 'toggle') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) brProfileFail('Profile id is required.');
        $isActive = brProfileBool($body['is_active'] ?? 1);
        $stmt = $conn->prepare('UPDATE bank_recon_rule_profiles SET is_active=?, updated_by=? WHERE id=?');
        if (!$stmt) brProfileFail('Failed to prepare profile status update: ' . $conn->error, 500);
        $stmt->bind_param('isi', $isActive, $by, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'Success', 'message' => 'Rule profile status updated.', 'data' => brProfileList($conn)]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) brProfileFail('Profile id is required.');
        $ruleRes = $conn->query('SELECT COUNT(*) total FROM bank_recon_auto_rules WHERE profile_id=' . $id);
        $reconRes = $conn->query('SELECT COUNT(*) total FROM bank_recons WHERE auto_rule_mode=\'profile\' AND auto_rule_profile_id=' . $id);
        $ruleCount = $ruleRes ? (int)($ruleRes->fetch_assoc()['total'] ?? 0) : 0;
        $reconCount = $reconRes ? (int)($reconRes->fetch_assoc()['total'] ?? 0) : 0;
        if ($ruleCount > 0 || $reconCount > 0) {
            brProfileFail('This profile is already in use. Disable it or move its rules/reconciliations before deleting it.', 422);
        }
        $stmt = $conn->prepare('DELETE FROM bank_recon_rule_profiles WHERE id=?');
        if (!$stmt) brProfileFail('Failed to prepare profile delete: ' . $conn->error, 500);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'Success', 'message' => 'Rule profile deleted.', 'data' => brProfileList($conn)]);
        exit;
    }

    $id = (int)($body['id'] ?? 0);
    $profileName = trim((string)($body['profile_name'] ?? ''));
    $context = brProfileContext($body);
    $isActive = brProfileBool($body['is_active'] ?? 1);

    if ($profileName === '') brProfileFail('Profile name is required.');
    if ($context['bank_name'] === '') brProfileFail('Bank name is required for a rule profile.');
    if ($context['currency'] === '') brProfileFail('Currency is required for a rule profile.');

    $profileNameE = $conn->real_escape_string($profileName);
    $bankNameE = $conn->real_escape_string($context['bank_name']);
    $currencyE = $conn->real_escape_string($context['currency']);
    $accountNoE = $conn->real_escape_string($context['account_number']);
    $companyE = $conn->real_escape_string($context['company_name']);
    $exclude = $id > 0 ? ' AND id<>' . $id : '';
    $dupe = $conn->query("SELECT id FROM bank_recon_rule_profiles
        WHERE profile_name='{$profileNameE}'
          AND COALESCE(company_name,'')='{$companyE}'
          AND bank_name='{$bankNameE}'
          AND COALESCE(account_number,'')='{$accountNoE}'
          AND currency='{$currencyE}'{$exclude}
        LIMIT 1");
    if ($dupe && $dupe->num_rows) brProfileFail('A rule profile with this name and bank/account scope already exists.', 422);

    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE bank_recon_rule_profiles SET profile_name=?, company_name=?, bank_name=?, account_name=?, account_number=?, currency=?, is_active=?, updated_by=? WHERE id=?');
        if (!$stmt) brProfileFail('Failed to prepare profile update: ' . $conn->error, 500);
        $stmt->bind_param('ssssssisi', $profileName, $context['company_name'], $context['bank_name'], $context['account_name'], $context['account_number'], $context['currency'], $isActive, $by, $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Rule profile updated.';
    } else {
        $stmt = $conn->prepare('INSERT INTO bank_recon_rule_profiles (profile_name, company_name, bank_name, account_name, account_number, currency, is_active, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
        if (!$stmt) brProfileFail('Failed to prepare profile insert: ' . $conn->error, 500);
        $stmt->bind_param('ssssssiss', $profileName, $context['company_name'], $context['bank_name'], $context['account_name'], $context['account_number'], $context['currency'], $isActive, $by, $by);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        $message = 'Rule profile created.';
    }

    echo json_encode(['status' => 'Success', 'message' => $message, 'id' => $id, 'data' => brProfileList($conn)]);
} catch (Throwable $e) {
    http_response_code(($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
