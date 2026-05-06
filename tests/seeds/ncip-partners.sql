-- NCIP partner seed: 3 mock libraries for E2E tests
-- Run: mysql -u USER DB < tests/seeds/ncip-partners.sql

SET NAMES utf8mb4;

INSERT INTO ncip_partners (name, endpoint_url, isil, notes, created_at, updated_at) VALUES
    ('Biblioteca Nazionale Centrale di Roma',  'https://bncr.example.org/ncip',   'IT-RM001', 'Partner di test #1', NOW(), NOW()),
    ('Biblioteca Nazionale Centrale di Firenze','https://bncf.example.org/ncip',  'IT-FI001', 'Partner di test #2', NOW(), NOW()),
    ('Biblioteca Apostolica Vaticana',          'https://bav.example.org/ncip',    'IT-VA001', 'Partner di test #3', NOW(), NOW());
