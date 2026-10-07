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

$pageTitle = 'Payments';
include __DIR__ . '/../includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar">
        <div class="member-brand">Customer Portal</div>
        <nav class="member-nav">
            <a href="dashboard.php" class="nav-item">Dashboard</a>
            <a href="profile.php" class="nav-item">Profile</a>
            <a href="package.php" class="nav-item">Package</a>
            <a href="billing.php" class="nav-item">Billing</a>
            <a href="payments.php" class="nav-item active">Payments</a>
            <a href="complaints.php" class="nav-item">Complaints</a>
            <a href="logout.php" class="nav-item danger">Logout</a>
        </nav>
    </aside>

    <main class="content">
        <div class="page-header">
            <h1>Payments</h1>
        </div>

        <div class="card">
            <p>Riwayat pembayaran akan tersedia pada PHASE 3.</p>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

