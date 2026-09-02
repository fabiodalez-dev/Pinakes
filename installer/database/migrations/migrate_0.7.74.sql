-- 0.7.74 — Backfill the activity timeline from existing circulation data.
--
-- The activity feed shipped in 0.7.73 records events at action time only:
-- every upgraded install started with an empty timeline even for books
-- currently on loan, which reads as a bug (real report: an overdue loan
-- with an empty "Cronologia modifiche"). This migration synthesizes one
-- loan.created event per existing loan (plus loan.returned for closed
-- ones) and one reservation.created per existing reservation, using the
-- historical timestamps and a NULL operator (rendered as "Sistema",
-- source "backfill"). Fully idempotent: every INSERT is guarded by a
-- NOT EXISTS on the (type, event, entity_id) triple, so re-runs and
-- installs that already logged real events are untouched.

INSERT INTO log_modifiche (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id, data_modifica)
SELECT 'libri', p.libro_id, 'inserimento', '{}',
  JSON_OBJECT('libro_id', p.libro_id, 'utente_id', p.utente_id,
    'data_prestito', p.data_prestito, 'data_scadenza', p.data_scadenza, 'stato', p.stato,
    '_activity', JSON_OBJECT('type','loan','event','loan.created','entity_id',p.id,
      'book_title', l.titolo, 'source','backfill')),
  NULL, COALESCE(p.created_at, CONCAT(p.data_prestito, ' 00:00:00'))
FROM prestiti p JOIN libri l ON l.id = p.libro_id
WHERE NOT EXISTS (
  SELECT 1 FROM log_modifiche lm WHERE lm.tabella = 'libri'
    AND JSON_EXTRACT(lm.dati_nuovi, '$._activity.entity_id') = p.id
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.type')) = 'loan'
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.event')) = 'loan.created');

INSERT INTO log_modifiche (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id, data_modifica)
SELECT 'libri', p.libro_id, 'aggiornamento', JSON_OBJECT('stato', 'in_corso'),
  JSON_OBJECT('libro_id', p.libro_id, 'utente_id', p.utente_id,
    'data_prestito', p.data_prestito, 'data_scadenza', p.data_scadenza,
    'data_restituzione', p.data_restituzione, 'stato', p.stato,
    '_activity', JSON_OBJECT('type','loan','event','loan.returned','entity_id',p.id,
      'book_title', l.titolo, 'source','backfill')),
  NULL, CONCAT(p.data_restituzione, ' 12:00:00')
FROM prestiti p JOIN libri l ON l.id = p.libro_id
WHERE p.data_restituzione IS NOT NULL
  AND NOT EXISTS (
  SELECT 1 FROM log_modifiche lm WHERE lm.tabella = 'libri'
    AND JSON_EXTRACT(lm.dati_nuovi, '$._activity.entity_id') = p.id
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.type')) = 'loan'
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.event')) = 'loan.returned');

INSERT INTO log_modifiche (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id, data_modifica)
SELECT 'libri', r.libro_id, 'inserimento', '{}',
  JSON_OBJECT('libro_id', r.libro_id, 'utente_id', r.utente_id,
    'data_prenotazione', r.data_prenotazione, 'stato', r.stato,
    '_activity', JSON_OBJECT('type','loan','event','reservation.created','entity_id',r.id,
      'book_title', l.titolo, 'source','backfill')),
  NULL, COALESCE(r.data_prenotazione, NOW())
FROM prenotazioni r JOIN libri l ON l.id = r.libro_id
WHERE NOT EXISTS (
  SELECT 1 FROM log_modifiche lm WHERE lm.tabella = 'libri'
    AND JSON_EXTRACT(lm.dati_nuovi, '$._activity.entity_id') = r.id
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.type')) = 'loan'
    AND JSON_UNQUOTE(JSON_EXTRACT(lm.dati_nuovi, '$._activity.event')) = 'reservation.created');
