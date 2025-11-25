-- =====================================================
-- INDICI DI OTTIMIZZAZIONE AGGIUNTIVI - Pinakes
-- Eseguire dopo l'installazione per migliorare le performance
-- Nota: molti indici sono già presenti in schema.sql
-- =====================================================

-- =====================================================
-- TABELLA: libri
-- Già presenti: genere_id, sottogenere_id, posizione_id, editore_id,
--               stato, scaffale_mensola, titolo_sottotitolo, FULLTEXT
-- =====================================================

-- Indice per ordinamento cronologico (dashboard, ultimi libri)
ALTER TABLE libri ADD INDEX IF NOT EXISTS idx_created_at (created_at);

-- Indice per ricerca ISBN (isbn13 ha UNIQUE, isbn10 no)
ALTER TABLE libri ADD INDEX IF NOT EXISTS idx_isbn10 (isbn10);

-- Indice composto per suggerimenti collocazione
ALTER TABLE libri ADD INDEX IF NOT EXISTS idx_genere_scaffale (genere_id, scaffale_id);
ALTER TABLE libri ADD INDEX IF NOT EXISTS idx_sottogenere_scaffale (sottogenere_id, scaffale_id);

-- =====================================================
-- TABELLA: libri_autori (CRITICA - mancano indici composti)
-- Già presenti: libro_id, autore_id (singoli)
-- =====================================================

-- Indice composto per JOIN efficienti (MOLTO IMPORTANTE)
ALTER TABLE libri_autori ADD INDEX IF NOT EXISTS idx_libro_autore (libro_id, autore_id);
ALTER TABLE libri_autori ADD INDEX IF NOT EXISTS idx_autore_libro (autore_id, libro_id);

-- Indice per ordinamento autori
ALTER TABLE libri_autori ADD INDEX IF NOT EXISTS idx_ordine_credito (ordine_credito);

-- Indice per filtro ruolo autore
ALTER TABLE libri_autori ADD INDEX IF NOT EXISTS idx_ruolo (ruolo);

-- =====================================================
-- TABELLA: autori (manca indice su nome!)
-- =====================================================

-- Indice per ricerca autori (IMPORTANTE per SearchController)
ALTER TABLE autori ADD INDEX IF NOT EXISTS idx_nome (nome(100));

-- =====================================================
-- TABELLA: editori (manca indice su nome)
-- =====================================================

ALTER TABLE editori ADD INDEX IF NOT EXISTS idx_nome (nome(100));

-- =====================================================
-- TABELLA: prestiti
-- Già presenti: libro_id, utente_id, stato+data_scadenza, libro+stato, attivo+stato
-- =====================================================

-- Indice per query dashboard (COUNT con stato + attivo)
ALTER TABLE prestiti ADD INDEX IF NOT EXISTS idx_stato_attivo (stato, attivo);

-- Indice per data prestito (storico)
ALTER TABLE prestiti ADD INDEX IF NOT EXISTS idx_data_prestito (data_prestito);

-- =====================================================
-- TABELLA: utenti (mancano indici per ricerca nome)
-- Già presenti: email, codice_tessera, cod_fiscale
-- =====================================================

-- Indici per ricerca utenti (SearchController usa LIKE su questi campi)
ALTER TABLE utenti ADD INDEX IF NOT EXISTS idx_nome (nome(50));
ALTER TABLE utenti ADD INDEX IF NOT EXISTS idx_cognome (cognome(50));
ALTER TABLE utenti ADD INDEX IF NOT EXISTS idx_nome_cognome (nome(50), cognome(50));
ALTER TABLE utenti ADD INDEX IF NOT EXISTS idx_ruolo (ruolo);

-- =====================================================
-- TABELLA: generi (manca indice su nome)
-- Già presente: parent_id
-- =====================================================

ALTER TABLE generi ADD INDEX IF NOT EXISTS idx_nome (nome(50));

-- =====================================================
-- TABELLA: posizioni (aggiungere composito)
-- =====================================================

ALTER TABLE posizioni ADD INDEX IF NOT EXISTS idx_scaffale_mensola (scaffale_id, mensola_id);

-- =====================================================
-- TABELLA: copie
-- Già presenti: libro_id, stato
-- =====================================================

-- Indice per inventario
ALTER TABLE copie ADD INDEX IF NOT EXISTS idx_numero_inventario (numero_inventario);

-- =====================================================
-- TABELLA: prenotazioni (aggiungere indici mancanti)
-- =====================================================

ALTER TABLE prenotazioni ADD INDEX IF NOT EXISTS idx_libro_id (libro_id);
ALTER TABLE prenotazioni ADD INDEX IF NOT EXISTS idx_utente_id (utente_id);
ALTER TABLE prenotazioni ADD INDEX IF NOT EXISTS idx_stato (stato);

-- =====================================================
-- ANALISI E VERIFICA
-- =====================================================

-- Per verificare gli indici creati:
-- SHOW INDEX FROM libri;
-- SHOW INDEX FROM libri_autori;
-- SHOW INDEX FROM autori;

-- Per analizzare le tabelle dopo la creazione indici:
-- ANALYZE TABLE libri, libri_autori, autori, editori, prestiti, utenti, posizioni;

-- Per testare una query con EXPLAIN:
-- EXPLAIN SELECT l.*, GROUP_CONCAT(a.nome) FROM libri l
--         LEFT JOIN libri_autori la ON l.id = la.libro_id
--         LEFT JOIN autori a ON la.autore_id = a.id
--         GROUP BY l.id LIMIT 10;
