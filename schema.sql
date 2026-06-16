-- Suly Envios Dashboard - Database Schema
-- MySQL 8.x

CREATE DATABASE IF NOT EXISTS suly_envios CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE suly_envios;

-- ── Stores ──
CREATE TABLE stores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    barri_agency_number VARCHAR(20) DEFAULT NULL,
    barri_operator_number VARCHAR(20) DEFAULT NULL,
    viamericas_agency_number VARCHAR(20) DEFAULT NULL,
    intercambio_agency_number VARCHAR(20) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO stores (name) VALUES ('Bruton'), ('Carrollton'), ('Lake June');

-- ── Users ──
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','cashier','employee') NOT NULL DEFAULT 'employee',
    photo_url VARCHAR(500) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Default admin (password: admin123 — CHANGE IN PRODUCTION)
INSERT INTO users (name, email, password_hash, role)
VALUES ('Suly Admin', 'admin@sulyenvios.com', '$2y$12$OGweCw.HKWQIXjc/1/sVM.Ci6PU/1qCw7Rbim1Y9y6LvxHLtAAATi', 'admin');

-- ── Caja Sessions ──
CREATE TABLE caja_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    cashier_name VARCHAR(100) DEFAULT NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    closing_balance DECIMAL(12,2) DEFAULT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_store_date (store_id, session_date)
) ENGINE=InnoDB;

-- ── Caja Entries ──
CREATE TABLE caja_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    company VARCHAR(100) NOT NULL,
    cash_in DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    checks_debits DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) GENERATED ALWAYS AS (cash_in + checks_debits) STORED,
    notes VARCHAR(500) DEFAULT NULL,
    sort_order SMALLINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES caja_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (session_id)
) ENGINE=InnoDB;

-- ── Caja Denominations ──
CREATE TABLE caja_denominations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    denomination DECIMAL(6,2) NOT NULL,
    count INT NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) GENERATED ALWAYS AS (denomination * count) STORED,
    FOREIGN KEY (session_id) REFERENCES caja_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Clients ──
CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_code VARCHAR(30) DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    monthly_limit DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    income_verified TINYINT(1) NOT NULL DEFAULT 0,
    income_doc_path VARCHAR(500) DEFAULT NULL,
    sender_id_path VARCHAR(500) DEFAULT NULL,
    sender_id_type VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_code (client_code)
) ENGINE=InnoDB;

-- ── Transfers ──
CREATE TABLE transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED DEFAULT NULL,
    store_id INT UNSIGNED NOT NULL,
    transaction_code VARCHAR(30) DEFAULT NULL,
    beneficiary VARCHAR(200) NOT NULL,
    date_sent DATETIME NOT NULL,
    date_paid DATETIME DEFAULT NULL,
    amount_usd DECIMAL(12,2) NOT NULL,
    fee DECIMAL(10,2) DEFAULT NULL,
    tax DECIMAL(10,2) DEFAULT NULL,
    amount_local DECIMAL(14,2) DEFAULT NULL,
    currency VARCHAR(10) DEFAULT 'MXN',
    paying_bank VARCHAR(100) DEFAULT NULL,
    destination_country VARCHAR(100) DEFAULT NULL,
    destination_city VARCHAR(200) DEFAULT NULL,
    company VARCHAR(50) DEFAULT NULL,
    transaction_type VARCHAR(30) DEFAULT NULL,
    source VARCHAR(30) DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_client_date (client_id, date_sent),
    INDEX idx_store_date (store_id, date_sent)
) ENGINE=InnoDB;

-- ── Suly Ledger ──
CREATE TABLE suly_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    employee_name VARCHAR(100) DEFAULT NULL,
    description VARCHAR(500) NOT NULL,
    owed_to_suly DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    suly_owes DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    entry_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_date (store_id, entry_date)
) ENGINE=InnoDB;

-- ── Employees ──
CREATE TABLE employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    hourly_rate DECIMAL(8,2) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Clock Ins ──
CREATE TABLE clock_ins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED NOT NULL,
    clock_in_time DATETIME NOT NULL,
    clock_out_time DATETIME DEFAULT NULL,
    photo_path VARCHAR(500) NOT NULL,
    clock_out_photo_path VARCHAR(500) DEFAULT NULL,
    hours_worked DECIMAL(5,2) DEFAULT NULL,
    status ENUM('clocked_in','clocked_out','missed') NOT NULL DEFAULT 'clocked_in',
    notes VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_employee_date (employee_id, clock_in_time),
    INDEX idx_store_date (store_id, clock_in_time)
) ENGINE=InnoDB;

-- ── Schedules ──
CREATE TABLE schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Mon,6=Sun',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    INDEX idx_store_week (store_id, week_start)
) ENGINE=InnoDB;

-- ── Transfer Statistics ──
CREATE TABLE transfer_statistics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    company VARCHAR(50) NOT NULL,
    month TINYINT NOT NULL,
    year SMALLINT NOT NULL,
    transfer_count INT NOT NULL DEFAULT 0,
    total_usd DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    UNIQUE KEY uk_store_company_period (store_id, company, month, year)
) ENGINE=InnoDB;

-- ── Accounting Entries ──
CREATE TABLE accounting_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    entry_type ENUM('receivable','payable') NOT NULL,
    entry_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_cat (store_id, category)
) ENGINE=InnoDB;

-- ── Inventory (Medicine) ──
CREATE TABLE inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    description VARCHAR(500) DEFAULT NULL,
    cost_price DECIMAL(10,2) DEFAULT NULL,
    retail_price DECIMAL(10,2) DEFAULT NULL,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_product (store_id, product_name)
) ENGINE=InnoDB;

-- ── Events (Salon de Eventos) ──
CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    event_date DATE NOT NULL,
    deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) GENERATED ALWAYS AS (deposit + balance) STORED,
    color_theme VARCHAR(100) DEFAULT NULL,
    package VARCHAR(100) DEFAULT NULL,
    payment_method VARCHAR(100) DEFAULT NULL,
    status ENUM('booked','confirmed','completed','cancelled') NOT NULL DEFAULT 'booked',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_date (store_id, event_date)
) ENGINE=InnoDB;

-- ── Plates (Vehicle Registration) ──
CREATE TABLE plates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    vin VARCHAR(50) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    delivery_date DATE DEFAULT NULL,
    payment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    agent_name VARCHAR(100) DEFAULT NULL,
    agent_fee DECIMAL(10,2) DEFAULT NULL,
    status ENUM('pending','in_progress','completed','delivered') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- ── Secure Notes ──
CREATE TABLE secure_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- ── Excel Imports ──
CREATE TABLE excel_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    sheet_mapping JSON DEFAULT NULL,
    rows_imported INT DEFAULT 0,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    errors TEXT DEFAULT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── Barri Reports ──
CREATE TABLE barri_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    agency_number VARCHAR(20) DEFAULT NULL,
    agency_name VARCHAR(200) DEFAULT NULL,
    agency_address VARCHAR(300) DEFAULT NULL,
    company VARCHAR(50) DEFAULT 'Barri',
    store_name VARCHAR(200) DEFAULT NULL,
    ar_executive VARCHAR(200) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    report_date_from DATE NOT NULL,
    report_date_to DATE NOT NULL,
    beginning_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    ending_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_transactions INT NOT NULL DEFAULT 0,
    total_principal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_fees DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_agcomm DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    filename VARCHAR(255) NOT NULL DEFAULT '',
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending','processed','error') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_store_date (store_id, report_date_from)
) ENGINE=InnoDB;

-- ── Barri Transactions ──
CREATE TABLE barri_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    transaction_time TIME NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type VARCHAR(30) NOT NULL DEFAULT 'giros',
    reference_number VARCHAR(30) NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    beneficiary_name VARCHAR(200) DEFAULT NULL,
    description VARCHAR(300) DEFAULT NULL,
    operator VARCHAR(30) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    principal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    running_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    ag_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    variable_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    variable_fx DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    matched TINYINT(1) NOT NULL DEFAULT 0,
    pushed_to_transfers TINYINT(1) NOT NULL DEFAULT 0,
    transfer_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES barri_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE SET NULL,
    INDEX idx_report (report_id),
    INDEX idx_client (client_id),
    INDEX idx_reference (reference_number)
) ENGINE=InnoDB;

-- ── Receivers (beneficiaries with ID for disambiguation) ──
CREATE TABLE receivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    id_path VARCHAR(500) DEFAULT NULL,
    id_type VARCHAR(50) DEFAULT NULL,
    destination_country VARCHAR(100) DEFAULT NULL,
    destination_city VARCHAR(200) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_name (client_id, name)
) ENGINE=InnoDB;

-- ── ALTER: Add sender ID columns to clients ──
-- ALTER TABLE clients ADD COLUMN sender_id_path VARCHAR(500) DEFAULT NULL AFTER income_doc_path;
-- ALTER TABLE clients ADD COLUMN sender_id_type VARCHAR(50) DEFAULT NULL AFTER sender_id_path;

-- ── ALTER: Add fee/tax/source columns to transfers ──
-- ALTER TABLE transfers ADD COLUMN receiver_id INT UNSIGNED DEFAULT NULL AFTER client_id;
-- ALTER TABLE transfers ADD COLUMN fee DECIMAL(10,2) DEFAULT NULL AFTER amount_usd;
-- ALTER TABLE transfers ADD COLUMN tax DECIMAL(10,2) DEFAULT NULL AFTER fee;
-- ALTER TABLE transfers ADD COLUMN transaction_type VARCHAR(30) DEFAULT NULL AFTER company;
-- ALTER TABLE transfers ADD COLUMN source VARCHAR(30) DEFAULT 'manual' AFTER transaction_type;

-- ── ALTER: Viamericas / Intermex support ──
-- ALTER TABLE barri_reports ADD COLUMN report_type VARCHAR(30) DEFAULT 'barri' AFTER status;
-- ALTER TABLE barri_reports ADD COLUMN total_received_foreign DECIMAL(14,2) DEFAULT 0.00 AFTER total_agcomm;

-- ALTER TABLE barri_transactions ADD COLUMN amount_received DECIMAL(14,2) DEFAULT 0.00 AFTER total;
-- ALTER TABLE barri_transactions ADD COLUMN received_currency VARCHAR(10) DEFAULT NULL AFTER amount_received;
-- ALTER TABLE barri_transactions ADD COLUMN paying_bank VARCHAR(200) DEFAULT NULL AFTER received_currency;
-- ALTER TABLE barri_transactions ADD COLUMN destination_country VARCHAR(100) DEFAULT NULL AFTER paying_bank;
-- ALTER TABLE barri_transactions ADD COLUMN destination_state VARCHAR(100) DEFAULT NULL AFTER destination_country;
-- ALTER TABLE barri_transactions ADD COLUMN destination_city VARCHAR(200) DEFAULT NULL AFTER destination_state;
-- ALTER TABLE barri_transactions ADD COLUMN payment_date DATETIME DEFAULT NULL AFTER destination_city;
-- ALTER TABLE barri_transactions ADD COLUMN transaction_status VARCHAR(50) DEFAULT NULL AFTER payment_date;
