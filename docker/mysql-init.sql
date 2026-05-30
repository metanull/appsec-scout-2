-- Creates the dedicated test database alongside the main application database.
-- This file is executed automatically by the MySQL container on first initialisation
-- (mounted into /docker-entrypoint-initdb.d/). Subsequent starts skip it.
CREATE DATABASE IF NOT EXISTS appsec_scout_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON appsec_scout_test.* TO 'appsec_scout'@'%';

FLUSH PRIVILEGES;
