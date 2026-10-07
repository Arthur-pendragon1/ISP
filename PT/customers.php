<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('customers.view');

if (isset($_GET['edit_id']) && !can('customers.edit')) {
    denyAccess('Anda tidak memiliki izin untuk mengedit pelanggan.');
}

$currentUser = currentUser();
$canWrite = can('customers.create') || can('customers.edit');
$pageTitle = 'Customer Management';
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'customer_created' => 'Customer berhasil ditambahkan.',
        'customer_updated' => 'Customer berhasil diperbarui.',
        default => 'Operasi berhasil.'
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security validation failed.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            requirePermission('customers.create');
        } elseif ($action === 'update' || $action === 'status') {
            requirePermission('customers.edit');
        }

        if ($action === 'create' || $action === 'update') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to modify customers.';
            } else {
                $name = trim((string) ($_POST['name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));
                $city = trim((string) ($_POST['city'] ?? ''));
                $province = trim((string) ($_POST['province'] ?? ''));
                $postalCode = trim((string) ($_POST['postal_code'] ?? ''));
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended'], true) ? $_POST['status'] : 'active';
                $notes = trim((string) ($_POST['notes'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');

                if ($name === '' || $username === '' || $email === '') {
                    $errorMessage = 'Name, username, and email are required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = 'Please provide a valid email address.';
                } elseif (strlen($phone) > 30 || strlen($whatsapp) > 30) {
                    $errorMessage = 'Phone and WhatsApp values are too long.';
                } else {
                    $existingUser = $pdo->prepare('SELECT id FROM customers WHERE username = :username OR email = :email LIMIT 1');
                    $existingUser->execute([':username' => $username, ':email' => $email]);
                    $existingUserRow = $existingUser->fetch();

                    if ($action === 'create' && ($password === '' || strlen($password) < 6)) {
                        $errorMessage = 'Password is required and must be at least 6 characters.';
                    } elseif ($action === 'update' && $existingUserRow && (int) $existingUserRow['id'] !== (int) ($_POST['customer_id'] ?? 0)) {
                        $errorMessage = 'Username or email is already used by another customer.';
                    } elseif ($existingUserRow && $action === 'create') {
                        $errorMessage = 'Username or email is already used.';
                    } else {
                        if ($action === 'create') {
                            $customerCode = generateCustomerCode($pdo);
                            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                            $statement = $pdo->prepare(
                                'INSERT INTO customers (customer_code, name, username, email, password, phone, whatsapp, address, city, province, postal_code, status, notes, created_at, updated_at) VALUES (:customer_code, :name, :username, :email, :password, :phone, :whatsapp, :address, :city, :province, :postal_code, :status, :notes, NOW(), NOW())'
                            );
                            $statement->execute([
                                ':customer_code' => $customerCode,
                                ':name' => $name,
                                ':username' => $username,
                                ':email' => $email,
                                ':password' => $hashedPassword,
                                ':phone' => $phone,
                                ':whatsapp' => $whatsapp,
                                ':address' => $address,
                                ':city' => $city,
                                ':province' => $province,
                                ':postal_code' => $postalCode,
                                ':status' => $status,
                                ':notes' => $notes,
                            ]);
                            logActivity($pdo, (int) $currentUser['id'], 'customer_created', 'Customer created: ' . $customerCode);
                            $successMessage = 'Customer berhasil ditambahkan.';
                            header('Location: customers.php?success=customer_created');
                            exit;
                        } else {
                            $customerId = (int) ($_POST['customer_id'] ?? 0);
                            $currentCustomer = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
                            $currentCustomer->execute([':id' => $customerId]);
                            $customerRow = $currentCustomer->fetch();

                            if (!$customerRow) {
                                $errorMessage = 'Customer not found.';
                            } else {
                                $newPassword = trim((string) ($_POST['password'] ?? ''));
                                $query = 'UPDATE customers SET name = :name, username = :username, email = :email, phone = :phone, whatsapp = :whatsapp, address = :address, city = :city, province = :province, postal_code = :postal_code, status = :status, notes = :notes, updated_at = NOW()';
                                $params = [
                                    ':name' => $name,
                                    ':username' => $username,
                                    ':email' => $email,
                                    ':phone' => $phone,
                                    ':whatsapp' => $whatsapp,
                                    ':address' => $address,
                                    ':city' => $city,
                                    ':province' => $province,
                                    ':postal_code' => $postalCode,
                                    ':status' => $status,
                                    ':notes' => $notes,
                                ];
                                if ($newPassword !== '') {
                                    if (strlen($newPassword) < 6) {
                                        $errorMessage = 'New password must be at least 6 characters.';
                                    } else {
                                        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                                        $query .= ', password = :password';
                                        $params[':password'] = $hashedPassword;
                                    }
                                }

                                if ($errorMessage === '') {
                                    $query .= ' WHERE id = :id';
                                    $params[':id'] = $customerId;
                                    $statement = $pdo->prepare($query);
                                    $statement->execute($params);
                                    logActivity($pdo, (int) $currentUser['id'], 'customer_updated', 'Customer updated: ' . $customerRow['customer_code']);
                                    $successMessage = 'Customer berhasil diperbarui.';
                                    header('Location: customers.php?success=customer_updated');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'status') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to change customer status.';
            } else {
                $customerId = (int) ($_POST['customer_id'] ?? 0);
                $newStatus = in_array($_POST['status'] ?? '', ['active', 'inactive', 'suspended'], true) ? $_POST['status'] : 'active';
                $statement = $pdo->prepare('UPDATE customers SET status = :status, updated_at = NOW() WHERE id = :id');
                $statement->execute([':status' => $newStatus, ':id' => $customerId]);
                logActivity($pdo, (int) $currentUser['id'], 'customer_status_changed', 'Customer status changed to ' . $newStatus);
                $successMessage = 'Customer status updated.';
            }
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(c.customer_code LIKE :search OR c.name LIKE :search OR c.username LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'inactive', 'suspended'], true)) {
    $conditions[] = 'c.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$countQuery = 'SELECT COUNT(*) FROM customers c' . $whereClause;
$countStatement = $pdo->prepare($countQuery);
$countStatement->execute($params);
$totalCustomers = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalCustomers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$customerQuery = 'SELECT c.*, (SELECT p.name FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.customer_id = c.id AND s.status = :active_status ORDER BY s.created_at DESC LIMIT 1) AS active_package FROM customers c' . $whereClause . ' ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset';
$paramsForList = $params;
$paramsForList[':active_status'] = 'active';
$paramsForList[':limit'] = $perPage;
$paramsForList[':offset'] = $offset;
$customerStatement = $pdo->prepare($customerQuery);
$customerStatement->bindValue(':active_status', 'active', PDO::PARAM_STR);
$customerStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$customerStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $customerStatement->bindValue($key, $value);
}
$customerStatement->execute();
$customers = $customerStatement->fetchAll();

$customerDetail = null;
if (isset($_GET['view']) && $_GET['view'] === 'detail' && !empty($_GET['id'])) {
    $customerId = (int) $_GET['id'];
    $detailStatement = $pdo->prepare(
        'SELECT c.*, (SELECT p.name FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.customer_id = c.id AND s.status = :active_status ORDER BY s.created_at DESC LIMIT 1) AS active_package FROM customers c WHERE c.id = :id LIMIT 1'
    );
    $detailStatement->execute([':active_status' => 'active', ':id' => $customerId]);
    $customerDetail = $detailStatement->fetch();

    if ($customerDetail) {
        $subscriptionStatement = $pdo->prepare(
            'SELECT s.*, p.name AS package_name, p.speed, p.speed_unit, p.price FROM subscriptions s INNER JOIN packages p ON p.id = s.package_id WHERE s.customer_id = :customer_id ORDER BY s.created_at DESC'
        );
        $subscriptionStatement->execute([':customer_id' => $customerId]);
        $customerSubscriptions = $subscriptionStatement->fetchAll();
    }
}

if (isset($_GET['edit_id'])) {
    $editingCustomer = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
    $editingCustomer->execute([':id' => (int) $_GET['edit_id']]);
    $editingCustomer = $editingCustomer->fetch();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>Customer Management</h1>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="modal-overlay <?php echo ((isset($_GET['edit_id']) && $editingCustomer) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') !== '' && $errorMessage !== '')) ? 'is-open' : ''; ?>" id="customer-modal" role="dialog" aria-modal="true" aria-labelledby="customer-modal-title">
            <div class="modal">
                <div class="modal-header">
                    <h3 id="customer-modal-title"><?php echo isset($_GET['edit_id']) ? 'Edit Customer' : 'Tambah Customer'; ?></h3>
                    <button type="button" class="modal-close" data-close-modal="customer-modal" aria-label="Close customer form">&times;</button>
                </div>

                <form method="post" action="customers.php" id="customer-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_id']) ? 'update' : 'create'; ?>">
                    <?php if (isset($_GET['edit_id'])): ?>
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars((string) ($editingCustomer['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="modal-body">
                        <div class="modal-grid">
                            <div class="form-group">
                                <label for="customer_name">Name</label>
                                <input class="form-control" id="customer_name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ($editingCustomer['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="customer_username">Username</label>
                                <input class="form-control" id="customer_username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ($editingCustomer['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="customer_email">Email</label>
                                <input class="form-control" id="customer_email" name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ($editingCustomer['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="customer_password"><?php echo isset($_GET['edit_id']) ? 'Password Baru (opsional)' : 'Password'; ?></label>
                                <input class="form-control" id="customer_password" name="password" type="password" <?php echo isset($_GET['edit_id']) ? '' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label for="customer_phone">Phone</label>
                                <input class="form-control" id="customer_phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ($editingCustomer['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_whatsapp">WhatsApp</label>
                                <input class="form-control" id="customer_whatsapp" name="whatsapp" value="<?php echo htmlspecialchars($_POST['whatsapp'] ?? ($editingCustomer['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_city">City</label>
                                <input class="form-control" id="customer_city" name="city" value="<?php echo htmlspecialchars($_POST['city'] ?? ($editingCustomer['city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_province">Province</label>
                                <input class="form-control" id="customer_province" name="province" value="<?php echo htmlspecialchars($_POST['province'] ?? ($editingCustomer['province'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_postal_code">Postal Code</label>
                                <input class="form-control" id="customer_postal_code" name="postal_code" value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ($editingCustomer['postal_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_status">Status</label>
                                <select class="form-control" id="customer_status" name="status">
                                    <option value="active" <?php echo (($_POST['status'] ?? ($editingCustomer['status'] ?? 'active')) === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (($_POST['status'] ?? ($editingCustomer['status'] ?? 'active')) === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo (($_POST['status'] ?? ($editingCustomer['status'] ?? 'active')) === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label for="customer_address">Address</label>
                                <textarea class="form-control" id="customer_address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ($editingCustomer['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="form-group full">
                                <label for="customer_notes">Notes</label>
                                <textarea class="form-control" id="customer_notes" name="notes" rows="3"><?php echo htmlspecialchars($_POST['notes'] ?? ($editingCustomer['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <?php if (isset($_GET['edit_id']) && !empty($editingCustomer['customer_code'])): ?>
                                <div class="form-group full">
                                    <label>Customer Code</label>
                                    <div class="readonly-field"><?php echo htmlspecialchars($editingCustomer['customer_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-close-modal="customer-modal">Batal</button>
                        <button type="submit" class="btn btn-primary" data-submit-button="customer-form"><?php echo isset($_GET['edit_id']) ? 'Simpan Perubahan' : 'Simpan'; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="page-header" style="margin-bottom:1rem;">
                <h2 style="margin:0;">Customer List</h2>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-primary" data-open-modal="customer-modal" style="width:auto;">+ Tambah Customer</button>
                <?php endif; ?>
            </div>
            <form method="get" action="customers.php" style="margin-bottom:1rem;">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Customer code, name, username, phone, email">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
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
                            <th>Code</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Phone</th>
                            <th>WhatsApp</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Package</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($customers): ?>
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($customer['customer_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['whatsapp'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge <?php echo customerStatusToBadge($customer['status']); ?>"><?php echo htmlspecialchars(strtoupper($customer['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars($customer['active_package'] ?? 'No active package', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <a href="customers.php?view=detail&id=<?php echo (int) $customer['id']; ?>">Detail</a>
                                        <?php if ($canWrite): ?>
                                            | <button type="button" class="link-button" data-open-edit="customer-modal" data-customer-id="<?php echo (int) $customer['id']; ?>">Edit</button>
                                            | <form method="post" action="customers.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="customer_id" value="<?php echo (int) $customer['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $customer['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                <button class="link-button" type="submit"><?php echo $customer['status'] === 'active' ? 'Set Inactive' : 'Set Active'; ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10">No customers found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div style="margin-top:1rem;">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <a href="customers.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $pageNumber; ?>" class="page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($customerDetail): ?>
            <div class="card" style="margin-top:1.5rem;">
                <h2>Customer Detail</h2>
                <div class="form-grid">
                    <div class="form-group"><strong>Customer Code:</strong> <?php echo htmlspecialchars($customerDetail['customer_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Name:</strong> <?php echo htmlspecialchars($customerDetail['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Username:</strong> <?php echo htmlspecialchars($customerDetail['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Email:</strong> <?php echo htmlspecialchars($customerDetail['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Phone:</strong> <?php echo htmlspecialchars($customerDetail['phone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>WhatsApp:</strong> <?php echo htmlspecialchars($customerDetail['whatsapp'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Address:</strong> <?php echo htmlspecialchars($customerDetail['address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>City:</strong> <?php echo htmlspecialchars($customerDetail['city'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Province:</strong> <?php echo htmlspecialchars($customerDetail['province'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Postal Code:</strong> <?php echo htmlspecialchars($customerDetail['postal_code'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Status:</strong> <span class="badge <?php echo customerStatusToBadge($customerDetail['status']); ?>"><?php echo htmlspecialchars(strtoupper($customerDetail['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="form-group"><strong>Created:</strong> <?php echo htmlspecialchars($customerDetail['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Updated:</strong> <?php echo htmlspecialchars($customerDetail['updated_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Active Package:</strong> <?php echo htmlspecialchars($customerDetail['active_package'] ?? 'No active package', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>

                <h3>Subscription History</h3>
                <?php if (!empty($customerSubscriptions)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Package</th>
                                <th>Speed</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Start</th>
                                <th>End</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customerSubscriptions as $subscription): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subscription['package_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) $subscription['speed'] . ' ' . $subscription['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $subscription['price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge <?php echo customerStatusToBadge($subscription['status']); ?>"><?php echo htmlspecialchars(strtoupper($subscription['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars($subscription['start_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($subscription['end_date'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>Customer belum memiliki paket aktif.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
