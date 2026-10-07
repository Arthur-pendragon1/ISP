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
$subscription = $pdo->prepare(
    'SELECT s.*, p.* FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.customer_id = :customer_id AND s.status = :status ORDER BY s.created_at DESC LIMIT 1'
);
$subscription->execute([':customer_id' => (int) $customer['id'], ':status' => 'active']);
$subscription = $subscription->fetch();

$pageTitle = 'Customer Package';
include __DIR__ . '/../includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar">
        <div class="member-brand">Customer Portal</div>
        <nav class="member-nav">
            <a href="dashboard.php" class="nav-item">Dashboard</a>
            <a href="profile.php" class="nav-item">Profile</a>
            <a href="package.php" class="nav-item active">Package</a>
            <a href="billing.php" class="nav-item">Billing</a>
            <a href="payments.php" class="nav-item">Payments</a>
            <a href="complaints.php" class="nav-item">Complaints</a>
            <a href="logout.php" class="nav-item danger">Logout</a>
        </nav>
    </aside>

    <main class="content">
        <div class="page-header">
            <h1>Paket Anda</h1>
        </div>

        <div class="card">
            <?php if ($subscription): ?>
                <div class="form-grid">
                    <div class="form-group"><strong>Nama Paket:</strong> <?php echo htmlspecialchars($subscription['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Kode Paket:</strong> <?php echo htmlspecialchars($subscription['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Kecepatan:</strong> <?php echo htmlspecialchars((string) $subscription['speed'] . ' ' . $subscription['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Harga:</strong> <?php echo htmlspecialchars(number_format((float) $subscription['price'], 2), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Dari Tanggal:</strong> <?php echo htmlspecialchars($subscription['start_date'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Berakhir:</strong> <?php echo htmlspecialchars($subscription['end_date'] ?? 'Tidak dibatasi', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Status Layanan:</strong> <span class="badge <?php echo customerStatusToBadge($subscription['status']); ?>"><?php echo htmlspecialchars(strtoupper($subscription['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="form-group" style="grid-column:1 / -1;"><strong>Deskripsi:</strong><br><?php echo nl2br(htmlspecialchars($subscription['description'] ?? '-', ENT_QUOTES, 'UTF-8')); ?></div>
                </div>
            <?php else: ?>
                <p>Tidak ada paket aktif saat ini.</p>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

