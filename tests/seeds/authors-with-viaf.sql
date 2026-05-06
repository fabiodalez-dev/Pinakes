-- Seed: authors-with-viaf.sql
-- 20 authors with known VIAF IDs, ISNI IDs, and authority fields
-- Used by: viaf-authority.spec.js, viaf-reconcile.spec.js
-- All ISNI check digits verified via MOD 11-2 (ISO/IEC 7064)
--
-- To load: mysql -u <user> -p <db> < tests/seeds/authors-with-viaf.sql
-- To clean: DELETE FROM autori WHERE nome LIKE 'SEED_VIAF_%';

INSERT IGNORE INTO autori (nome, viaf_id, viaf_uri, isni_id, isni_uri, authority_source, authority_confidence) VALUES
-- Group A: well-known Italian/international authors with real VIAF data
('SEED_VIAF_Dante Alighieri',       '97006617',  'https://viaf.org/viaf/97006617',  NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Giovanni Boccaccio',    '64002165',  'https://viaf.org/viaf/64002165',  NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Francesco Petrarca',    '39387539',  'https://viaf.org/viaf/39387539',  NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Alessandro Manzoni',    '7399869',   'https://viaf.org/viaf/7399869',   NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Giacomo Leopardi',      '46765049',  'https://viaf.org/viaf/46765049',  NULL, NULL, 'viaf', 'exact'),

-- Group B: authors with both VIAF and algorithmically-valid ISNI
-- ISNI check digits computed: sum = (sum + digit) * 2 for 15 digits, check = (12 - sum%11) % 11
('SEED_VIAF_Author_0000000000000028', '10000001', 'https://viaf.org/viaf/10000001',
 '0000000000000028', 'https://isni.org/isni/0000000000000028', 'viaf', 'exact'),
('SEED_VIAF_Author_0000000121436346', '10000002', 'https://viaf.org/viaf/10000002',
 '0000000121436346', 'https://isni.org/isni/0000000121436346', 'viaf', 'exact'),
('SEED_VIAF_Author_0000000000000109', '10000003', 'https://viaf.org/viaf/10000003',
 '0000000000000109', 'https://isni.org/isni/0000000000000109', 'viaf', 'exact'),

-- Group C: authors with VIAF only, no ISNI, various confidence levels
('SEED_VIAF_Author_Exact_1',   '20000001', 'https://viaf.org/viaf/20000001', NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Author_Exact_2',   '20000002', 'https://viaf.org/viaf/20000002', NULL, NULL, 'viaf', 'exact'),
('SEED_VIAF_Author_High_1',    '20000003', 'https://viaf.org/viaf/20000003', NULL, NULL, 'viaf', 'high'),
('SEED_VIAF_Author_High_2',    '20000004', 'https://viaf.org/viaf/20000004', NULL, NULL, 'viaf', 'high'),
('SEED_VIAF_Author_Medium_1',  '20000005', 'https://viaf.org/viaf/20000005', NULL, NULL, 'viaf', 'medium'),

-- Group D: authors without VIAF (for reconciliation "unmatched" tests)
('SEED_VIAF_Unmatched_Author_1',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Unmatched_Author_2',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Unmatched_Author_3',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Ambiguous_Author_A',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Ambiguous_Author_B',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Ambiguous_Author_C',  NULL, NULL, NULL, NULL, NULL, NULL),
('SEED_VIAF_Ambiguous_Author_D',  NULL, NULL, NULL, NULL, NULL, NULL);
