<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('isp_management_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isAuthenticated(): bool
{
    return currentUser() !== null;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        redirectTo('index.php?login_required=1');
    }
}

function requireRole(array $allowedRoles): void
{
    $user = currentUser();
    $roleName = $user['role_name'] ?? '';

    if (!$user || !in_array($roleName, $allowedRoles, true)) {
        redirectTo('dashboard.php?access_denied=1');
    }
}

function createCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function logActivity(PDO $pdo, int $userId, string $action, string $description): void
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $statement = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
    );

    $statement->execute([$userId, $action, $description, $ipAddress, $userAgent]);
}

function logCustomerActivity(PDO $pdo, int $customerId, string $action, string $description): void
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $statement = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (NULL, ?, ?, ?, ?, NOW())'
    );

    $statement->execute([$action, $description . ' [customer_id=' . $customerId . ']', $ipAddress, $userAgent]);
}

function loadAppSetting(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $statement->execute([':key' => $key]);
    $result = $statement->fetch();

    return $result['setting_value'] ?? $default;
}

function logoutUser(PDO $pdo): void
{
    $user = currentUser();

    if ($user && !empty($user['id'])) {
        logActivity($pdo, (int) $user['id'], 'logout', 'User logged out');
    }

    session_unset();
    session_destroy();
}

function currentCustomer(): ?array
{
    return $_SESSION['customer'] ?? null;
}

function isCustomerAuthenticated(): bool
{
    return currentCustomer() !== null;
}

function requireCustomerAuth(): void
{
    if (!isCustomerAuthenticated()) {
        redirectTo('index.php?login_required=1');
    }
}

function generateCustomerCode(PDO $pdo): string
{
    $statement = $pdo->query('SELECT customer_code FROM customers ORDER BY id DESC LIMIT 1');
    $lastCode = $statement->fetchColumn();
    $nextNumber = 1;

    if ($lastCode && preg_match('/^(CUS-)(\d{6})$/', $lastCode, $matches)) {
        $nextNumber = (int) $matches[2] + 1;
    }

    return 'CUS-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
}

function customerStatusToBadge(string $status): string
{
    return match ($status) {
        'active' => 'badge-active',
        'inactive' => 'badge-inactive',
        'suspended' => 'badge-suspended',
        default => 'badge-inactive',
    };
}
