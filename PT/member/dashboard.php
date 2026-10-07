<?php
session_name('isp_management_member_session');
if (session_status() === PHP_SESSION_NONE) {
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

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/auth.php';

$pdo = getDatabaseConnection();
requireCustomerAuth();

$customer = currentCustomer();
$customerRecord = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
$customerRecord->execute([':id' => (int) $customer['id']]);
$customerRecord = $customerRecord->fetch();

$subscription = $pdo->prepare(
    'SELECT s.*, p.name AS package_name, p.code AS package_code, p.speed, p.speed_unit, p.price, p.description FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.customer_id = :customer_id AND s.status = :status ORDER BY s.created_at DESC LIMIT 1'
);
$subscription->execute([':customer_id' => (int) $customer['id'], ':status' => 'active']);
$subscription = $subscription->fetch();

$pageTitle = 'Customer Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar">
        <div class="member-brand">Customer Portal</div>
        <nav class="member-nav">
            <a href="dashboard.php" class="nav-item active">Dashboard</a>
            <a href="profile.php" class="nav-item">Profile</a>
            <a href="package.php" class="nav-item">Package</a>
            <a href="billing.php" class="nav-item">Billing</a>
            <a href="payments.php" class="nav-item">Payments</a>
            <a href="complaints.php" class="nav-item">Complaints</a>
            <a href="logout.php" class="nav-item danger">Logout</a>
        </nav>
    </aside>

    <main class="content">
        <div class="page-header">
            <h1>Selamat datang, <?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Customer Code</h3>
                <div class="value"><?php echo htmlspecialchars($customer['customer_code'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="stat-card">
                <h3>Status Customer</h3>
                <div class="value"><?php echo htmlspecialchars(strtoupper($customerRecord['status']), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="stat-card">
                <h3>Paket Aktif</h3>
                <div class="value"><?php echo htmlspecialchars($subscription['package_name'] ?? 'Belum memiliki paket aktif.', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Service Information</h2>
            <?php if ($subscription): ?>
                <div class="form-grid">
                    <div class="form-group"><strong>Kecepatan:</strong> <?php echo htmlspecialchars((string) $subscription['speed'] . ' ' . $subscription['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Harga Paket:</strong> <?php echo htmlspecialchars(number_format((float) $subscription['price'], 2), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Tanggal Mulai:</strong> <?php echo htmlspecialchars($subscription['start_date'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Status Layanan:</strong> <span class="badge <?php echo customerStatusToBadge($subscription['status']); ?>"><?php echo htmlspecialchars(strtoupper($subscription['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
            <?php else: ?>
                <p>Belum memiliki paket aktif.</p>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

