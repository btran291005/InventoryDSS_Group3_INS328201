<?php
/**
 * File: frontend/components/sidebar.php
 * Purpose: Renders navigation menu based on $_SESSION['role'] (Sitemap - Project 2).
 * Warning: This controls menu VISIBILITY only. It does NOT enforce access —
 *          Middleware.php is the real access control. Never rely on a hidden
 *          menu item as a security measure.
 *
 * Yêu cầu: file gọi include component này phải include trước đó:
 *   app_config.php (BASE_URL, ROLE_ADMIN/MANAGER/STAFF), Auth.php (đã Auth::start()).
 *
 * Biến tùy chọn có thể set TRƯỚC KHI include file này để tô sáng mục đang active:
 *   $activeMenu = 'dashboard'; // khớp với key trong mảng $menuItems bên dưới
 */

if (!defined('BASE_URL')) {
    // An toàn: nếu ai đó include thiếu config, không cho sidebar render sai đường dẫn
    require_once __DIR__ . '/../../backend/config/app_config.php';
}

$roleId = Auth::roleId();
$activeMenu = $activeMenu ?? '';

/**
 * Cấu trúc menu theo đúng Sitemap - Project 2:
 *   Admin Homepage   -> Dashboard/KPI, Accounts, Permissions, Master Data, PO Approval,
 *                        Audit Log, Backup/Restore, Settings
 *   Manager Homepage -> Dashboard, Reorder, Purchase Order (PO), Demand Trend,
 *                        Product Performance, Supplier Lead-time, Shortage Incidents
 *   Store Staff Homepage -> Dashboard, Stock, Goods Receipt, Stock Count, Adjustments,
 *                        Sales History, Customer Feedback Records
 *
 * "Master Data" trong sitemap chưa có 1 file riêng trong repo hiện tại - tạm trỏ về
 * setting/categories.php (dữ liệu danh mục sản phẩm, gần nghĩa nhất hiện có).
 * "Stock" (Staff) gộp 2 file thật: stock/stock_view.php + stock/low_stock_alerts.php
 * -> dùng stock_view.php làm điểm vào chính, low-stock alert là mục con.
 */
$menuItems = [];

if ($roleId === ROLE_ADMIN) {
    $menuItems = [
        'dashboard'   => ['label' => 'Dashboard / KPI', 'href' => '/admin/dashboard.php', 'icon' => 'grid'],
        'accounts'    => ['label' => 'Accounts', 'href' => '/admin/accounts.php', 'icon' => 'users'],
        'permissions' => ['label' => 'Permissions', 'href' => '/admin/permissions.php', 'icon' => 'shield'],
        'master_data' => ['label' => 'Master Data', 'href' => '/admin/setting/categories.php', 'icon' => 'database'],
        'po_approval' => ['label' => 'PO Approval', 'href' => '/admin/po_approval.php', 'icon' => 'check-square'],
        'audit_log'   => ['label' => 'Audit Log', 'href' => '/admin/audit_log.php', 'icon' => 'clock'],
        'backup'      => ['label' => 'Backup / Restore', 'href' => '/admin/backup_restore.php', 'icon' => 'archive'],
        'settings'    => ['label' => 'Settings', 'href' => '/admin/api-config.php', 'icon' => 'settings'],
    ];
} elseif ($roleId === ROLE_MANAGER) {
    $menuItems = [
        'dashboard'    => ['label' => 'Dashboard', 'href' => '/manager/dashboard.php', 'icon' => 'grid'],
        'reorder'      => ['label' => 'Reorder', 'href' => '/manager/reorder/reorder_suggestions.php', 'icon' => 'refresh-cw'],
        'po'           => ['label' => 'Purchase Order (PO)', 'href' => '/manager/purchase_order/po_submit.php', 'icon' => 'file-text'],
        'demand_trend' => ['label' => 'Demand Trend', 'href' => '/manager/demand_trend.php', 'icon' => 'trending-up'],
        'product_pfm'  => ['label' => 'Product Performance', 'href' => '/manager/product_pfm.php', 'icon' => 'bar-chart-2'],
        'lead_time'    => ['label' => 'Supplier Lead-time', 'href' => '/manager/supplier_leadtime.php', 'icon' => 'truck'],
        'shortage'     => ['label' => 'Shortage Incidents', 'href' => '/manager/shortage_incidents.php', 'icon' => 'alert-triangle'],
    ];
} elseif ($roleId === ROLE_STAFF) {
    $menuItems = [
        'dashboard'  => ['label' => 'Dashboard', 'href' => '/staff/dashboard.php', 'icon' => 'grid'],
        'stock'      => ['label' => 'Stock', 'href' => '/staff/stock/stock_view.php', 'icon' => 'box'],
        'goods_recv' => ['label' => 'Goods Receipt', 'href' => '/staff/goods_receipt.php', 'icon' => 'inbox'],
        'stock_count'=> ['label' => 'Stock Count', 'href' => '/staff/stock_count.php', 'icon' => 'clipboard'],
        'adjustments'=> ['label' => 'Adjustments', 'href' => '/staff/adjustments.php', 'icon' => 'sliders'],
        'sales_hist' => ['label' => 'Sales History', 'href' => '/staff/sales_history.php', 'icon' => 'shopping-cart'],
        'feedback'   => ['label' => 'Customer Feedback Records', 'href' => '/staff/customer_feedback.php', 'icon' => 'message-square'],
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