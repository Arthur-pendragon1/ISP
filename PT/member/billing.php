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

$currentCustomer = currentCustomer();
$currentCustomerId = (int) ($currentCustomer['id'] ?? 0);
$pageTitle = 'Billing';
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'invoice_printed' => 'Invoice berhasil diprint.',
        default => 'Operasi berhasil.'
    };
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'unpaid', 'paid', 'overdue', 'cancelled'], true)) {
    $statusFilter = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$conditions = ['i.customer_id = :customer_id'];
$params = [':customer_id' => $currentCustomerId];

if ($search !== '') {
    $conditions[] = '(i.invoice_number LIKE :search OR c.name LIKE :search OR c.customer_code LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $conditions[] = "CASE WHEN i.status = 'unpaid' AND i.due_date < CURDATE() THEN 'overdue' ELSE i.status END = :status";
    $params[':status'] = $statusFilter;
}

$whereClause = ' WHERE ' . implode(' AND ', $conditions);
$countStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause
);
$countStatement->execute($params);
$totalInvoices = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalInvoices / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStatement = $pdo->prepare(
    'SELECT i.*, c.name AS customer_name, c.customer_code, p.name AS package_name, p.code AS package_code, CASE WHEN i.status = ' . "'unpaid'" . ' AND i.due_date < CURDATE() THEN ' . "'overdue'" . ' ELSE i.status END AS effective_status FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause . ' ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $listStatement->bindValue($key, $value);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$invoices = $listStatement->fetchAll();

foreach ($invoices as &$invoiceRow) {
    $invoiceRow['effective_status'] = effectiveInvoiceStatus($invoiceRow);
}
unset($invoiceRow);

$viewInvoiceId = isset($_GET['view']) && $_GET['view'] === 'detail' ? (int) ($_GET['id'] ?? 0) : 0;
$invoiceDetail = null;
if ($viewInvoiceId > 0) {
    $detailStatement = $pdo->prepare(
        'SELECT i.*, c.name AS customer_name, c.customer_code, c.phone, c.whatsapp, c.address, p.name AS package_name, p.code AS package_code FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id WHERE i.id = :id AND i.customer_id = :customer_id LIMIT 1'
    );
    $detailStatement->execute([':id' => $viewInvoiceId, ':customer_id' => $currentCustomerId]);
    $invoiceDetail = $detailStatement->fetch();

    if ($invoiceDetail) {
        $invoiceDetail['effective_status'] = effectiveInvoiceStatus($invoiceDetail);
        logCustomerActivity($pdo, $currentCustomerId, 'invoice_viewed', 'Customer viewed invoice: ' . $invoiceDetail['invoice_number']);
    } else {
        denyAccess('This invoice does not belong to your account.');
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="member-layout">
    <aside class="member-sidebar">
        <div class="member-brand">Customer Portal</div>
        <nav class="member-nav">
            <a href="dashboard.php" class="nav-item">Dashboard</a>
            <a href="profile.php" class="nav-item">Profile</a>
            <a href="package.php" class="nav-item">Package</a>
            <a href="billing.php" class="nav-item active">Billing</a>
            <a href="payments.php" class="nav-item">Payments</a>
            <a href="complaints.php" class="nav-item">Complaints</a>
            <a href="logout.php" class="nav-item danger">Logout</a>
        </nav>
    </aside>

    <main class="content">
        <?php if ($invoiceDetail): ?>
            <div class="page-header">
                <h1>Invoice Detail</h1>
                <a href="billing.php" class="btn btn-secondary">Back</a>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
                    <div>
                        <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.12em; color:#64748b; font-weight:700;">ISP Management</div>
                        <h2 style="margin:0.35rem 0 0;"><?php echo htmlspecialchars($invoiceDetail['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    </div>
                    <span class="badge <?php echo invoiceStatusBadgeClass($invoiceDetail['effective_status']); ?>"><?php echo htmlspecialchars(invoiceStatusBadgeLabel($invoiceDetail['effective_status']), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <div class="form-grid" style="margin-bottom:1.25rem;">
                    <div class="form-group">
                        <label>Customer</label>
                        <div class="readonly-field"><?php echo htmlspecialchars($invoiceDetail['customer_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($invoiceDetail['customer_code'], ENT_QUOTES, 'UTF-8'); ?>)</div>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <div class="readonly-field"><?php echo htmlspecialchars((string) ($invoiceDetail['phone'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>WhatsApp</label>
                        <div class="readonly-field"><?php echo htmlspecialchars((string) ($invoiceDetail['whatsapp'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <div class="readonly-field"><?php echo htmlspecialchars((string) ($invoiceDetail['address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Package</label>
                        <div class="readonly-field"><?php echo htmlspecialchars($invoiceDetail['package_code'] . ' - ' . $invoiceDetail['package_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Billing Period</label>
                        <div class="readonly-field"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoiceDetail['billing_period_start'])) . ' / ' . date('d-m-Y', strtotime($invoiceDetail['billing_period_end'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Issue Date</label>
                        <div class="readonly-field"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoiceDetail['issue_date'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <div class="readonly-field"><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoiceDetail['due_date'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Detail</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Subtotal</td><td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['subtotal']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr><td>Discount</td><td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['discount']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr><td>Tax</td><td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['tax']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <tr><td><strong>Total</strong></td><td><strong><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['total']), ENT_QUOTES, 'UTF-8'); ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:1rem;">
                    <a href="../billing_print.php?id=<?php echo (int) $invoiceDetail['id']; ?>" class="btn btn-primary" target="_blank" rel="noopener">Print Invoice</a>
                </div>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h1>Billing</h1>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="card">
                <form method="get" action="billing.php" style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:end;">
                    <div class="form-group" style="flex:1 1 220px; margin-bottom:0;">
                        <label for="billing_search">Search</label>
                        <input class="form-control" id="billing_search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Invoice number">
                    </div>
                    <div class="form-group" style="flex:0 0 180px; margin-bottom:0;">
                        <label for="billing_status">Status</label>
                        <select class="form-control" id="billing_status" name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="unpaid" <?php echo $statusFilter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div style="margin-bottom:0;">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Billing Period</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices): ?>
                                <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['billing_period_start'])) . ' / ' . date('d-m-Y', strtotime($invoice['billing_period_end'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['issue_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['due_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['total']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge <?php echo invoiceStatusBadgeClass($invoice['effective_status']); ?>"><?php echo htmlspecialchars(invoiceStatusBadgeLabel($invoice['effective_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><a href="billing.php?view=detail&id=<?php echo (int) $invoice['id']; ?>" class="link-button">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No invoices available.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <a href="billing.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $pageNumber; ?>" class="page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>"><?php echo $pageNumber; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

