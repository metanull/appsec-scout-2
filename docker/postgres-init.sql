-- Creates the dedicated test database alongside the main application database.
-- This file is executed automatically by the PostgreSQL container on first
-- initialisation (mounted into /docker-entrypoint-initdb.d/), running as the
-- POSTGRES_USER superuser, so the app user owns the test database outright.
-- Subsequent starts skip it. Mirrors docker/mysql-init.sql for the MySQL engine.
CREATE DATABASE appsec_scout_test;
