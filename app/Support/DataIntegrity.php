<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use Exception;

class DataIntegrity {
    private mysqli $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
    }

    /**
     * Ricalcola le copie disponibili per tutti i libri
     */
    public function recalculateAllBookAvailability(): array {
        $results = ['updated' => 0, 'errors' => []];

        try {
            $this->db->begin_transaction();

            // Aggiorna stato copie basandosi sui prestiti attivi (include 'prenotato' per prestiti futuri)
            $stmt = $this->db->prepare("
                UPDATE copie c
                LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                SET c.stato = CASE
                    WHEN p.id IS NOT NULL THEN 'prestato'
                    ELSE 'disponibile'
                END
                WHERE c.stato IN ('disponibile', 'prestato')
            ");
            $stmt->execute();
            $stmt->close();

            // Ricalcola copie_disponibili e stato per tutti i libri dalla tabella copie
            // Include 'prenotato' per considerare anche prestiti futuri già assegnati
            // Sottrae le prenotazioni attive che coprono la data odierna (slot-level)
            $stmt = $this->db->prepare("
                UPDATE libri l
                SET copie_disponibili = GREATEST(
                    (
                        SELECT COUNT(*)
                        FROM copie c
                        LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                        WHERE c.libro_id = l.id
                        AND c.stato = 'disponibile'
                        AND p.id IS NULL
                    ) - (
                        SELECT COUNT(*)
                        FROM prenotazioni pr
                        WHERE pr.libro_id = l.id
                        AND pr.stato = 'attiva'
                        AND pr.data_inizio_richiesta IS NOT NULL
                        AND pr.data_inizio_richiesta <= CURDATE()
                        AND COALESCE(pr.data_fine_richiesta, DATE(pr.data_scadenza_prenotazione), pr.data_inizio_richiesta) >= CURDATE()
                    ),
                    0
                ),
                copie_totali = (
                    SELECT COUNT(*)
                    FROM copie c
                    WHERE c.libro_id = l.id
                ),
                stato = CASE
                    WHEN GREATEST(
                        (
                            SELECT COUNT(*)
                            FROM copie c
                            LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                            WHERE c.libro_id = l.id
                            AND c.stato = 'disponibile'
                            AND p.id IS NULL
                        ) - (
                            SELECT COUNT(*)
                            FROM prenotazioni pr
                            WHERE pr.libro_id = l.id
                            AND pr.stato = 'attiva'
                            AND pr.data_inizio_richiesta IS NOT NULL
                            AND pr.data_inizio_richiesta <= CURDATE()
                            AND COALESCE(pr.data_fine_richiesta, DATE(pr.data_scadenza_prenotazione), pr.data_inizio_richiesta) >= CURDATE()
                        ),
                        0
                    ) > 0 THEN 'disponibile'
                    ELSE 'prestato'
                END
            ");
            $stmt->execute();
            $results['updated'] = $this->db->affected_rows;
            $stmt->close();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            $results['errors'][] = "Errore ricalcolo disponibilità: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Ricalcola le copie disponibili per un singolo libro
     */
    public function recalculateBookAvailability(int $bookId): bool {
        try {
            // Aggiorna stato copie del libro basandosi sui prestiti attivi (include 'prenotato')
            $stmt = $this->db->prepare("
                UPDATE copie c
                LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                SET c.stato = CASE
                    WHEN p.id IS NOT NULL THEN 'prestato'
                    ELSE 'disponibile'
                END
                WHERE c.libro_id = ?
                AND c.stato IN ('disponibile', 'prestato')
            ");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();

            // Aggiorna copie_disponibili e stato del libro dalla tabella copie
            // Include 'prenotato' per prestiti futuri con copia e sottrae prenotazioni attive su oggi
            $stmt = $this->db->prepare("
                UPDATE libri l
                SET copie_disponibili = GREATEST(
                    (
                        SELECT COUNT(*)
                        FROM copie c
                        LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                        WHERE c.libro_id = ?
                        AND c.stato = 'disponibile'
                        AND p.id IS NULL
                    ) - (
                        SELECT COUNT(*)
                        FROM prenotazioni pr
                        WHERE pr.libro_id = ?
                        AND pr.stato = 'attiva'
                        AND pr.data_inizio_richiesta IS NOT NULL
                        AND pr.data_inizio_richiesta <= CURDATE()
                        AND COALESCE(pr.data_fine_richiesta, DATE(pr.data_scadenza_prenotazione), pr.data_inizio_richiesta) >= CURDATE()
                    ),
                    0
                ),
                copie_totali = (
                    SELECT COUNT(*)
                    FROM copie c
                    WHERE c.libro_id = ?
                ),
                stato = CASE
                    WHEN GREATEST(
                        (
                            SELECT COUNT(*)
                            FROM copie c
                            LEFT JOIN prestiti p ON c.id = p.copia_id AND p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'prenotato')
                            WHERE c.libro_id = ?
                            AND c.stato = 'disponibile'
                            AND p.id IS NULL
                        ) - (
                            SELECT COUNT(*)
                            FROM prenotazioni pr
                            WHERE pr.libro_id = ?
                            AND pr.stato = 'attiva'
                            AND pr.data_inizio_richiesta IS NOT NULL
                            AND pr.data_inizio_richiesta <= CURDATE()
                            AND COALESCE(pr.data_fine_richiesta, DATE(pr.data_scadenza_prenotazione), pr.data_inizio_richiesta) >= CURDATE()
                        ),
                        0
                    ) > 0 THEN 'disponibile'
                    ELSE 'prestato'
                END
                WHERE id = ?
            ");
            $stmt->bind_param('iiiiiii', $bookId, $bookId, $bookId, $bookId, $bookId, $bookId, $bookId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (Exception $e) {
            error_log("Errore ricalcolo disponibilità libro {$bookId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica la coerenza dei dati nel database
     */
    public function verifyDataConsistency(): array {
        $issues = [];

        // 1. Verifica libri con copie disponibili negative
        $stmt = $this->db->prepare("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili < 0");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'negative_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha copie disponibili negative: {$row['copie_disponibili']}"
                ];
            }
        }
        $stmt->close();

        // 2. Verifica libri con più copie disponibili che totali
        $stmt = $this->db->prepare("SELECT id, titolo, copie_totali, copie_disponibili FROM libri WHERE copie_disponibili > copie_totali");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'excess_copies',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha più copie disponibili ({$row['copie_disponibili']}) che totali ({$row['copie_totali']})"
                ];
            }
        }
        $stmt->close();

        // 3. Verifica prestiti orfani (senza libro o utente)
        $stmt = $this->db->prepare("
            SELECT p.id, p.libro_id, p.utente_id
            FROM prestiti p
            LEFT JOIN libri l ON p.libro_id = l.id
            LEFT JOIN utenti u ON p.utente_id = u.id
            WHERE l.id IS NULL OR u.id IS NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'orphan_loan',
                    'message' => "Prestito ID {$row['id']} riferisce libro/utente inesistente (libro: {$row['libro_id']}, utente: {$row['utente_id']})"
                ];
            }
        }
        $stmt->close();

        // 4. Verifica prestiti attivi senza data scadenza
        $stmt = $this->db->prepare("
            SELECT id, libro_id, utente_id, stato
            FROM prestiti
            WHERE stato IN ('in_corso', 'in_ritardo')
            AND attivo = 1
            AND (data_scadenza IS NULL OR DATE(data_scadenza) IS NULL OR data_scadenza < '1900-01-01')
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'missing_due_date',
                    'message' => "Prestito ID {$row['id']} attivo senza data scadenza"
                ];
            }
        }
        $stmt->close();

        // 5. Verifica incoerenze stato libro vs copie disponibili
        $stmt = $this->db->prepare("
            SELECT id, titolo, stato, copie_disponibili
            FROM libri
            WHERE (stato = 'disponibile' AND copie_disponibili = 0)
               OR (stato = 'prestato' AND copie_disponibili > 0)
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'status_mismatch',
                    'message' => "Libro '{$row['titolo']}' (ID: {$row['id']}) ha stato '{$row['stato']}' ma copie disponibili: {$row['copie_disponibili']}"
                ];
            }
        }
        $stmt->close();

        // 6. Verifica prenotazioni che si sovrappongono a prestiti attivi dello stesso libro
        $stmt = $this->db->prepare("
            SELECT pr.id AS prenotazione_id, pr.libro_id, pr.data_inizio_richiesta, pr.data_fine_richiesta, pr.data_scadenza_prenotazione,
                   p.id AS prestito_id, p.data_prestito, p.data_scadenza, p.data_restituzione, p.stato
            FROM prenotazioni pr
            JOIN prestiti p ON pr.libro_id = p.libro_id
            WHERE pr.stato = 'attiva'
              AND p.stato IN ('in_corso','in_ritardo','pendente')
              AND p.attivo = 1
              AND (
                    (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_inizio_richiesta <= p.data_scadenza AND pr.data_fine_richiesta >= p.data_prestito)
                 OR (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NULL AND pr.data_inizio_richiesta <= COALESCE(p.data_scadenza, p.data_restituzione, p.data_prestito))
                 OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_fine_richiesta >= p.data_prestito)
                 OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NULL)
              )
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'overlap_reservation_loan',
                    'message' => "Prenotazione ID {$row['prenotazione_id']} si sovrappone al prestito ID {$row['prestito_id']} per il libro {$row['libro_id']}"
                ];
            }
        }
        $stmt->close();

        // 7. Verifica prenotazioni che si sovrappongono tra loro per lo stesso libro
        $stmt = $this->db->prepare("
            SELECT r1.id AS pren1, r2.id AS pren2, r1.libro_id, r1.data_inizio_richiesta, r1.data_fine_richiesta, r2.data_inizio_richiesta AS data_inizio_richiesta2, r2.data_fine_richiesta AS data_fine_richiesta2
            FROM prenotazioni r1
            JOIN prenotazioni r2 ON r1.libro_id = r2.libro_id AND r1.id < r2.id
            WHERE r1.stato = 'attiva' AND r2.stato = 'attiva'
              AND (
                  (r1.data_inizio_richiesta IS NOT NULL AND r1.data_fine_richiesta IS NOT NULL AND r2.data_inizio_richiesta IS NOT NULL AND r2.data_fine_richiesta IS NOT NULL
                   AND r1.data_inizio_richiesta <= r2.data_fine_richiesta AND r1.data_fine_richiesta >= r2.data_inizio_richiesta)
              )
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $issues[] = [
                    'type' => 'overlap_reservation_reservation',
                    'message' => "Prenotazioni ID {$row['pren1']} e {$row['pren2']} si sovrappongono per il libro {$row['libro_id']}"
                ];
            }
        }
        $stmt->close();

        // 8. Verifica configurazione APP_CANONICAL_URL nel .env
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        $currentUrl = $this->detectCurrentCanonicalUrl();

        if ($canonicalUrl === false) {
            $issues[] = [
                'type' => 'missing_canonical_url',
                'message' => "APP_CANONICAL_URL non configurato nel file .env. Link nelle email potrebbero non funzionare correttamente. Valore suggerito: {$currentUrl}",
                'severity' => 'warning',
                'fix_suggestion' => "Aggiungi al file .env: APP_CANONICAL_URL={$currentUrl}"
            ];
        } else {
            $canonicalUrl = trim((string)$canonicalUrl);
            if ($canonicalUrl === '') {
                $issues[] = [
                    'type' => 'empty_canonical_url',
                    'message' => "APP_CANONICAL_URL configurato ma vuoto nel file .env. Link nelle email useranno fallback a HTTP_HOST. Valore suggerito: {$currentUrl}",
                    'severity' => 'warning',
                    'fix_suggestion' => "Imposta nel file .env: APP_CANONICAL_URL={$currentUrl}"
                ];
            } elseif (!filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                $issues[] = [
                    'type' => 'invalid_canonical_url',
                    'message' => "APP_CANONICAL_URL configurato con valore non valido: '{$canonicalUrl}'. Link nelle email potrebbero non funzionare. Valore suggerito: {$currentUrl}",
                    'severity' => 'error',
                    'fix_suggestion' => "Correggi nel file .env: APP_CANONICAL_URL={$currentUrl}"
                ];
            }
        }

        return $issues;
    }

    /**
     * Rileva l'URL canonico corrente dal server
     */
    private function detectCurrentCanonicalUrl(): string {
        $scheme = 'http';

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $forwardedProto = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
            $scheme = strtolower($forwardedProto) === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
        } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $scheme = 'https';
        }

        $host = 'localhost';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0];
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $host = (string)$_SERVER['HTTP_HOST'];
        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $host = (string)$_SERVER['SERVER_NAME'];
        }

        // Remove port from host if it's already there
        $port = null;
        if (str_contains($host, ':')) {
            [$hostOnly, $portPart] = explode(':', $host, 2);
            $host = $hostOnly;
            $port = is_numeric($portPart) ? (int)$portPart : null;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (int)$_SERVER['HTTP_X_FORWARDED_PORT'];
        } elseif (isset($_SERVER['SERVER_PORT']) && is_numeric((string)$_SERVER['SERVER_PORT'])) {
            $port = (int)$_SERVER['SERVER_PORT'];
        }

        $base = $scheme . '://' . $host;
        $defaultPorts = ['http' => 80, 'https' => 443];
        if ($port !== null && ($defaultPorts[$scheme] ?? null) !== $port) {
            $base .= ':' . $port;
        }

        return rtrim($base, '/');
    }

    /**
     * Corregge automaticamente le incoerenze riparabili
     */
    public function fixDataInconsistencies(): array {
        $results = ['fixed' => 0, 'errors' => []];

        try {
            $this->db->begin_transaction();

            // 1. Ricalcola tutte le copie disponibili
            $availabilityResult = $this->recalculateAllBookAvailability();
            $results['fixed'] += $availabilityResult['updated'];
            $results['errors'] = array_merge($results['errors'], $availabilityResult['errors']);

            // 2. Correggi stati libri basandosi sulle copie disponibili
            $stmt = $this->db->prepare("
                UPDATE libri SET stato = CASE
                    WHEN copie_disponibili > 0 THEN 'disponibile'
                    WHEN copie_disponibili = 0 THEN 'prestato'
                    ELSE stato
                END
                WHERE stato IN ('disponibile', 'prestato')
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 3. Aggiorna prestiti in ritardo
            $stmt = $this->db->prepare("
                UPDATE prestiti SET stato = 'in_ritardo'
                WHERE stato = 'in_corso'
                AND data_scadenza < CURDATE()
                AND attivo = 1
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 4. Annulla prenotazioni attive che si sovrappongono a prestiti attivi dello stesso libro
            $stmt = $this->db->prepare("
                UPDATE prenotazioni pr
                JOIN prestiti p ON pr.libro_id = p.libro_id
                SET pr.stato = 'annullata'
                WHERE pr.stato = 'attiva'
                  AND p.stato IN ('in_corso','in_ritardo','pendente')
                  AND p.attivo = 1
                  AND (
                        (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_inizio_richiesta <= p.data_scadenza AND pr.data_fine_richiesta >= p.data_prestito)
                     OR (pr.data_inizio_richiesta IS NOT NULL AND pr.data_fine_richiesta IS NULL AND pr.data_inizio_richiesta <= COALESCE(p.data_scadenza, p.data_restituzione, p.data_prestito))
                     OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NOT NULL AND pr.data_fine_richiesta >= p.data_prestito)
                     OR (pr.data_inizio_richiesta IS NULL AND pr.data_fine_richiesta IS NULL)
                  )
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            // 5. Annulla prenotazioni attive che si sovrappongono tra loro per lo stesso libro (tiene la più vecchia)
            $stmt = $this->db->prepare("
                UPDATE prenotazioni r2
                JOIN prenotazioni r1 ON r1.libro_id = r2.libro_id AND r1.id < r2.id
                SET r2.stato = 'annullata'
                WHERE r1.stato = 'attiva' AND r2.stato = 'attiva'
                  AND (
                      r1.data_inizio_richiesta IS NOT NULL AND r1.data_fine_richiesta IS NOT NULL AND
                      r2.data_inizio_richiesta IS NOT NULL AND r2.data_fine_richiesta IS NOT NULL AND
                      r1.data_inizio_richiesta <= r2.data_fine_richiesta AND r1.data_fine_richiesta >= r2.data_inizio_richiesta
                  )
            ");
            $stmt->execute();
            $results['fixed'] += $this->db->affected_rows;
            $stmt->close();

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollback();
            $results['errors'][] = "Errore correzione dati: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * Verifica ed aggiorna lo stato di un prestito
     */
    public function validateAndUpdateLoan(int $loanId): array {
        $result = ['success' => false, 'message' => '', 'updated_fields' => []];

        try {
            // Recupera dati prestito
            $stmt = $this->db->prepare("
                SELECT p.*, l.copie_totali, l.stato as libro_stato
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $loanResult = $stmt->get_result();

            if ($loanResult->num_rows === 0) {
                $result['message'] = 'Prestito non trovato';
                return $result;
            }

            $loan = $loanResult->fetch_assoc();
            $stmt->close();

            $this->db->begin_transaction();
            $updates = [];

            // Verifica stato in ritardo
            if ($loan['stato'] === 'in_corso' && $loan['data_scadenza'] < date('Y-m-d')) {
                $updates['stato'] = 'in_ritardo';
                $result['updated_fields'][] = 'stato -> in_ritardo';
            }

            // Se ci sono aggiornamenti, applicali
            if (!empty($updates)) {
                $setParts = [];
                $params = [];
                $types = '';

                foreach ($updates as $field => $value) {
                    $setParts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }

                $params[] = $loanId;
                $types .= 'i';

                $sql = "UPDATE prestiti SET " . implode(', ', $setParts) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            // Aggiorna disponibilità libro
            $this->recalculateBookAvailability($loan['libro_id']);

            $this->db->commit();
            $result['success'] = true;
            $result['message'] = 'Prestito validato e aggiornato';

        } catch (Exception $e) {
            $this->db->rollback();
            $result['message'] = 'Errore validazione prestito: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Esegue controlli di integrità completi e genera report
     */
    public function generateIntegrityReport(): array {
        $report = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'consistency_issues' => $this->verifyDataConsistency(),
            'statistics' => [
                'total_books' => 0,
                'total_loans' => 0,
                'active_loans' => 0,
                'overdue_loans' => 0,
                'books_available' => 0,
                'books_unavailable' => 0
            ]
        ];

        // Statistiche generali
        $stmt = $this->db->prepare("
            SELECT
                (SELECT COUNT(*) FROM libri) as total_books,
                (SELECT COUNT(*) FROM prestiti) as total_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato IN ('in_corso', 'in_ritardo')) as active_loans,
                (SELECT COUNT(*) FROM prestiti WHERE stato = 'in_ritardo') as overdue_loans,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili > 0) as books_available,
                (SELECT COUNT(*) FROM libri WHERE copie_disponibili = 0) as books_unavailable
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($stats) {
            $report['statistics'] = array_map('intval', $stats);
        }

        // Add missing indexes check
        $report['missing_indexes'] = $this->checkMissingIndexes();

        return $report;
    }

    /**
     * Definisce gli indici di ottimizzazione attesi
     * Basato su installer/database/indexes_optimization.sql
     */
    private function getExpectedIndexes(): array {
        return [
            // TABELLA: libri
            'libri' => [
                'idx_created_at' => ['columns' => ['created_at']],
                'idx_isbn10' => ['columns' => ['isbn10']],
                'idx_genere_scaffale' => ['columns' => ['genere_id', 'scaffale_id']],
                'idx_sottogenere_scaffale' => ['columns' => ['sottogenere_id', 'scaffale_id']],
            ],
            // TABELLA: libri_autori (CRITICA - JOIN efficienti)
            'libri_autori' => [
                'idx_libro_autore' => ['columns' => ['libro_id', 'autore_id']],
                'idx_autore_libro' => ['columns' => ['autore_id', 'libro_id']],
                'idx_ordine_credito' => ['columns' => ['ordine_credito']],
                'idx_ruolo' => ['columns' => ['ruolo']],
            ],
            // TABELLA: autori
            'autori' => [
                'idx_nome' => ['columns' => ['nome'], 'prefix_length' => 100],
            ],
            // TABELLA: editori
            'editori' => [
                'idx_nome' => ['columns' => ['nome'], 'prefix_length' => 100],
            ],
            // TABELLA: prestiti
            'prestiti' => [
                'idx_stato_attivo' => ['columns' => ['stato', 'attivo']],
                'idx_data_prestito' => ['columns' => ['data_prestito']],
            ],
            // TABELLA: utenti
            'utenti' => [
                'idx_nome' => ['columns' => ['nome'], 'prefix_length' => 50],
                'idx_cognome' => ['columns' => ['cognome'], 'prefix_length' => 50],
                'idx_nome_cognome' => ['columns' => ['nome', 'cognome'], 'prefix_length' => 50],
                'idx_ruolo' => ['columns' => ['ruolo']],
            ],
            // TABELLA: generi
            'generi' => [
                'idx_nome' => ['columns' => ['nome'], 'prefix_length' => 50],
            ],
            // TABELLA: posizioni
            'posizioni' => [
                'idx_scaffale_mensola' => ['columns' => ['scaffale_id', 'mensola_id']],
            ],
            // TABELLA: copie
            'copie' => [
                'idx_numero_inventario' => ['columns' => ['numero_inventario']],
            ],
            // TABELLA: prenotazioni
            'prenotazioni' => [
                'idx_libro_id' => ['columns' => ['libro_id']],
                'idx_utente_id' => ['columns' => ['utente_id']],
                'idx_stato' => ['columns' => ['stato']],
            ],
        ];
    }

    /**
     * Verifica quali indici sono mancanti rispetto a quelli attesi
     */
    public function checkMissingIndexes(): array {
        $expected = $this->getExpectedIndexes();
        $missing = [];

        foreach ($expected as $table => $indexes) {
            // Verifica se la tabella esiste
            $tableCheck = $this->db->query("SHOW TABLES LIKE '$table'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                continue; // Salta tabelle che non esistono
            }
            if ($tableCheck instanceof \mysqli_result) {
                $tableCheck->free();
            }

            // Ottieni gli indici esistenti per questa tabella
            $existingIndexes = [];
            $indexResult = $this->db->query("SHOW INDEX FROM `$table`");
            if ($indexResult) {
                while ($row = $indexResult->fetch_assoc()) {
                    $indexName = $row['Key_name'];
                    if (!isset($existingIndexes[$indexName])) {
                        $existingIndexes[$indexName] = [];
                    }
                    $existingIndexes[$indexName][] = $row['Column_name'];
                }
                $indexResult->free();
            }

            // Confronta con gli indici attesi
            foreach ($indexes as $indexName => $indexDef) {
                if (!isset($existingIndexes[$indexName])) {
                    $missing[] = [
                        'table' => $table,
                        'index_name' => $indexName,
                        'columns' => $indexDef['columns'],
                        'prefix_length' => $indexDef['prefix_length'] ?? null,
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * Crea gli indici mancanti
     */
    public function createMissingIndexes(): array {
        $missing = $this->checkMissingIndexes();
        $results = ['created' => 0, 'errors' => [], 'details' => []];

        foreach ($missing as $index) {
            $table = $index['table'];
            $indexName = $index['index_name'];
            $columns = $index['columns'];
            $prefixLength = $index['prefix_length'] ?? null;

            // Costruisci la definizione delle colonne
            $columnDefs = [];
            foreach ($columns as $col) {
                if ($prefixLength !== null) {
                    $columnDefs[] = "`$col`($prefixLength)";
                } else {
                    $columnDefs[] = "`$col`";
                }
            }
            $columnStr = implode(', ', $columnDefs);

            $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($columnStr)";

            try {
                if ($this->db->query($sql)) {
                    $results['created']++;
                    $results['details'][] = [
                        'success' => true,
                        'table' => $table,
                        'index' => $indexName,
                        'message' => "Indice $indexName creato su $table"
                    ];
                } else {
                    $results['errors'][] = "Errore creazione $indexName su $table: " . $this->db->error;
                    $results['details'][] = [
                        'success' => false,
                        'table' => $table,
                        'index' => $indexName,
                        'message' => $this->db->error
                    ];
                }
            } catch (Exception $e) {
                $results['errors'][] = "Eccezione creazione $indexName su $table: " . $e->getMessage();
                $results['details'][] = [
                    'success' => false,
                    'table' => $table,
                    'index' => $indexName,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Genera lo script SQL per creare gli indici mancanti
     */
    public function generateMissingIndexesSQL(): string {
        $missing = $this->checkMissingIndexes();

        if (empty($missing)) {
            return "-- Nessun indice mancante. Il database è già ottimizzato.\n";
        }

        $sql = "-- =====================================================\n";
        $sql .= "-- SCRIPT INDICI MANCANTI - Generato automaticamente\n";
        $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- =====================================================\n\n";

        $currentTable = '';
        foreach ($missing as $index) {
            $table = $index['table'];
            $indexName = $index['index_name'];
            $columns = $index['columns'];
            $prefixLength = $index['prefix_length'] ?? null;

            if ($currentTable !== $table) {
                $sql .= "\n-- TABELLA: $table\n";
                $currentTable = $table;
            }

            // Costruisci la definizione delle colonne
            $columnDefs = [];
            foreach ($columns as $col) {
                if ($prefixLength !== null) {
                    $columnDefs[] = "`$col`($prefixLength)";
                } else {
                    $columnDefs[] = "`$col`";
                }
            }
            $columnStr = implode(', ', $columnDefs);

            $sql .= "ALTER TABLE `$table` ADD INDEX `$indexName` ($columnStr);\n";
        }

        $sql .= "\n-- Fine script\n";

        return $sql;
    }
}
