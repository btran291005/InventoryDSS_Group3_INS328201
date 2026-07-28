<?php
/**
 * File: frontend/components/sidebar.php
 * Purpose: Renders navigation menu based on $_SESSION['role'], bám sát đúng
 * cây Sitemap (Login -> Admin/Staff/Manager Homepage -> menu con) đã chốt
 * với nhóm - xem ảnh sitemap trong thư mục docs của repo.
 * Warning: This controls menu VISIBILITY only. It does NOT enforce access —
 *          Middleware.php is the real access control. Never rely on a hidden
 *          menu item as a security measure.
 *
 * Yêu cầu: file gọi include component này phải include trước đó:
 *   app_config.php (BASE_URL, ROLE_ADMIN/MANAGER/STAFF), Auth.php (đã Auth::start()).
 *
 * Biến tùy chọn có thể set TRƯỚC KHI include file này để tô sáng mục đang active:
 *   $activeMenu = 'dashboard'; // khớp với key của 1 mục PHẲNG (không nhóm), hoặc
 *                               // key của 1 mục CON trong 1 nhóm bên dưới.
 * Khi $activeMenu khớp 1 mục con, nhóm cha chứa nó tự động mở sẵn (is-open)
 * và tô sáng (has-active), không cần set thêm biến nào khác.
 *
 * CẤU TRÚC DỮ LIỆU $menuItems:
 *   - Mục PHẲNG (không có submenu):
 *       'key' => ['label' => ..., 'href' => ..., 'icon' => ...]
 *   - Mục NHÓM (có submenu, đúng 2 tầng theo sitemap - không hỗ trợ lồng sâu hơn):
 *       'key' => ['label' => ..., 'icon' => ..., 'children' => [
 *           'child_key' => ['label' => ..., 'href' => ..., 'icon' => ...],
 *           ...
 *       ]]
 *     Mục nhóm KHÔNG có 'href' - nút chỉ để mở/đóng submenu, không điều hướng.
 *
 * GHI CHÚ ĐỐI CHIẾU VỚI SITEMAP (để không route vào file/link không tồn tại):
 *   - Admin > Inventory (Overview, Count History): sitemap có yêu cầu, nhưng
 *     BACKEND/FRONTEND CHƯA CÓ file admin nào cho phần này (đã rà toàn bộ
 *     frontend/admin/ - chỉ có audit_log, po_approval, dashboard, account &
 *     permission/, setting/). KHÔNG tạo link chết - tạm ẩn khỏi menu, xem
 *     TODO ngay dưới định nghĩa $menuItems của Admin.
 *   - Staff > Inventory > FEFO Picking: sitemap có yêu cầu, logic FEFO đã có
 *     ở backend (StaffService.php/Product.php/Inventory.php) nhưng CHƯA CÓ
 *     trang UI riêng trong frontend/staff/. Tạm ẩn khỏi menu, xem TODO.
 *   - Manager > Reports: sitemap gộp thành 1 mục "Reports", nhưng repo hiện
 *     có 3 trang phân tích riêng biệt (Demand Trend, Product Performance,
 *     Supplier Lead-time) - không có trang "Reports" tổng hợp nào khác. Gom
 *     3 trang này làm submenu của "Reports" để khớp đúng nhãn sitemap, thay
 *     vì bịa thêm 1 trang reports.php không tồn tại.
 *   - Đường dẫn có khoảng trắng/dấu & (VD "reorder & forecast", "account &
 *     permission") - PHP require (nằm ở các file admin/manager/*.php, xử lý
 *     filesystem path, không phải URL) chạy đúng với chuỗi thường không cần
 *     encode gì. NHƯNG href render ra HTML LÀ URL - khoảng trắng và dấu '&'
 *     KHÔNG hợp lệ trong URL (dấu '&' đặc biệt bị hiểu là ký tự phân tách
 *     query string, cắt path ngay tại đó), nên mọi href bên dưới bắt buộc đi
 *     qua sidebarHref() để rawurlencode() từng đoạn - xem hàm ngay dưới đây.
 *     Bug thật đã từng xảy ra: href trỏ '.../account & permission/accounts.php'
 *     bị trình duyệt/server hiểu thành query string tại dấu '&', link 404.
 */

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
        'users_roles' => ['label' => 'Users & Roles', 'icon' => 'users', 'children' => [
            'accounts'    => ['label' => 'Accounts', 'href' => '/admin/account & permission/accounts.php', 'icon' => 'user'],
            'permissions' => ['label' => 'Permissions', 'href' => '/admin/account & permission/permissions.php', 'icon' => 'shield'],
        ]],
        'rules'       => ['label' => 'Rules', 'href' => '/admin/setting/reorder_rules.php', 'icon' => 'sliders'],
        // TODO: sitemap yêu cầu "Inventory" (Overview + Count History) cho Admin,
        // nhưng chưa có file frontend/admin nào cho phần này - tạm ẩn khỏi menu
        // cho tới khi 2 trang đó được code, để tránh trỏ vào link 404.
        'approvals'   => ['label' => 'Approvals', 'href' => '/admin/po_approval.php', 'icon' => 'check-square'],
        'audit_log'   => ['label' => 'Audit Log', 'href' => '/admin/audit_log.php', 'icon' => 'clock'],
        'system_backup' => ['label' => 'System Backup', 'href' => '/admin/setting/backup_restore.php', 'icon' => 'archive'],
        'settings'    => ['label' => 'Settings', 'href' => '/admin/setting/api_config.php', 'icon' => 'settings'],
    ];
} elseif ($roleId === ROLE_MANAGER) {
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/manager/dashboard.php', 'icon' => 'grid'],
        'inventory' => ['label' => 'Inventory', 'icon' => 'box', 'children' => [
            // "Inventory Health" chưa có trang riêng - forecast.php là màn hình
            // gần nghĩa nhất hiện có (theo dõi tồn kho + gợi ý dựa trên forecast).
            'inventory_health' => ['label' => 'Inventory Health', 'href' => '/manager/reorder & forecast/forecast.php', 'icon' => 'activity'],
            'ai_replenishment' => ['label' => 'AI Replenishment', 'href' => '/manager/reorder & forecast/reorder_suggestions.php', 'icon' => 'refresh-cw'],
            'stock_incidents'  => ['label' => 'Stock Incidents', 'href' => '/manager/shortage_incidents.php', 'icon' => 'alert-triangle'],
        ]],
        'orders' => ['label' => 'Orders', 'icon' => 'file-text', 'children' => [
            'purchase_orders' => ['label' => 'Purchase Orders', 'href' => '/manager/purchase_order/po_create.php', 'icon' => 'file-plus'],
            'po_tracking'     => ['label' => 'PO Tracking', 'href' => '/manager/purchase_order/po-status.php', 'icon' => 'truck'],
        ]],
        'reports' => ['label' => 'Reports', 'icon' => 'bar-chart-2', 'children' => [
            'demand_trend' => ['label' => 'Demand Trend', 'href' => '/manager/reorder & forecast/demand_trend.php', 'icon' => 'trending-up'],
            'product_pfm'  => ['label' => 'Product Performance', 'href' => '/manager/vendor/product_pfm.php', 'icon' => 'bar-chart'],
            'lead_time'    => ['label' => 'Supplier Lead-time', 'href' => '/manager/vendor/supplier_leadtime.php', 'icon' => 'clock'],
        ]],
    ];
} elseif ($roleId === ROLE_STAFF) {
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/staff/dashboard.php', 'icon' => 'grid'],
        'inventory' => ['label' => 'Inventory', 'icon' => 'box', 'children' => [
            'stock_count'    => ['label' => 'Stock Count', 'href' => '/staff/inventory/stock_count.php', 'icon' => 'clipboard'],
            'good_receipt'   => ['label' => 'Good Receipt', 'href' => '/staff/inventory/goods_receipt.php', 'icon' => 'inbox'],
            'inv_adjustment' => ['label' => 'Inventory Adjustment', 'href' => '/staff/inventory/adjustments.php', 'icon' => 'sliders'],
            // TODO: sitemap yêu cầu "FEFO Picking" - logic FEFO đã có ở backend
            // (StaffService/Product/Inventory model) nhưng chưa có trang UI
            // riêng trong frontend/staff/ - tạm ẩn khỏi menu cho tới khi có.
        ]],
        // Không có trong sitemap ảnh, nhưng khớp FR-STF-01/03/10/11/13 (Stock
        // view, Sales History, Feedback) - giữ lại vì đã có trang thật, không
        // xóa chức năng đã code chỉ vì sitemap rút gọn không vẽ chi tiết.
        'stock'      => ['label' => 'Stock', 'href' => '/staff/stock/stock_view.php', 'icon' => 'box'],
        'sales_hist' => ['label' => 'Sales History', 'href' => '/staff/sales_history.php', 'icon' => 'shopping-cart'],
        'feedback'   => ['label' => 'Customer Feedback', 'href' => '/staff/customer_feedback.php', 'icon' => 'message-square'],
    ];
}

/** Item con đang active không? (dùng để tô sáng .sidebar-sublink và mở nhóm cha) */
function sidebarChildIsActive(string $childKey, string $activeMenu): bool
{
    return $childKey === $activeMenu;
}

/** Nhóm có chứa item con đang active không? (dùng để mở nhóm + tô sáng nút cha) */
function sidebarGroupHasActive(array $item, string $activeMenu): bool
{
    if (!isset($item['children'])) {
        return false;
    }
    return array_key_exists($activeMenu, $item['children']);
}

/**
 * Ghép BASE_URL + href thành URL hợp lệ - encode TỪNG ĐOẠN path bằng
 * rawurlencode() (khoảng trắng -> %20, dấu '&' -> %26...) nhưng GIỮ NGUYÊN
 * dấu '/' phân tách thư mục (rawurlencode() sẽ encode luôn cả '/' nếu áp
 * dụng cho cả chuỗi, làm hỏng path - nên phải tách theo '/', encode từng
 * đoạn, rồi nối lại bằng '/').
 *
 * Bắt buộc dùng hàm này cho MỌI href render ra <a> trong sidebar, vì 1 số
 * thư mục thật trong repo có khoảng trắng/dấu & trong tên (VD "account &
 * permission", "reorder & forecast") - nếu ghép thẳng chuỗi không qua hàm
 * này, dấu '&' cắt đứt URL tại đó (bị hiểu thành query string), href sẽ trỏ
 * sai và trả về 404.
 */
function sidebarHref(string $relativeHref): string
{
    $segments = explode('/', $relativeHref);
    $encodedSegments = array_map('rawurlencode', $segments);
    return BASE_URL . implode('/', $encodedSegments);
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
            <?php if (isset($item['children'])): ?>
                <?php $groupActive = sidebarGroupHasActive($item, $activeMenu); ?>
                <div class="sidebar-group<?= $groupActive ? ' is-open has-active' : '' ?>" data-menu-group="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="sidebar-group-toggle" data-group-toggle>
                        <span class="sidebar-group-toggle-left">
                            <span class="sidebar-link-icon" data-icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></span>
                            <span class="sidebar-link-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <svg class="sidebar-group-caret" width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <div class="sidebar-submenu">
                        <?php foreach ($item['children'] as $childKey => $child): ?>
                            <a href="<?= sidebarHref($child['href']) ?>"
                               class="sidebar-sublink<?= sidebarChildIsActive($childKey, $activeMenu) ? ' active' : '' ?>"
                               data-menu="<?= htmlspecialchars($childKey, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="sidebar-link-label"><?= htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= sidebarHref($item['href']) ?>"
                   class="sidebar-link<?= $activeMenu === $key ? ' active' : '' ?>"
                   data-menu="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="sidebar-link-icon" data-icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></span>
                    <span class="sidebar-link-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout">Log out</a>
    </div>
</aside>

<script>
// Toggle nhóm menu cha/con - vanilla JS, không phụ thuộc thư viện ngoài (common.js hiện đang rỗng).
document.querySelectorAll('.sidebar-group-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        btn.closest('.sidebar-group').classList.toggle('is-open');
    });
});
</script>