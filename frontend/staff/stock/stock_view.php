<?php
/**
 * File: frontend/staff/stock/stock_view.php
<<<<<<< HEAD
 * Purpose: View current stock by product, with search and low-stock indicators.
 * Related: FR-STF-01
 * Calls: StaffService::getStock(), Product::getEffectiveReorderRule()
=======
 * Purpose: View current stock by product with search and low-stock status for Store Staff.
 * Related: FR-STF-01
 * Calls: StaffService::getStock()
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
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

<<<<<<< HEAD
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$allStock = $staffService->getStock();

if ($searchQuery !== '') {
    $allStock = array_filter($allStock, function (array $row) use ($searchQuery) {
        return stripos((string) $row['sku_code'], $searchQuery) !== false
            || stripos((string) $row['product_name'], $searchQuery) !== false;
    });
}

$stockList = array_values($allStock);

usort($stockList, function (array $a, array $b) {
    $qtyA = (int) $a['total_quantity'];
    $qtyB = (int) $b['total_quantity'];
    if ($qtyA !== $qtyB) {
        return $qtyA <=> $qtyB;
    }
    return strnatcasecmp((string) $a['product_name'], (string) $b['product_name']);
});

$totalProducts = count($stockList);
$totalQuantity = array_sum(array_column($stockList, 'total_quantity'));
$lowStockCount = 0;

foreach ($stockList as &$stockRow) {
    $rule = $productModel->getEffectiveReorderRule((int) $stockRow['product_id']);
    $stockRow['reorder_point'] = $rule['reorder_point'] ?? null;
    $stockRow['safety_stock'] = $rule['safety_stock'] ?? null;
    $stockRow['is_low_stock'] = $stockRow['reorder_point'] !== null
        && (int) $stockRow['total_quantity'] <= (int) $stockRow['reorder_point'];
    if ($stockRow['is_low_stock']) {
        $lowStockCount++;
    }
}
unset($stockRow);

$activeMenu  = 'stock';
$pageTitle   = 'Stock Overview';
$breadcrumbs = ['Staff', 'Stock'];
=======
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
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
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
<<<<<<< HEAD
    <style>
        .stock-badge-low { background: var(--color-danger, #de350b); color: #fff; }
        .stock-badge-ok { background: var(--color-success, #00875a); color: #fff; }
        .stock-search-bar { max-width: 420px; }
    </style>
=======
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">
<<<<<<< HEAD
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="page-heading mb-1">Stock Overview</h2>
                        <p class="page-subheading mb-0">Xem tồn kho hiện tại theo sản phẩm, tìm theo SKU hoặc tên.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <div class="text-end text-muted small">
                            <div>Tổng sản phẩm: <strong><?= number_format($totalProducts) ?></strong></div>
                            <div>Tổng tồn kho: <strong><?= number_format($totalQuantity) ?></strong></div>
                            <div>Thiếu kho: <strong><?= number_format($lowStockCount) ?></strong></div>
=======
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="letter-spacing: .5px;">Inventory</span>
                        <h2 class="page-heading mb-1">Stock View</h2>
                        <p class="page-subheading mb-0">View current stock by SKU and low stock status for Store Staff.</p>
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
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
                        </div>
                    </div>
                </div>

<<<<<<< HEAD
                <form method="get" class="d-flex align-items-center gap-2 mb-3 stock-search-bar">
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Tìm SKU hoặc tên sản phẩm...">
                    <button type="submit" class="btn btn-brand">Tìm</button>
                    <?php if ($searchQuery !== ''): ?>
                        <a href="stock_view.php" class="btn btn-outline-secondary">Xóa</a>
                    <?php endif; ?>
                </form>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Stock details</h3>
                        <span class="panel-card-note">Sắp theo số lượng tồn thấp nhất trước.</span>
                    </div>

                    <?php if (empty($stockList)): ?>
                        <div class="empty-state">Không tìm thấy sản phẩm phù hợp.</div>
=======
                <div class="panel-card mb-3">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Filters</h3>
                    </div>
                    <div class="panel-card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-12 col-md-6 col-lg-4">
                                <label class="form-label small">Search</label>
                                <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" placeholder="SKU or product name">
                            </div>
                            <div class="col-12 col-md-4 col-lg-3">
                                <label class="form-label small">Filter</label>
                                <select name="filter" class="form-select form-select-sm">
                                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock</option>
                                    <option value="critical" <?= $filter === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-brand btn-sm w-100">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Stock Overview</h3>
                        <span class="panel-card-note"><?= number_format(count($filteredRows)) ?> / <?= number_format(count($rows)) ?> products displayed</span>
                    </div>
                    <?php if (empty($filteredRows)): ?>
                        <div class="empty-state">No products match the current filters.</div>
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
<<<<<<< HEAD
                                        <th>Sản phẩm</th>
                                        <th class="text-end">Tồn kho</th>
                                        <th class="text-end">Reorder point</th>
                                        <th class="text-end">Safety stock</th>
                                        <th class="text-center">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockList as $row): ?>
                                        <tr class="<?= $row['is_low_stock'] ? 'table-danger' : '' ?>">
                                            <td class="text-muted"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end fw-semibold"><?= number_format((int) $row['total_quantity']) ?></td>
                                            <td class="text-end"><?= $row['reorder_point'] !== null ? number_format((int) $row['reorder_point']) : '—' ?></td>
                                            <td class="text-end"><?= $row['safety_stock'] !== null ? number_format((int) $row['safety_stock']) : '—' ?></td>
                                            <td class="text-center">
                                                <?php if ($row['is_low_stock']): ?>
                                                    <span class="status-badge stock-badge-low">Low stock</span>
                                                <?php else: ?>
                                                    <span class="status-badge stock-badge-ok">OK</span>
                                                <?php endif; ?>
                                            </td>
=======
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
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
<<<<<<< HEAD

            <?php require __DIR__ . '/../../components/footer.php'; ?>
        </div>
    </div>
</body>
</html>
=======
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>
>>>>>>> fd0fc819d99e766142880f49fafbc4762c1cd2d0
