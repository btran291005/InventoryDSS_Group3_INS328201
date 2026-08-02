<?php
/**
 * File: frontend/staff/stock/stock_view.php
 * Purpose: View current stock by product, with search and low-stock indicators.
 * Related: FR-STF-01
 * Calls: StaffService::getStock(), Product::getEffectiveReorderRule()
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
    <style>
        .stock-badge-low { background: var(--color-danger, #de350b); color: #fff; }
        .stock-badge-ok { background: var(--color-success, #00875a); color: #fff; }
        .stock-search-bar { max-width: 420px; }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">
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
                        </div>
                    </div>
                </div>

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
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
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
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>

            <?php require __DIR__ . '/../../components/footer.php'; ?>
        </div>
    </div>
</body>
</html>
