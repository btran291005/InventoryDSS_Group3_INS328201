-- ==============================================================================
-- INVENTORY DSS (GS25 HANOI BRANCH) - DATABASE SCHEMA & MASSIVE MOCK DATA
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ==============================================================================
-- 1. DROP TABLES 
-- ==============================================================================
DROP TABLE IF EXISTS audit_logs, api_configs, demand_forecasts, shortage_incidents, 
customer_feedback, stock_count_details, stock_counts, sales_transaction_details, 
sales_transactions, purchase_order_details, purchase_orders, stock_movements, 
stock_batches, stock, reorder_rules, products, warehouses, suppliers, categories, 
notifications, system_settings, adjustment_reasons, accounts, role_permissions, 
permissions, roles;

-- ==============================================================================
-- 2. CREATE TABLES
-- ==============================================================================
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
    email VARCHAR(100) NULL,
    phone_number VARCHAR(20) NULL,
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
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
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
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
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

CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    email_status ENUM('pending', 'sent', 'failed', 'not_required') NOT NULL DEFAULT 'not_required',
    zalo_status ENUM('pending', 'sent', 'failed', 'not_required') NOT NULL DEFAULT 'not_required',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
);

CREATE TABLE system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    description VARCHAR(255) NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES accounts(account_id)
);

CREATE TABLE adjustment_reasons (
    reason_id INT AUTO_INCREMENT PRIMARY KEY,
    reason_name VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);


-- ==============================================================================
-- 3. INSERT MASSIVE DATA
-- ==============================================================================

-- 3.1. Master Data (Roles, Permissions, Accounts, Categories, Warehouses, Suppliers, Settings)
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
(2,3), (2,4), (2,6), (3,5), (3,6);

INSERT INTO accounts (account_id, username, password_hash, full_name, email, phone_number, role_id) VALUES 
(1, 'admin', '$2a$10$x/6I5B/kxGecjcjVxVPSTO5lMvIi7ib8J8dKxvpXxkCNbeDPYZm1O', 'Trụ sở chính (Admin)', 'admin@gs25.vn', '0900000001', 1),
(2, 'manager1', '$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm', 'Lê Hà Bảo Trân (Store Manager)', 'bao.tran@gs25.vn', '0901234567', 2),
(3, 'manager2', '$2a$10$eH6AY24mQt9Zg20J28e/a.HjmaIYzscsSgOfk163OzIKOBFdTfACm', 'Đỗ Thị Phương (Store Manager)', 'phuong.do@gs25.vn', '0901234568', 2),
(4, 'staff1', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Nguyễn Văn Nam (Staff Sáng)', 'staff1@gs25.vn', '0901111111', 3),
(5, 'staff2', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Trần Thu Hà (Staff Sáng)', NULL, NULL, 3),
(6, 'staff3', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Lê Hoàng Long (Staff Chiều)', NULL, NULL, 3),
(7, 'staff4', '$2a$10$mvgA2kXGLewpqqubV0dkUeQhL0nZmdJ7cSqaiRRJJn1oVXncvZJ2S', 'Phạm Minh Đạt (Staff Đêm)', NULL, NULL, 3);

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
(2, 'Tủ mát (Chiller)', 'Khu vực khách hàng'),
(3, 'Tủ đông (Freezer)', 'Khu vực khách hàng'),
(4, 'Kho sau (Backroom)', 'Phòng kho nội bộ');

INSERT INTO suppliers (supplier_id, supplier_name, contact_phone, avg_lead_time_days) VALUES 
(1, 'GS Retail (YouUs Direct)', '028-1111-2222', 7.0), (2, 'CJ Foods Việt Nam', '028-3333-4444', 1.0),
(3, 'Masan Consumer', '028-5555-6666', 2.0), (4, 'Suntory PepsiCo', '028-7777-8888', 1.5),
(5, 'Coca-Cola Việt Nam', '028-9999-0000', 1.5), (6, 'Orion Vina', '028-1234-5678', 2.0),
(7, 'Samyang Foods', '028-8765-4321', 5.0), (8, 'Acecook Việt Nam', '028-2222-3333', 2.0),
(9, 'Paldo Vina', '028-4444-5555', 3.0), (10, 'Binggrae', '028-6666-7777', 3.0),
(11, 'Lotte Việt Nam', '028-8888-9999', 2.5), (12, 'Vinamilk', '028-0000-1111', 1.0),
(13, 'TH True Milk', '028-1111-3333', 1.0), (14, 'Nestle Việt Nam', '028-2222-4444', 2.0),
(15, 'Mondelez Kinh Đô', '028-3333-5555', 2.0), (16, 'Unilever Việt Nam', '028-4444-6666', 3.0),
(17, 'Rohto Mentholatum', '028-5555-7777', 3.0), (18, 'Kao Việt Nam', '028-6666-8888', 3.0),
(19, 'Bánh kẹo Phạm Nguyên', '028-7777-9999', 2.0), (20, 'Heineken Việt Nam', '028-8888-0000', 2.0);

INSERT INTO system_settings (setting_key, setting_value, description, updated_by) VALUES 
('max_inventory_discrepancy', '5', 'Ngưỡng chênh lệch kiểm kê (%)', 1),
('zalo_alert_enabled', 'true', 'Bật gửi cảnh báo qua Zalo', 1);

INSERT INTO adjustment_reasons (reason_name) VALUES 
('Hết hạn sử dụng (Write-off)'), ('Hư hỏng vật lý / Bao bì móp méo'),
('Thất thoát không rõ nguyên nhân'), ('Sản phẩm lỗi từ Nhà cung cấp');

INSERT INTO api_configs (api_name, endpoint_url, api_key, configured_by) VALUES 
('AI_Demand_Forecast', 'https://api.inventorydss.com/v1/predict', 'sk_live_gs25_fbf932', 1),
('Supplier_EDI_Gate', 'https://edi.gs25.vn/gateway', 'sk_edi_gs25_888', 1),
('Zalo_ZNS_API', 'https://business.openapi.zalo.me/message/template', 'zalo_access_token_mock_123', 1),
('Email_SMTP', 'smtp.gmail.com', 'smtp_app_password_mock_456', 1);

-- 3.2. Products & Reorder Rules
INSERT INTO products (product_id, sku_code, product_name, category_id, supplier_id, unit, shelf_life_days, unit_cost, selling_price) VALUES 
(1, 'RTE-GB-001', 'Gimbap Bò Bulgogi GS25', 2, 2, 'Cuộn', 2, 18000, 28000), (2, 'RTE-GB-002', 'Gimbap Xúc Xích Phô Mai GS25', 2, 2, 'Cuộn', 2, 16000, 25000),
(3, 'RTE-TB-001', 'Tteokbokki Cay Ngọt Truyền Thống', 2, 2, 'Hộp', 3, 22000, 35000), (4, 'RTE-ON-001', 'Cơm Nắm Cá Ngừ Mayonnaise', 2, 2, 'Cái', 2, 12000, 18000),
(5, 'RTE-SW-001', 'Sandwich Gà Teriyaki', 2, 2, 'Gói', 3, 15000, 25000), (6, 'RTE-BM-001', 'Bánh Mì Que Thịt Bằm', 2, 2, 'Cái', 3, 8000, 13000),
(7, 'RTE-BB-001', 'Bánh Bao Xá Xíu Trứng Muối', 2, 2, 'Cái', 2, 10000, 15000), (8, 'KOR-YU-001', 'Nước ép dưa hấu YouUs 270ml', 3, 1, 'Chai', 180, 9000, 14000),
(9, 'KOR-YU-002', 'Snack Tteokbokki YouUs', 3, 1, 'Gói', 180, 11000, 18000), (10, 'KOR-SY-001', 'Mì gà cay Samyang Carbonara 130g', 3, 7, 'Gói', 360, 13000, 22000),
(11, 'KOR-SY-002', 'Mì gà cay Samyang Phô Mai 130g', 3, 7, 'Gói', 360, 13000, 22000), (12, 'KOR-BG-001', 'Sữa chuối Binggrae 200ml', 3, 10, 'Hộp', 180, 9500, 15000),
(13, 'KOR-BG-002', 'Sữa dâu Binggrae 200ml', 3, 10, 'Hộp', 180, 9500, 15000), (14, 'KOR-PD-001', 'Mì xào tương đen Jjajangmen Paldo', 3, 9, 'Gói', 360, 14000, 22000),
(15, 'KOR-LT-001', 'Nước ép nha đam Lotte 500ml', 3, 11, 'Chai', 360, 16000, 25000), (16, 'DAI-VN-001', 'Sữa tươi Vinamilk không đường 180ml', 5, 12, 'Hộp', 180, 6500, 10000),
(17, 'DAI-TH-001', 'Sữa chua uống TH True Yogurt Dâu', 5, 13, 'Chai', 45, 8500, 12000), (18, 'BEV-SP-001', 'Pepsi Không Calo 320ml', 4, 4, 'Lon', 360, 6000, 10000),
(19, 'BEV-SP-002', 'Trà Ô Long Tea+ Plus 455ml', 4, 4, 'Chai', 360, 7500, 12000), (20, 'BEV-SP-003', 'Nước tăng lực Sting Dâu 330ml', 4, 4, 'Chai', 360, 6500, 11000),
(21, 'BEV-CC-001', 'Coca-Cola Plus 320ml', 4, 5, 'Lon', 360, 6000, 10000), (22, 'BEV-CC-002', 'Nước khoáng Dasani 500ml', 4, 5, 'Chai', 360, 4000, 7000),
(23, 'BEV-NS-001', 'Cà phê rang xay Nescafe Lon', 4, 14, 'Lon', 360, 9000, 15000), (24, 'BEV-HK-001', 'Bia Heineken Silver 330ml', 4, 20, 'Lon', 360, 14000, 22000),
(25, 'FMC-MS-001', 'Mì Omachi Xốt Spaghetti', 1, 3, 'Gói', 150, 7000, 10000), (26, 'FMC-MS-002', 'Mì ly Kokomi Tôm Chua Cay', 1, 3, 'Ly', 150, 8000, 11000),
(27, 'FMC-AC-001', 'Mì Hảo Hảo Chua Cay', 1, 8, 'Gói', 180, 3500, 5000), (28, 'FMC-OR-001', 'Snack khoai tây Ostar Tảo Biển', 6, 6, 'Gói', 180, 6000, 9000),
(29, 'FMC-OR-002', 'Bánh Chocopie hộp 12 cái', 6, 6, 'Hộp', 360, 32000, 45000), (30, 'FMC-MD-001', 'Bánh quy Oreo Vani', 6, 15, 'Thanh', 360, 5500, 8000),
(31, 'PER-UL-001', 'Kem đánh răng P/S Trà Xanh 100g', 7, 16, 'Tuýp', 1080, 15000, 22000), (32, 'PER-RH-001', 'Sữa rửa mặt Acnes 100g', 7, 17, 'Tuýp', 1080, 38000, 55000),
(33, 'PER-KA-001', 'Băng vệ sinh Laurier Dày', 7, 18, 'Gói', 1080, 28000, 40000);

INSERT INTO reorder_rules (category_id, product_id, min_stock, max_stock, safety_stock, reorder_point, updated_by) VALUES 
(1, NULL, 50, 300, 30, 80, 1), (2, NULL, 5, 30, 3, 10, 1), (3, NULL, 20, 200, 15, 40, 1),
(4, NULL, 60, 500, 40, 100, 1), (6, NULL, 30, 250, 20, 50, 1),
(NULL, 1, 15, 50, 5, 20, 1), (NULL, 4, 20, 60, 5, 25, 1), (NULL, 10, 50, 300, 30, 80, 1),
(NULL, 12, 30, 100, 10, 40, 1), (NULL, 18, 100, 1000, 50, 200, 1), (NULL, 27, 200, 2000, 100, 350, 1);

-- 3.3. Inventory (Stock & Batches)
INSERT INTO stock (product_id, warehouse_id, quantity_on_hand, last_updated) VALUES 
(1, 2, 12, NOW()), (2, 2, 8, NOW()), (3, 2, 5, NOW()), (4, 2, 18, NOW()), (5, 2, 7, NOW()), (6, 2, 10, NOW()), (7, 2, 15, NOW()),
(8, 2, 15, NOW()), (8, 4, 50, NOW()), (9, 1, 20, NOW()), (9, 4, 80, NOW()), (10, 1, 45, NOW()), (10, 4, 200, NOW()),
(11, 1, 30, NOW()), (11, 4, 150, NOW()), (12, 2, 18, NOW()), (12, 4, 60, NOW()), (13, 2, 20, NOW()), (13, 4, 70, NOW()),
(14, 1, 25, NOW()), (14, 4, 100, NOW()), (15, 2, 12, NOW()), (15, 4, 40, NOW()), (16, 2, 30, NOW()), (16, 4, 120, NOW()),
(17, 2, 25, NOW()), (17, 4, 80, NOW()), (18, 2, 40, NOW()), (18, 4, 400, NOW()), (19, 2, 25, NOW()), (19, 4, 150, NOW()),
(20, 2, 30, NOW()), (20, 4, 200, NOW()), (21, 2, 45, NOW()), (21, 4, 350, NOW()), (22, 1, 50, NOW()), (22, 4, 250, NOW()),
(23, 1, 15, NOW()), (23, 4, 80, NOW()), (24, 2, 35, NOW()), (24, 4, 200, NOW()), (25, 1, 45, NOW()), (25, 4, 150, NOW()),
(26, 1, 30, NOW()), (26, 4, 100, NOW()), (27, 1, 120, NOW()), (27, 4, 600, NOW()), (28, 1, 35, NOW()), (28, 4, 100, NOW()),
(29, 1, 20, NOW()), (29, 4, 80, NOW()), (30, 1, 25, NOW()), (30, 4, 90, NOW()), (31, 1, 15, NOW()), (31, 4, 50, NOW()),
(32, 1, 10, NOW()), (32, 4, 30, NOW()), (33, 1, 20, NOW()), (33, 4, 70, NOW());

INSERT INTO stock_batches (product_id, received_date, expiry_date, quantity_remaining) VALUES 
(1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 5),
(1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 7),
(2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 8),
(4, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 8),
(4, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 10),
(12, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 150 DAY), 78),
(16, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 170 DAY), 150);

-- 3.4. Purchase Orders (~20 Rows)
INSERT INTO purchase_orders (po_id, supplier_id, created_by, status, approved_by, created_at, approved_at) VALUES 
(1, 2, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
(2, 4, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(3, 7, 3, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
(4, 1, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
(5, 3, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(6, 5, 3, 'Approved', 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(7, 8, 2, 'Approved', 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(8, 2, 2, 'Delivered', 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(9, 6, 3, 'Pending', NULL, CURDATE(), NULL), (10, 10, 2, 'Pending', NULL, CURDATE(), NULL),
(11, 12, 2, 'Draft', NULL, CURDATE(), NULL), (12, 14, 3, 'Draft', NULL, CURDATE(), NULL),
(13, 16, 2, 'Rejected', 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(14, 20, 3, 'Approved', 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), CURDATE()),
(15, 11, 2, 'Draft', NULL, CURDATE(), NULL), (16, 18, 2, 'Pending', NULL, CURDATE(), NULL),
(17, 13, 3, 'Pending', NULL, CURDATE(), NULL), (18, 9, 2, 'Draft', NULL, CURDATE(), NULL),
(19, 15, 2, 'Draft', NULL, CURDATE(), NULL), (20, 17, 3, 'Draft', NULL, CURDATE(), NULL);

INSERT INTO purchase_order_details (po_id, product_id, suggested_qty, approved_qty, received_qty, discrepancy_reason) VALUES 
(1, 1, 30, 30, 30, NULL), (1, 2, 20, 20, 18, 'Giao thiếu 2'), (1, 4, 40, 40, 40, NULL),
(2, 18, 500, 400, 400, NULL), (2, 19, 200, 200, 200, NULL),
(3, 10, 100, 100, 100, NULL), (3, 11, 50, 50, 50, NULL),
(4, 8, 100, 100, 100, NULL), (4, 9, 200, 150, 150, NULL),
(5, 25, 200, 200, 200, NULL), (5, 26, 100, 100, 100, NULL),
(6, 21, 300, 300, NULL, NULL), (6, 22, 200, 200, NULL, NULL),
(7, 27, 800, 800, NULL, NULL), (8, 1, 50, 50, 50, NULL), (8, 3, 20, 20, 20, NULL),
(9, 28, 150, 150, NULL, NULL), (9, 29, 50, 50, NULL, NULL),
(10, 12, 100, 100, NULL, NULL), (10, 13, 100, 100, NULL, NULL),
(11, 16, 200, 200, NULL, NULL), (12, 23, 100, 100, NULL, NULL),
(13, 31, 50, 50, NULL, 'Từ chối do kho còn tồn nhiều'), (14, 24, 300, 300, NULL, NULL);

-- 3.5. Sales Transactions (~50 Rows)
INSERT INTO sales_transactions (transaction_id, performed_by, transaction_time) VALUES 
(1, 4, DATE_SUB(NOW(), INTERVAL 70 HOUR)), (2, 4, DATE_SUB(NOW(), INTERVAL 68 HOUR)),
(3, 5, DATE_SUB(NOW(), INTERVAL 65 HOUR)), (4, 6, DATE_SUB(NOW(), INTERVAL 60 HOUR)),
(5, 7, DATE_SUB(NOW(), INTERVAL 55 HOUR)), (6, 4, DATE_SUB(NOW(), INTERVAL 50 HOUR)),
(7, 5, DATE_SUB(NOW(), INTERVAL 48 HOUR)), (8, 6, DATE_SUB(NOW(), INTERVAL 45 HOUR)),
(9, 7, DATE_SUB(NOW(), INTERVAL 40 HOUR)), (10, 4, DATE_SUB(NOW(), INTERVAL 35 HOUR)),
(11, 4, DATE_SUB(NOW(), INTERVAL 33 HOUR)), (12, 5, DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(13, 6, DATE_SUB(NOW(), INTERVAL 28 HOUR)), (14, 7, DATE_SUB(NOW(), INTERVAL 25 HOUR)),
(15, 4, DATE_SUB(NOW(), INTERVAL 23 HOUR)), (16, 5, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(17, 6, DATE_SUB(NOW(), INTERVAL 18 HOUR)), (18, 7, DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(19, 4, DATE_SUB(NOW(), INTERVAL 13 HOUR)), (20, 5, DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(21, 6, DATE_SUB(NOW(), INTERVAL 9 HOUR)), (22, 7, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(23, 4, DATE_SUB(NOW(), INTERVAL 7 HOUR)), (24, 5, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(25, 6, DATE_SUB(NOW(), INTERVAL 5 HOUR)), (26, 7, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(27, 4, DATE_SUB(NOW(), INTERVAL 3 HOUR)), (28, 5, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(29, 6, DATE_SUB(NOW(), INTERVAL 90 MINUTE)), (30, 7, DATE_SUB(NOW(), INTERVAL 80 MINUTE)),
(31, 4, DATE_SUB(NOW(), INTERVAL 75 MINUTE)), (32, 5, DATE_SUB(NOW(), INTERVAL 70 MINUTE)),
(33, 6, DATE_SUB(NOW(), INTERVAL 65 MINUTE)), (34, 7, DATE_SUB(NOW(), INTERVAL 60 MINUTE)),
(35, 4, DATE_SUB(NOW(), INTERVAL 55 MINUTE)), (36, 5, DATE_SUB(NOW(), INTERVAL 50 MINUTE)),
(37, 6, DATE_SUB(NOW(), INTERVAL 45 MINUTE)), (38, 7, DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
(39, 4, DATE_SUB(NOW(), INTERVAL 35 MINUTE)), (40, 5, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
(41, 6, DATE_SUB(NOW(), INTERVAL 28 MINUTE)), (42, 7, DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
(43, 4, DATE_SUB(NOW(), INTERVAL 22 MINUTE)), (44, 5, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
(45, 6, DATE_SUB(NOW(), INTERVAL 18 MINUTE)), (46, 7, DATE_SUB(NOW(), INTERVAL 15 MINUTE)),
(47, 4, DATE_SUB(NOW(), INTERVAL 10 MINUTE)), (48, 5, DATE_SUB(NOW(), INTERVAL 8 MINUTE)),
(49, 6, DATE_SUB(NOW(), INTERVAL 5 MINUTE)), (50, 7, DATE_SUB(NOW(), INTERVAL 2 MINUTE));

INSERT INTO sales_transaction_details (transaction_id, product_id, quantity_sold, unit_price, unit_cost, batch_id) VALUES 
(1, 1, 1, 28000, 18000, 1), (1, 18, 1, 10000, 6000, NULL), (2, 4, 2, 18000, 12000, 4), 
(2, 12, 1, 15000, 9500, 6), (3, 10, 1, 22000, 13000, NULL), (3, 18, 2, 10000, 6000, NULL), 
(4, 27, 3, 5000, 3500, NULL), (4, 31, 1, 22000, 15000, NULL), (5, 2, 1, 25000, 16000, NULL),
(6, 8, 2, 14000, 9000, NULL), (7, 19, 1, 12000, 7500, NULL), (7, 28, 2, 9000, 6000, NULL),
(8, 24, 6, 22000, 14000, NULL), (9, 3, 1, 35000, 22000, NULL), (10, 29, 1, 45000, 32000, NULL),
(11, 1, 2, 28000, 18000, 1), (12, 4, 1, 18000, 12000, 4), (13, 21, 3, 10000, 6000, NULL),
(14, 27, 5, 5000, 3500, NULL), (15, 30, 2, 8000, 5500, NULL), (16, 22, 1, 7000, 4000, NULL),
(17, 16, 2, 10000, 6500, 7), (18, 17, 3, 12000, 8500, NULL), (19, 11, 2, 22000, 13000, NULL),
(20, 14, 1, 22000, 14000, NULL), (21, 23, 2, 15000, 9000, NULL), (22, 25, 4, 10000, 7000, NULL),
(23, 26, 2, 11000, 8000, NULL), (24, 7, 2, 15000, 10000, NULL), (25, 6, 3, 13000, 8000, NULL),
(26, 5, 1, 25000, 15000, NULL), (27, 9, 2, 18000, 11000, NULL), (28, 33, 1, 40000, 28000, NULL),
(29, 32, 1, 55000, 38000, NULL), (30, 18, 3, 10000, 6000, NULL), (31, 20, 2, 11000, 6500, NULL),
(32, 1, 1, 28000, 18000, 1), (33, 27, 10, 5000, 3500, NULL), (34, 10, 2, 22000, 13000, NULL),
(35, 12, 2, 15000, 9500, 6), (36, 15, 1, 25000, 16000, NULL), (37, 4, 3, 18000, 12000, 4),
(38, 13, 2, 15000, 9500, NULL), (39, 24, 12, 22000, 14000, NULL), (40, 21, 2, 10000, 6000, NULL),
(41, 2, 1, 25000, 16000, NULL), (42, 8, 1, 14000, 9000, NULL), (43, 19, 2, 12000, 7500, NULL),
(44, 28, 3, 9000, 6000, NULL), (45, 29, 1, 45000, 32000, NULL), (46, 31, 2, 22000, 15000, NULL),
(47, 16, 4, 10000, 6500, 7), (48, 27, 5, 5000, 3500, NULL), (49, 18, 2, 10000, 6000, NULL),
(50, 1, 2, 28000, 18000, 2);

-- 3.6. Stock Movements (~50 Rows)
INSERT INTO stock_movements (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at) VALUES 
(1, 'stock_in', 30, NULL, 1, 2, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(2, 'stock_in', 18, NULL, 1, 2, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(18, 'stock_in', 400, NULL, 2, 2, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(19, 'stock_in', 200, NULL, 2, 2, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(10, 'stock_in', 100, NULL, 3, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(11, 'stock_in', 50, NULL, 3, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(8, 'stock_in', 100, NULL, 4, 2, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(9, 'stock_in', 150, NULL, 4, 2, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(25, 'stock_in', 200, NULL, 5, 2, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(26, 'stock_in', 100, NULL, 5, 2, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'stock_in', 50, NULL, 8, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, 'stock_in', 20, NULL, 8, 2, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'sale', -1, NULL, 1, 4, DATE_SUB(NOW(), INTERVAL 70 HOUR)),
(18, 'sale', -1, NULL, 1, 4, DATE_SUB(NOW(), INTERVAL 70 HOUR)),
(4, 'sale', -2, NULL, 2, 4, DATE_SUB(NOW(), INTERVAL 68 HOUR)),
(12, 'sale', -1, NULL, 2, 4, DATE_SUB(NOW(), INTERVAL 68 HOUR)),
(10, 'sale', -1, NULL, 3, 5, DATE_SUB(NOW(), INTERVAL 65 HOUR)),
(18, 'sale', -2, NULL, 3, 5, DATE_SUB(NOW(), INTERVAL 65 HOUR)),
(27, 'sale', -3, NULL, 4, 6, DATE_SUB(NOW(), INTERVAL 60 HOUR)),
(31, 'sale', -1, NULL, 4, 6, DATE_SUB(NOW(), INTERVAL 60 HOUR)),
(2, 'sale', -1, NULL, 5, 7, DATE_SUB(NOW(), INTERVAL 55 HOUR)),
(8, 'sale', -2, NULL, 6, 4, DATE_SUB(NOW(), INTERVAL 50 HOUR)),
(19, 'sale', -1, NULL, 7, 5, DATE_SUB(NOW(), INTERVAL 48 HOUR)),
(28, 'sale', -2, NULL, 7, 5, DATE_SUB(NOW(), INTERVAL 48 HOUR)),
(24, 'sale', -6, NULL, 8, 6, DATE_SUB(NOW(), INTERVAL 45 HOUR)),
(3, 'sale', -1, NULL, 9, 7, DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(29, 'sale', -1, NULL, 10, 4, DATE_SUB(NOW(), INTERVAL 35 HOUR)),
(2, 'adjustment', -2, 'Hư hỏng vật lý / Bao bì móp méo', NULL, 5, DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(5, 'adjustment', -1, 'Hết hạn sử dụng (Write-off)', NULL, 4, DATE_SUB(NOW(), INTERVAL 28 HOUR)),
(1, 'sale', -2, NULL, 11, 4, DATE_SUB(NOW(), INTERVAL 33 HOUR)),
(4, 'sale', -1, NULL, 12, 5, DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(21, 'sale', -3, NULL, 13, 6, DATE_SUB(NOW(), INTERVAL 28 HOUR)),
(27, 'sale', -5, NULL, 14, 7, DATE_SUB(NOW(), INTERVAL 25 HOUR)),
(30, 'sale', -2, NULL, 15, 4, DATE_SUB(NOW(), INTERVAL 23 HOUR)),
(22, 'sale', -1, NULL, 16, 5, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(16, 'sale', -2, NULL, 17, 6, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(17, 'sale', -3, NULL, 18, 7, DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(11, 'sale', -2, NULL, 19, 4, DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(14, 'sale', -1, NULL, 20, 5, DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(10, 'count_correction', -1, NULL, 1, 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(27, 'count_correction', -5, NULL, 1, 6, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(1, 'count_correction', -1, NULL, 2, 7, CURDATE()),
(23, 'sale', -2, NULL, 21, 6, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(25, 'sale', -4, NULL, 22, 7, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(26, 'sale', -2, NULL, 23, 4, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(7, 'sale', -2, NULL, 24, 5, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(6, 'sale', -3, NULL, 25, 6, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(5, 'sale', -1, NULL, 26, 7, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(9, 'sale', -2, NULL, 27, 4, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(33, 'sale', -1, NULL, 28, 5, DATE_SUB(NOW(), INTERVAL 2 HOUR));

-- 3.7. Stock Counts, Feedback, Incidents, Forecasts, Audits, Notifications
INSERT INTO stock_counts (count_id, performed_by, count_date) VALUES 
(1, 6, DATE_SUB(CURDATE(), INTERVAL 5 DAY)), (2, 7, DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
(3, 4, DATE_SUB(CURDATE(), INTERVAL 3 DAY)), (4, 5, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(5, 6, CURDATE());

INSERT INTO stock_count_details (count_id, product_id, system_qty, actual_qty) VALUES 
(1, 10, 245, 244), (1, 18, 330, 330), (1, 27, 600, 595),
(2, 1, 15, 15), (2, 4, 20, 19), (3, 8, 65, 65), (3, 12, 80, 80),
(4, 24, 235, 230), (4, 31, 45, 45), (5, 1, 9, 8), (5, 12, 78, 78);

INSERT INTO customer_feedback (product_id, logged_by, feedback_text, created_at) VALUES 
(1, 4, 'Khách hỏi mua Gimbap Bò lúc 7h tối nhưng đã hết hàng.', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(12, 5, 'Khách phàn nàn sữa chuối Binggrae trên kệ bị móp méo.', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3, 6, 'Khách tìm Tteokbokki nhưng không thấy.', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(27, 7, 'Khách chê mì Hảo Hảo dạo này hay đứt hàng.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(18, 4, 'Pepsi không calo trong tủ mát không đủ lạnh.', DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO shortage_incidents (product_id, handled_by, resolution_action, status, created_at) VALUES 
(1, 2, 'Gọi giục CJ Foods giao hàng sớm.', 'Resolved', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(10, 3, 'Tạo PO gấp nhưng NCC Samyang báo hết hàng kho tổng, chờ 3 ngày.', 'Open', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(27, 2, 'Đã đặt thêm 800 gói từ Acecook.', 'Resolved', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(24, 3, 'Lên đơn 300 lon Heineken chuẩn bị cuối tuần.', 'Resolved', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(16, 2, 'Gửi PO cho Vinamilk.', 'Open', NOW());

INSERT INTO demand_forecasts (product_id, suggested_qty, api_status, requested_at) VALUES 
(1, 45, 'success', DATE_SUB(NOW(), INTERVAL 3 DAY)), (18, 600, 'success', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(10, 150, 'fallback_used', DATE_SUB(NOW(), INTERVAL 2 DAY)), (27, 850, 'success', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(24, 320, 'success', DATE_SUB(NOW(), INTERVAL 1 DAY)), (12, 120, 'fallback_used', NOW());

INSERT INTO audit_logs (account_id, action_type, target_table, target_id, timestamp) VALUES 
(1, 'UPDATE_REORDER_RULE', 'reorder_rules', 1, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 2, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 4, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 5, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 6, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 7, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'OVERRIDE_PO_QTY', 'purchase_order_details', 9, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 'OVERRIDE_PO_QTY', 'purchase_order_details', 12, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'REJECT_PO', 'purchase_orders', 13, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'APPROVE_PO', 'purchase_orders', 14, CURDATE());

INSERT INTO notifications (account_id, title, message, is_read, email_status, zalo_status, created_at) VALUES 
(2, 'Cảnh báo Tồn kho thấp', 'Sản phẩm Gimbap Bò (RTE-GB-001) chạm Reorder Point.', TRUE, 'not_required', 'sent', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3, 'Đơn hàng cần xử lý', 'NCC Samyang vừa xác nhận đơn hàng PO #3.', TRUE, 'sent', 'sent', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(2, 'Cảnh báo Tồn kho thấp', 'Mì Hảo Hảo (FMC-AC-001) chạm Reorder Point.', TRUE, 'not_required', 'sent', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'PO Đợi Duyệt', 'Manager Đỗ Thị Phương vừa trình PO #6.', TRUE, 'sent', 'not_required', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'PO Đợi Duyệt', 'Manager Lê Hà Bảo Trân vừa trình PO #7.', FALSE, 'pending', 'not_required', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'PO Bị Từ Chối', 'Admin đã từ chối PO #13.', FALSE, 'sent', 'sent', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'Cảnh báo Hết Hạn', 'Gimbap Bò (Batch 1) hết hạn vào ngày mai.', FALSE, 'not_required', 'sent', NOW());

SET FOREIGN_KEY_CHECKS = 1;