<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\SecureLogger;
use function __;

class LoanApprovalController
{

    public function pendingLoans(Request $request, Response $response, mysqli $db): Response
    {
        // Get all pending loan requests with origin info
        $stmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   COALESCE(p.origine, 'richiesta') as origine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'pendente'
            ORDER BY p.created_at ASC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $pendingLoans = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get all loans ready for pickup (da_ritirare)
        // "Oggi" nel timezone applicativo (M9), non in quello del processo PHP.
        $today = DateHelper::today();
        $pickupStmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   p.pickup_deadline,
                   COALESCE(p.origine, 'richiesta') as origine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.attivo = 1
              AND (p.stato = 'da_ritirare'
                OR (p.stato = 'prenotato' AND p.data_prestito <= ?))
            ORDER BY
                CASE WHEN p.pickup_deadline IS NOT NULL THEN p.pickup_deadline ELSE p.data_prestito END ASC
        ");
        $pickupStmt->bind_param('s', $today);
        $pickupStmt->execute();
        $pickupResult = $pickupStmt->get_result();
        $pickupLoans = $pickupResult->fetch_all(MYSQLI_ASSOC);
        $pickupStmt->close();

        // Get scheduled loans (prenotato with data_prestito > today - future loans)
        $scheduledStmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   COALESCE(p.origine, 'richiesta') as origine
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'prenotato' AND p.data_prestito > ? AND p.attivo = 1
            ORDER BY p.data_prestito ASC
        ");
        $scheduledStmt->bind_param('s', $today);
        $scheduledStmt->execute();
        $scheduledLoans = $scheduledStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $scheduledStmt->close();

        // Get active loans (in_corso)
        $activeStmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   c.numero_inventario as copia_inventario
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON p.utente_id = u.id
            LEFT JOIN copie c ON p.copia_id = c.id
            WHERE p.stato = 'in_corso' AND p.attivo = 1
            ORDER BY p.data_scadenza ASC
        ");
        $activeStmt->execute();
        $activeLoans = $activeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $activeStmt->close();

        // Get overdue loans (in_ritardo)
        $overdueStmt = $db->prepare("
            SELECT p.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   p.data_prestito as data_richiesta_inizio,
                   p.data_scadenza as data_richiesta_fine,
                   c.numero_inventario as copia_inventario,
                   DATEDIFF(?, p.data_scadenza) as giorni_ritardo
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON p.utente_id = u.id
            LEFT JOIN copie c ON p.copia_id = c.id
            WHERE p.stato = 'in_ritardo' AND p.attivo = 1
            ORDER BY p.data_scadenza ASC
        ");
        $overdueStmt->bind_param('s', $today);
        $overdueStmt->execute();
        $overdueLoans = $overdueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $overdueStmt->close();

        // Get active reservations
        $reservationsStmt = $db->prepare("
            SELECT r.*, l.titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                   r.data_inizio_richiesta, r.data_fine_richiesta,
                   r.data_scadenza_prenotazione,
                   (SELECT COUNT(*) + 1 FROM prenotazioni r2
                    WHERE r2.libro_id = r.libro_id
                    AND r2.stato = 'attiva'
                    AND r2.created_at < r.created_at) as posizione_coda
            FROM prenotazioni r
            JOIN libri l ON r.libro_id = l.id AND l.deleted_at IS NULL
            JOIN utenti u ON r.utente_id = u.id
            WHERE r.stato = 'attiva'
            ORDER BY r.created_at ASC
        ");
        $reservationsStmt->execute();
        $activeReservations = $reservationsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $reservationsStmt->close();

        ob_start();
        $title = __("Gestione Prestiti") . " - " . __("Amministrazione");
        require __DIR__ . '/../Views/admin/pending_loans.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function approveLoan(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();

            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima, poi `prestiti`.
            // Determiniamo il libro del prestito con una lettura NON bloccante, poi
            // acquisiamo i lock nell'ordine libri -> prestiti come tutti gli altri
            // entry point, evitando deadlock da lock-order inversion.
            $bookLookup = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ? AND stato = 'pendente'");
            $bookLookup->bind_param('i', $loanId);
            $bookLookup->execute();
            $bookRow = $bookLookup->get_result()->fetch_assoc();
            $bookLookup->close();
            if (!$bookRow) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o già processato')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $libroId = (int) $bookRow['libro_id'];

            // Lock della riga `libri` PRIMA — serializza anche le approvazioni dello
            // stesso libro (CONC-03).
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockedBook = $lockBookStmt->get_result()->fetch_assoc();
            $lockBookStmt->close();

            // Libro soft-deleted o inesistente: il FOR UPDATE non ha restituito
            // righe. Niente approvazione di un prestito per un titolo rimosso dal
            // catalogo (le query su `libri` devono rispettare deleted_at IS NULL).
            if (!$lockedBook) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Libro non trovato o non più disponibile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Ora lock + ri-verifica del prestito pendente (FOR UPDATE impedisce
            // approvazioni concorrenti dello stesso prestito).
            $stmt = $db->prepare("SELECT libro_id, utente_id, data_prestito, data_scadenza, copia_id FROM prestiti WHERE id = ? AND stato = 'pendente' FOR UPDATE");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o già processato')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $loan = $result->fetch_assoc();
            $stmt->close();

            $existingCopiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;
            $dataPrestito = $loan['data_prestito'];
            $dataScadenza = $loan['data_scadenza'];
            // "Oggi" calcolato nel timezone configurato dell'applicazione, non in quello
            // di default di PHP (spesso UTC in produzione): altrimenti, vicino alla
            // mezzanotte, un prestito datato oggi (lato browser/Rome) verrebbe visto come
            // futuro e finirebbe 'prenotato' invece di 'da_ritirare' (P1 timezone).
            // DateHelper::today() incapsula già i fallback sul timezone (M9).
            $today = DateHelper::today();

            $utenteId = (int) $loan['utente_id'];

            // M7 — gate di idoneità anche in APPROVAZIONE: l'utente può essere
            // stato sospeso (o la tessera può essere scaduta) tra la richiesta e
            // l'approvazione admin. Stesso gate usato da store()/createReservation.
            // Lock della riga utente PRIMA del check (come store()): senza,
            // una sospensione concorrente può committare tra il check e l'UPDATE.
            $userLockStmt = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
            $userLockStmt->bind_param('i', $utenteId);
            $userLockStmt->execute();
            $userLockStmt->get_result();
            $userLockStmt->close();

            $eligibilityError = \App\Support\LoanEligibility::checkUser($db, $utenteId);
            if ($eligibilityError !== null) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => \App\Support\LoanEligibility::errorMessage($eligibilityError)
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            $dupStmt = $db->prepare("
                SELECT id FROM prestiti
                WHERE libro_id = ? AND utente_id = ? AND id != ?
                AND (
                    (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
                    OR (attivo = 0 AND stato = 'pendente')
                )
                LIMIT 1
            ");
            $dupStmt->bind_param('iii', $libroId, $utenteId, $loanId);
            $dupStmt->execute();
            $hasActiveDuplicate = $dupStmt->get_result()->num_rows > 0;
            $dupStmt->close();
            if ($hasActiveDuplicate) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('L\'utente ha già un prestito attivo per questo libro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

            $dupReservationStmt = $db->prepare("
                SELECT id
                FROM prenotazioni
                WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                LIMIT 1
                FOR UPDATE
            ");
            $dupReservationStmt->bind_param('ii', $libroId, $utenteId);
            $dupReservationStmt->execute();
            $hasActiveReservation = $dupReservationStmt->get_result()->num_rows > 0;
            $dupReservationStmt->close();
            if ($hasActiveReservation) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('L\'utente ha già una prenotazione attiva per questo libro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

            // Enforce max_active_loans_per_user anche in approvazione (P1): il limite era
            // applicato solo alla creazione/richiesta, ma approvando più richieste pendenti
            // su libri diversi un admin poteva superarlo. Conta i prestiti attivi escluso
            // quello in approvazione; il lock sulla riga libro sopra serializza.
            $maxLoans = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                // Serialize concurrent approvals for the SAME user on DIFFERENT books:
                // the libri lock above only serializes this loan's book, so two
                // parallel approvals could both read activeCount below the cap and
                // both flip to active. Locking the user row prevents exceeding it.
                $userLockStmt = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
                $userLockStmt->bind_param('i', $utenteId);
                $userLockStmt->execute();
                $userLockStmt->close();

                $cntStmt = $db->prepare("SELECT COUNT(*) AS c FROM prestiti WHERE utente_id = ? AND id != ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')");
                $cntStmt->bind_param('ii', $utenteId, $loanId);
                $cntStmt->execute();
                $activeCount = (int) ($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $cntStmt->close();
                if ($activeCount >= $maxLoans) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('L\'utente ha raggiunto il numero massimo di prestiti attivi consentiti')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
            }

            // Determine state: 'prenotato' if future loan, 'da_ritirare' if immediate
            // User must confirm pickup before loan becomes 'in_corso'
            $isFutureLoan = ($dataPrestito > $today);
            $newState = $isFutureLoan ? 'prenotato' : 'da_ritirare';

            // Calculate pickup deadline for immediate loans (da_ritirare state)
            $pickupDeadline = null;
            if (!$isFutureLoan) {
                $settingsRepo = new \App\Models\SettingsRepository($db);
                $pickupDays = (int) ($settingsRepo->get('loans', 'pickup_expiry_days', '3') ?? 3);
                // Base su $today (timezone applicativo) per coerenza con la
                // classificazione immediato/futuro a cavallo della mezzanotte.
                $pickupDeadline = date('Y-m-d', strtotime("{$today} +{$pickupDays} days"));
                // Cap alla fine della finestra del prestito (L1): una deadline oltre
                // data_scadenza permetterebbe di confermare il ritiro di un prestito
                // già scaduto e terrebbe la copia impegnata oltre la finestra.
                if ($dataScadenza !== null && $dataScadenza !== '' && $pickupDeadline > $dataScadenza) {
                    $pickupDeadline = $dataScadenza;
                }
            }

            // Step 1: Try to use pre-assigned copy first (avoids false rejection when slots are at capacity)
            // If loan already has a valid assigned copy, we can skip global slot counting
            $selectedCopy = null;

            if ($existingCopiaId !== null) {
                $existingCopyStmt = $db->prepare("
                    SELECT c.id FROM copie c
                    WHERE c.id = ?
                    AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
                    AND NOT EXISTS (
                        SELECT 1 FROM prestiti p
                        WHERE p.copia_id = c.id
                        AND p.id != ?
                        AND p.data_prestito <= ?
                        AND (p.stato = 'in_ritardo' OR p.data_scadenza >= ?)
                        AND (
                            (p.attivo = 1 AND p.stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo'))
                            OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                        )
                    )
                ");
                $existingCopyStmt->bind_param('iiss', $existingCopiaId, $loanId, $dataScadenza, $dataPrestito);
                $existingCopyStmt->execute();
                $existingCopyResult = $existingCopyStmt->get_result();
                $selectedCopy = $existingCopyResult ? $existingCopyResult->fetch_assoc() : null;
                $existingCopyStmt->close();
            }

            // Step 2: If no valid pre-assigned copy, check global availability and find a new copy
            if (!$selectedCopy) {
                // Step 2a: Count total lendable copies for this book
                $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')");
                $totalCopiesStmt->bind_param('i', $libroId);
                $totalCopiesStmt->execute();
                $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $totalCopiesStmt->close();

                // Step 2b: Count overlapping loans (excluding the current pending one)
                $loanCountStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM prestiti
                    WHERE libro_id = ? AND id != ?
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                    AND (
                        (attivo = 1 AND stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo'))
                        OR (stato = 'pendente' AND copia_id IS NOT NULL)
                    )
                ");
                $loanCountStmt->bind_param('iiss', $libroId, $loanId, $dataScadenza, $dataPrestito);
                $loanCountStmt->execute();
                $overlappingLoans = (int) ($loanCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $loanCountStmt->close();

                // Step 2c: Count overlapping prenotazioni
                // Use COALESCE to handle NULL data_inizio_richiesta and data_fine_richiesta
                // Fall back to data_scadenza_prenotazione if specific dates are not set
                $resCountStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM prenotazioni
                    WHERE libro_id = ? AND stato = 'attiva'
                    AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
                    AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
                    AND utente_id != ?
                ");
                $resCountStmt->bind_param('issi', $libroId, $dataScadenza, $dataPrestito, $loan['utente_id']);
                $resCountStmt->execute();
                $overlappingReservations = (int) ($resCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $resCountStmt->close();

                // Check if there's at least one slot available
                $totalOccupied = $overlappingLoans + $overlappingReservations;
                if ($totalOccupied >= $totalCopies) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('Nessuna copia disponibile per il periodo richiesto')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }

                // Step 2d: Find a specific lendable copy without overlapping assigned loans for this period
                // Exclude non-lendable copies
                $overlapStmt = $db->prepare("
                    SELECT c.id FROM copie c
                    WHERE c.libro_id = ?
                    AND c.stato IN ('disponibile', 'prenotato')
                    AND NOT EXISTS (
                        SELECT 1 FROM prestiti p
                        WHERE p.copia_id = c.id
                        AND p.data_prestito <= ?
                        AND (p.stato = 'in_ritardo' OR p.data_scadenza >= ?)
                        AND (
                            (p.attivo = 1 AND p.stato IN ('in_corso', 'prenotato', 'da_ritirare', 'in_ritardo'))
                            OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                        )
                    )
                    LIMIT 1
                ");
                $overlapStmt->bind_param('iss', $libroId, $dataScadenza, $dataPrestito);
                $overlapStmt->execute();
                $overlapResult = $overlapStmt->get_result();
                $selectedCopy = $overlapResult ? $overlapResult->fetch_assoc() : null;
                $overlapStmt->close();
            }

            // Step 3: Final fallback using CopyRepository
            if (!$selectedCopy) {
                // Fallback: try date-aware method to find available copy for the requested period
                $copyRepo = new \App\Models\CopyRepository($db);
                $availableCopies = $copyRepo->getAvailableByBookIdForDateRange($libroId, $dataPrestito, $dataScadenza);

                if (empty($availableCopies)) {
                    $db->rollback();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('Nessuna copia disponibile per il periodo richiesto')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                $selectedCopy = $availableCopies[0];
            }

            // Lock selected copy and re-check overlap to prevent race
            $lockCopyStmt = $db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
            $lockCopyStmt->bind_param('i', $selectedCopy['id']);
            $lockCopyStmt->execute();
            $lockCopyStmt->close();

            // Occupazione canonica #157: la copia è tenuta da un prestito attivo
            // OPPURE da un pending di conversione prenotazione che ha già una
            // copia (attivo=0, stato='pendente', copia_id valorizzato). Omettere
            // il secondo ramo permetteva di assegnare in approvazione una copia
            // già tenuta da un pending sovrapposto → doppia prenotazione della
            // stessa copia (mascherata dal trigger quando presente).
            $overlapCopyStmt = $db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ? AND id != ?
                AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                AND (
                    (attivo = 1 AND stato IN ('in_corso','prenotato','da_ritirare','in_ritardo'))
                    OR (stato = 'pendente' AND copia_id IS NOT NULL)
                )
                LIMIT 1
                FOR UPDATE
            ");
            $overlapCopyStmt->bind_param('iiss', $selectedCopy['id'], $loanId, $dataScadenza, $dataPrestito);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Nessuna copia disponibile per il periodo richiesto')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Block the copy for the loan period (prenotato/da_ritirare)
            $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
            $copyCheckStmt->bind_param('i', $selectedCopy['id']);
            $copyCheckStmt->execute();
            $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
            $copyCheckStmt->close();

            $invalidStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
            if (!$copyResult || in_array($copyResult['stato'], $invalidStates, true)) {
                throw new \RuntimeException(__('Copia non disponibile per il prestito'));
            }

            $copyRepo = new \App\Models\CopyRepository($db);
            if (!$copyRepo->updateStatus($selectedCopy['id'], 'prenotato')) {
                throw new \RuntimeException(__('Impossibile aggiornare lo stato della copia'));
            }

            // Assegna la copia al prestito con lo stato corretto e pickup_deadline se applicabile
            if ($pickupDeadline !== null) {
                $stmt = $db->prepare("
                    UPDATE prestiti
                    SET stato = ?, attivo = 1, copia_id = ?, pickup_deadline = ?
                    WHERE id = ? AND stato = 'pendente'
                ");
                $stmt->bind_param('sisi', $newState, $selectedCopy['id'], $pickupDeadline, $loanId);
            } else {
                $stmt = $db->prepare("
                    UPDATE prestiti
                    SET stato = ?, attivo = 1, copia_id = ?, pickup_deadline = NULL
                    WHERE id = ? AND stato = 'pendente'
                ");
                $stmt->bind_param('sii', $newState, $selectedCopy['id'], $loanId);
            }
            $stmt->execute();
            $stmt->close();

            // Per 'da_ritirare' e 'prenotato', la copia resta 'prenotato' fino al ritiro
            // La copia diventa 'prestato' SOLO quando si conferma il ritiro

            // Update book availability with integrity check (inside transaction)
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            // Send appropriate notification to user
            try {
                $notificationService = new \App\Support\NotificationService($db);
                if ($isFutureLoan) {
                    // Future loan: send general approval notification
                    $notificationService->sendLoanApprovedNotification($loanId);
                } else {
                    // Immediate loan (da_ritirare): send pickup ready notification with deadline
                    $notificationService->sendPickupReadyNotification($loanId);
                }
            } catch (\Throwable $notifError) {
                \App\Support\SecureLogger::warning("Approval notification failed for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail the approval if notification fails
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $isFutureLoan
                    ? __('Prestito prenotato con successo')
                    : __('Prestito approvato - in attesa di ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("Loan approval failed for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore interno durante l\'approvazione')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function rejectLoan(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);
        $reason = $data['reason'] ?? '';

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Start transaction for atomic delete + availability update
        $db->begin_transaction();

        try {
            // Lock and fetch FULL loan data BEFORE deletion (needed for rejection email)
            // Must include user email/name and book title since loan will be deleted
            $stmt = $db->prepare("
                SELECT p.libro_id, p.utente_id, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ? AND p.stato = 'pendente'
                FOR UPDATE
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o già processato')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $bookId = (int) $loan['libro_id'];
            // Store data needed for rejection email BEFORE deletion
            $userEmail = $loan['utente_email'];
            $userName = $loan['utente_nome'];
            $bookTitle = $loan['libro_titolo'];

            // Delete the loan
            $stmt = $db->prepare("DELETE FROM prestiti WHERE id = ? AND stato = 'pendente'");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();

            if ($db->affected_rows === 0) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito già processato da un altro utente')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }
            $stmt->close();

            // Update book availability (inside transaction)
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($bookId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            // Send notification AFTER successful commit (outside transaction)
            // Use pre-fetched data since loan is deleted
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendLoanRejectedNotificationDirect(
                    $userEmail,
                    $userName,
                    $bookTitle,
                    $reason
                );
            } catch (\Throwable $notifError) {
                \App\Support\SecureLogger::warning("[rejectLoan] Notification error for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail - deletion already committed
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Richiesta rifiutata')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("[rejectLoan] Error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore nel rifiuto della richiesta')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Confirm pickup of a loan that is ready for pickup.
     * Accepts loans in 'da_ritirare' state or 'prenotato' state if data_prestito <= today.
     * This allows the system to work even without MaintenanceService.
     */
    public function confirmPickup(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();
            $today = DateHelper::today();

            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima, poi `prestiti`.
            // Determiniamo il libro con una lettura NON bloccante, poi acquisiamo i lock
            // nell'ordine libri -> prestiti come approveLoan/store/renew, evitando
            // deadlock da lock-order inversion con operazioni concorrenti sullo stesso
            // libro (M2).
            $bookLookup = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
            $bookLookup->bind_param('i', $loanId);
            $bookLookup->execute();
            $bookRow = $bookLookup->get_result()->fetch_assoc();
            $bookLookup->close();
            if (!$bookRow) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non pronto per il ritiro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $libroId = (int) $bookRow['libro_id'];

            // Lock della riga `libri` SENZA filtro deleted_at: come per le restituzioni
            // (vedi LoanRepository::close), l'evasione di un prestito già approvato deve
            // poter procedere anche se il libro è stato soft-deleted nel frattempo.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Poi lock + ri-verifica del prestito con le guardie di stato.
            // Accept 'da_ritirare' OR 'prenotato' if data_prestito <= today (for systems without MaintenanceService)
            $stmt = $db->prepare("
                SELECT id, libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, pickup_deadline
                FROM prestiti
                WHERE id = ? AND attivo = 1
                AND (stato = 'da_ritirare' OR (stato = 'prenotato' AND data_prestito <= ?))
                FOR UPDATE
            ");
            $stmt->bind_param('is', $loanId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non pronto per il ritiro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Re-verifica TOCTOU: la lettura iniziale era non bloccante per preservare
            // l'ordine di lock canonico; se libro_id fosse cambiato nel frattempo
            // (rarissimo) avremmo lockato il libro sbagliato: abort.
            if ((int) $loan['libro_id'] !== $libroId) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non pronto per il ritiro')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Block if no copy assigned (data integrity issue - legacy/migration problem)
            if (empty($loan['copia_id'])) {
                $db->rollback();
                \App\Support\SecureLogger::error("[confirmPickup] Loan {$loanId} has no assigned copy - cannot confirm pickup");
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito senza copia assegnata - contattare l\'amministratore')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Check if pickup deadline has passed
            if (!empty($loan['pickup_deadline']) && $today > $loan['pickup_deadline']) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Il termine per il ritiro è scaduto')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Guardia L1: pickup_deadline (per prestiti storici, pre-cap) può superare
            // data_scadenza. Non avviare un 'in_corso' la cui finestra è già interamente
            // trascorsa: nascerebbe scaduto.
            if (!empty($loan['data_scadenza']) && $loan['data_scadenza'] < $today) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('La finestra del prestito è già trascorsa: annulla il ritiro o modifica le date')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $copiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;

            // Update loan state to 'in_corso'
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'in_corso', pickup_deadline = NULL
                WHERE id = ?
            ");
            $updateStmt->bind_param('i', $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // Update copy status to 'prestato' (only if copy is in a loanable state)
            if ($copiaId) {
                // Verify copy is in a valid state for lending (FOR UPDATE prevents TOCTOU race)
                $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                $copyCheckStmt->bind_param('i', $copiaId);
                $copyCheckStmt->execute();
                $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
                $copyCheckStmt->close();

                $invalidStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
                if (!$copyResult || in_array($copyResult['stato'], $invalidStates, true)) {
                    // Fail closed: roll back the just-applied 'in_corso' update instead
                    // of committing a loan over a missing/non-lendable copy (BUG7c/D12).
                    $db->rollback();
                    $copyState = $copyResult ? (string) $copyResult['stato'] : 'inesistente';
                    \App\Support\SecureLogger::error("[confirmPickup] Loan {$loanId} aborted: copy {$copiaId} non-lendable ('{$copyState}')");
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => __('La copia assegnata non è prestabile. Riassegna la copia o annulla il ritiro.')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                $copyRepo = new \App\Models\CopyRepository($db);
                $copyRepo->updateStatus($copiaId, 'prestato');
            }

            // Recalculate book availability (inside transaction)
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Ritiro confermato con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("[confirmPickup] Error for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante la conferma del ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Cancel a pickup that was not collected (e.g., expired or user didn't show up).
     * Accepts loans in 'da_ritirare' state or 'prenotato' state if data_prestito <= today.
     * Releases the copy and updates availability.
     */
    public function cancelPickup(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);
        $reason = $data['reason'] ?? __('Ritiro non effettuato');

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();
            $today = DateHelper::today();

            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima, poi `prestiti`.
            // Lettura NON bloccante del libro, poi lock nell'ordine libri -> prestiti
            // come approveLoan/store/renew (M2, niente lock-order inversion).
            $bookLookup = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
            $bookLookup->bind_param('i', $loanId);
            $bookLookup->execute();
            $bookRow = $bookLookup->get_result()->fetch_assoc();
            $bookLookup->close();
            if (!$bookRow) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non cancellabile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $libroId = (int) $bookRow['libro_id'];

            // Lock della riga `libri` SENZA filtro deleted_at: l'annullamento di un
            // ritiro deve sempre poter procedere anche su libro soft-deleted (vedi
            // LoanRepository::close), altrimenti prestito e copia resterebbero
            // impegnati per sempre.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Poi lock + ri-verifica del prestito con le guardie di stato.
            // Accept 'da_ritirare' OR 'prenotato' if data_prestito <= today
            $stmt = $db->prepare("
                SELECT id, libro_id, copia_id, utente_id, stato
                FROM prestiti
                WHERE id = ? AND attivo = 1
                AND (stato = 'da_ritirare' OR (stato = 'prenotato' AND data_prestito <= ?))
                FOR UPDATE
            ");
            $stmt->bind_param('is', $loanId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non cancellabile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Re-verifica TOCTOU: se libro_id è cambiato tra la lettura non bloccante e
            // il lock avremmo bloccato (e ricalcolato) il libro sbagliato: abort.
            if ((int) $loan['libro_id'] !== $libroId) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non cancellabile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $copiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;

            // Mark loan as expired (not picked up)
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET stato = 'scaduto', attivo = 0, pickup_deadline = NULL
                WHERE id = ?
            ");
            $updateStmt->bind_param('i', $loanId);
            $updateStmt->execute();
            $updateStmt->close();

            // Prepare reassignment service (for advancing reservation queue)
            // Critical: This is the ONLY reassignment opportunity if MaintenanceService doesn't run
            $reassignmentService = new \App\Services\ReservationReassignmentService($db);
            $reassignmentService->setExternalTransaction(true);

            // Release copy if assigned (set back to 'disponibile' only if valid state)
            if ($copiaId) {
                // FOR UPDATE prevents TOCTOU race - lock row before checking and updating
                $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                $copyCheckStmt->bind_param('i', $copiaId);
                $copyCheckStmt->execute();
                $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
                $copyCheckStmt->close();

                $invalidStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
                if ($copyResult && !in_array($copyResult['stato'], $invalidStates, true)) {
                    $copyRepo = new \App\Models\CopyRepository($db);
                    $copyRepo->updateStatus($copiaId, 'disponibile');

                    // Advance reservation queue: promote next waiting user for this copy
                    $reassignmentService->reassignOnReturn($copiaId);
                } elseif ($copyResult) {
                    \App\Support\SecureLogger::warning("[cancelPickup] Copy {$copiaId} in state '{$copyResult['stato']}' not reset to disponibile");
                }
            }

            // Promote the waitlist (Layer 2): a cancelled pickup frees capacity for
            // queued reservations. Loop until none convert. Both queues (D5/BUG10).
            $reservationManager = new \App\Controllers\ReservationManager($db);
            $reservationManager->setExternalTransaction(true);
            for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                // keep promoting while freed capacity converts the next queued reservation
            }

            // Recalculate book availability (inside transaction)
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            // Send deferred reservation notifications AFTER commit (outside transaction)
            $reassignmentService->flushDeferredNotifications();
            $reservationManager->flushDeferredNotifications();

            // Send notification to user about cancelled pickup (outside transaction)
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendPickupCancelledNotification($loanId, $reason);
            } catch (\Throwable $notifError) {
                \App\Support\SecureLogger::warning("[cancelPickup] Notification error for loan {$loanId}: " . $notifError->getMessage());
                // Don't fail - cancellation already committed
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Ritiro annullato con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("[cancelPickup] Error for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante l\'annullamento del ritiro')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Mark an active loan as returned (JSON API for pending loans page).
     */
    public function returnLoan(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $loanId = (int) ($data['loan_id'] ?? 0);

        if ($loanId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prestito non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();

            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima, poi `prestiti`.
            // Lettura NON bloccante del libro, poi lock nell'ordine libri -> prestiti
            // come approveLoan/store/renew (M2, niente lock-order inversion).
            $bookLookup = $db->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
            $bookLookup->bind_param('i', $loanId);
            $bookLookup->execute();
            $bookRow = $bookLookup->get_result()->fetch_assoc();
            $bookLookup->close();
            if (!$bookRow) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non restituibile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $libroId = (int) $bookRow['libro_id'];

            // Lock della riga `libri` SENZA filtro deleted_at: la RESTITUZIONE deve
            // sempre poter procedere anche su libro soft-deleted (vedi il commento in
            // LoanRepository::close), altrimenti prestito e copia resterebbero
            // occupati per sempre.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Poi lock + ri-verifica del prestito con le guardie di stato.
            $stmt = $db->prepare("
                SELECT libro_id, copia_id, stato, data_scadenza
                FROM prestiti
                WHERE id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo')
                FOR UPDATE
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non restituibile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Re-verifica TOCTOU: se libro_id è cambiato tra la lettura non bloccante e
            // il lock avremmo bloccato (e ricalcolato) il libro sbagliato: abort.
            if ((int) $loan['libro_id'] !== $libroId) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prestito non trovato o non restituibile')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $copiaId = $loan['copia_id'] ? (int) $loan['copia_id'] : null;
            // "Oggi" nel timezone applicativo (M9): a cavallo della mezzanotte
            // date('Y-m-d') in UTC registrerebbe il giorno sbagliato di restituzione
            // e falserebbe il flag di ritardo.
            $dataRestituzione = DateHelper::today();
            // Returned-late flag: a return past the due date is restituito + flag (I4/BUG5).
            // This is the primary quick-return path — without it late returns keep the
            // flag at 0 and Stats/Recensioni undercount late returners.
            $scadenza = (string) ($loan['data_scadenza'] ?? '');
            $ritardo = ($scadenza !== '' && $scadenza < $dataRestituzione) ? 1 : 0;

            // Close the loan
            $stmt = $db->prepare("UPDATE prestiti SET stato = 'restituito', restituito_in_ritardo = ?, data_restituzione = ?, attivo = 0 WHERE id = ? AND attivo = 1");
            $stmt->bind_param('isi', $ritardo, $dataRestituzione, $loanId);
            $stmt->execute();
            $stmt->close();

            // Update copy status (only if not in a non-lendable state)
            $copyAvailable = false;
            if ($copiaId) {
                $copyCheckStmt = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                $copyCheckStmt->bind_param('i', $copiaId);
                $copyCheckStmt->execute();
                $copyResult = $copyCheckStmt->get_result()->fetch_assoc();
                $copyCheckStmt->close();

                $invalidStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
                if ($copyResult && !in_array($copyResult['stato'], $invalidStates, true)) {
                    $copyRepo = new \App\Models\CopyRepository($db);
                    if (!$copyRepo->updateStatus($copiaId, 'disponibile')) {
                        throw new \RuntimeException(__('Impossibile aggiornare lo stato della copia'));
                    }
                    $copyAvailable = true;
                }
            }

            // Reassign returned copy to next waiting reservation
            // Niente catch locale: la riassegnazione condivide questa transazione e
            // un errore deve propagare al catch esterno per il rollback completo,
            // evitando il commit di uno stato parziale (CRITICAL #157).
            $reassignmentService = null;
            if ($copiaId && $copyAvailable) {
                $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                $reassignmentService->setExternalTransaction(true);
                $reassignmentService->reassignOnReturn($copiaId);
            }

            // Promote the waitlist (Layer 2): a freed unit may convert one or more
            // queued reservations. Loop until none convert — multi-copy frees can
            // promote several. Both queues on every release path (D5/BUG10).
            $reservationManager = new \App\Controllers\ReservationManager($db);
            $reservationManager->setExternalTransaction(true);
            for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                // keep promoting while freed capacity converts the next queued reservation
            }

            // Recalculate availability AFTER reassignment + promotion
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            // Send deferred notifications after commit
            if ($reassignmentService) {
                $reassignmentService->flushDeferredNotifications();
            }
            $reservationManager->flushDeferredNotifications();

            // Notify wishlist users AFTER commit, only if book has available copies
            try {
                $availStmt = $db->prepare("SELECT copie_disponibili FROM libri WHERE id = ? AND deleted_at IS NULL");
                $availStmt->bind_param('i', $libroId);
                $availStmt->execute();
                $availResult = $availStmt->get_result()->fetch_assoc();
                $availStmt->close();

                if ($availResult && (int)($availResult['copie_disponibili'] ?? 0) > 0) {
                    $notificationService = new \App\Support\NotificationService($db);
                    $notificationService->notifyWishlistBookAvailability($libroId);
                }
            } catch (\Throwable $e) {
                \App\Support\SecureLogger::warning("[returnLoan] Wishlist notify error for book {$libroId}: " . $e->getMessage());
            }

            // Conferma di restituzione all'utente (GAP-1), dopo il commit
            try {
                (new \App\Support\NotificationService($db))->sendLoanReturnedNotification($loanId);
            } catch (\Throwable $e) {
                \App\Support\SecureLogger::warning("[returnLoan] Loan returned notify error for loan {$loanId}: " . $e->getMessage());
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Libro restituito con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("[returnLoan] Error for loan {$loanId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante la restituzione')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Admin cancel a reservation (JSON API for pending loans page).
     */
    public function cancelReservation(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || empty($data)) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) $request->getBody();
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $reservationId = (int) ($data['reservation_id'] ?? 0);
        // reason arriva dal body e finisce nel template email come {{motivo}}:
        // neutralizza input non scalare e limita la lunghezza (l'escaping HTML
        // avviene al sink in EmailService::replaceVariables).
        $rawReason = $data['reason'] ?? '';
        $reason = is_scalar($rawReason) ? mb_substr(trim((string) $rawReason), 0, 500) : '';

        if ($reservationId <= 0) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => __('ID prenotazione non valido')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $db->begin_transaction();

            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima, poi
            // `prenotazioni`. Lettura NON bloccante del libro, poi lock nell'ordine
            // libri -> prenotazioni come approveLoan/store/renew (M2).
            $bookLookup = $db->prepare("SELECT libro_id FROM prenotazioni WHERE id = ? AND stato = 'attiva'");
            $bookLookup->bind_param('i', $reservationId);
            $bookLookup->execute();
            $bookRow = $bookLookup->get_result()->fetch_assoc();
            $bookLookup->close();
            if (!$bookRow) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prenotazione non trovata o già annullata')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $libroId = (int) $bookRow['libro_id'];

            // Lock della riga `libri` SENZA filtro deleted_at: l'annullamento di una
            // prenotazione deve sempre poter procedere anche su libro soft-deleted
            // (vedi LoanRepository::close), per non lasciare la coda bloccata.
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockBookStmt->close();

            // Poi lock + ri-verifica della prenotazione. Il JOIN recupera anche
            // destinatario e titolo per la notifica post-commit (M11), come fa
            // rejectLoan; niente filtro deleted_at sul libro (vedi sopra).
            $stmt = $db->prepare("
                SELECT r.libro_id, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prenotazioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.id = ? AND r.stato = 'attiva'
                FOR UPDATE
            ");
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $result = $stmt->get_result();
            $reservation = $result->fetch_assoc();
            $stmt->close();

            if (!$reservation) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prenotazione non trovata o già annullata')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Re-verifica TOCTOU: se libro_id è cambiato tra la lettura non bloccante e
            // il lock avremmo bloccato (e ricalcolato) il libro sbagliato: abort.
            if ((int) $reservation['libro_id'] !== $libroId) {
                $db->rollback();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => __('Prenotazione non trovata o già annullata')
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Dati per la notifica post-commit (M11).
            $userEmail = (string) $reservation['utente_email'];
            $userName = (string) $reservation['utente_nome'];
            $bookTitle = (string) $reservation['libro_titolo'];

            // Cancel the reservation
            $stmt = $db->prepare("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?");
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $stmt->close();

            // Reorder queue positions for remaining active reservations
            $reorderStmt = $db->prepare("
                SELECT id FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                ORDER BY queue_position ASC
                FOR UPDATE
            ");
            $reorderStmt->bind_param('i', $libroId);
            $reorderStmt->execute();
            $reorderResult = $reorderStmt->get_result();

            $position = 1;
            $updatePos = $db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
            while ($row = $reorderResult->fetch_assoc()) {
                $updatePos->bind_param('ii', $position, $row['id']);
                $updatePos->execute();
                $position++;
            }
            $updatePos->close();
            $reorderStmt->close();

            // Recalculate book availability
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $db->commit();

            // Notifica all'utente DOPO il commit (M11): try/catch isolato, un errore
            // di invio non deve far fallire l'annullamento già committato.
            try {
                $notificationService = new \App\Support\NotificationService($db);
                $notificationService->sendReservationCancelledNotification($userEmail, [
                    'utente_nome' => $userName,
                    'libro_titolo' => $bookTitle,
                    'motivo' => $reason ?: __('Annullata dalla biblioteca')
                ]);
            } catch (\Throwable $notifError) {
                \App\Support\SecureLogger::warning("[cancelReservation] Notification error for reservation {$reservationId}: " . $notifError->getMessage());
                // Don't fail - cancellation already committed
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => __('Prenotazione annullata con successo')
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error("[cancelReservation] Error for reservation {$reservationId}: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => __('Errore durante l\'annullamento della prenotazione')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

}
