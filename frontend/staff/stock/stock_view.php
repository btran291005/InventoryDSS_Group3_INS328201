<?php
/**
 * File: frontend/staff/stock/stock_view.php
 * Purpose: View current stock by product with search and low-stock status for Store Staff.
 * Related: FR-STF-01
 * Calls: StaffService::getStock()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/StaffService.php';
require_once __DIR__ . '/../../../backend/models/Product.php';

Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();
$productModel = new Product();

$search = trim((string) ($_GET['q'] ?? ''));
$filter = $_GET['filter'] ?? 'all';
$filter = in_array($filter, ['all', 'low', 'critical'], true) ? $filter : 'all';

$stockRows = $staffService->getStock();
$rows = [];
$lowCount = 0;
$criticalCount = 0;

foreach ($stockRows as $stockRow) {
    $productId = (int) $stockRow['product_id'];
    $rule = $productModel->getEffectiveReorderRule($productId);
    $currentQty = (int) $stockRow['total_quantity'];

    $reorderPoint = $rule !== false ? (int) $rule['reorder_point'] : null;
    $safetyStock = $rule !== false ? (int) $rule['safety_stock'] : null;

    if ($rule === false) {
        $status = ['label' => 'No rule', 'class' => 'status-badge-muted'];
    } elseif ($currentQty <= $safetyStock) {
        $status = ['label' => 'Critical', 'class' => 'status-badge-danger'];
        $criticalCount++;
        $lowCount++;
    } elseif ($currentQty <= $reorderPoint) {
        $status = ['label' => 'Low stock', 'class' => 'status-badge-warning'];
        $lowCount++;
    } else {
        $status = ['label' => 'Normal', 'class' => 'status-badge-info'];
    }

    $rows[] = [
        'product_id'   => $productId,
        'sku_code'     => $stockRow['sku_code'],
        'product_name' => $stockRow['product_name'],
        'current_qty'  => $currentQty,
        'reorder_point'=> $reorderPoint,
        'safety_stock' => $safetyStock,
        'status'       => $status,
    ];
}

$filteredRows = array_filter($rows, function ($row) use ($search, $filter) {
    if ($search !== '') {
        $needle = mb_strtolower($search, 'UTF-8');
        if (mb_strpos(mb_strtolower($row['sku_code'], 'UTF-8'), $needle) === false
            && mb_strpos(mb_strtolower($row['product_name'], 'UTF-8'), $needle) === false) {
            return false;
        }
    }

    if ($filter === 'low') {
        return $row['status']['label'] !== 'Normal' && $row['status']['label'] !== 'No rule';
    }
    if ($filter === 'critical') {
        return $row['status']['label'] === 'Critical';
    }
    return true;
});

$activeMenu  = 'stock';
$pageTitle   = 'Stock View';
$breadcrumbs = ['Staff', 'Stock', 'Stock View'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock View - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="letter-spacing: .5px;">Inventory</span>
                        <h2 class="page-heading mb-1">Stock View</h2>
                        <p class="page-subheading mb-0">Xem tồn kho hiện tại theo SKU và trạng thái low stock cho Store Staff.</p>
                    </div>
                    <a href="low_stock_alerts.php" class="btn btn-outline-secondary btn-sm">Low Stock Alerts</a>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Active products</span>
                            <span class="kpi-value"><?= number_format(count($rows)) ?></span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4 col-xl-3">
                        <div class="kpi-card <?= $lowCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Items below reorder point</span>
                            <span class="kpi-value"><?= number_format($lowCount) ?></span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4 col-xl-3">
                        <div class="kpi-card <?= $criticalCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Critical items</span>
                            <span class="kpi-value"><?= number_format($criticalCount) ?></span>
                        </div>
                    </div>
                </div>

                <div class="panel-card mb-3">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Filters</h3>
                    </div>
                    <div class="panel-card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-12 col-md-6 col-lg-4">
                                <label class="form-label small">Tìm kiếm</label>
                                <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" placeholder="SKU hoặc tên sản phẩm">
                            </div>
                            <div class="col-12 col-md-4 col-lg-3">
                                <label class="form-label small">Bộ lọc</label>
                                <select name="filter" class="form-select form-select-sm">
                                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Tất cả</option>
                                    <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock</option>
                                    <option value="critical" <?= $filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-brand btn-sm w-100">Áp dụng</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Stock Overview</h3>
                        <span class="panel-card-note"><?= number_format(count($filteredRows)) ?> / <?= number_format(count($rows)) ?> sản phẩm hiển thị</span>
                    </div>
                    <?php if (empty($filteredRows)): ?>
                        <div class="empty-state">Không có sản phẩm phù hợp với bộ lọc hiện tại.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Reorder Point</th>
                                        <th class="text-end">Safety Stock</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredRows as $row): ?>
                                        <tr>
                                            <td class="text-muted"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end fw-semibold"><?= number_format($row['current_qty']) ?></td>
                                            <td class="text-end text-muted"><?= $row['reorder_point'] !== null ? number_format($row['reorder_point']) : '—' ?></td>
                                            <td class="text-end text-muted"><?= $row['safety_stock'] !== null ? number_format($row['safety_stock']) : '—' ?></td>
                                            <td><span class="status-badge <?= $row['status']['class'] ?>"><?= htmlspecialchars($row['status']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
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
