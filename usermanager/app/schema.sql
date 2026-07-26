CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL DEFAULT '',
    role ENUM('superadmin','operator','billing','viewer') NOT NULL DEFAULT 'operator',
    language ENUM('fa','en') NOT NULL DEFAULT 'fa',
    phone VARCHAR(30) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    host VARCHAR(255) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL DEFAULT 443,
    username VARCHAR(100) NOT NULL,
    password_encrypted TEXT NULL,
    use_api_key TINYINT(1) NOT NULL DEFAULT 0,
    api_key_encrypted TEXT NULL,
    use_tls TINYINT(1) NOT NULL DEFAULT 1,
    verify_tls TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    last_poll_at DATETIME NULL,
    last_status ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_router_host_port (host, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    mikrotik_profile VARCHAR(100) NOT NULL,
    rate_limit VARCHAR(50) NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(5) NOT NULL DEFAULT 'IRR',
    validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    data_cap_gb INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_plan_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ppp_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    router_id BIGINT UNSIGNED NOT NULL,
    mikrotik_secret_id VARCHAR(50) NOT NULL DEFAULT '',
    username VARCHAR(100) NOT NULL,
    password_encrypted TEXT NULL,
    service ENUM('pppoe','pptp','l2tp','sstp','any') NOT NULL DEFAULT 'pppoe',
    plan_id BIGINT UNSIGNED NULL,
    profile VARCHAR(100) NOT NULL DEFAULT '',
    rate_limit VARCHAR(50) NOT NULL DEFAULT '',
    status ENUM('active','disabled','expired','suspended','missing_on_device','needs_plan_assignment') NOT NULL DEFAULT 'active',
    expiration_date DATE NULL,
    full_name VARCHAR(150) NOT NULL DEFAULT '',
    phone VARCHAR(30) NOT NULL DEFAULT '',
    address TEXT NULL,
    comment TEXT NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ppp_router_username (router_id, username),
    KEY idx_ppp_expiration (expiration_date),
    KEY idx_ppp_status (status),
    KEY idx_ppp_username (username),
    CONSTRAINT fk_ppp_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ppp_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_counters (
    period_key CHAR(6) PRIMARY KEY,
    counter_value INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    local_user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    amount DECIMAL(15,2) NOT NULL,
    discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax DECIMAL(15,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL,
    status ENUM('unpaid','paid','overdue','cancelled','credited') NOT NULL DEFAULT 'unpaid',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    paid_at DATETIME NULL,
    related_invoice_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_user (local_user_id),
    KEY idx_invoice_status_due (status, due_date),
    CONSTRAINT fk_invoice_user FOREIGN KEY (local_user_id) REFERENCES ppp_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_related FOREIGN KEY (related_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    method ENUM('cash','bank_transfer','online_gateway') NOT NULL DEFAULT 'cash',
    reference VARCHAR(100) NOT NULL DEFAULT '',
    received_by BIGINT UNSIGNED NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    KEY idx_payment_invoice (invoice_id),
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_admin FOREIGN KEY (received_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS traffic_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_user_id BIGINT UNSIGNED NOT NULL,
    log_date DATE NOT NULL,
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count INT UNSIGNED NOT NULL DEFAULT 0,
    uptime_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_traffic_user_date (local_user_id, log_date),
    KEY idx_traffic_date (log_date),
    CONSTRAINT fk_traffic_user FOREIGN KEY (local_user_id) REFERENCES ppp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS active_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    router_id BIGINT UNSIGNED NOT NULL,
    session_key VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL,
    address VARCHAR(100) NOT NULL DEFAULT '',
    uptime VARCHAR(50) NOT NULL DEFAULT '',
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NOT NULL,
    UNIQUE KEY uniq_active_router_session (router_id, session_key),
    KEY idx_active_router_user (router_id, username),
    KEY idx_active_last_seen (last_seen_at),
    CONSTRAINT fk_active_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL DEFAULT '',
    target_id BIGINT UNSIGNED NULL,
    detail LONGTEXT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_admin_time (admin_id, created_at),
    KEY idx_activity_target (target_type, target_id),
    CONSTRAINT fk_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_login_attempt (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
