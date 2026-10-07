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
$customerRow = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
$customerRow->execute([':id' => (int) $customer['id']]);
$customerRow = $customerRow->fetch();

$pageTitle = 'Customer Profile';
include __DIR__ . '/../includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar">
        <div class="member-brand">Customer Portal</div>
        <nav class="member-nav">
            <a href="dashboard.php" class="nav-item">Dashboard</a>
            <a href="profile.php" class="nav-item active">Profile</a>
            <a href="package.php" class="nav-item">Package</a>
            <a href="billing.php" class="nav-item">Billing</a>
            <a href="payments.php" class="nav-item">Payments</a>
            <a href="complaints.php" class="nav-item">Complaints</a>
            <a href="logout.php" class="nav-item danger">Logout</a>
        </nav>
    </aside>

    <main class="content">
        <div class="page-header">
            <h1>Profile</h1>
        </div>

        <div class="card">
            <div class="form-grid">
                <div class="form-group"><strong>Nama:</strong> <?php echo htmlspecialchars($customerRow['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Customer Code:</strong> <?php echo htmlspecialchars($customerRow['customer_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Username:</strong> <?php echo htmlspecialchars($customerRow['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Email:</strong> <?php echo htmlspecialchars($customerRow['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Phone:</strong> <?php echo htmlspecialchars($customerRow['phone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>WhatsApp:</strong> <?php echo htmlspecialchars($customerRow['whatsapp'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Alamat:</strong> <?php echo htmlspecialchars($customerRow['address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Kota:</strong> <?php echo htmlspecialchars($customerRow['city'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Provinsi:</strong> <?php echo htmlspecialchars($customerRow['province'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="form-group"><strong>Kode Pos:</strong> <?php echo htmlspecialchars($customerRow['postal_code'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

