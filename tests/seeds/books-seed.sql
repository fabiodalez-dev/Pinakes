-- Pinakes book seed — reusable, idempotent
-- Covers: 18 Aldebaran volumes (4 cycles), 15 general classic books (3 series + standalone)
-- Import via: mysql < tests/seeds/books-seed.sql
-- Prerequisites: fresh install complete (all tables exist, plugins installed)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── AUTHORS ───────────────────────────────────────────────────────────────
INSERT IGNORE INTO autori (nome) VALUES
  ('Leo Aldebaran'),
  ('Umberto Eco'),
  ('Primo Levi'),
  ('J.R.R. Tolkien'),
  ('Isaac Asimov'),
  ('J.K. Rowling'),
  ('George Orwell'),
  ('Luigi Pirandello'),
  ('Gabriel García Márquez'),
  ('Fyodor Dostoevsky'),
  ('Jonathan Franzen');

-- ─── PUBLISHERS ────────────────────────────────────────────────────────────
INSERT IGNORE INTO editori (nome) VALUES
  ('Bompiani'),
  ('Einaudi'),
  ('Mondadori'),
  ('Salani'),
  ('Feltrinelli');

-- ─── SERIES ────────────────────────────────────────────────────────────────
-- Aldebaran universe (4 cycles — flat, as imported by LibraryThing importer)
INSERT IGNORE INTO collane (nome, tipo) VALUES
  ('I Mondi di Aldebarano - Aldebarano', 'serie'),
  ('I Mondi di Aldebarano - Betelgeuse', 'serie'),
  ('I Mondi di Aldebarano - Antares', 'serie'),
  ('I Mondi di Aldebarano - Gli Orfani', 'serie');

-- Classic series
INSERT IGNORE INTO collane (nome, tipo) VALUES
  ('Il Signore degli Anelli', 'serie'),
  ('Ciclo della Fondazione', 'serie'),
  ('Harry Potter', 'serie');

-- ─── BOOKS — Aldebaran universe ────────────────────────────────────────────
INSERT IGNORE INTO libri
  (titolo, isbn13, lingua, collana, numero_serie, copie_totali, copie_disponibili)
VALUES
  ('Aldebaran - Volume 1: La Catastrofe', '9782205050608', 'francese', 'I Mondi di Aldebarano - Aldebarano', '1', 1, 1),
  ('Aldebaran - Volume 2: La Survival',   '9782205050790', 'francese', 'I Mondi di Aldebarano - Aldebarano', '2', 1, 1),
  ('Aldebaran - Volume 3: La Photo',      '9782205051056', 'francese', 'I Mondi di Aldebarano - Aldebarano', '3', 1, 1),
  ('Aldebaran - Volume 4: La Blonde',     '9782205051438', 'francese', 'I Mondi di Aldebarano - Aldebarano', '4', 1, 1),
  ('Aldebaran - Volume 5: La Créature',   '9782205052305', 'francese', 'I Mondi di Aldebarano - Aldebarano', '5', 1, 1),
  ('Betelgeuse - Volume 1: La Planète',   '9782205052367', 'francese', 'I Mondi di Aldebarano - Betelgeuse', '1', 1, 1),
  ('Betelgeuse - Volume 2: Les Survivants','9782205054348','francese', 'I Mondi di Aldebarano - Betelgeuse', '2', 1, 1),
  ('Betelgeuse - Volume 3: L''Expédition','9782205054928', 'francese', 'I Mondi di Aldebarano - Betelgeuse', '3', 1, 1),
  ('Betelgeuse - Volume 4: Les Cavernes', '9782205055598', 'francese', 'I Mondi di Aldebarano - Betelgeuse', '4', 1, 1),
  ('Betelgeuse - Volume 5: La Geste',     '9782205058017', 'francese', 'I Mondi di Aldebarano - Betelgeuse', '5', 1, 1),
  ('Antares - Volume 1: Épisode 1',       '9782205062274', 'francese', 'I Mondi di Aldebarano - Antares',    '1', 1, 1),
  ('Antares - Volume 2: Épisode 2',       '9782205065169', 'francese', 'I Mondi di Aldebarano - Antares',    '2', 1, 1),
  ('Antares - Volume 3: Épisode 3',       '9782205068207', 'francese', 'I Mondi di Aldebarano - Antares',    '3', 1, 1),
  ('Antares - Volume 4: Épisode 4',       '9782205070538', 'francese', 'I Mondi di Aldebarano - Antares',    '4', 1, 1),
  ('Antares - Volume 5: Épisode 5',       '9782205072952', 'francese', 'I Mondi di Aldebarano - Antares',    '5', 1, 1),
  ('Antares - Volume 6: Épisode 6',       '9782205075557', 'francese', 'I Mondi di Aldebarano - Antares',    '6', 1, 1),
  ('Gli Orfani - Volume 1: Saison 1',     '9782205078817', 'francese', 'I Mondi di Aldebarano - Gli Orfani', '1', 1, 1),
  ('Gli Orfani - Volume 2: Saison 2',     '9782205081343', 'francese', 'I Mondi di Aldebarano - Gli Orfani', '2', 1, 1);

-- ─── BOOKS — Italian and international classics ─────────────────────────────
INSERT IGNORE INTO libri
  (titolo, isbn13, anno_pubblicazione, lingua, editore_id, collana, numero_serie,
   parole_chiave, copie_totali, copie_disponibili)
VALUES
  ('Il nome della rosa',       '9788845292613', 1980, 'Italiano', (SELECT id FROM editori WHERE nome='Bompiani'),
   NULL, NULL, 'medioevo,detective,storia,crimine', 1, 1),
  ('Se questo è un uomo',      '9788806189754', 1947, 'Italiano', (SELECT id FROM editori WHERE nome='Einaudi'),
   NULL, NULL, 'shoah,deportazione,memoria,nazi', 1, 1),
  ('Il Signore degli Anelli - La Compagnia dell''Anello', '9788845295492', 1954, 'Italiano',
   (SELECT id FROM editori WHERE nome='Bompiani'), 'Il Signore degli Anelli', '1', 'fantasy,avventura,magia', 1, 1),
  ('Il Signore degli Anelli - Le Due Torri', '9788845295508', 1954, 'Italiano',
   (SELECT id FROM editori WHERE nome='Bompiani'), 'Il Signore degli Anelli', '2', 'fantasy,avventura,guerra', 1, 1),
  ('Il Signore degli Anelli - Il Ritorno del Re', '9788845295515', 1955, 'Italiano',
   (SELECT id FROM editori WHERE nome='Bompiani'), 'Il Signore degli Anelli', '3', 'fantasy,redenzione,guerra', 1, 1),
  ('Fondazione',               '9788804668336', 1951, 'Italiano', (SELECT id FROM editori WHERE nome='Mondadori'),
   'Ciclo della Fondazione', '1', 'fantascienza,futuro,impero', 1, 1),
  ('Fondazione e Impero',      '9788804668343', 1952, 'Italiano', (SELECT id FROM editori WHERE nome='Mondadori'),
   'Ciclo della Fondazione', '2', 'fantascienza,futuro,guerra', 1, 1),
  ('Seconda Fondazione',       '9788804668350', 1953, 'Italiano', (SELECT id FROM editori WHERE nome='Mondadori'),
   'Ciclo della Fondazione', '3', 'fantascienza,futuro,psichica', 1, 1),
  ('Harry Potter e la Pietra Filosofale', '9788867158379', 1997, 'Italiano',
   (SELECT id FROM editori WHERE nome='Salani'), 'Harry Potter', '1', 'magia,scuola,amicizia', 1, 1),
  ('Harry Potter e la Camera dei Segreti', '9788867158386', 1998, 'Italiano',
   (SELECT id FROM editori WHERE nome='Salani'), 'Harry Potter', '2', 'magia,mistero,avventura', 1, 1),
  ('1984',                     '9788804668640', 1949, 'Italiano', (SELECT id FROM editori WHERE nome='Mondadori'),
   NULL, NULL, 'politica,distopia,totalitarismo', 1, 1),
  ('Il fu Mattia Pascal',      '9788845296345', 1904, 'Italiano', (SELECT id FROM editori WHERE nome='Mondadori'),
   NULL, NULL, 'identità,libertà,modernità', 1, 1),
  ('Cent''anni di solitudine',  '9788845292453', 1967, 'Italiano', (SELECT id FROM editori WHERE nome='Feltrinelli'),
   NULL, NULL, 'colombia,magia,storia,famiglia', 1, 1),
  ('Delitto e castigo',        '9788845261350', 1866, 'Italiano', (SELECT id FROM editori WHERE nome='Feltrinelli'),
   NULL, NULL, 'Russia,crimine,redenzione,psicologia', 1, 1),
  ('Le correzioni',            '9788806163389', 2001, 'Italiano', (SELECT id FROM editori WHERE nome='Einaudi'),
   NULL, NULL, 'famiglia,america,demenza,contemporaneo', 1, 1);

-- ─── AUTHOR–BOOK LINKS ─────────────────────────────────────────────────────
-- Aldebaran (all 18 volumes → Leo Aldebaran)
INSERT IGNORE INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito)
SELECT l.id, a.id, 'principale', 1
FROM libri l
JOIN autori a ON a.nome = 'Leo Aldebaran'
WHERE l.collana LIKE 'I Mondi di Aldebarano%'
  AND l.deleted_at IS NULL;

-- Classics
INSERT IGNORE INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito)
SELECT l.id, a.id, 'principale', 1
FROM libri l
JOIN autori a ON (
  (l.isbn13 = '9788845292613' AND a.nome = 'Umberto Eco') OR
  (l.isbn13 = '9788806189754' AND a.nome = 'Primo Levi') OR
  (l.isbn13 IN ('9788845295492','9788845295508','9788845295515') AND a.nome = 'J.R.R. Tolkien') OR
  (l.isbn13 IN ('9788804668336','9788804668343','9788804668350') AND a.nome = 'Isaac Asimov') OR
  (l.isbn13 IN ('9788867158379','9788867158386') AND a.nome = 'J.K. Rowling') OR
  (l.isbn13 = '9788804668640' AND a.nome = 'George Orwell') OR
  (l.isbn13 = '9788845296345' AND a.nome = 'Luigi Pirandello') OR
  (l.isbn13 = '9788845292453' AND a.nome = 'Gabriel García Márquez') OR
  (l.isbn13 = '9788845261350' AND a.nome = 'Fyodor Dostoevsky') OR
  (l.isbn13 = '9788806163389' AND a.nome = 'Jonathan Franzen')
)
WHERE l.deleted_at IS NULL;

-- ─── SERIES–BOOK LINKS (libri_collane) ─────────────────────────────────────
INSERT IGNORE INTO libri_collane (libro_id, collana_id, numero_volume)
SELECT l.id, c.id, CAST(l.numero_serie AS UNSIGNED)
FROM libri l
JOIN collane c ON c.nome = l.collana
WHERE l.collana IS NOT NULL AND l.deleted_at IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
