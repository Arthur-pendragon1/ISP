<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('billing.view');

$currentUser = currentUser();
$pageTitle = 'Billing';
$successMessage = '';
$errorMessage = '';
$viewInvoiceId = 0;
$editingInvoice = null;
$shouldOpenInvoiceModal = false;

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'invoice_created' => 'Invoice berhasil dibuat.',
        'invoice_updated' => 'Invoice berhasil diperbarui.',
        'invoice_cancelled' => 'Invoice berhasil dibatalkan.',
        default => 'Operasi berhasil.'
    };
}

if (isset($_GET['view']) && $_GET['view'] === 'detail' && isset($_GET['id'])) {
    $viewInvoiceId = (int) $_GET['id'];
}

if (isset($_GET['edit_id'])) {
    requirePermission('billing.edit');
    $invoiceId = (int) $_GET['edit_id'];
    $editingStatement = $pdo->prepare(
        'SELECT i.*, c.name AS customer_name, c.customer_code, c.phone, c.whatsapp, c.address, s.package_id, p.name AS package_name, p.code AS package_code, p.price FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id WHERE i.id = :id LIMIT 1'
    );
    $editingStatement->execute([':id' => $invoiceId]);
    $editingInvoice = $editingStatement->fetch();
    if ($editingInvoice) {
        $shouldOpenInvoiceModal = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security validation failed.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            requirePermission('billing.create');
        } elseif ($action === 'update') {
            requirePermission('billing.edit');
        } elseif ($action === 'cancel') {
            requirePermission('billing.cancel');
        }

        if ($action === 'create' || $action === 'update') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
            $billingStart = trim((string) ($_POST['billing_period_start'] ?? ''));
            $billingEnd = trim((string) ($_POST['billing_period_end'] ?? ''));
            $issueDate = trim((string) ($_POST['issue_date'] ?? ''));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $subtotal = (float) ($_POST['subtotal'] ?? 0);
            $discount = (float) ($_POST['discount'] ?? 0);
            $tax = (float) ($_POST['tax'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($customerId <= 0) {
                $errorMessage = 'Customer is required.';
            } elseif ($subscriptionId <= 0) {
                $errorMessage = 'Subscription is required.';
            } elseif ($billingStart === '' || $billingEnd === '' || $issueDate === '' || $dueDate === '') {
                $errorMessage = 'Billing period, issue date, and due date are required.';
            } else {
                $customerStatement = $pdo->prepare('SELECT id, customer_code, name FROM customers WHERE id = :id AND status = :status LIMIT 1');
                $customerStatement->execute([':id' => $customerId, ':status' => 'active']);
                $customer = $customerStatement->fetch();

                if (!$customer) {
                    $errorMessage = 'Selected customer does not exist or is not active.';
                } else {
                    $subscriptionStatement = $pdo->prepare(
                        'SELECT s.id, s.customer_id, p.name AS package_name, p.price FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.id = :id AND s.customer_id = :customer_id LIMIT 1'
                    );
                    $subscriptionStatement->execute([':id' => $subscriptionId, ':customer_id' => $customerId]);
                    $subscription = $subscriptionStatement->fetch();

                    if (!$subscription) {
                        $errorMessage = 'Selected subscription is not valid for this customer.';
                    } else {
                        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $billingStart);
                        $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $billingEnd);
                        $issueDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $issueDate);
                        $dueDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);

                        if (!$startDateObj || !$endDateObj || !$issueDateObj || !$dueDateObj) {
                            $errorMessage = 'Please use valid dates in YYYY-MM-DD format.';
                        } elseif ($endDateObj < $startDateObj) {
                            $errorMessage = 'Billing end date cannot be earlier than the start date.';
                        } elseif ($dueDateObj < $issueDateObj) {
                            $errorMessage = 'Due date cannot be earlier than the issue date.';
                        } elseif ($subtotal < 0 || $discount < 0 || $tax < 0) {
                            $errorMessage = 'Subtotal, discount, and tax cannot be negative.';
                        } else {
                            $calculatedTotal = round($subtotal - $discount + $tax, 2);
                            if ($calculatedTotal < 0) {
                                $errorMessage = 'Invoice total cannot be negative.';
                            } else {
                                $invoiceNumber = generateInvoiceNumber($pdo);
                                $status = 'unpaid';

                                if ($action === 'update') {
                                    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
                                    $existingInvoiceStatement = $pdo->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
                                    $existingInvoiceStatement->execute([':id' => $invoiceId]);
                                    $existingInvoice = $existingInvoiceStatement->fetch();

                                    if (!$existingInvoice) {
                                        $errorMessage = 'Invoice not found.';
                                    } else {
                                        $status = in_array($existingInvoice['status'], ['paid', 'cancelled'], true) ? $existingInvoice['status'] : 'unpaid';
                                        $pdo->prepare(
                                            'UPDATE invoices SET customer_id = :customer_id, subscription_id = :subscription_id, billing_period_start = :billing_period_start, billing_period_end = :billing_period_end, issue_date = :issue_date, due_date = :due_date, subtotal = :subtotal, discount = :discount, tax = :tax, total = :total, status = :status, notes = :notes, updated_at = NOW() WHERE id = :id'
                                        )->execute([
                                            ':customer_id' => $customerId,
                                            ':subscription_id' => $subscriptionId,
                                            ':billing_period_start' => $billingStart,
                                            ':billing_period_end' => $billingEnd,
                                            ':issue_date' => $issueDate,
                                            ':due_date' => $dueDate,
                                            ':subtotal' => $subtotal,
                                            ':discount' => $discount,
                                            ':tax' => $tax,
                                            ':total' => $calculatedTotal,
                                            ':status' => $status,
                                            ':notes' => $notes,
                                            ':id' => $invoiceId,
                                        ]);

                                        logActivity($pdo, (int) $currentUser['id'], 'invoice_updated', 'Invoice updated: ' . $existingInvoice['invoice_number']);
                                        header('Location: billing.php?success=invoice_updated');
                                        exit;
                                    }
                                }

                                if ($action === 'create') {
                                    $statement = $pdo->prepare(
                                        'INSERT INTO invoices (customer_id, subscription_id, invoice_number, billing_period_start, billing_period_end, issue_date, due_date, subtotal, discount, tax, total, status, notes, created_at, updated_at) VALUES (:customer_id, :subscription_id, :invoice_number, :billing_period_start, :billing_period_end, :issue_date, :due_date, :subtotal, :discount, :tax, :total, :status, :notes, NOW(), NOW())'
                                    );
                                    $statement->execute([
                                        ':customer_id' => $customerId,
                                        ':subscription_id' => $subscriptionId,
                                        ':invoice_number' => $invoiceNumber,
                                        ':billing_period_start' => $billingStart,
                                        ':billing_period_end' => $billingEnd,
                                        ':issue_date' => $issueDate,
                                        ':due_date' => $dueDate,
                                        ':subtotal' => $subtotal,
                                        ':discount' => $discount,
                                        ':tax' => $tax,
                                        ':total' => $calculatedTotal,
                                        ':status' => $status,
                                        ':notes' => $notes,
                                    ]);

                                    logActivity($pdo, (int) $currentUser['id'], 'invoice_created', 'Invoice created: ' . $invoiceNumber);
                                    header('Location: billing.php?success=invoice_created');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($action === 'cancel') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $invoiceStatement = $pdo->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
            $invoiceStatement->execute([':id' => $invoiceId]);
            $invoice = $invoiceStatement->fetch();

            if (!$invoice) {
                $errorMessage = 'Invoice not found.';
            } else {
                $pdo->prepare('UPDATE invoices SET status = :status, updated_at = NOW() WHERE id = :id')->execute([
                    ':status' => 'cancelled',
                    ':id' => $invoiceId,
                ]);
                logActivity($pdo, (int) $currentUser['id'], 'invoice_cancelled', 'Invoice cancelled: ' . $invoice['invoice_number']);
                header('Location: billing.php?success=invoice_cancelled');
                exit;
            }
        }
    }
}

$customerList = $pdo->query('SELECT id, customer_code, name FROM customers ORDER BY name ASC')->fetchAll();
$subscriptionOptionsByCustomer = [];
$subscriptionRows = $pdo->query(
    'SELECT s.id, s.customer_id, s.status AS subscription_status, p.name AS package_name, p.code AS package_code FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id ORDER BY s.customer_id ASC, s.created_at DESC'
)->fetchAll();

foreach ($subscriptionRows as $subscriptionRow) {
    $subscriptionOptionsByCustomer[(int) $subscriptionRow['customer_id']][] = [
        'id' => (int) $subscriptionRow['id'],
        'name' => $subscriptionRow['package_code'] . ' - ' . $subscriptionRow['package_name'],
        'status' => $subscriptionRow['subscription_status'],
    ];
}

if (isset($_GET['view']) && $_GET['view'] === 'detail' && $viewInvoiceId > 0) {
    $detailStatement = $pdo->prepare(
        'SELECT i.*, c.name AS customer_name, c.customer_code, c.phone, c.whatsapp, c.address, s.id AS subscription_id, p.name AS package_name, p.code AS package_code, p.price FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id WHERE i.id = :id LIMIT 1'
    );
    $detailStatement->execute([':id' => $viewInvoiceId]);
    $invoiceDetail = $detailStatement->fetch();

    if ($invoiceDetail) {
        $invoiceDetail['effective_status'] = effectiveInvoiceStatus($invoiceDetail);
        logActivity($pdo, (int) $currentUser['id'], 'invoice_viewed', 'Invoice viewed: ' . $invoiceDetail['invoice_number']);
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'unpaid', 'paid', 'overdue', 'cancelled'], true)) {
    $statusFilter = 'all';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(i.invoice_number LIKE :search OR c.name LIKE :search OR c.customer_code LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
    $conditions[] = "CASE WHEN i.status = 'unpaid' AND i.due_date < CURDATE() THEN 'overdue' ELSE i.status END = :status";
    $params[':status'] = $statusFilter;
}

$whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause
);
$countStatement->execute($params);
$totalInvoices = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalInvoices / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listQuery =
    'SELECT i.*, c.name AS customer_name, c.customer_code, p.name AS package_name, p.code AS package_code, CASE WHEN i.status = ' . "'unpaid'" . ' AND i.due_date < CURDATE() THEN ' . "'overdue'" . ' ELSE i.status END AS effective_status FROM invoices i INNER JOIN customers c ON c.id = i.customer_id INNER JOIN subscriptions s ON s.id = i.subscription_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause . ' ORDER BY i.created_at DESC LIMIT :limit OFFSET :offset';
$listStatement = $pdo->prepare($listQuery);
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

$modalCustomerId = (int) ($_POST['customer_id'] ?? ($editingInvoice['customer_id'] ?? 0));
$modalSubscriptionId = (int) ($_POST['subscription_id'] ?? ($editingInvoice['subscription_id'] ?? 0));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <?php if (isset($_GET['view']) && $_GET['view'] === 'detail' && $viewInvoiceId > 0 && isset($invoiceDetail)): ?>
            <div class="page-header">
                <h1>Invoice Detail</h1>
                <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                    <?php if (can('billing.print')): ?>
                        <a href="billing_print.php?id=<?php echo (int) $invoiceDetail['id']; ?>" class="btn btn-primary" target="_blank" rel="noopener">Print Invoice</a>
                    <?php endif; ?>
                    <a href="billing.php" class="btn btn-secondary">Back to Billing</a>
                </div>
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
                        <label>Service Period</label>
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
                                <th>Details</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Subtotal</td>
                                <td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['subtotal']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <td>Discount</td>
                                <td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['discount']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <td>Tax</td>
                                <td><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['tax']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total</strong></td>
                                <td><strong><?php echo htmlspecialchars(formatCurrency((float) $invoiceDetail['total']), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:1rem;">
                    <strong>Notes:</strong>
                    <p style="margin:0.5rem 0 0; white-space:pre-wrap;">
                        <?php echo nl2br(htmlspecialchars((string) ($invoiceDetail['notes'] ?? 'No notes'), ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h1>Billing & Invoices</h1>
                <?php if (can('billing.create')): ?>
                    <button type="button" class="btn btn-primary" data-open-modal="invoice-modal">+ Buat Invoice</button>
                <?php endif; ?>
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
                        <input class="form-control" id="billing_search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Invoice number, customer name, customer code">
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
                                <th>Customer</th>
                                <th>Subscription</th>
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
                                        <td><?php echo htmlspecialchars($invoice['customer_code'] . ' - ' . $invoice['customer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($invoice['package_code'] . ' - ' . $invoice['package_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['billing_period_start'])) . ' / ' . date('d-m-Y', strtotime($invoice['billing_period_end'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['issue_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['due_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(formatCurrency((float) $invoice['total']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge <?php echo invoiceStatusBadgeClass($invoice['effective_status']); ?>"><?php echo htmlspecialchars(invoiceStatusBadgeLabel($invoice['effective_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td>
                                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                                <a href="billing.php?view=detail&id=<?php echo (int) $invoice['id']; ?>" class="link-button">View</a>
                                                <?php if (can('billing.edit') && !in_array($invoice['effective_status'], ['paid', 'cancelled'], true)): ?>
                                                    <a href="billing.php?edit_id=<?php echo (int) $invoice['id']; ?>" class="link-button">Edit</a>
                                                <?php endif; ?>
                                                <?php if (can('billing.cancel') && strtolower((string) $invoice['status']) !== 'cancelled' && strtolower((string) $invoice['status']) !== 'paid'): ?>
                                                    <form method="post" action="billing.php" style="display:inline; margin:0;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="cancel">
                                                        <input type="hidden" name="invoice_id" value="<?php echo (int) $invoice['id']; ?>">
                                                        <button type="submit" class="link-button" onclick="return confirm('Cancel this invoice?');">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">No invoices found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <a href="billing.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $pageNumber; ?>" class="page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>"><?php echo $pageNumber; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (can('billing.create') || can('billing.edit')): ?>
    <div class="modal-overlay <?php echo $shouldOpenInvoiceModal ? 'is-open' : ''; ?>" id="invoice-modal" role="dialog" aria-modal="true" aria-labelledby="invoice-modal-title">
        <div class="modal">
            <div class="modal-header">
                <h3 id="invoice-modal-title"><?php echo $editingInvoice ? 'Edit Invoice' : 'Create Invoice'; ?></h3>
                <button type="button" class="modal-close" data-close-modal="invoice-modal" aria-label="Close invoice form">&times;</button>
            </div>

            <form method="post" action="billing.php" id="invoice-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="<?php echo $editingInvoice ? 'update' : 'create'; ?>">
                <?php if ($editingInvoice): ?>
                    <input type="hidden" name="invoice_id" value="<?php echo (int) $editingInvoice['id']; ?>">
                <?php endif; ?>
                <div class="modal-body">
                    <div class="modal-grid">
                        <div class="form-group">
                            <label for="invoice_customer_id">Customer</label>
                            <select class="form-control" id="invoice_customer_id" name="customer_id" required>
                                <option value="">Select customer</option>
                                <?php foreach ($customerList as $customer): ?>
                                    <option value="<?php echo (int) $customer['id']; ?>" <?php echo ((string) ($modalCustomerId ?: ($editingInvoice['customer_id'] ?? '')) === (string) $customer['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($customer['customer_code'] . ' - ' . $customer['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="invoice_subscription_id">Subscription</label>
                            <select class="form-control" id="invoice_subscription_id" name="subscription_id" required>
                                <option value="">Select subscription</option>
                                <?php if ($editingInvoice): ?>
                                    <option value="<?php echo (int) $editingInvoice['subscription_id']; ?>" selected><?php echo htmlspecialchars($editingInvoice['package_code'] . ' - ' . $editingInvoice['package_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="billing_period_start">Billing Period Start</label>
                            <input class="form-control" id="billing_period_start" name="billing_period_start" type="date" value="<?php echo htmlspecialchars($_POST['billing_period_start'] ?? ($editingInvoice['billing_period_start'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="billing_period_end">Billing Period End</label>
                            <input class="form-control" id="billing_period_end" name="billing_period_end" type="date" value="<?php echo htmlspecialchars($_POST['billing_period_end'] ?? ($editingInvoice['billing_period_end'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="issue_date">Issue Date</label>
                            <input class="form-control" id="issue_date" name="issue_date" type="date" value="<?php echo htmlspecialchars($_POST['issue_date'] ?? ($editingInvoice['issue_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="due_date">Due Date</label>
                            <input class="form-control" id="due_date" name="due_date" type="date" value="<?php echo htmlspecialchars($_POST['due_date'] ?? ($editingInvoice['due_date'] ?? date('Y-m-d', strtotime('+7 days'))), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="subtotal">Subtotal</label>
                            <input class="form-control" id="subtotal" name="subtotal" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($_POST['subtotal'] ?? ($editingInvoice['subtotal'] ?? '0')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="discount">Discount</label>
                            <input class="form-control" id="discount" name="discount" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($_POST['discount'] ?? ($editingInvoice['discount'] ?? '0')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="tax">Tax</label>
                            <input class="form-control" id="tax" name="tax" type="number" step="0.01" min="0" value="<?php echo htmlspecialchars((string) ($_POST['tax'] ?? ($editingInvoice['tax'] ?? '0')), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group full">
                            <label for="invoice_notes">Notes</label>
                            <textarea class="form-control" id="invoice_notes" name="notes"><?php echo htmlspecialchars((string) ($_POST['notes'] ?? ($editingInvoice['notes'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="invoice-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-submit-button><?php echo $editingInvoice ? 'Update Invoice' : 'Save Invoice'; ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
const subscriptionOptionsByCustomer = <?php echo json_encode($subscriptionOptionsByCustomer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const customerSelect = document.getElementById('invoice_customer_id');
const subscriptionSelect = document.getElementById('invoice_subscription_id');

function refreshSubscriptionOptions() {
    if (!customerSelect || !subscriptionSelect) {
        return;
    }

    const customerId = Number(customerSelect.value || 0);
    const selectedValue = '<?php echo htmlspecialchars((string) ($modalSubscriptionId ?: ($editingInvoice['subscription_id'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>';
    const choices = subscriptionOptionsByCustomer[customerId] || [];
    subscriptionSelect.innerHTML = '<option value="">Select subscription</option>';

    choices.forEach(function (option) {
        const optionNode = document.createElement('option');
        optionNode.value = option.id;
        optionNode.textContent = option.name;
        if (String(option.id) === String(selectedValue)) {
            optionNode.selected = true;
        }
        subscriptionSelect.appendChild(optionNode);
    });
}

if (customerSelect) {
    customerSelect.addEventListener('change', refreshSubscriptionOptions);
    refreshSubscriptionOptions();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
