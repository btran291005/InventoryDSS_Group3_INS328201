<?php
/**
 * File: frontend/staff/inventory/adjustments.php
 * Purpose: UI điều chỉnh tồn kho thủ công (hư hỏng/hết hạn/thất thoát) kèm
 * lý do bắt buộc (BR-11), và bảng lịch sử các điều chỉnh đã thực hiện.
 *
 * KHÁC với ảnh tham khảo: KHÔNG gộp "Recent Customer Feedback" vào trang
 * này - customer_feedback.php đã là trang riêng trong cấu trúc thật của dự
 * án (frontend/staff/customer_feedback.php), giữ nguyên tách biệt theo yêu
 * cầu, không gộp lại.
 *
 * Related: FR-STF-08, FR-STF-12, BR-11, BR-12
 * Calls: StaffService::adjustStock(), listAdjustmentReasons(),
 *        getAllAdjustmentHistory()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/StaffService.php';
require_once __DIR__ . '/../../../backend/models/Product.php';
require_once __DIR__ . '/../../../backend/models/Warehouse.php';

Middleware::guard([ROLE_STAFF]);

$staffService   = new StaffService();
$productModel   = new Product();
$warehouseModel = new Warehouse();
$staffId = (int) Auth::id();

$errorMessage = null;
$successMessage = null;

// --- Ghi nhận điều chỉnh mới (Stock Correction) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    $productId   = (int) ($_POST['product_id'] ?? 0);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $reason      = trim((string) ($_POST['reason'] ?? ''));
    $quantityChange = (int) ($_POST['quantity_change'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? '')); // Ghi chú thêm - không có cột riêng trong stock_movements, gộp vào reason nếu có.

    if ($productId <= 0 || $warehouseId <= 0) {
        $errorMessage = 'Vui lòng chọn sản phẩm và kho.';
    } elseif ($quantityChange === 0) {
        $errorMessage = 'Số lượng điều chỉnh phải khác 0.';
    } else {
        $fullReason = $notes !== '' ? "{$reason} - {$notes}" : $reason;
        $result = $staffService->adjustStock($productId, $warehouseId, $quantityChange, $fullReason, $staffId);

        if ($result['success']) {
            $successMessage = 'Đã cập nhật điều chỉnh tồn kho.';
        } else {
            $errorMessage = $result['message'];
        }
    }
}

// --- Dữ liệu form ---
$allProducts = $productModel->getAll(null, null, true);
$warehouses  = $warehouseModel->getAll();
$reasons     = $staffService->listAdjustmentReasons();

// --- Bộ lọc lịch sử ---
$filterProductId = isset($_GET['filter_product']) && $_GET['filter_product'] !== '' ? (int) $_GET['filter_product'] : null;
$filterReason     = $_GET['filter_reason'] ?? null;
$filterDate       = $_GET['filter_date'] ?? null;

$history = $staffService->getAllAdjustmentHistory($filterProductId, $filterReason ?: null, $filterDate ?: null);

// --- KPI: tỉ lệ chênh lệch hằng ngày (dựa trên số dòng điều chỉnh hôm nay) ---
$todayAdjustments = array_filter($history, fn($h) => date('Y-m-d', strtotime($h['created_at'])) === date('Y-m-d'));

$activeMenu  = 'inv_adjustment';
$pageTitle   = 'Inventory Adjustment';
$breadcrumbs = ['Staff', 'Inventory', 'Adjustment'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Adjustment - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        .adj-qty-pos { color: var(--color-success, #00875a); font-weight: 600; }
        .adj-qty-neg { color: var(--color-danger, #de350b); font-weight: 600; }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <div class="mb-3">
                    <h2 class="page-heading mb-1">Inventory Adjustment</h2>
                    <p class="page-subheading mb-0">Đối chiếu tồn kho thực tế và ghi nhận hao hụt (hư hỏng/hết hạn/thất thoát).</p>
                </div>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($successMessage): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <!-- Stock Correction -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Stock Correction</h3>
                            </div>

                            <form method="post">
                                <input type="hidden" name="action" value="adjust_stock">

                                <div class="mb-3">
                                    <label class="form-label">Sản phẩm (SKU)</label>
                                    <select name="product_id" class="form-select" required>
                                        <option value="">-- Chọn sản phẩm --</option>
                                        <?php foreach ($allProducts as $p): ?>
                                            <option value="<?= (int) $p['product_id'] ?>"><?= htmlspecialchars($p['sku_code'] . ' - ' . $p['product_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Kho</label>
                                    <select name="warehouse_id" class="form-select" required>
                                        <option value="">-- Chọn kho --</option>
                                        <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= (int) $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Lý do điều chỉnh</label>
                                    <select name="reason" class="form-select" required>
                                        <option value="">-- Chọn lý do (BR-11) --</option>
                                        <?php foreach ($reasons as $r): ?>
                                            <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Số lượng thay đổi (+/-)</label>
                                    <input type="number" step="1" name="quantity_change" class="form-control" placeholder="VD: -3" required>
                                    <div class="form-text">Số âm = giảm tồn kho (hư hỏng/mất), số dương = tăng (tìm lại hàng thất lạc...).</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Ghi chú (tùy chọn)</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Giải thích thêm về sai lệch..."></textarea>
                                </div>

                                <button type="submit" class="btn btn-brand w-100">Cập nhật tồn kho</button>
                            </form>
                        </div>
                    </div>

                    <!-- KPI cards bên phải -->
                    <div class="col-12 col-xl-7">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <div class="kpi-card h-100">
                                    <span class="kpi-label">Điều chỉnh hôm nay</span>
                                    <span class="kpi-value"><?= number_format(count($todayAdjustments)) ?></span>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="kpi-card h-100">
                                    <span class="kpi-label">Tổng dòng lịch sử (gần đây)</span>
                                    <span class="kpi-value"><?= number_format(count($history)) ?></span>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="kpi-card h-100">
                                    <span class="kpi-label">Lý do khả dụng</span>
                                    <span class="kpi-value"><?= count($reasons) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="panel-card mt-3">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Lý do điều chỉnh hợp lệ</h3>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($reasons as $r): ?>
                                    <span class="status-badge status-badge-muted"><?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-muted small mt-3 mb-0">Mọi điều chỉnh đều bắt buộc chọn 1 trong các lý do trên (BR-11) và được ghi vào lịch sử để kiểm tra sau này (BR-12).</p>
                        </div>
                    </div>
                </div>

                <!-- Inventory Adjustment History -->
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Inventory Adjustment History</h3>
                        <span class="panel-card-note">Xem các điều chỉnh đã thực hiện trước đó</span>
                    </div>

                    <form method="get" class="filter-bar mb-3">
                        <div class="filter-bar-search">
                            <label class="form-label">Sản phẩm</label>
                            <select name="filter_product" class="form-select form-select-sm">
                                <option value="">Tất cả sản phẩm</option>
                                <?php foreach ($allProducts as $p): ?>
                                    <option value="<?= (int) $p['product_id'] ?>" <?= $filterProductId === (int) $p['product_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['sku_code'] . ' - ' . $p['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Lý do</label>
                            <select name="filter_reason" class="form-select form-select-sm">
                                <option value="">Tất cả lý do</option>
                                <?php foreach ($reasons as $r): ?>
                                    <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" <?= $filterReason === $r ? 'selected' : '' ?>><?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Ngày</label>
                            <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $filterDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="filter-bar-actions">
                            <button type="submit" class="btn btn-brand btn-sm flex-fill">Lọc</button>
                            <a href="adjustments.php" class="btn btn-outline-secondary btn-sm flex-fill">Reset</a>
                        </div>
                    </form>

                    <?php if (empty($history)): ?>
                        <div class="empty-state">Không có điều chỉnh nào khớp bộ lọc.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Ngày</th>
                                        <th>Sản phẩm</th>
                                        <th class="text-end">Số lượng</th>
                                        <th>Lý do</th>
                                        <th>Nhân viên</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $h): ?>
                                        <?php $qty = (int) $h['quantity_change']; ?>
                                        <tr>
                                            <td class="text-muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($h['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($h['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($h['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end">
                                                <span class="<?= $qty >= 0 ? 'adj-qty-pos' : 'adj-qty-neg' ?>"><?= $qty > 0 ? '+' : '' ?><?= number_format($qty) ?></span>
                                            </td>
                                            <td><span class="status-badge status-badge-muted"><?= htmlspecialchars($h['reason'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td class="text-muted"><?= htmlspecialchars($h['performed_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>