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

function denyAccess(string $message = 'Access Denied'): void
{
    $user = currentUser();

    if ($user && !empty($user['id']) && function_exists('getDatabaseConnection')) {
        try {
            $pdo = getDatabaseConnection();
            logActivity($pdo, (int) $user['id'], 'permission_denied', $message);
        } catch (Throwable $exception) {
            // Ignore database logging failures so access can still be denied cleanly.
        }
    }

    http_response_code(403);
    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403 Access Denied</title>';
    echo '<style>body{font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a;} .box{padding:2rem 2.5rem;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 16px 32px rgba(15,23,42,0.08);text-align:center;} h1{margin:0 0 .5rem;font-size:2.5rem;} p{margin:0;color:#475569;}</style>';
    echo '</head><body><div class="box"><h1>403</h1><p>Access Denied</p><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
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

function getCurrentRoleName(): string
{
    $user = currentUser();
    $roleName = trim((string) ($user['role_name'] ?? ''));

    if ($roleName !== '') {
        return strtolower($roleName);
    }

    return '';
}

function normalizeRoleList($roles): array
{
    if (is_string($roles)) {
        $roles = [$roles];
    }

    if (!is_array($roles)) {
        return [];
    }

    $normalized = [];
    foreach ($roles as $role) {
        $value = trim((string) $role);
        if ($value !== '') {
            $normalized[] = strtolower($value);
        }
    }

    return array_values(array_unique($normalized));
}

function hasRole($roles): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    $roleName = getCurrentRoleName();
    $allowedRoles = normalizeRoleList($roles);

    return $roleName !== '' && in_array($roleName, $allowedRoles, true);
}

function requireRole($roles): void
{
    if (!hasRole($roles)) {
        denyAccess('Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}

function hasPermission(string $permission): bool
{
    $roleName = getCurrentRoleName();
    if ($roleName === '') {
        return false;
    }

    $permissions = rolePermissions($roleName);
    foreach ($permissions as $allowedPermission) {
        if ($allowedPermission === $permission) {
            return true;
        }
    }

    return false;
}

function requirePermission(string $permission): void
{
    if (!isAuthenticated()) {
        redirectTo('index.php?login_required=1');
    }

    if (!hasPermission($permission)) {
        denyAccess('Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}

function rolePermissions(string $role): array
{
    $role = strtolower(trim($role));

    $permissionsByRole = [
        'super_admin' => [
            'dashboard.view',
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'packages.view', 'packages.create', 'packages.edit', 'packages.delete',
            'subscriptions.view', 'subscriptions.create', 'subscriptions.edit', 'subscriptions.delete',
            'billing.view', 'billing.create', 'billing.edit', 'billing.cancel', 'billing.print',
            'payments.view', 'payments.create', 'payments.edit',
            'employees.view', 'employees.create', 'employees.edit',
            'attendance.view', 'attendance.create', 'attendance.edit',
            'network.view', 'network.manage',
            'mikrotik.view', 'mikrotik.manage',
            'olt.view', 'olt.manage',
            'onu.view', 'onu.manage',
            'monitoring.view',
            'reports.view',
            'users.view', 'users.create', 'users.edit', 'users.delete', 'users.manage_roles',
            'settings.view', 'settings.edit',
            'activity_logs.view',
            'backup.manage',
        ],
        'admin' => [
            'dashboard.view',
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'packages.view', 'packages.create', 'packages.edit', 'packages.delete',
            'subscriptions.view', 'subscriptions.create', 'subscriptions.edit', 'subscriptions.delete',
            'billing.view', 'billing.create', 'billing.edit', 'billing.cancel', 'billing.print',
            'payments.view',
            'employees.view', 'employees.create', 'employees.edit',
            'attendance.view', 'attendance.create', 'attendance.edit',
            'network.view',
            'monitoring.view',
            'reports.view',
            'activity_logs.view',
            'users.view', 'users.create', 'users.edit', 'users.delete',
        ],
        'finance' => [
            'dashboard.view',
            'customers.view',
            'packages.view',
            'subscriptions.view',
            'billing.view', 'billing.create', 'billing.edit', 'billing.cancel', 'billing.print',
            'payments.view', 'payments.create', 'payments.edit',
            'reports.view',
        ],
        'technician' => [
            'dashboard.view',
            'customers.view',
            'subscriptions.view',
            'attendance.view', 'attendance.create', 'attendance.edit',
            'network.view', 'network.manage',
            'mikrotik.view', 'mikrotik.manage',
            'olt.view', 'olt.manage',
            'onu.view', 'onu.manage',
            'monitoring.view',
            'reports.view',
        ],
        'viewer' => [
            'dashboard.view',
            'customers.view',
            'packages.view',
            'subscriptions.view',
            'billing.view', 'billing.print',
            'payments.view',
            'employees.view',
            'attendance.view',
            'network.view',
            'mikrotik.view',
            'olt.view',
            'onu.view',
            'monitoring.view',
            'reports.view',
        ],
    ];

    return $permissionsByRole[$role] ?? [];
}

function permissionMatches(string $allowedPermission, string $requiredPermission): bool
{
    if ($allowedPermission === $requiredPermission) {
        return true;
    }

    if (str_ends_with($allowedPermission, '.*')) {
        $prefix = substr($allowedPermission, 0, -2);
        return str_starts_with($requiredPermission, $prefix . '.') || $requiredPermission === $prefix;
    }

    if (str_ends_with($requiredPermission, '.*')) {
        $prefix = substr($requiredPermission, 0, -2);
        return str_starts_with($allowedPermission, $prefix . '.') || $allowedPermission === $prefix;
    }

    return false;
}

function can(string $permission): bool
{
    return hasPermission($permission);
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

function generateInvoiceNumber(PDO $pdo): string
{
    $yearMonth = date('Y') . date('m');
    $prefix = 'INV-' . $yearMonth . '-';

    $statement = $pdo->prepare(
        'SELECT invoice_number FROM invoices WHERE invoice_number LIKE :prefix ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':prefix' => $prefix . '%']);
    $lastInvoiceNumber = $statement->fetchColumn();

    $nextSequence = 1;
    if ($lastInvoiceNumber && preg_match('/^INV-\d{6}-(\d{6})$/', (string) $lastInvoiceNumber, $matches)) {
        $nextSequence = (int) $matches[1] + 1;
    }

    return $prefix . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
}

function invoiceStatusBadgeLabel(string $status): string
{
    return match (strtolower($status)) {
        'paid' => 'PAID',
        'overdue' => 'OVERDUE',
        'cancelled' => 'CANCELLED',
        'unpaid' => 'UNPAID',
        default => strtoupper($status),
    };
}

function invoiceStatusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'paid' => 'badge-active',
        'overdue' => 'badge-suspended',
        'cancelled' => 'badge-inactive',
        'unpaid' => 'badge-inactive',
        default => 'badge-inactive',
    };
}

function effectiveInvoiceStatus(array $invoice): string
{
    $status = strtolower((string) ($invoice['status'] ?? 'unpaid'));
    if ($status === 'unpaid' && !empty($invoice['due_date']) && $invoice['due_date'] < date('Y-m-d')) {
        return 'overdue';
    }

    return $status;
}

function formatCurrency(float $value): string
{
    return 'Rp ' . number_format($value, 2, ',', '.');
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
