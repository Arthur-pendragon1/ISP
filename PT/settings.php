<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/auth.php';

$pdo = getDatabaseConnection();
requireAuth();
requirePermission('settings.view');

$pageTitle = 'Settings';

$settings = [];
$settingRows = $pdo->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll();
foreach ($settingRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('settings.edit');

    $expectedKeys = ['app_name', 'company_name', 'timezone', 'maintenance_mode'];

    foreach ($expectedKeys as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));

        if ($key === 'maintenance_mode') {
            $value = $value === '1' ? '1' : '0';
        }

        $statement = $pdo->prepare('UPDATE settings SET setting_value = :value, updated_at = NOW() WHERE setting_key = :key');
        $statement->execute([
            ':value' => $value,
            ':key' => $key,
        ]);
    }

    $successMessage = 'Settings updated successfully.';
    $settingRows = $pdo->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll();
    $settings = [];
    foreach ($settingRows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="app-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="content">
        <div class="page-header">
            <h1>Settings</h1>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post" action="settings.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="app_name">Application Name</label>
                        <input class="form-control" type="text" id="app_name" name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? 'ISP Management', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input class="form-control" type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($settings['company_name'] ?? 'PT ISP Indonesia', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="timezone">Timezone</label>
                        <input class="form-control" type="text" id="timezone" name="timezone" value="<?php echo htmlspecialchars($settings['timezone'] ?? 'Asia/Jakarta', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="maintenance_mode">Maintenance Mode</label>
                        <select class="form-control" id="maintenance_mode" name="maintenance_mode">
                            <option value="0" <?php echo (($settings['maintenance_mode'] ?? '0') === '0') ? 'selected' : ''; ?>>Off</option>
                            <option value="1" <?php echo (($settings['maintenance_mode'] ?? '0') === '1') ? 'selected' : ''; ?>>On</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" style="width:auto;">Save Settings</button>
                </div>
            </form>
        </div>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
