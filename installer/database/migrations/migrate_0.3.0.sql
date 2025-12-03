-- Migration script for Pinakes 0.3.0
-- Renames classificazione_dowey -> classificazione_dewey (typo fix)
-- Run this BEFORE updating PHP code on existing installations

-- Check if old column exists and rename it
-- MySQL/MariaDB syntax
ALTER TABLE libri
CHANGE COLUMN classificazione_dowey classificazione_dewey VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

-- Note: If you get an error "Unknown column 'classificazione_dowey'",
-- your database already has the correct column name and no action is needed.
