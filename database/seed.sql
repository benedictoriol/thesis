INSERT INTO users (fullname, email, password_hash, role, status, phone, created_at, last_login)
VALUES
    ('System Admin', 'sysadmin@example.com', '$2y$12$1Z1bQD9RM6Vz3mYxVbfkVO/tLdhl0CHZ/SAO4hlHaMMwb0CixotTO', 'sys_admin', 'active', NULL, NOW(), NULL),
    ('Shop Owner', 'owner@example.com', '$2y$12$1Z1bQD9RM6Vz3mYxVbfkVO/tLdhl0CHZ/SAO4hlHaMMwb0CixotTO', 'owner', 'active', NULL, NOW(), NULL),
    ('HR User', 'hr@example.com', '$2y$12$1Z1bQD9RM6Vz3mYxVbfkVO/tLdhl0CHZ/SAO4hlHaMMwb0CixotTO', 'hr', 'active', NULL, NOW(), NULL),
    ('Employee User', 'employee@example.com', '$2y$12$1Z1bQD9RM6Vz3mYxVbfkVO/tLdhl0CHZ/SAO4hlHaMMwb0CixotTO', 'employee', 'active', NULL, NOW(), NULL),
    ('Client User', 'client@example.com', '$2y$12$1Z1bQD9RM6Vz3mYxVbfkVO/tLdhl0CHZ/SAO4hlHaMMwb0CixotTO', 'client', 'active', NULL, NOW(), NULL);