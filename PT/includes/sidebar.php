<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$navGroups = [
    ['label' => 'Dashboard', 'items' => [
        ['permission' => 'dashboard.view', 'label' => 'Dashboard', 'href' => 'dashboard.php'],
    ]],
    ['label' => 'Customer Management', 'items' => [
        ['permission' => 'customers.view', 'label' => 'Customers', 'href' => 'customers.php'],
    ]],
    ['label' => 'Product', 'items' => [
        ['permission' => 'packages.view', 'label' => 'Packages', 'href' => 'packages.php'],
    ]],
    ['label' => 'Service', 'items' => [
        ['permission' => 'subscriptions.view', 'label' => 'Subscriptions', 'href' => 'subscriptions.php'],
        ['permission' => 'billing.view', 'label' => 'Billing', 'href' => 'billing.php'],
    ]],
    ['label' => 'System', 'items' => [
        ['permission' => 'users.view', 'label' => 'Users', 'href' => 'users.php'],
        ['permission' => 'settings.view', 'label' => 'Settings', 'href' => 'settings.php'],
        ['permission' => 'activity_logs.view', 'label' => 'Activity Logs', 'href' => 'activity_logs.php'],
    ]],
];
?>
<aside class="sidebar">
    <nav class="sidebar-nav">
        <?php foreach ($navGroups as $group): ?>
            <?php $visibleItems = array_filter($group['items'], static fn(array $item): bool => can($item['permission'])); ?>
            <?php if ($visibleItems): ?>
                <div class="nav-group-label"><?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php foreach ($visibleItems as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="nav-item <?php echo $currentPage === $item['href'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <a href="logout.php" class="nav-item danger">Logout</a>
    </nav>
</aside>
