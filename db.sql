SET FOREIGN_KEY_CHECKS = 0;

-- Part 1. DROP TABLES (Đảo ngược thứ tự FK để tránh lỗi)
DROP TABLE IF EXISTS audit_logs, api_configs, demand_forecasts, shortage_incidents, customer_feedback, stock_count_details, stock_counts, sales_transaction_details, sales_transactions, purchase_order_details, purchase_orders, stock_movements, stock_batches, stock, reorder_rules, products, warehouses, suppliers, categories, accounts, role_permissions, permissions, roles;

SET FOREIGN_KEY_CHECKS = 1;

-- Part 2. CREATE TABLES

CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL
);

CREATE TABLE role_permissions (
    role_id INT,
    permission_id INT,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
);

CREATE TABLE accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role_id INT NOT NULL,
    status ENUM('active','locked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
);

CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    category_type ENUM('FMCG','Fresh_Food','Imported_Korean') NOT NULL,
    requires_fefo BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL,
    contact_phone VARCHAR(20) NULL,
    avg_lead_time_days DECIMAL(5,1) NULL
);

CREATE TABLE warehouses (
    warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_name VARCHAR(50) NOT NULL,
    location VARCHAR(100) NULL
);

CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    sku_code VARCHAR(30) NOT NULL UNIQUE,
    product_name VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    supplier_id INT NOT NULL,
    unit VARCHAR(20) NOT NULL,
    shelf_life_days INT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
);

CREATE TABLE reorder_rules (
    rule_id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    product_id INT NULL,
    min_stock INT NOT NULL,
    max_stock INT NOT NULL,
    safety_stock INT NOT NULL,
    reorder_point INT NOT NULL,
    updated_by INT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (updated_by) REFERENCES accounts(account_id),
    CONSTRAINT chk_rule_target CHECK (category_id IS NOT NULL OR product_id IS NOT NULL)
);

CREATE TABLE stock (
    stock_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    quantity_on_hand INT NOT NULL DEFAULT 0,
    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(warehouse_id),
    CONSTRAINT uq_product_warehouse UNIQUE (product_id, warehouse_id),
    CONSTRAINT chk_qty_positive CHECK (quantity_on_hand >= 0)
);

CREATE TABLE stock_batches (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    received_date DATE NOT NULL,
    expiry_date DATE NULL,
    quantity_remaining INT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    CONSTRAINT chk_batch_qty CHECK (quantity_remaining >= 0)
);

CREATE TABLE stock_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('sale','stock_in','adjustment','count_correction') NOT NULL,
    quantity_change INT NOT NULL,
    reason VARCHAR(100) NULL,
    reference_id INT NULL,
    performed_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (performed_by) REFERENCES accounts(account_id),
    CONSTRAINT chk_adjustment_reason CHECK (movement_type != 'adjustment' OR reason IS NOT NULL)
);

CREATE TABLE purchase_orders (
    po_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    created_by INT NOT NULL,
    status ENUM('Draft','Pending','Approved','Rejected','Delivered') NOT NULL DEFAULT 'Draft',
    approved_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (created_by) REFERENCES accounts(account_id),
    FOREIGN KEY (approved_by) REFERENCES accounts(account_id)
);

CREATE TABLE purchase_order_details (
    po_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL,
    suggested_qty INT NOT NULL,
    approved_qty INT NOT NULL,
    received_qty INT NULL,
    discrepancy_reason VARCHAR(150) NULL,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE sales_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    performed_by INT NOT NULL,
    transaction_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES accounts(account_id)
);

CREATE TABLE sales_transaction_details (
    detail_id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_sold INT NOT NULL,
    batch_id INT NULL,
    FOREIGN KEY (transaction_id) REFERENCES sales_transactions(transaction_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (batch_id) REFERENCES stock_batches(batch_id),
    CONSTRAINT chk_qty_sold CHECK (quantity_sold > 0)
);

CREATE TABLE stock_counts (
    count_id INT AUTO_INCREMENT PRIMARY KEY,
    performed_by INT NOT NULL,
    count_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES accounts(account_id)
);

CREATE TABLE stock_count_details (
    count_detail_id INT AUTO_INCREMENT PRIMARY KEY,
    count_id INT NOT NULL,
    product_id INT NOT NULL,
    system_qty INT NOT NULL,
    actual_qty INT NOT NULL,
    discrepancy INT GENERATED ALWAYS AS (actual_qty - system_qty) STORED,
    FOREIGN KEY (count_id) REFERENCES stock_counts(count_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE customer_feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    logged_by INT NOT NULL,
    feedback_text VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (logged_by) REFERENCES accounts(account_id)
);

CREATE TABLE shortage_incidents (
    incident_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    handled_by INT NOT NULL,
    resolution_action VARCHAR(255) NULL,
    status ENUM('Open','Resolved') NOT NULL DEFAULT 'Open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (handled_by) REFERENCES accounts(account_id)
);

CREATE TABLE demand_forecasts (
    forecast_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    suggested_qty INT NOT NULL,
    api_status ENUM('success','fallback_used') NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE api_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(50) NOT NULL,
    endpoint_url VARCHAR(255) NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    configured_by INT,
    FOREIGN KEY (configured_by) REFERENCES accounts(account_id)
);

CREATE TABLE audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    target_table VARCHAR(50) NULL,
    target_id INT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(account_id)
);

-- Part 3. INSERT DATA
-- ==============================================================================
-- BỘ DATA MOCK MỞ RỘNG - THỰC TẾ GS25 VIỆT NAM (20 - 50 ROWS/TABLE)
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. ROLES, PERMISSIONS & ACCOUNTS (Đã chuẩn hóa, không cần quá nhiều)

INSERT INTO roles (role_id, role_name) VALUES 
(1, 'Admin'), (2, 'Manager'), (3, 'Store Staff');

INSERT INTO permissions (permission_id, permission_code, description) VALUES 
(1, 'FR-ADM-01', 'Manage master data (Products, Suppliers)'),
(2, 'FR-ADM-04', 'Configure reorder rules'),
(3, 'FR-MGR-05', 'Submit Purchase Orders for approval'),
(4, 'FR-MGR-01', 'View Dashboard & Analytics'),
(5, 'FR-STF-02', 'Deduct stock on sales'),
(6, 'FR-STF-06', 'Record incoming stock (Goods Receipt)');

INSERT INTO role_permissions (role_id, permission_id) VALUES 
(1,1), (1,2), (1,3), (1,4), (1,5), (1,6),
(2,3), (2,4), (2,6),
(3,5), (3,6);

INSERT INTO accounts (account_id, username, password_hash, full_name, role_id) VALUES 
(1, 'admin', '$2a$10$x/6I5B/kxGecjcjVxVPSTO5lMvIi7ib8J8dKxvpXxkCNbeDPYZm1O', 'Trụ sở chính (Admin)', 1),
(2, 'manager1', '$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm', 'Lê Hà Bảo Trân (Store Manager)', 2),
(3, 'manager2', '$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm', 'Đỗ Thị Phương (Store Manager)', 2),
(4, 'staff1', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Nguyễn Văn Nam (Staff Sáng)', 3),
(5, 'staff2', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Trần Thu Hà (Staff Sáng)', 3),
(6, 'staff3', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Lê Hoàng Long (Staff Chiều)', 3),
(7, 'staff4', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Phạm Minh Đạt (Staff Đêm)', 3);

-- 2. CATEGORIES & WAREHOUSES

INSERT INTO categories (category_id, category_name, category_type, requires_fefo) VALUES 
(1, 'Thực phẩm khô & Đóng gói', 'FMCG', FALSE),
(2, 'Thức ăn chế biến sẵn (RTE)', 'Fresh_Food', TRUE),
(3, 'Nhãn riêng & Nhập khẩu Hàn Quốc', 'Imported_Korean', FALSE),
(4, 'Nước giải khát & Bia', 'FMCG', FALSE),
(5, 'Sữa & Chế phẩm từ sữa', 'Fresh_Food', TRUE),
(6, 'Bánh kẹo & Snack', 'FMCG', FALSE),
(7, 'Hóa mỹ phẩm & Đồ dùng cá nhân', 'FMCG', FALSE);

INSERT INTO warehouses (warehouse_id, warehouse_name, location) VALUES 
(1, 'Kệ trưng bày (Sales Floor)', 'Khu vực khách hàng'),
(2, 'Tủ mát (Chiller / Open Cooler)', 'Khu vực khách hàng'),
(3, 'Tủ đông (Freezer)', 'Khu vực khách hàng'),
(4, 'Kho sau (Backroom)', 'Phòng kho nội bộ');

-- 3. SUPPLIERS (20 Đối tác thực tế của GS25)

INSERT INTO suppliers (supplier_id, supplier_name, contact_phone, avg_lead_time_days) VALUES 
(1, 'GS Retail (YouUs Direct Import)', '028-1111-2222', 7.0),
(2, 'CJ Foods Việt Nam', '028-3333-4444', 1.0),
(3, 'Masan Consumer', '028-5555-6666', 2.0),
(4, 'Suntory PepsiCo Việt Nam', '028-7777-8888', 1.5),
(5, 'Coca-Cola Việt Nam', '028-9999-0000', 1.5),
(6, 'Orion Vina', '028-1234-5678', 2.0),
(7, 'Samyang Foods (Korea)', '028-8765-4321', 5.0),
(8, 'Acecook Việt Nam', '028-2222-3333', 2.0),
(9, 'Paldo Vina', '028-4444-5555', 3.0),
(10, 'Binggrae (Nhà phân phối VN)', '028-6666-7777', 3.0),
(11, 'Lotte Việt Nam', '028-8888-9999', 2.5),
(12, 'Vinamilk', '028-0000-1111', 1.0),
(13, 'TH True Milk', '028-1111-3333', 1.0),
(14, 'Nestle Việt Nam', '028-2222-4444', 2.0),
(15, 'Mondelez Kinh Đô', '028-3333-5555', 2.0),
(16, 'Unilever Việt Nam', '028-4444-6666', 3.0),
(17, 'Rohto Mentholatum', '028-5555-7777', 3.0),
(18, 'Kao Việt Nam', '028-6666-8888', 3.0),
(19, 'Cty TNHH Bánh kẹo Phạm Nguyên', '028-7777-9999', 2.0),
(20, 'Heineken Việt Nam', '028-8888-0000', 2.0);

-- 4. PRODUCTS (40 SKUs thực tế thường thấy ở GS25)

INSERT INTO products (product_id, sku_code, product_name, category_id, supplier_id, unit, shelf_life_days) VALUES 
-- Fresh Food (Hạn sử dụng ngắn, áp dụng FEFO)
(1, 'RTE-GB-001', 'Gimbap Bò Bulgogi GS25', 2, 2, 'Cuộn', 2),
(2, 'RTE-GB-002', 'Gimbap Xúc Xích Phô Mai GS25', 2, 2, 'Cuộn', 2),
(3, 'RTE-TB-001', 'Tteokbokki Cay Ngọt Truyền Thống', 2, 2, 'Hộp', 3),
(4, 'RTE-ON-001', 'Cơm Nắm Cá Ngừ Mayonnaise', 2, 2, 'Cái', 2),
(5, 'RTE-SW-001', 'Sandwich Gà Teriyaki', 2, 2, 'Gói', 3),
(6, 'RTE-BM-001', 'Bánh Mì Que Thịt Bằm', 2, 2, 'Cái', 3),
(7, 'RTE-BB-001', 'Bánh Bao Xá Xíu Trứng Muối', 2, 2, 'Cái', 2),
-- Nhập khẩu Hàn / Nhãn riêng YouUs
(8, 'KOR-YU-001', 'Nước ép dưa hấu YouUs 270ml', 3, 1, 'Chai', 180),
(9, 'KOR-YU-002', 'Snack Tteokbokki YouUs', 3, 1, 'Gói', 180),
(10, 'KOR-SY-001', 'Mì gà cay Samyang Carbonara 130g', 3, 7, 'Gói', 360),
(11, 'KOR-SY-002', 'Mì gà cay Samyang Phô Mai 130g', 3, 7, 'Gói', 360),
(12, 'KOR-BG-001', 'Sữa chuối Binggrae 200ml', 3, 10, 'Hộp', 180),
(13, 'KOR-BG-002', 'Sữa dâu Binggrae 200ml', 3, 10, 'Hộp', 180),
(14, 'KOR-PD-001', 'Mì xào tương đen Jjajangmen Paldo', 3, 9, 'Gói', 360),
(15, 'KOR-LT-001', 'Nước ép nha đam Lotte 500ml', 3, 11, 'Chai', 360),
-- Sữa (Cần FEFO)
(16, 'DAI-VN-001', 'Sữa tươi Vinamilk không đường 180ml', 5, 12, 'Hộp', 180),
(17, 'DAI-TH-001', 'Sữa chua uống TH True Yogurt Dâu', 5, 13, 'Chai', 45),
-- Nước giải khát
(18, 'BEV-SP-001', 'Pepsi Không Calo 320ml', 4, 4, 'Lon', 360),
(19, 'BEV-SP-002', 'Trà Ô Long Tea+ Plus 455ml', 4, 4, 'Chai', 360),
(20, 'BEV-SP-003', 'Nước tăng lực Sting Dâu 330ml', 4, 4, 'Chai', 360),
(21, 'BEV-CC-001', 'Coca-Cola Plus 320ml', 4, 5, 'Lon', 360),
(22, 'BEV-CC-002', 'Nước khoáng Dasani 500ml', 4, 5, 'Chai', 360),
(23, 'BEV-NS-001', 'Cà phê rang xay Nescafe Lon', 4, 14, 'Lon', 360),
(24, 'BEV-HK-001', 'Bia Heineken Silver 330ml', 4, 20, 'Lon', 360),
-- FMCG / Đồ khô
(25, 'FMC-MS-001', 'Mì Omachi Xốt Spaghetti', 1, 3, 'Gói', 150),
(26, 'FMC-MS-002', 'Mì ly Kokomi Tôm Chua Cay', 1, 3, 'Ly', 150),
(27, 'FMC-AC-001', 'Mì Hảo Hảo Chua Cay', 1, 8, 'Gói', 180),
(28, 'FMC-OR-001', 'Snack khoai tây Ostar Tảo Biển', 6, 6, 'Gói', 180),
(29, 'FMC-OR-002', 'Bánh Chocopie hộp 12 cái', 6, 6, 'Hộp', 360),
(30, 'FMC-MD-001', 'Bánh quy Oreo Vani', 6, 15, 'Thanh', 360),
-- Personal Care
(31, 'PER-UL-001', 'Kem đánh răng P/S Trà Xanh 100g', 7, 16, 'Tuýp', 1080),
(32, 'PER-RH-001', 'Sữa rửa mặt Acnes 100g', 7, 17, 'Tuýp', 1080),
(33, 'PER-KA-001', 'Băng vệ sinh Laurier Dày', 7, 18, 'Gói', 1080);

-- 5. REORDER RULES (20 Rules - Mix giữa rule cho Nhóm và rule cho SKU lẻ)

-- Rule chung cho Danh mục
INSERT INTO reorder_rules (category_id, product_id, min_stock, max_stock, safety_stock, reorder_point, updated_by) VALUES 
(1, NULL, 50, 300, 30, 80, 1),   -- FMCG
(2, NULL, 5, 30, 3, 10, 1),      -- Fresh Food (Rất khắt khe do HSD ngắn)
(3, NULL, 20, 200, 15, 40, 1),   -- Hàng nhập
(4, NULL, 60, 500, 40, 100, 1),  -- Đồ uống
(6, NULL, 30, 250, 20, 50, 1);   -- Snack
-- Rule ghi đè (Override) cho các SKU bán cực chạy (Best Sellers)
INSERT INTO reorder_rules (category_id, product_id, min_stock, max_stock, safety_stock, reorder_point, updated_by) VALUES 
(NULL, 1, 15, 50, 5, 20, 1),     -- Gimbap Bò bán rất chạy
(NULL, 4, 20, 60, 5, 25, 1),     -- Cơm nắm cá ngừ bán rất chạy
(NULL, 10, 50, 300, 30, 80, 1),  -- Mì Samyang
(NULL, 12, 30, 100, 10, 40, 1),  -- Sữa chuối Binggrae
(NULL, 18, 100, 1000, 50, 200, 1),-- Pepsi Không Calo (Top sales)
(NULL, 27, 200, 2000, 100, 350, 1);-- Mì Hảo Hảo

-- 6. STOCK (Chia kho Kệ Trưng Bày và Kho Sau)

INSERT INTO stock (product_id, warehouse_id, quantity_on_hand, last_updated) VALUES 
-- Fresh Food (Chỉ để Tủ Mát)
(1, 2, 8, NOW()), (2, 2, 4, NOW()), (3, 2, 5, NOW()), (4, 2, 12, NOW()), (5, 2, 3, NOW()),
-- Hàng Nhập / YouUs (Có cả trên kệ và kho sau)
(8, 2, 15, NOW()), (8, 4, 50, NOW()), (9, 1, 20, NOW()), (9, 4, 80, NOW()),
(10, 1, 45, NOW()), (10, 4, 200, NOW()), (12, 2, 18, NOW()), (12, 4, 60, NOW()),
-- Beverage
(18, 2, 30, NOW()), (18, 4, 300, NOW()), (19, 2, 25, NOW()), (19, 4, 150, NOW()),
(20, 2, 10, NOW()), (20, 4, 100, NOW()), (21, 2, 40, NOW()), (22, 1, 50, NOW()),
-- FMCG / Snack
(25, 1, 35, NOW()), (25, 4, 120, NOW()), (27, 1, 100, NOW()), (27, 4, 500, NOW()),
(28, 1, 25, NOW()), (28, 4, 80, NOW()), (29, 1, 15, NOW()), (29, 4, 45, NOW()),
-- Personal Care
(31, 1, 12, NOW()), (31, 4, 30, NOW()), (33, 1, 20, NOW()), (33, 4, 60, NOW());

-- 7. STOCK BATCHES (Dữ liệu quan trọng cho FEFO)
-- Giả định thời điểm hiện tại là giữa tháng.

INSERT INTO stock_batches (product_id, received_date, expiry_date, quantity_remaining) VALUES 
(1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 3),
(1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 5),
(2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 4),
(4, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2),
(4, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 10),
(12, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 150 DAY), 78),
(16, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 170 DAY), 50);

-- 8. PURCHASE ORDERS & DETAILS (20-30 Rows)

INSERT INTO purchase_orders (po_id, supplier_id, created_by, status, approved_by, created_at, approved_at) VALUES 
(1, 2, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(2, 4, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
(3, 7, 3, 'Approved', 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(4, 1, 2, 'Pending', NULL, CURDATE(), NULL),
(5, 3, 2, 'Draft', NULL, CURDATE(), NULL);

INSERT INTO purchase_order_details (po_id, product_id, suggested_qty, approved_qty, received_qty, discrepancy_reason) VALUES 
(1, 1, 30, 30, 30, NULL), (1, 2, 20, 20, 18, 'Nhà cung cấp giao thiếu 2 cuộn'), (1, 4, 40, 40, 40, NULL),
(2, 18, 500, 400, 400, NULL), (2, 19, 200, 200, 200, NULL),
(3, 10, 100, 100, NULL, NULL), (3, 11, 50, 50, NULL, NULL),
(4, 8, 100, 100, NULL, NULL), (4, 9, 200, 150, NULL, NULL),
(5, 25, 200, 200, NULL, NULL), (5, 26, 100, 100, NULL, NULL);

-- 9. SALES TRANSACTIONS & DETAILS (Mô phỏng 20+ hóa đơn)

-- Tạo 5 giao dịch
INSERT INTO sales_transactions (transaction_id, performed_by, transaction_time) VALUES 
(1, 4, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2, 4, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(3, 5, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(4, 5, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(5, 6, DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- Mua combo ăn trưa (Gimbap + Nước)
INSERT INTO sales_transaction_details (transaction_id, product_id, quantity_sold, batch_id) VALUES 
(1, 1, 1, 1), (1, 18, 1, NULL),
(2, 4, 2, 4), (2, 12, 1, 6),
(3, 10, 1, NULL), (3, 18, 2, NULL), (3, 28, 1, NULL),
(4, 3, 1, NULL), (4, 19, 1, NULL),
(5, 27, 3, NULL), (5, 29, 1, NULL), (5, 31, 1, NULL);

-- 10. STOCK MOVEMENTS (Nhật ký nhập/xuất/chỉnh sửa)

INSERT INTO stock_movements (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at) VALUES 
(1, 'stock_in', 30, NULL, 1, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'stock_in', 18, NULL, 1, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(18, 'stock_in', 400, NULL, 2, 2, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'sale', -1, NULL, 1, 4, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(4, 'sale', -2, NULL, 2, 4, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(2, 'adjustment', -2, 'Hư hỏng lúc sắp xếp lên kệ', NULL, 5, DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(5, 'adjustment', -1, 'Hết hạn sử dụng (Write-off)', NULL, 4, DATE_SUB(NOW(), INTERVAL 12 HOUR));

-- 11. STOCK COUNTS & DETAILS (Kiểm kê định kỳ)

INSERT INTO stock_counts (count_id, performed_by, count_date) VALUES 
(1, 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(2, 7, CURDATE());

INSERT INTO stock_count_details (count_id, product_id, system_qty, actual_qty) VALUES 
(1, 10, 245, 244), -- Lệch 1 gói mì Samyang
(1, 18, 330, 330), -- Khớp
(1, 27, 600, 595), -- Mất 5 gói Hảo Hảo
(2, 1, 9, 8),      -- Lệch 1 Gimbap
(2, 12, 78, 78);   -- Khớp


-- 12. INCIDENTS, FEEDBACK & FORECASTS

INSERT INTO customer_feedback (product_id, logged_by, feedback_text, created_at) VALUES 
(1, 4, 'Khách hỏi mua Gimbap Bò Bulgogi lúc 7h tối nhưng đã hết hàng.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(12, 5, 'Khách phàn nàn sữa chuối Binggrae trên kệ bị móp méo.', DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO shortage_incidents (product_id, handled_by, resolution_action, status, created_at) VALUES 
(1, 2, 'Gọi điện giục CJ Foods giao hàng sớm hơn vào ca sáng mai.', 'Resolved', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(10, 3, 'Tạo PO gấp nhưng NCC Samyang báo hết hàng kho tổng, chờ 3 ngày.', 'Open', NOW());

INSERT INTO demand_forecasts (product_id, suggested_qty, api_status, requested_at) VALUES 
(1, 45, 'success', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(18, 600, 'success', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(10, 150, 'fallback_used', NOW()); -- Lỗi API, xài Rule dự phòng

INSERT INTO api_configs (api_name, endpoint_url, api_key, configured_by) VALUES 
('AI_Demand_Forecast', 'https://api.inventorydss.com/v1/predict', 'sk_live_gs25_fbf932', 1),
('Supplier_EDI_Gate', 'https://edi.gs25.vn/gateway', 'sk_edi_gs25_888', 1);

INSERT INTO audit_logs (account_id, action_type, target_table, target_id, timestamp) VALUES 
(1, 'UPDATE_REORDER_RULE', 'reorder_rules', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'OVERRIDE_PO_QTY', 'purchase_order_details', 9, DATE_SUB(NOW(), INTERVAL 1 DAY));