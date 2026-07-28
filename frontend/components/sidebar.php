<?php

if (!defined('BASE_URL')) {
    // An toàn: nếu ai đó include thiếu config, không cho sidebar render sai đường dẫn
    require_once __DIR__ . '/../../backend/config/app_config.php';
}

$roleId = Auth::roleId();
$activeMenu = $activeMenu ?? '';

$menuItems = [];

if ($roleId === ROLE_ADMIN) {
    $menuItems = [
        'dashboard'   => ['label' => 'Dashboard / KPI', 'href' => '/admin/dashboard.php', 'icon' => 'grid'],
        'accounts'    => ['label' => 'Accounts', 'href' => '/admin/accounts.php', 'icon' => 'users'],
        'permissions' => ['label' => 'Permissions', 'href' => '/admin/permissions.php', 'icon' => 'shield'],
        'po_approval' => ['label' => 'PO Approval', 'href' => '/admin/po_approval.php', 'icon' => 'check-square'],
        'audit_log'   => ['label' => 'Audit Log', 'href' => '/admin/audit_log.php', 'icon' => 'clock'],
        // GỘP: Master Data (setting/categories.php + setting/reorder_rules.php)
        // + API Config (api-config.php) + Backup/Restore (backup_restore.php)
        // -> trỏ vào file đầu tiên của nhóm; xem TAB_GROUPS['settings'] trong tab-nav.php
        'settings'    => ['label' => 'Settings', 'href' => '/admin/setting/categories.php', 'icon' => 'settings'],
    ];
} elseif ($roleId === ROLE_MANAGER) {
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/manager/dashboard.php', 'icon' => 'grid'],
        // GỘP: Stock-out Risk + Demand Forecast + Reorder Suggestions +
        // Demand Trend -> trỏ vào file đầu tiên; xem TAB_GROUPS['reorder']
        'reorder'   => ['label' => 'Reorder & Forecast', 'href' => '/manager/reorder/stockout_risk.php', 'icon' => 'activity'],
        // GỘP: Create/Submit PO + PO Status -> xem TAB_GROUPS['po']
        'po'        => ['label' => 'Purchase Order (PO)', 'href' => '/manager/purchase_order/po_submit.php', 'icon' => 'file-text'],
        // GỘP: Product Performance + Supplier Lead-time -> xem TAB_GROUPS['vendor']
        'vendor'    => ['label' => 'Vendor Management', 'href' => '/manager/vendor/product_pfm.php', 'icon' => 'bar-chart-2'],
        'shortage'  => ['label' => 'Shortage Incidents', 'href' => '/manager/shortage_incidents.php', 'icon' => 'alert-triangle'],
    ];
} elseif ($roleId === ROLE_STAFF) {
    $menuItems = [
        'dashboard'     => ['label' => 'Dashboard', 'href' => '/staff/dashboard.php', 'icon' => 'grid'],
        // Đã gộp sẵn từ trước: Stock View + Low-stock Alerts -> xem TAB_GROUPS['stock']
        'stock'         => ['label' => 'Stock', 'href' => '/staff/stock/stock_view.php', 'icon' => 'box'],
        // GỘP MỚI: Goods Receipt + Stock Count + Adjustments -> xem TAB_GROUPS['inventory_ops']
        'inventory_ops' => ['label' => 'Inventory Operations', 'href' => '/staff/inventory_ops/goods_receipt.php', 'icon' => 'inbox'],
        'sales_hist'    => ['label' => 'Sales History', 'href' => '/staff/sales_history.php', 'icon' => 'shopping-cart'],
        'feedback'      => ['label' => 'Customer Feedback Records', 'href' => '/staff/customer_feedback.php', 'icon' => 'message-square'],
    ];
}
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <img src="<?= BASE_URL ?>/assets/img/logo_GS25.png" alt="GS25" class="sidebar-logo">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-title">InventoryDSS</span>
            <span class="sidebar-brand-role"><?= htmlspecialchars(Auth::roleName() ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $key => $item): ?>
            <a href="<?= BASE_URL . $item['href'] ?>"
               class="sidebar-link<?= $activeMenu === $key ? ' active' : '' ?>"
               data-menu="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                <span class="sidebar-link-icon" data-icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></span>
                <span class="sidebar-link-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout">Log out</a>
    </div>
</aside>