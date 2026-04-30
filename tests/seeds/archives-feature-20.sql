-- Archives focused reusable seed for PR #105.
-- Idempotent and persistent: it upserts records and never deletes archival data.
-- Run against a database where installer/database/migrations/migrate_0.5.9.sql
-- or ArchivesPlugin::ensureSchema() has already created the archives tables.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @archives_seed_institution := _utf8mb4'PINAKES' COLLATE utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE IF NOT EXISTS _archives_feature_seed (
    reference_code       VARCHAR(64) PRIMARY KEY,
    parent_ref           VARCHAR(64) NULL,
    level                VARCHAR(16) NOT NULL,
    formal_title         VARCHAR(500) NULL,
    constructed_title    VARCHAR(500) NOT NULL,
    date_start           SMALLINT NULL,
    date_end             SMALLINT NULL,
    extent               VARCHAR(500) NULL,
    scope_content        TEXT NULL,
    accruals             VARCHAR(16) NULL,
    language_codes       VARCHAR(64) NULL,
    material_status      VARCHAR(32) NOT NULL,
    registration_date    DATE NULL,
    specific_material    VARCHAR(32) NOT NULL,
    dimensions           VARCHAR(100) NULL,
    color_mode           VARCHAR(16) NULL,
    photographer         VARCHAR(255) NULL,
    publisher            VARCHAR(255) NULL,
    collection_name      VARCHAR(255) NULL,
    local_classification VARCHAR(64) NULL,
    cover_image_path     VARCHAR(500) NULL,
    document_path        VARCHAR(500) NULL,
    document_mime        VARCHAR(100) NULL,
    document_filename    VARCHAR(255) NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE _archives_feature_seed;

-- CASE E2E_ARCHIVE_001 level=fonds material=text color=NULL status=completed parent=NONE
-- CASE E2E_ARCHIVE_002 level=series material=photograph color=bw status=cataloguing parent=E2E_ARCHIVE_001
-- CASE E2E_ARCHIVE_003 level=series material=poster color=color status=completed parent=E2E_ARCHIVE_001
-- CASE E2E_ARCHIVE_004 level=series material=postcard color=mixed status=unclassified parent=E2E_ARCHIVE_001
-- CASE E2E_ARCHIVE_005 level=file material=drawing color=NULL status=cataloguing parent=E2E_ARCHIVE_002
-- CASE E2E_ARCHIVE_006 level=file material=audio color=bw status=completed parent=E2E_ARCHIVE_002
-- CASE E2E_ARCHIVE_007 level=file material=video color=color status=unclassified parent=E2E_ARCHIVE_002
-- CASE E2E_ARCHIVE_008 level=file material=other color=mixed status=cataloguing parent=E2E_ARCHIVE_002
-- CASE E2E_ARCHIVE_009 level=file material=map color=NULL status=completed parent=E2E_ARCHIVE_003
-- CASE E2E_ARCHIVE_010 level=file material=picture color=bw status=cataloguing parent=E2E_ARCHIVE_003
-- CASE E2E_ARCHIVE_011 level=file material=object color=color status=completed parent=E2E_ARCHIVE_003
-- CASE E2E_ARCHIVE_012 level=file material=film color=mixed status=unclassified parent=E2E_ARCHIVE_003
-- CASE E2E_ARCHIVE_013 level=item material=microform color=NULL status=completed parent=E2E_ARCHIVE_009
-- CASE E2E_ARCHIVE_014 level=item material=electronic color=bw status=cataloguing parent=E2E_ARCHIVE_009
-- CASE E2E_ARCHIVE_015 level=item material=mixed color=color status=completed parent=E2E_ARCHIVE_010
-- CASE E2E_ARCHIVE_016 level=item material=text color=mixed status=unclassified parent=E2E_ARCHIVE_010
-- CASE E2E_ARCHIVE_017 level=item material=photograph color=NULL status=completed parent=E2E_ARCHIVE_011
-- CASE E2E_ARCHIVE_018 level=file material=poster color=bw status=cataloguing parent=E2E_ARCHIVE_004
-- CASE E2E_ARCHIVE_019 level=series material=postcard color=color status=completed parent=E2E_ARCHIVE_001
-- CASE E2E_ARCHIVE_020 level=fonds material=drawing color=mixed status=unclassified parent=NONE
INSERT INTO _archives_feature_seed
    (reference_code, parent_ref, level, formal_title, constructed_title,
     date_start, date_end, extent, scope_content, accruals, language_codes,
     material_status, registration_date, specific_material, dimensions,
     color_mode, photographer, publisher, collection_name, local_classification,
     cover_image_path, document_path, document_mime, document_filename)
VALUES
    ('E2E_ARCHIVE_001', NULL, 'fonds', 'Fondo Pinakes seed', 'Archivio Pinakes - seed ISAD completo', 1898, 1978, '32 boxes, 4 series', 'Fondo di test per gerarchie ISAD(G), ricerca pubblica e SRU.', 'ongoing', 'ita;eng', 'completed', '2026-04-22', 'text', '32 boxes', NULL, NULL, 'Pinakes Test Archive', 'Seed Collection A', 'SEED-FONDO-001', NULL, '/uploads/archives/e2e/fondo-inventory.pdf', 'application/pdf', 'fondo-inventory.pdf'),
    ('E2E_ARCHIVE_002', 'E2E_ARCHIVE_001', 'series', 'Serie fotografie', 'Serie fotografica del fondo seed', 1901, 1939, '180 photographs', 'Serie per verificare materiali fotografici, dimensioni e bianco/nero.', 'completed', 'ita', 'cataloguing', '2026-04-22', 'photograph', '18x24 cm', 'bw', 'Studio Pinakes', NULL, 'Seed Collection A', 'SEED-SER-002', '/uploads/archives/e2e/photo-cover.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_003', 'E2E_ARCHIVE_001', 'series', 'Manifesti politici', 'Serie manifesti e affissioni', 1919, 1948, '95 posters', 'Serie per verificare poster, colore e metadata editoriali.', 'irregular', 'ita', 'completed', '2026-04-22', 'poster', '70x100 cm', 'color', NULL, 'Tipografia Sociale', 'Seed Collection A', 'SEED-SER-003', '/uploads/archives/e2e/poster-cover.jpg', '/uploads/archives/e2e/poster-register.pdf', 'application/pdf', 'poster-register.pdf'),
    ('E2E_ARCHIVE_004', 'E2E_ARCHIVE_001', 'series', 'Cartoline e corrispondenza', 'Serie cartoline illustrate', 1905, 1960, '14 folders', 'Serie per verificare cartoline, colore misto e link authority.', 'none', 'ita;fra', 'unclassified', '2026-04-22', 'postcard', '10x15 cm', 'mixed', NULL, 'Edizioni Test', 'Seed Collection B', 'SEED-SER-004', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_005', 'E2E_ARCHIVE_002', 'file', 'Disegni preparatori', 'Fascicolo disegni e schizzi', 1924, 1928, '2 folders', 'Fascicolo per il materiale drawing e controllo parent series.', 'completed', 'ita', 'cataloguing', '2026-04-22', 'drawing', '30x42 cm', NULL, 'Archivista Demo', NULL, 'Seed Collection B', 'SEED-FILE-005', '/uploads/archives/e2e/drawing-cover.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_006', 'E2E_ARCHIVE_002', 'file', 'Registrazioni audio', 'Fascicolo nastri audio', 1958, 1962, '12 reels', 'Fascicolo audio per verificare materiali non testuali.', 'completed', 'ita', 'completed', '2026-04-22', 'audio', '1/4 inch tape', 'bw', NULL, 'Archivio Sonoro Demo', 'Seed Collection C', 'SEED-FILE-006', NULL, '/uploads/archives/e2e/audio-index.pdf', 'application/pdf', 'audio-index.pdf'),
    ('E2E_ARCHIVE_007', 'E2E_ARCHIVE_002', 'file', 'Riprese video', 'Fascicolo video documentari', 1971, 1978, '6 tapes', 'Fascicolo video per verificare schema e SRU su video.', 'irregular', 'ita', 'unclassified', '2026-04-22', 'video', 'U-matic', 'color', NULL, 'Video Test Lab', 'Seed Collection C', 'SEED-FILE-007', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_008', 'E2E_ARCHIVE_002', 'file', 'Materiali miscellanei', 'Fascicolo oggetti non classificati', 1930, 1935, '1 box', 'Fascicolo other per coprire il fallback dei materiali.', 'none', 'ita', 'cataloguing', '2026-04-22', 'other', NULL, 'mixed', NULL, NULL, 'Seed Collection C', 'SEED-FILE-008', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_009', 'E2E_ARCHIVE_003', 'file', 'Mappe cittadine', 'Fascicolo mappe e planimetrie', 1908, 1914, '7 maps', 'Fascicolo cartografico per materiale map e figli item.', 'completed', 'ita', 'completed', '2026-04-22', 'map', '50x70 cm', NULL, NULL, 'Ufficio Tecnico Demo', 'Seed Collection D', 'SEED-FILE-009', NULL, '/uploads/archives/e2e/maps.pdf', 'application/pdf', 'maps.pdf'),
    ('E2E_ARCHIVE_010', 'E2E_ARCHIVE_003', 'file', 'Stampe illustrate', 'Fascicolo stampe e immagini', 1910, 1922, '34 prints', 'Fascicolo picture per materiale iconografico e figli item.', 'ongoing', 'ita', 'cataloguing', '2026-04-22', 'picture', '24x30 cm', 'bw', 'Fotografo Demo', 'Editore Demo', 'Seed Collection D', 'SEED-FILE-010', '/uploads/archives/e2e/picture-cover.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_011', 'E2E_ARCHIVE_003', 'file', 'Oggetti tridimensionali', 'Fascicolo realia e oggetti', 1920, 1955, '9 objects', 'Fascicolo object per verificare realia e asset opzionali.', 'irregular', 'ita', 'completed', '2026-04-22', 'object', 'various', 'color', NULL, NULL, 'Seed Collection D', 'SEED-FILE-011', '/uploads/archives/e2e/object-cover.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_012', 'E2E_ARCHIVE_003', 'file', 'Pellicole cinematografiche', 'Fascicolo film e bobine', 1936, 1942, '4 reels', 'Fascicolo film per verificare estensione ENUM phase 5.', 'none', 'ita', 'unclassified', '2026-04-22', 'film', '16mm', 'mixed', NULL, 'Cine Demo', 'Seed Collection D', 'SEED-FILE-012', NULL, '/uploads/archives/e2e/film-notes.pdf', 'application/pdf', 'film-notes.pdf'),
    ('E2E_ARCHIVE_013', 'E2E_ARCHIVE_009', 'item', 'Microfilm inventario', 'Item microfilm inventario mappe', 1965, 1965, '1 reel', 'Item microform per controllo foglia della gerarchia.', 'completed', 'ita', 'completed', '2026-04-22', 'microform', '35mm', NULL, NULL, NULL, 'Seed Collection E', 'SEED-ITEM-013', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_014', 'E2E_ARCHIVE_009', 'item', 'Floppy disk progetto', 'Item elettronico born-digital', 1988, 1988, '1 disk', 'Item electronic per risorse digitali e document_mime.', 'completed', 'ita', 'cataloguing', '2026-04-22', 'electronic', '3.5 inch', 'bw', NULL, NULL, 'Seed Collection E', 'SEED-ITEM-014', NULL, '/uploads/archives/e2e/floppy-dump.zip', 'application/zip', 'floppy-dump.zip'),
    ('E2E_ARCHIVE_015', 'E2E_ARCHIVE_010', 'item', 'Dossier misto stampa', 'Item mixed materiali compositi', 1946, 1947, '1 folder', 'Item mixed per materiali composti e ricerca testo.', 'irregular', 'ita;eng', 'completed', '2026-04-22', 'mixed', 'folder', 'color', NULL, NULL, 'Seed Collection E', 'SEED-ITEM-015', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_016', 'E2E_ARCHIVE_010', 'item', 'Lettera trascritta', 'Item testuale con colore misto', 1911, 1911, '4 pages', 'Item text per verificare ripetizione materiale base.', 'none', 'ita', 'unclassified', '2026-04-22', 'text', 'A4', 'mixed', NULL, NULL, 'Seed Collection E', 'SEED-ITEM-016', NULL, '/uploads/archives/e2e/letter.pdf', 'application/pdf', 'letter.pdf'),
    ('E2E_ARCHIVE_017', 'E2E_ARCHIVE_011', 'item', 'Ritratto singolo', 'Item fotografia con cover', 1929, 1929, '1 print', 'Item photograph per asset cover e parent object.', 'completed', 'ita', 'completed', '2026-04-22', 'photograph', '13x18 cm', NULL, 'Studio Demo', NULL, 'Seed Collection F', 'SEED-ITEM-017', '/uploads/archives/e2e/portrait.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_018', 'E2E_ARCHIVE_004', 'file', 'Manifesti minori', 'Fascicolo poster bianco e nero', 1932, 1934, '11 posters', 'Fascicolo poster ripetuto per testare colori diversi.', 'completed', 'ita', 'cataloguing', '2026-04-22', 'poster', '50x70 cm', 'bw', NULL, 'Tipografia Demo', 'Seed Collection F', 'SEED-FILE-018', NULL, NULL, NULL, NULL),
    ('E2E_ARCHIVE_019', 'E2E_ARCHIVE_001', 'series', 'Cartoline a colori', 'Serie cartoline a colori', 1950, 1968, '22 folders', 'Serie postcard ripetuta con status completato.', 'ongoing', 'ita', 'completed', '2026-04-22', 'postcard', '10x15 cm', 'color', NULL, 'Cartoleria Demo', 'Seed Collection F', 'SEED-SER-019', '/uploads/archives/e2e/postcard-cover.jpg', NULL, NULL, NULL),
    ('E2E_ARCHIVE_020', NULL, 'fonds', 'Fondo disegni separato', 'Archivio disegni seed autonomo', 1870, 1910, '6 boxes', 'Secondo fonds per verificare piu radici pubbliche.', 'irregular', 'ita', 'unclassified', '2026-04-22', 'drawing', 'various', 'mixed', 'Autore Demo', NULL, 'Seed Collection G', 'SEED-FONDO-020', NULL, NULL, NULL, NULL);

INSERT INTO archival_units
    (parent_id, reference_code, institution_code, level, formal_title,
     constructed_title, date_start, date_end, extent, scope_content,
     accruals, language_codes, material_status, registration_date,
     specific_material, dimensions, color_mode, photographer, publisher,
     collection_name, local_classification, cover_image_path, document_path,
     document_mime, document_filename, deleted_at)
SELECT
    NULL, s.reference_code, @archives_seed_institution, s.level, s.formal_title,
    s.constructed_title, s.date_start, s.date_end, s.extent, s.scope_content,
    s.accruals, s.language_codes, s.material_status, s.registration_date,
    s.specific_material, s.dimensions, s.color_mode, s.photographer, s.publisher,
    s.collection_name, s.local_classification, s.cover_image_path, s.document_path,
    s.document_mime, s.document_filename, NULL
FROM _archives_feature_seed s
ON DUPLICATE KEY UPDATE
    level = VALUES(level),
    formal_title = VALUES(formal_title),
    constructed_title = VALUES(constructed_title),
    date_start = VALUES(date_start),
    date_end = VALUES(date_end),
    extent = VALUES(extent),
    scope_content = VALUES(scope_content),
    accruals = VALUES(accruals),
    language_codes = VALUES(language_codes),
    material_status = VALUES(material_status),
    registration_date = VALUES(registration_date),
    specific_material = VALUES(specific_material),
    dimensions = VALUES(dimensions),
    color_mode = VALUES(color_mode),
    photographer = VALUES(photographer),
    publisher = VALUES(publisher),
    collection_name = VALUES(collection_name),
    local_classification = VALUES(local_classification),
    cover_image_path = VALUES(cover_image_path),
    document_path = VALUES(document_path),
    document_mime = VALUES(document_mime),
    document_filename = VALUES(document_filename),
    deleted_at = NULL,
    updated_at = CURRENT_TIMESTAMP;

UPDATE archival_units child
JOIN _archives_feature_seed s
  ON s.reference_code = child.reference_code
 AND child.institution_code = @archives_seed_institution
LEFT JOIN archival_units parent
  ON parent.reference_code = s.parent_ref
 AND parent.institution_code = @archives_seed_institution
SET child.parent_id = parent.id
WHERE s.parent_ref IS NOT NULL;

UPDATE archival_units child
JOIN _archives_feature_seed s
  ON s.reference_code = child.reference_code
 AND child.institution_code = @archives_seed_institution
SET child.parent_id = NULL
WHERE s.parent_ref IS NULL;

CREATE TEMPORARY TABLE IF NOT EXISTS _archives_feature_authority (
    type                VARCHAR(16) NOT NULL,
    authorised_form     VARCHAR(500) NOT NULL,
    identifiers         VARCHAR(500) NULL,
    dates_of_existence  VARCHAR(255) NULL,
    history             TEXT NULL,
    functions_text      TEXT NULL,
    gender              VARCHAR(16) NULL,
    external_refs       TEXT NULL,
    PRIMARY KEY (type, authorised_form)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE _archives_feature_authority;

INSERT INTO _archives_feature_authority
    (type, authorised_form, identifiers, dates_of_existence, history, functions_text, gender, external_refs)
VALUES
    ('person', 'E2E Authority Ada Demo', 'VIAF:E2E-ADA', '1875-1942', 'Persona seed per collegamenti creator e subject.', 'Produzione fotografica; ordinamento fondi', 'female', 'https://example.test/authority/ada-demo'),
    ('person', 'E2E Authority Bruno Demo', 'VIAF:E2E-BRUNO', '1901-1977', 'Persona seed per destinatari e associati.', 'Corrispondenza; schedatura', 'male', 'https://example.test/authority/bruno-demo'),
    ('corporate', 'E2E Authority Archivio Demo', 'ISNI:E2E-ARCHIVIO', '1909-oggi', 'Ente seed per custodia e provenienza.', 'Conservazione; digitalizzazione; accesso pubblico', NULL, 'https://example.test/authority/archivio-demo'),
    ('corporate', 'E2E Authority Tipografia Demo', 'ISNI:E2E-TIPOGRAFIA', '1890-1965', 'Tipografia seed per manifesti e cartoline.', 'Stampa; editoria', NULL, 'https://example.test/authority/tipografia-demo'),
    ('family', 'E2E Authority Famiglia Demo', 'LOCAL:E2E-FAM', '1880-1950', 'Famiglia seed per relazioni archivistiche.', 'Donazione; produzione documentaria', NULL, 'https://example.test/authority/famiglia-demo');

INSERT INTO authority_records
    (type, authorised_form, identifiers, dates_of_existence, history, `functions`, gender, external_refs, deleted_at)
SELECT
    a.type, a.authorised_form, a.identifiers, a.dates_of_existence, a.history,
    a.functions_text, a.gender, a.external_refs, NULL
FROM _archives_feature_authority a
WHERE NOT EXISTS (
    SELECT 1
    FROM authority_records ar
    WHERE ar.type = a.type
      AND ar.authorised_form = a.authorised_form
);

UPDATE authority_records ar
JOIN _archives_feature_authority a
  ON a.type = ar.type
 AND a.authorised_form = ar.authorised_form
SET ar.identifiers = a.identifiers,
    ar.dates_of_existence = a.dates_of_existence,
    ar.history = a.history,
    ar.`functions` = a.functions_text,
    ar.gender = a.gender,
    ar.external_refs = a.external_refs,
    ar.deleted_at = NULL,
    ar.updated_at = CURRENT_TIMESTAMP;

CREATE TEMPORARY TABLE IF NOT EXISTS _archives_feature_links (
    unit_ref        VARCHAR(64) NOT NULL,
    authority_type  VARCHAR(16) NOT NULL,
    authority_name  VARCHAR(500) NOT NULL,
    role            VARCHAR(16) NOT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE _archives_feature_links;

INSERT INTO _archives_feature_links (unit_ref, authority_type, authority_name, role)
VALUES
    ('E2E_ARCHIVE_001', 'corporate', 'E2E Authority Archivio Demo', 'custodian'),
    ('E2E_ARCHIVE_001', 'family', 'E2E Authority Famiglia Demo', 'creator'),
    ('E2E_ARCHIVE_002', 'person', 'E2E Authority Ada Demo', 'creator'),
    ('E2E_ARCHIVE_003', 'corporate', 'E2E Authority Tipografia Demo', 'associated'),
    ('E2E_ARCHIVE_004', 'person', 'E2E Authority Bruno Demo', 'recipient'),
    ('E2E_ARCHIVE_009', 'corporate', 'E2E Authority Archivio Demo', 'subject'),
    ('E2E_ARCHIVE_010', 'person', 'E2E Authority Ada Demo', 'subject'),
    ('E2E_ARCHIVE_014', 'corporate', 'E2E Authority Archivio Demo', 'associated'),
    ('E2E_ARCHIVE_017', 'person', 'E2E Authority Ada Demo', 'creator'),
    ('E2E_ARCHIVE_020', 'family', 'E2E Authority Famiglia Demo', 'subject');

INSERT IGNORE INTO archival_unit_authority (archival_unit_id, authority_id, role)
SELECT au.id, ar.id, l.role
FROM _archives_feature_links l
JOIN archival_units au
  ON au.reference_code = l.unit_ref
 AND au.institution_code = @archives_seed_institution
JOIN authority_records ar
  ON ar.type = l.authority_type
 AND ar.authorised_form = l.authority_name;
