CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('sys_admin', 'owner', 'hr', 'employee', 'client') NOT NULL,
    status ENUM('pending', 'active', 'disabled') NOT NULL,
    phone VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    full_address_text VARCHAR(255) NOT NULL,
    town_text VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_client_addresses_client (client_user_id),
    INDEX idx_client_addresses_default (client_user_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    method ENUM('cash_on_delivery', 'bank_transfer', 'e_wallet', 'card', 'other') NOT NULL,
    details_text TEXT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_client_payment_client (client_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity VARCHAR(100) NOT NULL,
    entity_id INT NULL,
    meta_json JSON NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM(
        'message_received',
        'quote_request_received',
        'quote_sent',
        'quote_accepted',
        'quote_rejected',
        'order_placed',
        'order_accepted',
        'order_rejected',
        'order_status_changed',
        'payment_proof_uploaded',
        'payment_reminder',
        'payment_verified',
        'payment_rejected',
        'low_stock',
        'hiring_application'
    ) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    link_type ENUM(
        'conversation',
        'quote_request',
        'quote',
        'order',
        'payment',
        'post',
        'application'
    ) NULL,
    link_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    address_text VARCHAR(255) NULL,
    logo_path VARCHAR(255) NULL,
    cover_path VARCHAR(255) NULL,
    status ENUM('pending', 'active', 'suspended', 'hidden') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_verification (
    shop_id INT PRIMARY KEY,
    status ENUM('draft', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
    shop_name VARCHAR(255) NULL,
    pickup_address VARCHAR(255) NULL,
    shop_email VARCHAR(255) NULL,
    shop_phone VARCHAR(50) NULL,
    individual_name VARCHAR(255) NULL,
    business_name VARCHAR(255) NULL,
    business_address VARCHAR(255) NULL,
    primary_document_type VARCHAR(100) NULL,
    government_id_type VARCHAR(100) NULL,
    business_email VARCHAR(255) NULL,
    business_phone VARCHAR(50) NULL,
    tax_identification_number VARCHAR(100) NULL,
    vat_registration ENUM('vat_registered', 'non_vat_registered') NULL,
    bir_certification VARCHAR(255) NULL,
    sworn_declaration TINYINT(1) NOT NULL DEFAULT 0,
    terms_accepted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    reviewed_by_user_id INT NULL,
    admin_note TEXT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    open_time TIME NULL,
    close_time TIME NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shop_day (shop_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_availability (
    shop_id INT PRIMARY KEY,
    accepting_orders TINYINT(1) NOT NULL DEFAULT 1,
    accepting_quotes TINYINT(1) NOT NULL DEFAULT 1,
    accepting_custom TINYINT(1) NOT NULL DEFAULT 1,
    accepting_rush TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_metrics (
    shop_id INT PRIMARY KEY,
    avg_rating DECIMAL(3, 2) NOT NULL DEFAULT 0,
    review_count INT NOT NULL DEFAULT 0,
    completion_rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    avg_turnaround_days DECIMAL(6, 2) NOT NULL DEFAULT 0,
    price_index DECIMAL(6, 2) NOT NULL DEFAULT 0,
    cancellation_rate DECIMAL(5, 2) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dss_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    query_json JSON NOT NULL,
    results_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dss_logs_client (client_user_id),
    INDEX idx_dss_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    doc_type ENUM('business_permit', 'dti_sec', 'valid_id', 'location_proof', 'bir_certificate', 'other') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    item_type ENUM('tshirt', 'cap', 'bag', 'logo', 'other') NULL,
    base_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    turnaround_text VARCHAR(255) NULL,
    status ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    image_path VARCHAR(255) NULL,
    dss_score DECIMAL(10, 4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    INDEX idx_products_shop (shop_id),
    INDEX idx_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_images_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parent_id INT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_portfolio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category_id INT NULL,
    status ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    image_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_shop_portfolio_shop (shop_id),
    INDEX idx_shop_portfolio_category (category_id),
    INDEX idx_shop_portfolio_status (status),
    INDEX idx_shop_portfolio_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_portfolio_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    portfolio_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (portfolio_id) REFERENCES shop_portfolio(id) ON DELETE CASCADE,
    INDEX idx_portfolio_images_portfolio (portfolio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dss_global_weights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    criterion VARCHAR(255) NOT NULL,
    weight DECIMAL(6, 4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dss_recalculation_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    triggered_by INT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'queued',
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dss_recalculation_status (status),
    INDEX idx_dss_recalculation_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS moderation_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    entity VARCHAR(100) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    reason TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('hr', 'employee') NOT NULL,
    position VARCHAR(255) NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    can_quote TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_orders TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_inventory TINYINT(1) NOT NULL DEFAULT 0,
    hired_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shop_staff (shop_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_step_defaults (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    step ENUM('digitizing', 'hooping', 'stitching', 'trimming', 'qc', 'packing') NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shop_step_default (shop_id, step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hiring_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location_text VARCHAR(255) NULL,
    employment_type ENUM('full_time', 'part_time', 'contract', 'internship', 'temporary') NOT NULL DEFAULT 'full_time',
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    INDEX idx_hiring_posts_shop (shop_id),
    INDEX idx_hiring_posts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    post_id INT NULL,
    user_id INT NULL,
    fullname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    position VARCHAR(255) NULL,
    resume_path VARCHAR(255) NULL,
    status ENUM('pending', 'interviewed', 'approved', 'rejected', 'converted') NOT NULL DEFAULT 'pending',
    interview_notes TEXT NULL,
    applied_at DATETIME NOT NULL,
    interviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES hiring_posts(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_applications_post (post_id),
    INDEX idx_applications_shop (shop_id),
    INDEX idx_applications_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    client_user_id INT NOT NULL,
    context_type ENUM('inquiry', 'post', 'quote_request', 'order') NOT NULL,
    context_id INT NULL,
    created_at DATETIME NOT NULL,
    last_message_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversations_shop (shop_id),
    INDEX idx_conversations_client (client_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_user_id INT NOT NULL,
    message_text TEXT NOT NULL,
    attachment_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_conversation (conversation_id),
    INDEX idx_messages_conversation_created (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    price_add DECIMAL(10, 2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_variants_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    addon_price DECIMAL(10, 2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_addons_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    shop_id INT NOT NULL,
    client_user_id INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_reviews_order (order_id),
    INDEX idx_reviews_shop (shop_id),
    INDEX idx_reviews_product (product_id),
    INDEX idx_reviews_client (client_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS review_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    INDEX idx_review_images_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS custom_designs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    shop_id INT NULL,
    name VARCHAR(120) NOT NULL,
    item_type ENUM('tshirt', 'cap', 'bag', 'logo') NOT NULL,
    preview_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE SET NULL,
    INDEX idx_custom_designs_owner (owner_user_id),
    INDEX idx_custom_designs_shop (shop_id),
    INDEX idx_custom_designs_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS custom_design_layers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    design_id INT NOT NULL,
    layer_type ENUM('image', 'text') NOT NULL,
    content TEXT NOT NULL,
    x DECIMAL(10,2) NOT NULL DEFAULT 0,
    y DECIMAL(10,2) NOT NULL DEFAULT 0,
    scale DECIMAL(8,3) NOT NULL DEFAULT 1,
    rotation DECIMAL(8,3) NOT NULL DEFAULT 0,
    color VARCHAR(40) NULL,
    font VARCHAR(120) NULL,
    font_size INT NULL,
    font_weight INT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (design_id) REFERENCES custom_designs(id) ON DELETE CASCADE,
    INDEX idx_custom_design_layers_design (design_id),
    INDEX idx_custom_design_layers_type (layer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    item_type ENUM('tshirt', 'cap', 'bag', 'logo', 'other') NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    budget_min DECIMAL(10, 2) NULL,
    budget_max DECIMAL(10, 2) NULL,
    deadline_date DATE NULL,
    town_text VARCHAR(255) NULL,
    status ENUM('open', 'closed', 'expired', 'converted') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_client_posts_client (client_user_id),
    INDEX idx_client_posts_status (status),
    INDEX idx_client_posts_deadline (deadline_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    shop_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_post_invite (post_id, shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE CASCADE,
    INDEX idx_post_files_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_design_refs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    design_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (design_id) REFERENCES custom_designs(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_post_design (post_id, design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    shop_id INT NOT NULL,
    offered_by_user_id INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    turnaround_days INT NULL,
    notes TEXT NULL,
    status ENUM('sent', 'withdrawn', 'accepted', 'rejected') NOT NULL DEFAULT 'sent',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (offered_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_post_offers_post (post_id),
    INDEX idx_post_offers_shop (shop_id),
    INDEX idx_post_offers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    shop_id INT NOT NULL,
    source_type ENUM('customization', 'client_post') NOT NULL,
    source_id INT NOT NULL,
    design_id INT NULL,
    notes TEXT NULL,
    status ENUM('pending', 'quoted', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (design_id) REFERENCES custom_designs(id) ON DELETE SET NULL,
    INDEX idx_quote_requests_client (client_user_id),
    INDEX idx_quote_requests_shop (shop_id),
    INDEX idx_quote_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_request_id INT NOT NULL,
    quoted_by_user_id INT NULL,
    price DECIMAL(12, 2) NOT NULL,
    turnaround_days INT NOT NULL,
    notes TEXT NULL,
    valid_until DATETIME NULL,
    status ENUM('sent', 'revised', 'accepted', 'rejected') NOT NULL DEFAULT 'sent',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (quote_request_id) REFERENCES quote_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (quoted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quotes_request (quote_request_id),
    INDEX idx_quotes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quote_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    changed_by_user_id INT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_quote_status_logs_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NULL,
    client_user_id INT NOT NULL,
    shop_id INT NOT NULL,
    post_id INT NULL,
    offer_id INT NULL,
    quote_id INT NULL,
    status ENUM('pending', 'in_progress', 'ready', 'completed', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE SET NULL,
    FOREIGN KEY (offer_id) REFERENCES post_offers(id) ON DELETE SET NULL,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_orders_number (order_number),
    INDEX idx_orders_client (client_user_id),
    INDEX idx_orders_shop (shop_id),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    changed_by_user_id INT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_status_logs_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_jobs_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(150) NOT NULL,
    ran_at DATETIME NOT NULL,
    status VARCHAR(50) NOT NULL,
    details_json JSON NULL,
    INDEX idx_system_jobs_log_job (job_name),
    INDEX idx_system_jobs_log_ran (ran_at),
    INDEX idx_system_jobs_log_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    staff_user_id INT NOT NULL,
    assigned_by_user_id INT NOT NULL,
    assigned_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_order_staff (order_id, staff_user_id),
    INDEX idx_order_assignments_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_job_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    ticket_code VARCHAR(40) NOT NULL,
    title VARCHAR(150) NULL,
    notes TEXT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_by_user_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_job_ticket_code (ticket_code),
    INDEX idx_order_job_tickets_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    step ENUM('digitizing', 'hooping', 'stitching', 'trimming', 'qc', 'packing') NOT NULL,
    assigned_to_user_id INT NOT NULL,
    status ENUM('queued', 'working', 'for_review', 'done') NOT NULL DEFAULT 'queued',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_job_tickets_order (order_id),
    INDEX idx_job_tickets_assigned (assigned_to_user_id),
    INDEX idx_job_tickets_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    status ENUM('queued', 'working', 'for_review', 'done') NOT NULL,
    note TEXT NULL,
    photo_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES job_tickets(id) ON DELETE CASCADE,
    INDEX idx_job_updates_ticket (ticket_id),
    INDEX idx_job_updates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    proof_path VARCHAR(255) NOT NULL,
    uploaded_by_user_id INT NOT NULL,
    uploaded_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_order_proofs_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    method ENUM('cod', 'pickup_cash', 'bank_transfer') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    status ENUM('unpaid', 'pending_proof', 'verified', 'rejected') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_payments_order (order_id),
    INDEX idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    proof_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    INDEX idx_payment_proofs_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    reviewed_by_user_id INT NOT NULL,
    decision ENUM('verified', 'rejected') NOT NULL,
    reason TEXT NOT NULL,
    reviewed_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_payment_reviews_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS earnings_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    order_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    method ENUM('cod', 'pickup_cash', 'bank_transfer') NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_earnings_payment (payment_id),
    INDEX idx_earnings_order (order_id),
    INDEX idx_earnings_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS timesheets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    user_id INT NOT NULL,
    work_date DATE NOT NULL,
    hours DECIMAL(6, 2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    approved_by_user_id INT NULL,
    status ENUM('pending', 'approved') NOT NULL DEFAULT 'pending',
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_timesheets_shop (shop_id),
    INDEX idx_timesheets_user (user_id),
    INDEX idx_timesheets_status (status),
    INDEX idx_timesheets_work_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('draft', 'finalized') NOT NULL DEFAULT 'draft',
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    INDEX idx_payroll_periods_shop (shop_id),
    INDEX idx_payroll_periods_status (status),
    INDEX idx_payroll_periods_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    user_id INT NOT NULL,
    base_pay DECIMAL(10, 2) NOT NULL DEFAULT 0,
    overtime_pay DECIMAL(10, 2) NOT NULL DEFAULT 0,
    bonus DECIMAL(10, 2) NOT NULL DEFAULT 0,
    deductions DECIMAL(10, 2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(10, 2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_payroll_entries_period (period_id),
    INDEX idx_payroll_entries_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS productivity_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    user_id INT NOT NULL,
    metric_date DATE NOT NULL,
    jobs_done INT NOT NULL DEFAULT 0,
    avg_turnaround_hours DECIMAL(6, 2) NOT NULL DEFAULT 0,
    late_jobs INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_productivity_metrics_shop (shop_id),
    INDEX idx_productivity_metrics_user (user_id),
    INDEX idx_productivity_metrics_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;