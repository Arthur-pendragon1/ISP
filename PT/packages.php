<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('packages.view');

if (isset($_GET['edit_id']) && !can('packages.edit')) {
    denyAccess('Anda tidak memiliki izin untuk mengedit paket.');
}

$currentUser = currentUser();
$canWrite = can('packages.create') || can('packages.edit');
$pageTitle = 'Packages';
$successMessage = '';
$errorMessage = '';

if (isset($_GET['success'])) {
    $successMessage = match ($_GET['success']) {
        'package_created' => 'Package berhasil ditambahkan.',
        'package_updated' => 'Package berhasil diperbarui.',
        default => 'Operasi berhasil.'
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security validation failed.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            requirePermission('packages.create');
        } elseif ($action === 'update' || $action === 'status') {
            requirePermission('packages.edit');
        }

        if ($action === 'create' || $action === 'update') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to modify packages.';
            } else {
                $name = trim((string) ($_POST['name'] ?? ''));
                $code = trim((string) ($_POST['code'] ?? ''));
                $speed = trim((string) ($_POST['speed'] ?? ''));
                $speedUnit = trim((string) ($_POST['speed_unit'] ?? 'Mbps'));
                $price = trim((string) ($_POST['price'] ?? '0'));
                $description = trim((string) ($_POST['description'] ?? ''));
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

                if ($name === '') {
                    $errorMessage = 'Package name is required.';
                } elseif ($code === '') {
                    $errorMessage = 'Package code is required.';
                } elseif (!is_numeric($speed) || (float) $speed <= 0) {
                    $errorMessage = 'Speed must be a positive number.';
                } elseif (!is_numeric($price) || (float) $price < 0) {
                    $errorMessage = 'Price must be zero or greater.';
                } else {
                    $packageCodeExists = $pdo->prepare('SELECT id FROM packages WHERE code = :code LIMIT 1');
                    $packageCodeExists->execute([':code' => $code]);
                    $existingPackage = $packageCodeExists->fetch();

                    if ($action === 'create' && $existingPackage) {
                        $errorMessage = 'Package code already exists.';
                    } else {
                        if ($action === 'create') {
                            $statement = $pdo->prepare(
                                'INSERT INTO packages (name, code, speed, speed_unit, price, description, status, created_at, updated_at) VALUES (:name, :code, :speed, :speed_unit, :price, :description, :status, NOW(), NOW())'
                            );
                            $statement->execute([
                                ':name' => $name,
                                ':code' => strtoupper($code),
                                ':speed' => (int) $speed,
                                ':speed_unit' => $speedUnit,
                                ':price' => (float) $price,
                                ':description' => $description,
                                ':status' => $status,
                            ]);
                            logActivity($pdo, (int) $currentUser['id'], 'package_created', 'Package created: ' . strtoupper($code));
                            $successMessage = 'Package berhasil ditambahkan.';
                            header('Location: packages.php?success=package_created');
                            exit;
                        } else {
                            $packageId = (int) ($_POST['package_id'] ?? 0);
                            $existing = $pdo->prepare('SELECT id, code FROM packages WHERE id = :id LIMIT 1');
                            $existing->execute([':id' => $packageId]);
                            $row = $existing->fetch();

                            if (!$row) {
                                $errorMessage = 'Package not found.';
                            } else {
                                if ($row['code'] !== strtoupper($code)) {
                                    $duplicateCheck = $pdo->prepare('SELECT id FROM packages WHERE code = :code AND id != :id LIMIT 1');
                                    $duplicateCheck->execute([':code' => strtoupper($code), ':id' => $packageId]);
                                    if ($duplicateCheck->fetch()) {
                                        $errorMessage = 'Package code already exists.';
                                    }
                                }

                                if ($errorMessage === '') {
                                    $statement = $pdo->prepare(
                                        'UPDATE packages SET name = :name, code = :code, speed = :speed, speed_unit = :speed_unit, price = :price, description = :description, status = :status, updated_at = NOW() WHERE id = :id'
                                    );
                                    $statement->execute([
                                        ':name' => $name,
                                        ':code' => strtoupper($code),
                                        ':speed' => (int) $speed,
                                        ':speed_unit' => $speedUnit,
                                        ':price' => (float) $price,
                                        ':description' => $description,
                                        ':status' => $status,
                                        ':id' => $packageId,
                                    ]);
                                    logActivity($pdo, (int) $currentUser['id'], 'package_updated', 'Package updated: ' . strtoupper($code));
                                    $successMessage = 'Package berhasil diperbarui.';
                                    header('Location: packages.php?success=package_updated');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'status') {
            if (!$canWrite) {
                $errorMessage = 'You do not have permission to update package status.';
            } else {
                $packageId = (int) ($_POST['package_id'] ?? 0);
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
                $statement = $pdo->prepare('UPDATE packages SET status = :status, updated_at = NOW() WHERE id = :id');
                $statement->execute([':status' => $status, ':id' => $packageId]);
                logActivity($pdo, (int) $currentUser['id'], 'package_status_changed', 'Package status changed to ' . $status);
                $successMessage = 'Package status updated.';
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
    $where[] = '(name LIKE :search OR code LIKE :search OR description LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM packages' . $whereClause);
$countStatement->execute($params);
$totalPackages = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalPackages / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = $pdo->prepare('SELECT * FROM packages' . $whereClause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $statement->bindValue($key, $value);
}
$statement->execute();
$packages = $statement->fetchAll();

$packageDetail = null;
if (isset($_GET['view']) && $_GET['view'] === 'detail' && !empty($_GET['id'])) {
    $detailStatement = $pdo->prepare('SELECT * FROM packages WHERE id = :id LIMIT 1');
    $detailStatement->execute([':id' => (int) $_GET['id']]);
    $packageDetail = $detailStatement->fetch();
}

if (isset($_GET['edit_id'])) {
    $editingPackage = $pdo->prepare('SELECT * FROM packages WHERE id = :id LIMIT 1');
    $editingPackage->execute([':id' => (int) $_GET['edit_id']]);
    $editingPackage = $editingPackage->fetch();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>Packages</h1>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="modal-overlay <?php echo ((isset($_GET['edit_id']) && $editingPackage) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') !== '' && $errorMessage !== '')) ? 'is-open' : ''; ?>" id="package-modal" role="dialog" aria-modal="true" aria-labelledby="package-modal-title">
            <div class="modal">
                <div class="modal-header">
                    <h3 id="package-modal-title"><?php echo isset($_GET['edit_id']) ? 'Edit Package' : 'Tambah Package'; ?></h3>
                    <button type="button" class="modal-close" data-close-modal="package-modal" aria-label="Close package form">&times;</button>
                </div>

                <form method="post" action="packages.php" id="package-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_id']) ? 'update' : 'create'; ?>">
                    <?php if (isset($_GET['edit_id'])): ?>
                        <input type="hidden" name="package_id" value="<?php echo htmlspecialchars((string) ($editingPackage['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="modal-body">
                        <div class="modal-grid">
                            <div class="form-group">
                                <label for="package_name">Package Name</label>
                                <input class="form-control" id="package_name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ($editingPackage['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="package_code">Code</label>
                                <input class="form-control" id="package_code" name="code" value="<?php echo htmlspecialchars($_POST['code'] ?? ($editingPackage['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="package_speed">Speed</label>
                                <input class="form-control" id="package_speed" name="speed" type="number" min="1" value="<?php echo htmlspecialchars((string) ($_POST['speed'] ?? ($editingPackage['speed'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="package_speed_unit">Speed Unit</label>
                                <input class="form-control" id="package_speed_unit" name="speed_unit" value="<?php echo htmlspecialchars($_POST['speed_unit'] ?? ($editingPackage['speed_unit'] ?? 'Mbps'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="package_price">Price</label>
                                <input class="form-control" id="package_price" name="price" type="number" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($_POST['price'] ?? ($editingPackage['price'] ?? '0')), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="package_status">Status</label>
                                <select class="form-control" id="package_status" name="status">
                                    <option value="active" <?php echo (($_POST['status'] ?? ($editingPackage['status'] ?? 'active')) === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (($_POST['status'] ?? ($editingPackage['status'] ?? 'active')) === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label for="package_description">Description</label>
                                <textarea class="form-control" id="package_description" name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ($editingPackage['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <?php if (isset($_GET['edit_id']) && !empty($editingPackage['code'])): ?>
                                <div class="form-group full">
                                    <label>Package Code</label>
                                    <div class="readonly-field"><?php echo htmlspecialchars($editingPackage['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-close-modal="package-modal">Batal</button>
                        <button type="submit" class="btn btn-primary" data-submit-button="package-form"><?php echo isset($_GET['edit_id']) ? 'Simpan Perubahan' : 'Simpan'; ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="page-header" style="margin-bottom:1rem;">
                <h2 style="margin:0;">Package List</h2>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-primary" data-open-modal="package-modal" style="width:auto;">+ Tambah Package</button>
                <?php endif; ?>
            </div>
            <form method="get" action="packages.php" style="margin-bottom:1rem;">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name or code">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
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
                            <th>Name</th>
                            <th>Code</th>
                            <th>Speed</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($packages): ?>
                            <?php foreach ($packages as $package): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($package['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) $package['speed'] . ' ' . $package['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $package['price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><span class="badge <?php echo $package['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo htmlspecialchars(strtoupper($package['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars($package['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="action-group">
                                        <a href="packages.php?view=detail&id=<?php echo (int) $package['id']; ?>" class="btn btn-sm btn-outline">Detail</a>
                                        <?php if ($canWrite): ?>
                                            <button type="button" class="btn btn-sm btn-outline" data-open-edit="package-modal" data-package-id="<?php echo (int) $package['id']; ?>">Edit</button>
                                            <form method="post" action="packages.php" class="action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(createCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="status">
                                                <input type="hidden" name="package_id" value="<?php echo (int) $package['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $package['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                <button class="btn btn-sm <?php echo $package['status'] === 'active' ? 'btn-danger-outline' : 'btn-outline'; ?>" type="submit"><?php echo $package['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No packages found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div style="margin-top:1rem;">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <a href="packages.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $pageNumber; ?>" class="page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($packageDetail): ?>
            <div class="card" style="margin-top:1.5rem;">
                <h2>Package Detail</h2>
                <div class="form-grid">
                    <div class="form-group"><strong>Name:</strong> <?php echo htmlspecialchars($packageDetail['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Code:</strong> <?php echo htmlspecialchars($packageDetail['code'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Speed:</strong> <?php echo htmlspecialchars((string) $packageDetail['speed'] . ' ' . $packageDetail['speed_unit'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Price:</strong> <?php echo htmlspecialchars(number_format((float) $packageDetail['price'], 2), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group"><strong>Status:</strong> <span class="badge <?php echo $packageDetail['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>"><?php echo htmlspecialchars(strtoupper($packageDetail['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="form-group"><strong>Created At:</strong> <?php echo htmlspecialchars($packageDetail['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="form-group" style="grid-column:1 / -1;"><strong>Description:</strong><br><?php echo nl2br(htmlspecialchars($packageDetail['description'] ?? '-', ENT_QUOTES, 'UTF-8')); ?></div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
