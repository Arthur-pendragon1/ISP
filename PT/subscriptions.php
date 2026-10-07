<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('subscriptions.view');

if (isset($_GET['edit_id']) && !can('subscriptions.edit')) {
    denyAccess('Anda tidak memiliki izin untuk mengedit langganan.');
}

$currentUser = currentUser();
$canWrite = can('subscriptions.create') || can('subscriptions.edit');
$pageTitle = 'Subscriptions';
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'subscription_created' => 'Subscription berhasil ditambahkan.',
        'subscription_updated' => 'Subscription berhasil diperbarui.',
        default => 'Operasi berhasil.'
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security validation failed.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            requirePermission('subscriptions.create');
        } elseif ($action === 'update') {
            requirePermission('subscriptions.edit');
        }

        if ($action === 'create' || $action === 'update') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to modify subscriptions.';
            } else {
                $customerId = (int) ($_POST['customer_id'] ?? 0);
                $packageId = (int) ($_POST['package_id'] ?? 0);
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended', 'expired'], true) ? $_POST['status'] : 'active';
                $startDate = trim((string) ($_POST['start_date'] ?? ''));
                $endDate = trim((string) ($_POST['end_date'] ?? ''));
                $installationAddress = trim((string) ($_POST['installation_address'] ?? ''));
                $notes = trim((string) ($_POST['notes'] ?? ''));

                if ($customerId <= 0 || $packageId <= 0) {
                    $errorMessage = 'Customer and package are required.';
                } elseif ($startDate === '') {
                    $errorMessage = 'Start date is required.';
                } else {
                    $customerCheck = $pdo->prepare('SELECT id, status FROM customers WHERE id = :id LIMIT 1');
                    $customerCheck->execute([':id' => $customerId]);
                    $customerRow = $customerCheck->fetch();
                    $packageCheck = $pdo->prepare('SELECT id, status, code FROM packages WHERE id = :id LIMIT 1');
                    $packageCheck->execute([':id' => $packageId]);
                    $packageRow = $packageCheck->fetch();

                    if (!$customerRow) {
                        $errorMessage = 'Selected customer does not exist.';
                    } elseif (!$packageRow) {
                        $errorMessage = 'Selected package does not exist.';
                    } elseif ($customerRow['status'] !== 'active') {
                        $errorMessage = 'Customer must be active before creating a subscription.';
                    } elseif ($packageRow['status'] !== 'active' && $status === 'active') {
                        $errorMessage = 'Selected package is inactive and cannot be assigned to an active subscription.';
                    } else {
                        if ($action === 'create') {
                            $activeSubscriptionCheck = $pdo->prepare('SELECT id FROM subscriptions WHERE customer_id = :customer_id AND status = :status LIMIT 1');
                            $activeSubscriptionCheck->execute([':customer_id' => $customerId, ':status' => 'active']);
                            if ($status === 'active' && $activeSubscriptionCheck->fetch()) {
                                $errorMessage = 'This customer already has an active subscription. Please set the existing one to inactive before creating a new active subscription.';
                            } else {
                                $statement = $pdo->prepare(
                                    'INSERT INTO subscriptions (customer_id, package_id, start_date, end_date, status, installation_address, notes, created_at, updated_at) VALUES (:customer_id, :package_id, :start_date, :end_date, :status, :installation_address, :notes, NOW(), NOW())'
                                );
                                $statement->execute([
                                    ':customer_id' => $customerId,
                                    ':package_id' => $packageId,
                                    ':start_date' => $startDate,
                                    ':end_date' => $endDate !== '' ? $endDate : null,
                                    ':status' => $status,
                                    ':installation_address' => $installationAddress,
                                    ':notes' => $notes,
                                ]);
                                logActivity($pdo, (int) $currentUser['id'], 'subscription_created', 'Subscription created for customer ' . (int) $customerId);
                                $successMessage = 'Subscription berhasil ditambahkan.';
                                header('Location: subscriptions.php?success=subscription_created');
                                exit;
                            }
                        } else {
                            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
                            $existingSubscription = $pdo->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
                            $existingSubscription->execute([':id' => $subscriptionId]);
                            $subscriptionRow = $existingSubscription->fetch();

                            if (!$subscriptionRow) {
                                $errorMessage = 'Subscription not found.';
                            } else {
                                if ($status === 'active') {
                                    $duplicateCheck = $pdo->prepare('SELECT id FROM subscriptions WHERE customer_id = :customer_id AND status = :status AND id != :id LIMIT 1');
                                    $duplicateCheck->execute([':customer_id' => $customerId, ':status' => 'active', ':id' => $subscriptionId]);
                                    if ($duplicateCheck->fetch()) {
                                        $errorMessage = 'This customer already has another active subscription.';
                                    }
                                }

                                if ($errorMessage === '') {
                                    $statement = $pdo->prepare(
                                        'UPDATE subscriptions SET customer_id = :customer_id, package_id = :package_id, start_date = :start_date, end_date = :end_date, status = :status, installation_address = :installation_address, notes = :notes, updated_at = NOW() WHERE id = :id'
                                    );
                                    $statement->execute([
                                        ':customer_id' => $customerId,
                                        ':package_id' => $packageId,
                                        ':start_date' => $startDate,
                                        ':end_date' => $endDate !== '' ? $endDate : null,
                                        ':status' => $status,
                                        ':installation_address' => $installationAddress,
                                        ':notes' => $notes,
                                        ':id' => $subscriptionId,
                                    ]);
                                    logActivity($pdo, (int) $currentUser['id'], 'subscription_updated', 'Subscription updated for customer ' . (int) $customerId);
                                    $successMessage = 'Subscription berhasil diperbarui.';
                                    header('Location: subscriptions.php?success=subscription_updated');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(c.name LIKE :search OR c.customer_code LIKE :search OR p.name LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'inactive', 'suspended', 'expired'], true)) {
    $where[] = 's.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM subscriptions s INNER JOIN customers c ON c.id = s.customer_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause);
$countStatement->execute($params);
$totalSubscriptions = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalSubscriptions / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = $pdo->prepare(
    'SELECT s.*, c.name AS customer_name, c.customer_code, p.name AS package_name, p.speed, p.speed_unit, p.price FROM subscriptions s INNER JOIN customers c ON c.id = s.customer_id INNER JOIN packages p ON p.id = s.package_id' . $whereClause . ' ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset'
);
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $statement->bindValue($key, $value);
}
$statement->execute();
$subscriptions = $statement->fetchAll();

$customerOptions = $pdo->query('SELECT id, customer_code, name FROM customers ORDER BY name ASC')->fetchAll();
$packageOptions = $pdo->query('SELECT id, name, code, status FROM packages WHERE status = "active" ORDER BY name ASC')->fetchAll();

$editingSubscription = null;
if (isset($_GET['edit_id'])) {
    $editingStatement = $pdo->prepare('SELECT * FROM subscriptions WHERE id = :id LIMIT 1');
    $editingStatement->execute([':id' => (int) $_GET['edit_id']]);
    $editingSubscription = $editingStatement->fetch();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>Subscriptions</h1>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="modal-overlay <?php echo ((isset($_GET['edit_id']) && $editingSubscription) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') !== '' && $errorMessage !== '')) ? 'is-open' : ''; ?>" id="subscription-modal" role="dialog" aria-modal="true" aria-labelledby="subscription-modal-title">
            <div class="modal">
                <div class="modal-header">
                    <h3 id="subscription-modal-title"><?php echo isset($_GET['edit_id']) ? 'Edit Subscription' : 'Tambah Subscription'; ?></h3>
                    <button type="button" class="modal-close" data-close-modal="subscription-modal" aria-label="Close subscription form">&times;</button>
                </div>

                <form method="post" action="subscriptions.php" id="subscription-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_id']) ? 'update' : 'create'; ?>">
                    <?php if (isset($_GET['edit_id'])): ?>
                        <input type="hidden" name="subscription_id" value="<?php echo htmlspecialchars((string) ($editingSubscription['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="modal-body">
                        <div class="modal-grid">
                            <div class="form-group">
                                <label for="subscription_customer_id">Customer</label>
                                <select class="form-control" id="subscription_customer_id" name="customer_id" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customerOptions as $customer): ?>
                                        <option value="<?php echo (int) $customer['id']; ?>" <?php echo ((string) ($_POST['customer_id'] ?? ($editingSubscription['customer_id'] ?? '')) === (string) $customer['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($customer['customer_code'] . ' - ' . $customer['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="subscription_package_id">Package</label>
                                <select class="form-control" id="subscription_package_id" name="package_id" required>
                                    <option value="">Select package</option>
                                    <?php foreach ($packageOptions as $package): ?>
                                        <option value="<?php echo (int) $package['id']; ?>" <?php echo ((string) ($_POST['package_id'] ?? ($editingSubscription['package_id'] ?? '')) === (string) $package['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($package['name'] . ' — ' . $package['code'] . ' - ' . $package['speed'] . ' ' . $package['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="subscription_start_date">Start Date</label>
                                <input class="form-control" id="subscription_start_date" name="start_date" type="date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? ($editingSubscription['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="subscription_end_date">End Date</label>
                                <input class="form-control" id="subscription_end_date" name="end_date" type="date" value="<?php echo htmlspecialchars($_POST['end_date'] ?? ($editingSubscription['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="subscription_status">Status</label>
                                <select class="form-control" id="subscription_status" name="status">
                                    <option value="active" <?php echo (($_POST['status'] ?? ($editingSubscription['status'] ?? 'active')) === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (($_POST['status'] ?? ($editingSubscription['status'] ?? 'active')) === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo (($_POST['status'] ?? ($editingSubscription['status'] ?? 'active')) === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="expired" <?php echo (($_POST['status'] ?? ($editingSubscription['status'] ?? 'active')) === 'expired') ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label for="subscription_installation_address">Installation Address</label>
                                <textarea class="form-control" id="subscription_installation_address" name="installation_address" rows="3"><?php echo htmlspecialchars($_POST['installation_address'] ?? ($editingSubscription['installation_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="form-group full">
                                <label for="subscription_notes">Notes</label>
                                <textarea class="form-control" id="subscription_notes" name="notes" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? ($editingSubscription['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-close-modal="subscription-modal">Batal</button>
                        <button type="submit" class="btn btn-primary" data-submit-button="subscription-form"><?php echo isset($_GET['edit_id']) ? 'Simpan Perubahan' : 'Simpan'; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="page-header" style="margin-bottom:1rem;">
                <h2 style="margin:0;">Subscription List</h2>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-primary" data-open-modal="subscription-modal" style="width:auto;">+ Tambah Subscription</button>
                <?php endif; ?>
            </div>
            <form method="get" action="subscriptions.php" style="margin-bottom:1rem;">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Customer or package">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" style="width:auto;">Filter</button>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Code</th>
                            <th>Package</th>
                            <th>Speed</th>
                            <th>Price</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($subscriptions): ?>
                            <?php foreach ($subscriptions as $subscription): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subscription['customer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($subscription['customer_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($subscription['package_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) $subscription['speed'] . ' ' . $subscription['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $subscription['price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($subscription['start_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($subscription['end_date'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge <?php echo customerStatusToBadge($subscription['status']); ?>"><?php echo htmlspecialchars(strtoupper($subscription['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td>
                                        <?php if ($canWrite): ?>
                                            <button type="button" class="link-button" data-open-edit="subscription-modal" data-subscription-id="<?php echo (int) $subscription['id']; ?>">Edit</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">No subscriptions found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div style="margin-top:1rem;">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <a href="subscriptions.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $pageNumber; ?>" class="page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
