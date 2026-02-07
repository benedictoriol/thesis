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
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    reviewed_by_user_id INT NULL,
    admin_note TEXT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shop_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    doc_type ENUM('business_permit', 'dti_sec', 'valid_id', 'location_proof', 'other') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    parent_id INT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
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
    created_at DATETIME NOT NULL,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shop_staff (shop_id, user_id)
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

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_user_id INT NOT NULL,
    shop_id INT NOT NULL,
    post_id INT NULL,
    offer_id INT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    FOREIGN KEY (client_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES client_posts(id) ON DELETE SET NULL,
    FOREIGN KEY (offer_id) REFERENCES post_offers(id) ON DELETE SET NULL,
    INDEX idx_orders_client (client_user_id),
    INDEX idx_orders_shop (shop_id),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;