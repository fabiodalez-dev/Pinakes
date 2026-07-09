<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\Csv;
use App\Support\DataIntegrity;
use App\Support\NotificationService;
use App\Support\SecureLogger;
use Exception;

/**
 * Controller for managing loans (prestiti) in the admin panel
 *
 * Handles loan listing, creation, approval, return, renewal,
 * and CSV export functionality. Requires staff or admin access.
 *
 * @package App\Controllers
 */
class PrestitiController
{
    /**
     * Display the loans list page with filtering and pending loans widget
     *
     * @param Request $request PSR-7 request
     * @param Response $response PSR-7 response
     * @param mysqli $db Database connection
     * @return Response Rendered view
     */
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $repo = new \App\Models\LoanRepository($db);
        $prestiti = $repo->listRecent(100);

        // Get pending loans for the dashboard widget
        $stmt = $db->prepare("
            SELECT p.id, p.libro_id, p.utente_id, p.data_prestito, p.data_scadenza, p.created_at,
                   l.titolo as libro_titolo, l.copertina_url,
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
            FROM prestiti p
            JOIN libri l ON p.libro_id = l.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'pendente' AND l.deleted_at IS NULL
            ORDER BY p.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $pending_loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        ob_start();
        $prestiti = $prestiti; // Make variable available to included file
        $pending_loans = $pending_loans; // Make pending loans variable available to included file
        require __DIR__ . '/../Views/prestiti/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function createForm(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }

        // Pre-fill user info if utente_id query param provided
        $queryParams = $request->getQueryParams();
        $presetUserId = isset($queryParams['utente_id']) ? (int)$queryParams['utente_id'] : 0;
        $presetUserName = '';
        $presetUserLocked = false;
        if ($presetUserId > 0) {
            $presetUser = null;
            $stmt = $db->prepare("SELECT id, nome, cognome, codice_tessera FROM utenti WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $presetUserId);
                $stmt->execute();
                $presetUser = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            if ($presetUser) {
                $presetUserName = $presetUser['nome'] . ' ' . $presetUser['cognome'] . ' (' . $presetUser['codice_tessera'] . ')';
                $presetUserLocked = true;
            } else {
                $presetUserId = 0;
            }
        }

        ob_start();
        require __DIR__ . '/../Views/prestiti/crea_prestito.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();

        // CSRF validated by CsrfMiddleware

        // Verifica dati obbligatori
        $utente_id = (int) ($data['utente_id'] ?? 0);
        $libro_id = (int) ($data['libro_id'] ?? 0);
        $data_prestito = $data['data_prestito'] ?? '';
        $data_scadenza = $data['data_scadenza'] ?? '';
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        // Se le date sono vuote, usa i valori di default. "Oggi" nel timezone
        // applicativo (M9): date() userebbe la TZ del processo (spesso UTC).
        if (empty($data_prestito)) {
            $data_prestito = \App\Support\DateHelper::today();
        }
        if (empty($data_scadenza)) {
            // Default loan duration read from admin settings (fallback: 30 days)
            $loanDays = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'loan_duration_days', '30') ?? 30);
            if ($loanDays < 1) {
                $loanDays = 30;
            }
            $data_scadenza = date('Y-m-d', strtotime($data_prestito . " +{$loanDays} days"));
        }

        if ($utente_id <= 0 || $libro_id <= 0) {
            return $response->withHeader('Location', url('/admin/loans/create') . '?error=missing_fields')->withStatus(302);
        }

        // Verifica che la data di scadenza sia successiva alla data di prestito
        if (strtotime($data_scadenza) <= strtotime($data_prestito)) {
            return $response->withHeader('Location', url('/admin/loans/create') . '?error=invalid_dates')->withStatus(302);
        }

        $db->begin_transaction();

        try {
            // ORDINE DI LOCK CANONICO: la riga `libri` SEMPRE per prima, poi `prestiti`
            // e `copie` (P3). Tutti gli entry point di creazione/richiesta prestito
            // (store, createReservation, loan) usano questo stesso ordine per evitare
            // deadlock da lock-order inversion.
            $lockStmt = $db->prepare("SELECT id, stato, copie_disponibili FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libro_id);
            $lockStmt->execute();
            $bookResult = $lockStmt->get_result();
            $book = $bookResult ? $bookResult->fetch_assoc() : null;
            $lockStmt->close();

            if (!$book) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=book_not_found')->withStatus(302);
            }

            // Case 8: Prevent multiple active reservations/loans for the same book by the same user
            // Note: 'pendente' has attivo=0, other active states have attivo=1
            $dupStmt = $db->prepare("
                SELECT id FROM prestiti
                WHERE libro_id = ? AND utente_id = ? AND (
                    (attivo = 0 AND stato = 'pendente')
                    OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
                )
                FOR UPDATE
            ");
            $dupStmt->bind_param('ii', $libro_id, $utente_id);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                $dupStmt->close();
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=duplicate_reservation')->withStatus(302);
            }
            $dupStmt->close();

            $dupReservationStmt = $db->prepare("
                SELECT id
                FROM prenotazioni
                WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                LIMIT 1
                FOR UPDATE
            ");
            $dupReservationStmt->bind_param('ii', $libro_id, $utente_id);
            $dupReservationStmt->execute();
            if ($dupReservationStmt->get_result()->num_rows > 0) {
                $dupReservationStmt->close();
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=duplicate_reservation')->withStatus(302);
            }
            $dupReservationStmt->close();

            // Serialize concurrent same-user requests: the libri lock above only
            // mutually-excludes requests for the SAME book, so two store() calls
            // for the same user on different books could both read activeCount
            // below the limit and both insert. Locking the user row prevents it.
            // Il lock è INCONDIZIONATO (M7): prima viveva nel ramo maxLoans>0,
            // quindi con il default 0 un utente_id inesistente arrivava all'INSERT
            // e moriva sulla FK con un 500 invece di un errore pulito.
            $userLockStmt = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
            $userLockStmt->bind_param('i', $utente_id);
            $userLockStmt->execute();
            $userLockStmt->get_result();
            $userLockStmt->close();

            // Idoneità utente (M7): esistenza + stato attivo + tessera in corso di
            // validità, verificati sotto il lock della riga utente. Il codice
            // restituito (user_not_found/user_suspended/card_expired) diventa il
            // codice errore della query string.
            $eligibilityError = \App\Support\LoanEligibility::checkUser($db, $utente_id);
            if ($eligibilityError !== null) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=' . $eligibilityError)->withStatus(302);
            }

            // Enforce max active loans per user (admin setting; 0 = no limit)
            $maxLoans = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')");
                $cntStmt->bind_param('i', $utente_id);
                $cntStmt->execute();
                $cntResult = $cntStmt->get_result();
                $activeCount = (int) ($cntResult ? $cntResult->fetch_row()[0] : 0);
                $cntStmt->close();
                if ($activeCount >= $maxLoans) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=max_loans_reached')->withStatus(302);
                }
            }

            // Check if loan starts today (immediate loan) or in the future (scheduled loan).
            // "Oggi" nel timezone configurato dell'app, non nel default PHP (UTC in prod):
            // altrimenti vicino a mezzanotte un prestito datato oggi verrebbe trattato come
            // futuro (stesso bug timezone corretto in LoanApprovalController).
            $today = \App\Support\DateHelper::today();
            $loanStartDate = date('Y-m-d', strtotime($data_prestito));
            $isImmediateLoan = ($loanStartDate <= $today);

            // Select a copy for the loan
            // For IMMEDIATE loans: find a copy that is currently 'disponibile'
            // For FUTURE loans: find a copy that has no overlapping active loans in the requested period
            $copyRepo = new \App\Models\CopyRepository($db);
            $selectedCopy = null;

            // OPTIONAL per-copy code (numero_inventario): when supplied, the operator
            // is loaning a SPECIFIC physical copy (e.g. scanned/typed at the desk).
            // Resolve it, verify it belongs to the selected book and is free for the
            // requested period, then force it. When empty, fall through to the normal
            // auto-assign paths below (fully backward compatible).
            $copyCode = trim((string) ($data['copy_code'] ?? ''));
            if ($copyCode !== '') {
                $codeStmt = $db->prepare("
                    SELECT c.id, c.stato, c.libro_id
                    FROM copie c
                    JOIN libri l ON l.id = c.libro_id
                    WHERE c.numero_inventario = ? AND l.deleted_at IS NULL
                    LIMIT 1
                    FOR UPDATE
                ");
                $codeStmt->bind_param('s', $copyCode);
                $codeStmt->execute();
                $codeRow = $codeStmt->get_result()->fetch_assoc();
                $codeStmt->close();

                if (!$codeRow) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=copy_not_found')->withStatus(302);
                }
                if ((int) $codeRow['libro_id'] !== $libro_id) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=copy_wrong_book')->withStatus(302);
                }

                $forcedCopyId = (int) $codeRow['id'];

                // Must be in a lendable state and have no overlapping hold for the period.
                $lendable = in_array($codeRow['stato'], ['disponibile', 'prenotato'], true);
                if ($lendable) {
                    $ovStmt = $db->prepare("
                        SELECT 1 FROM prestiti
                        WHERE copia_id = ?
                          AND data_prestito <= ?
                          AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                          AND (
                                (attivo = 1 AND stato IN ('in_corso','da_ritirare','prenotato','in_ritardo'))
                                OR (stato = 'pendente' AND copia_id IS NOT NULL)
                          )
                        LIMIT 1
                    ");
                    $ovStmt->bind_param('iss', $forcedCopyId, $data_scadenza, $data_prestito);
                    $ovStmt->execute();
                    if ($ovStmt->get_result()->fetch_row()) {
                        $lendable = false;
                    }
                    $ovStmt->close();
                }

                if (!$lendable) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=copy_not_available')->withStatus(302);
                }

                // A forced physical-copy scan must still respect the book-level
                // capacity gate: active prenotazioni do not pin this copia_id, but
                // they reserve one capacity unit for the requested period.
                $capacity = new \App\Services\CapacityService($db);
                if (!$capacity->hasFreeCapacity($libro_id, $data_prestito, $data_scadenza)) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=no_copies_available')->withStatus(302);
                }

                $selectedCopy = ['id' => $forcedCopyId];
            }

            if ($selectedCopy === null && $isImmediateLoan) {
                // For immediate loans, verify book-level availability (including prenotazioni)
                // Step 1: Count total lendable copies
                $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')");
                $totalCopiesStmt->bind_param('i', $libro_id);
                $totalCopiesStmt->execute();
                $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $totalCopiesStmt->close();

                // Step 2: Count overlapping loans for the loan period
                $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ?
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                AND (
                    (attivo = 1 AND stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                    OR (stato = 'pendente' AND copia_id IS NOT NULL)
                )
                ");
                $loanCountStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $loanCountStmt->execute();
                $overlappingLoans = (int) ($loanCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $loanCountStmt->close();

                // Step 3: Count overlapping prenotazioni for the loan period
                // Use COALESCE to handle NULL dates - matches ReservationManager pattern
                // Note: data_scadenza_prenotazione is datetime, preserving full value for comparison
                $resCountStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM prenotazioni
                    WHERE libro_id = ? AND stato = 'attiva'
                    AND COALESCE(data_inizio_richiesta, data_scadenza_prenotazione) <= ?
                    AND COALESCE(data_fine_richiesta, data_scadenza_prenotazione) >= ?
                ");
                $resCountStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $resCountStmt->execute();
                $overlappingReservations = (int) ($resCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $resCountStmt->close();

                // Check if there's at least one slot available
                $totalOccupied = $overlappingLoans + $overlappingReservations;
                if ($totalOccupied >= $totalCopies) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=no_copies_available')->withStatus(302);
                }

                // Find a copy without overlapping loans for the requested period
                // Include 'disponibile' and 'prenotato' copies - NOT EXISTS prevents date overlaps
                // This allows scheduling non-overlapping loans on the same copy
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
                            (p.attivo = 1 AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                            OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                        )
                    )
                    LIMIT 1
                ");
                $overlapStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $overlapStmt->execute();
                $overlapResult = $overlapStmt->get_result();
                $selectedCopy = $overlapResult ? $overlapResult->fetch_assoc() : null;
                $overlapStmt->close();
                // Note: No fallback to getAvailableByBookId - if primary query finds no copy,
                // all copies have overlapping loans for the requested period
            } elseif ($selectedCopy === null) {
                // For FUTURE loans, find a copy that has no overlapping active loans
                // Also verify book-level availability (considering prenotazioni)

                // Step 1: Count total lendable copies
                $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')");
                $totalCopiesStmt->bind_param('i', $libro_id);
                $totalCopiesStmt->execute();
                $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $totalCopiesStmt->close();

                // Step 2: Count overlapping loans (approved states only)
                $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ?
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                AND (
                    (attivo = 1 AND stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                    OR (stato = 'pendente' AND copia_id IS NOT NULL)
                )
                ");
                $loanCountStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $loanCountStmt->execute();
                $overlappingLoans = (int) ($loanCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $loanCountStmt->close();

                // Step 3: Count overlapping prenotazioni
                // Use COALESCE to handle NULL dates - matches ReservationManager pattern
                $resCountStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM prenotazioni
                    WHERE libro_id = ? AND stato = 'attiva'
                    AND COALESCE(data_inizio_richiesta, data_scadenza_prenotazione) <= ?
                    AND COALESCE(data_fine_richiesta, data_scadenza_prenotazione) >= ?
                ");
                $resCountStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $resCountStmt->execute();
                $overlappingReservations = (int) ($resCountStmt->get_result()->fetch_assoc()['count'] ?? 0);
                $resCountStmt->close();

                // Check if there's at least one slot available
                $totalOccupied = $overlappingLoans + $overlappingReservations;
                if ($totalOccupied >= $totalCopies) {
                    // No slots available at book level
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans/create') . '?error=no_copies_available')->withStatus(302);
                }

                // Step 4: Find a specific copy without overlapping assigned loans
                // Include 'disponibile' and 'prenotato' copies - NOT EXISTS prevents date overlaps
                // This allows scheduling non-overlapping loans on the same copy
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
                            (p.attivo = 1 AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                            OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                        )
                    )
                    LIMIT 1
                ");
                $overlapStmt->bind_param('iss', $libro_id, $data_scadenza, $data_prestito);
                $overlapStmt->execute();
                $overlapResult = $overlapStmt->get_result();
                $freeCopy = $overlapResult ? $overlapResult->fetch_assoc() : null;
                $overlapStmt->close();

                if ($freeCopy) {
                    $selectedCopy = $freeCopy;
                }
            }

            if (!$selectedCopy) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=no_copies_available')->withStatus(302);
            }

            // Lock selected copy and re-check overlap to prevent race conditions
            $lockCopyStmt = $db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
            $lockCopyStmt->bind_param('i', $selectedCopy['id']);
            $lockCopyStmt->execute();
            $lockCopyStmt->close();

            // Race condition check to prevent double-booking
            $overlapCopyStmt = $db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ? AND attivo = 1
                AND stato IN ('in_corso','da_ritirare','prenotato','in_ritardo')
                AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                LIMIT 1
            ");
            $overlapCopyStmt->bind_param('iss', $selectedCopy['id'], $data_scadenza, $data_prestito);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=no_copies_available')->withStatus(302);
            }

            $processedBy = null;
            if (isset($_SESSION['user']['id'])) {
                $candidateId = (int) $_SESSION['user']['id'];
                if ($candidateId > 0) {
                    $staffCheck = $db->prepare("SELECT id FROM utenti WHERE id = ? AND tipo_utente IN ('staff','admin') LIMIT 1");
                    if ($staffCheck) {
                        $staffCheck->bind_param('i', $candidateId);
                        if ($staffCheck->execute()) {
                            $result = $staffCheck->get_result();
                            if ($result && $result->num_rows > 0) {
                                $processedBy = $candidateId;
                            }
                        }
                        $staffCheck->close();
                    }
                }
            }

            // Inserimento del prestito con copia_id. origine='diretto' (L5): questo
            // è l'unico percorso di creazione diretta da admin — senza il tag
            // esplicito la colonna resterebbe al default 'richiesta' e l'enum
            // 'diretto' non verrebbe mai scritto da nessuno.
            $stmt = $db->prepare("INSERT INTO prestiti
                (libro_id, copia_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, origine, sanzione, renewals, processed_by, note, attivo, pickup_deadline)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $data_restituzione = null;

            // Determine loan state based on date and "Consegna immediata" checkbox
            // - Future loans: always 'prenotato' (user will pick up when loan starts)
            // - Immediate loans with checkbox checked: 'in_corso' (book delivered now)
            // - Immediate loans with checkbox unchecked: 'da_ritirare' (user must confirm pickup)
            $consegnaImmediata = isset($data['consegna_immediata']) && $data['consegna_immediata'] === '1';
            $pickupDeadline = null;

            if ($isImmediateLoan) {
                if ($consegnaImmediata) {
                    // Book delivered immediately to user
                    $stato_prestito = 'in_corso';
                    $copyStatus = 'prestato';
                } else {
                    // User must come to pick up the book
                    $stato_prestito = 'da_ritirare';
                    $copyStatus = 'prenotato';
                    // Calculate pickup deadline from settings, basata su $today
                    // (timezone applicativo) per coerenza con la classificazione
                    // immediato/futuro ed evitare lo scarto di un giorno a mezzanotte.
                    $settingsRepo = new \App\Models\SettingsRepository($db);
                    $pickupDays = (int) ($settingsRepo->get('loans', 'pickup_expiry_days', '3') ?? 3);
                    $pickupDeadline = date('Y-m-d', strtotime("{$today} +{$pickupDays} days"));
                }
            } else {
                // Future loan - user will pick up when loan period starts
                $stato_prestito = 'prenotato';
                $copyStatus = 'prenotato';
            }

            $origine = 'diretto';
            $sanzione = 0.00;
            $renewals = 0;
            $attivo = 1;

            $stmt->bind_param(
                "iiisssssdiisis",
                $libro_id,
                $selectedCopy['id'],
                $utente_id,
                $data_prestito,
                $data_scadenza,
                $data_restituzione,
                $stato_prestito,
                $origine,
                $sanzione,
                $renewals,
                $processedBy,
                $note,
                $attivo,
                $pickupDeadline
            );
            if (!$stmt->execute()) {
                $db->rollback();
                throw new Exception(__('Errore durante la creazione del prestito: ') . $stmt->error);
            }
            $newLoanId = (int) $db->insert_id;
            $stmt->close();

            // Update copy status based on delivery mode
            $copyRepo->updateStatus($selectedCopy['id'], $copyStatus);



            // Commit della transazione
            $db->commit();

            // Allineamento disponibilità e validazione prestito
            try {
                $integrity = new DataIntegrity($db);
                $integrity->recalculateBookAvailability($libro_id);
                $validation = $integrity->validateAndUpdateLoan($newLoanId);
                if (!($validation['success'] ?? false)) {
                    SecureLogger::warning(__('Validazione prestito fallita (post-creazione)'), [
                        'loan_id' => $newLoanId,
                        'message' => $validation['message'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                SecureLogger::warning(__('DataIntegrity warning (store loan)'), ['error' => $e->getMessage()]);
            }

            // Create in-app notification for new loan
            try {
                $notificationService = new \App\Support\NotificationService($db);

                // Get book and user details for notification
                $infoStmt = $db->prepare("
                    SELECT l.titolo, CONCAT(u.nome, ' ', u.cognome) as utente_nome, p.stato
                    FROM prestiti p
                    JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                    JOIN utenti u ON p.utente_id = u.id
                    WHERE p.id = ?
                ");
                $infoStmt->bind_param('i', $newLoanId);
                $infoStmt->execute();
                $loanInfo = $infoStmt->get_result()->fetch_assoc();
                $infoStmt->close();

                if ($loanInfo) {
                    $notificationService->createNotification(
                        'general',
                        __('Nuovo prestito creato'),
                        sprintf(__('%s ha preso in prestito "%s"'), $loanInfo['utente_nome'], $loanInfo['titolo']),
                        url('/admin/loans'),
                        $newLoanId
                    );

                    // #14: an admin-created (origine='diretto') loan sent the borrower no
                    // confirmation, unlike the request→approve flow which always emails.
                    // Mirror approveLoan() for the scheduled states. 'in_corso' means the
                    // patron is at the desk right now, so no email is needed there.
                    if (($loanInfo['stato'] ?? '') === 'prenotato') {
                        $notificationService->sendLoanApprovedNotification($newLoanId);
                    } elseif (($loanInfo['stato'] ?? '') === 'da_ritirare') {
                        $notificationService->sendPickupReadyNotification($newLoanId);
                    }
                }
            } catch (\Throwable $e) {
                SecureLogger::warning(__('Notifica prestito fallita'), ['error' => $e->getMessage()]);
            }

            // Redirect to list page — PDF download triggered via JS if requested
            $scaricaPdf = isset($data['scarica_pdf']) && $data['scarica_pdf'] === '1';
            $redirectUrl = url('/admin/loans') . '?created=1';
            if ($consegnaImmediata && $scaricaPdf) {
                $redirectUrl .= '&pdf=' . (int) $newLoanId;
            }

            return $response->withHeader('Location', url($redirectUrl))->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            // Se il messaggio di errore contiene un riferimento a un prestito già
            // attivo. Il frammento è quello comune al vecchio testo ("...per questo
            // libro") e al SIGNAL reale dei trigger ("Esiste già un prestito attivo
            // e sovrapposto per questa copia.", L5): così un SIGNAL diventa il
            // redirect dedicato e non un 500.
            if (str_contains($e->getMessage(), 'Esiste già un prestito attivo')) {
                return $response->withHeader('Location', url('/admin/loans/create') . '?error=libro_in_prestito')->withStatus(302);
            } else {
                throw $e;
            }
        }
    }


    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $repo = new \App\Models\LoanRepository($db);
        $prestito = $repo->getById($id);
        if (!$prestito) {
            return $response->withStatus(404);
        }
        ob_start();
        require __DIR__ . '/../Views/prestiti/modifica_prestito.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\LoanRepository($db);
        $processedBy = $_SESSION['user']['id'] ?? null;
        // Whitelist esplicita dei campi modificabili per prevenire mass assignment.
        // libro_id è SOLO display (cambiarlo scollegherebbe la copia dal libro → I7);
        // data_restituzione/attivo/stato sono gestiti dal form "Registra Restituzione".
        $allowedFields = ['utente_id', 'data_prestito', 'data_scadenza'];
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                switch ($field) {
                    case 'utente_id':
                        $updateData[$field] = (int) $data[$field];
                        break;
                    case 'data_prestito':
                    case 'data_scadenza':
                        $updateData[$field] = $data[$field];
                        break;
                }
            }
        }
        $updateData['processed_by'] = $processedBy;

        // Lettura NON bloccante della riga corrente: serve libro_id per il lock
        // canonico (P3) e i valori correnti per completare i campi non inviati.
        $check = $db->prepare('SELECT attivo, data_restituzione, libro_id, utente_id, data_prestito, data_scadenza FROM prestiti WHERE id=?');
        $check->bind_param('i', $id);
        $check->execute();
        $current = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$current) {
            return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
        }
        // Rifiuta la modifica di un prestito CHIUSO: editarlo non deve riattivarlo
        // (I1/I2 — BUG1). Un prestito è chiuso se attivo=0 o data_restituzione valorizzata.
        // (Ri-verificato più sotto, sotto lock.)
        if ((int) $current['attivo'] === 0 || $current['data_restituzione'] !== null) {
            return $response->withHeader('Location', url('/admin/loans') . '?error=loan_closed')->withStatus(302);
        }

        $libroId = (int) $current['libro_id'];
        $newUserId = isset($updateData['utente_id']) ? (int) $updateData['utente_id'] : (int) $current['utente_id'];
        $newPrestito = (string) ($updateData['data_prestito'] ?? $current['data_prestito']);
        $newScadenza = (string) ($updateData['data_scadenza'] ?? $current['data_scadenza']);

        // Valida il range date (M6a): una scadenza precedente alla partenza
        // creerebbe un intervallo invertito che nessun predicato di overlap
        // intercetta. Errore dedicato, mai un 500. La parità (prestito a
        // giornata singola) è lecita: createReservation accetta end == start,
        // quindi un rifiuto strettamente esclusivo renderebbe immodificabili
        // i prestiti a giornata nati dal calendario utente.
        if (strtotime($newScadenza) === false || strtotime($newPrestito) === false
            || strtotime($newScadenza) < strtotime($newPrestito)) {
            return $response->withHeader('Location', url('/admin/loans') . '?error=invalid_dates')->withStatus(302);
        }

        $db->begin_transaction();
        try {
            // ORDINE DI LOCK CANONICO (P3): la riga `libri` PRIMA del prestito,
            // come store/renew/close. Niente filtro deleted_at: la modifica di un
            // prestito esistente deve poter procedere anche su libro soft-deleted
            // (stessa regola dei rientri in LoanRepository::close()).
            $lockBook = $db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            $lockBook->bind_param('i', $libroId);
            $lockBook->execute();
            $lockBook->get_result();
            $lockBook->close();

            // Lock del prestito e ri-verifica sotto lock: stato aperto invariato e
            // libro_id non cambiato (TOCTOU sulla lettura non bloccante iniziale).
            $lockLoan = $db->prepare('SELECT attivo, data_restituzione, libro_id, utente_id FROM prestiti WHERE id=? FOR UPDATE');
            $lockLoan->bind_param('i', $id);
            $lockLoan->execute();
            $locked = $lockLoan->get_result()->fetch_assoc();
            $lockLoan->close();
            if (!$locked || (int) $locked['attivo'] === 0 || $locked['data_restituzione'] !== null) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans') . '?error=loan_closed')->withStatus(302);
            }
            if ((int) $locked['libro_id'] !== $libroId) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans') . '?error=loan_update_failed')->withStatus(302);
            }

            // Se l'utente cambia, ri-esegui i controlli di store() (M6b): il campo
            // arriva da hidden field e senza ricontrolli permetterebbe di aggirare
            // idoneità e dup-check assegnando il prestito a un altro utente.
            if ($newUserId !== (int) $locked['utente_id']) {
                // Lock the target user's row before the eligibility + max-loans checks, so two
                // concurrent reassignments to the same user can't both pass the COUNT and
                // overshoot max_active_loans_per_user (store() takes the same lock — #12).
                $newUserLock = $db->prepare("SELECT id FROM utenti WHERE id = ? FOR UPDATE");
                $newUserLock->bind_param('i', $newUserId);
                $newUserLock->execute();
                $newUserLock->get_result();
                $newUserLock->close();

                $eligibilityError = \App\Support\LoanEligibility::checkUser($db, $newUserId);
                if ($eligibilityError !== null) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans') . '?error=' . $eligibilityError)->withStatus(302);
                }

                // Dup-check (libro, nuovo utente) sugli stati attivi, escludendo il
                // prestito in modifica — stesso predicato di store().
                $dupStmt = $db->prepare("
                    SELECT id FROM prestiti
                    WHERE libro_id = ? AND utente_id = ? AND id <> ? AND (
                        (attivo = 0 AND stato = 'pendente')
                        OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
                    )
                    FOR UPDATE
                ");
                $dupStmt->bind_param('iii', $libroId, $newUserId, $id);
                $dupStmt->execute();
                $hasDup = $dupStmt->get_result()->num_rows > 0;
                $dupStmt->close();
                if ($hasDup) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans') . '?error=duplicate_reservation')->withStatus(302);
                }

                // Also block a user who already holds an ACTIVE queue reservation for this
                // book (store()/approveLoan() enforce this too — #10). Without it a reassign
                // leaves the user with both a loan and a queue reservation for the same book,
                // the state the other paths forbid, which confuses queue promotion.
                $resDupStmt = $db->prepare("SELECT id FROM prenotazioni WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva' FOR UPDATE");
                $resDupStmt->bind_param('ii', $libroId, $newUserId);
                $resDupStmt->execute();
                $hasResDup = $resDupStmt->get_result()->num_rows > 0;
                $resDupStmt->close();
                if ($hasResDup) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans') . '?error=duplicate_reservation')->withStatus(302);
                }

                // Cap prestiti attivi del NUOVO utente (M6): stesso enforcement di
                // store() — senza, la riassegnazione admin aggira silenziosamente
                // max_active_loans_per_user.
                $maxLoans = (int) ((new \App\Models\SettingsRepository($db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
                if ($maxLoans > 0) {
                    $cntStmt = $db->prepare("SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')");
                    $cntStmt->bind_param('i', $newUserId);
                    $cntStmt->execute();
                    $cntResult = $cntStmt->get_result();
                    $activeCount = (int) ($cntResult ? $cntResult->fetch_row()[0] : 0);
                    $cntStmt->close();
                    if ($activeCount >= $maxLoans) {
                        $db->rollback();
                        return $response->withHeader('Location', url('/admin/loans') . '?error=max_loans_reached')->withStatus(302);
                    }
                }
            }

            // #11: if the loan is being RESCHEDULED, re-check the new window against
            // overlapping loans + queue reservations vs capacity (renew() does this — update()
            // used to accept any new dates and only recalc counters, silently extending a loan
            // over a queued reservation). Only when the dates actually change.
            if ($newPrestito !== (string) $current['data_prestito'] || $newScadenza !== (string) $current['data_scadenza']) {
                $ovlLoanStmt = $db->prepare("
                    SELECT COUNT(*) AS c FROM prestiti
                    WHERE libro_id = ? AND id != ? AND attivo = 1
                    AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                ");
                $ovlLoanStmt->bind_param('iiss', $libroId, $id, $newScadenza, $newPrestito);
                $ovlLoanStmt->execute();
                $ovlLoans = (int) ($ovlLoanStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $ovlLoanStmt->close();

                $ovlResStmt = $db->prepare("
                    SELECT COUNT(*) AS c FROM prenotazioni
                    WHERE libro_id = ? AND stato = 'attiva'
                    AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
                    AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
                ");
                $ovlResStmt->bind_param('iss', $libroId, $newScadenza, $newPrestito);
                $ovlResStmt->execute();
                $ovlRes = (int) ($ovlResStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $ovlResStmt->close();

                $totCopStmt = $db->prepare("SELECT COUNT(*) AS t FROM copie WHERE libro_id = ? AND stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento')");
                $totCopStmt->bind_param('i', $libroId);
                $totCopStmt->execute();
                $totCop = (int) ($totCopStmt->get_result()->fetch_assoc()['t'] ?? 0);
                $totCopStmt->close();

                // This loan holds 1 slot in the new window; if the rest would exceed capacity, reject.
                if (1 + $ovlLoans + $ovlRes > $totCop) {
                    $db->rollback();
                    return $response->withHeader('Location', url('/admin/loans') . '?error=no_copies_available')->withStatus(302);
                }
            }

            // update() del repository non tocca MAI i campi lifecycle (vedi il suo
            // docblock): qui passano solo utente/date/processed_by. I valori
            // effettivi (campo inviato oppure valore corrente) sono passati
            // esplicitamente: i default del repository (utente 0 / oggi) non
            // devono mai scattare su un campo assente dal form.
            $updateData['utente_id'] = $newUserId;
            $updateData['data_prestito'] = $newPrestito;
            $updateData['data_scadenza'] = $newScadenza;
            if (!$repo->update($id, $updateData)) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans') . '?error=loan_update_failed')->withStatus(302);
            }

            // Ricalcola la disponibilità (M6c): spostare le date di un 'prenotato'
            // attraverso oggi cambia l'occupazione corrente e lascerebbe
            // copie_disponibili/libri.stato stantii fino al prossimo evento.
            $integrity = new DataIntegrity($db);
            if (!$integrity->recalculateBookAvailability($libroId, insideTransaction: true)) {
                throw new \RuntimeException('Impossibile ricalcolare la disponibilità del libro.');
            }

            $db->commit();
        } catch (\mysqli_sql_exception $e) {
            $db->rollback();
            // A trigger SIGNAL (e.g. I7) surfaces here under STRICT mode — never a 500.
            \App\Support\SecureLogger::error('Loan update failed: ' . $e->getMessage());
            return $response->withHeader('Location', url('/admin/loans') . '?error=loan_update_failed')->withStatus(302);
        } catch (\Throwable $e) {
            $db->rollback();
            \App\Support\SecureLogger::error('Loan update failed: ' . $e->getMessage());
            return $response->withHeader('Location', url('/admin/loans') . '?error=loan_update_failed')->withStatus(302);
        }
        return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
    }
    public function close(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\LoanRepository($db);
        // false = prestito inesistente o non chiudibile (guardia di stato H1):
        // segnala invece di fingere il successo.
        if (!$repo->close($id)) {
            return $response->withHeader('Location', url('/admin/loans') . '?error=loan_not_closable')->withStatus(302);
        }
        return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
    }

    public function returnForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        // Recupera i dati del prestito
        $stmt = $db->prepare("
            SELECT prestiti.id, prestiti.libro_id, libri.titolo, prestiti.utente_id,
                   CONCAT(utenti.nome, ' ', utenti.cognome) as utente_nome,
                   utenti.email as utente_email, utenti.telefono as utente_telefono,
                   prestiti.data_prestito, prestiti.data_scadenza, prestiti.data_restituzione,
                   prestiti.stato, prestiti.note
            FROM prestiti
            LEFT JOIN libri ON prestiti.libro_id = libri.id AND libri.deleted_at IS NULL
            LEFT JOIN utenti ON prestiti.utente_id = utenti.id
            WHERE prestiti.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $prestito = $result->fetch_assoc();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../Views/prestiti/restituito_prestito.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function processReturn(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        $nuovo_stato = $data['stato'] ?? '';
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $redirectTo = $this->sanitizeRedirect($data['redirect_to'] ?? null);

        // 'in_ritardo' NON è più un esito di restituzione: un rientro tardivo è
        // 'restituito' + restituito_in_ritardo=1 (I4). Gli unici esiti sono questi.
        $allowed_status = ['restituito', 'manutenzione', 'in_restauro', 'perso', 'danneggiato'];
        if (!in_array($nuovo_stato, $allowed_status)) {
            return $response->withHeader('Location', url('/admin/loans/returned/' . $id) . '?error=invalid_status')->withStatus(302);
        }

        // La riparazione NON è un esito di prestito: `prestiti.stato` non ha stati
        // 'manutenzione'/'in_restauro'. Un rientro-con-riparazione chiude il prestito
        // come 'restituito' (a tutti gli effetti il libro è tornato) e devia la COPIA
        // verso lo stato di riparazione (gestito dal match più sotto). Disaccoppiare
        // l'esito-prestito dallo stato-copia tiene coerente il flusso prestiti.
        $loan_stato = in_array($nuovo_stato, ['manutenzione', 'in_restauro'], true)
            ? 'restituito'
            : $nuovo_stato;

        // "Oggi" nel timezone applicativo (M9): con date() (TZ processo, spesso
        // UTC) un rientro poco dopo mezzanotte verrebbe datato al giorno prima.
        $data_restituzione = \App\Support\DateHelper::today();

        // Avvia transazione
        $db->begin_transaction();
        try {
            // ORDINE DI LOCK CANONICO (P3): determina il libro del prestito con una
            // lettura NON bloccante, poi blocca la riga `libri` PRIMA e infine quella
            // del prestito — stesso ordine di store/approveLoan/renew/close per
            // evitare deadlock con le approvazioni concorrenti sullo stesso libro.
            $lookup = $db->prepare('SELECT libro_id FROM prestiti WHERE id = ?');
            $lookup->bind_param('i', $id);
            $lookup->execute();
            $lrow = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$lrow) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/returned/' . $id) . '?error=not_returnable')->withStatus(302);
            }
            $libro_id = (int) $lrow['libro_id'];

            // Lock della riga `libri`. NIENTE filtro deleted_at (come in
            // LoanRepository::close()): la restituzione deve sempre poter procedere
            // anche su libro soft-deleted — la regola soft-delete governa
            // prestabilità/visibilità, non i rientri.
            $lockBook = $db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            $lockBook->bind_param('i', $libro_id);
            $lockBook->execute();
            $lockBook->get_result();
            $lockBook->close();

            // Recupera e BLOCCA il prestito: solo un prestito ancora aperto
            // (attivo=1, in_corso/in_ritardo) è restituibile (BUG4/D10 — niente
            // doppia elaborazione, niente TypeError su copia_id nullo).
            $stmt = $db->prepare("
                SELECT libro_id, copia_id, stato, data_scadenza
                FROM prestiti
                WHERE id = ? AND attivo = 1 AND stato IN ('in_corso','in_ritardo')
                FOR UPDATE
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                return $response->withHeader('Location', url('/admin/loans/returned/' . $id) . '?error=not_returnable')->withStatus(302);
            }

            // Re-verifica TOCTOU: la lettura iniziale era non bloccante per
            // preservare l'ordine di lock canonico; se libro_id fosse cambiato nel
            // frattempo avremmo lockato (e ricalcolato) il libro sbagliato.
            if ((int) $loan['libro_id'] !== $libro_id) {
                throw new \RuntimeException('libro_id del prestito cambiato durante il lock (TOCTOU).');
            }

            $copia_id = $loan['copia_id'];

            // Ritardo: un rientro oltre la scadenza è 'restituito' + flag (I4/BUG5).
            // Confronto lessicografico Y-m-d sicuro. Significativo solo per 'restituito'.
            $scadenza = (string) ($loan['data_scadenza'] ?? '');
            $ritardo = ($loan_stato === 'restituito' && $scadenza !== '' && $scadenza < $data_restituzione) ? 1 : 0;

            // Aggiorna il prestito (state-guard sull'attivo per evitare doppie restituzioni)
            $stmt = $db->prepare("UPDATE prestiti SET stato = ?, data_restituzione = ?, note = ?, attivo = 0, restituito_in_ritardo = ? WHERE id = ? AND attivo = 1");
            $stmt->bind_param("sssii", $loan_stato, $data_restituzione, $note, $ritardo, $id);
            $stmt->execute();
            $loanAffected = $stmt->affected_rows;
            $stmt->close();
            if ($loanAffected < 1) {
                // Un'altra richiesta ha già chiuso questo prestito tra la SELECT e l'UPDATE.
                throw new \RuntimeException('Prestito non più restituibile (race).');
            }

            // Mappa esito-rientro → stato copia (form di RESTITUZIONE). Le due voci
            // di riparazione chiudono il prestito come 'restituito' ($loan_stato) ma
            // portano la copia fuori circolazione: il ricalcolo disponibilità la
            // esclude e la preserva (DataIntegrity: WHERE stato IN disponibile/
            // prestato/prenotato), così resta in riparazione finché un operatore non
            // la rimette 'disponibile' (CopyController::updateCopy).
            $copia_stato = match ($nuovo_stato) {
                'restituito'   => 'disponibile',
                'manutenzione' => 'manutenzione',
                'in_restauro'  => 'in_restauro',
                'perso'        => 'perso',
                'danneggiato'  => 'danneggiato',
                default        => 'disponibile'
            };

            // Aggiorna lo stato della copia — solo se il prestito porta una copia
            // (updateStatus ha firma int non-nullable: un copia_id nullo darebbe TypeError).
            if ($copia_id !== null) {
                $copyRepo = new \App\Models\CopyRepository($db);
                $copyRepo->updateStatus((int) $copia_id, $copia_stato);
            }

            $integrity = new DataIntegrity($db);

            // Se copia torna disponibile, gestisci notifiche e riassegnazione coda
            if ($copia_stato === 'disponibile') {
                // Wishlist availability notifications are DEFERRED until after the
                // commit (#157): firing them here, inside the transaction, could
                // e-mail users about availability that a later rollback would undo.
                $notifyWishlistForLibroId = $libro_id;

                // Case 3: Reassign returned copy to next waiting reservation (NEW SYSTEM - prestiti table)
                // Niente catch locale qui: una riassegnazione fallita condivide questa
                // transazione e deve propagare al catch esterno per il rollback
                // dell'intera restituzione, evitando il commit di uno stato parziale
                // (CRITICAL #157). Il servizio rilancia in transazione esterna.
                if ($copia_id !== null) {
                    $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                    $reassignmentService->setExternalTransaction(true);
                    $reassignmentService->reassignOnReturn((int) $copia_id);
                }

                // Processa prenotazioni attive per questo libro (Future/Scheduled
                // reservations). Loop finché la capacità liberata converte la
                // prossima prenotazione in coda: una restituzione può liberare più
                // slot di uno (stesso pattern degli altri release-path, D5/BUG10).
                $reservationManager = new \App\Controllers\ReservationManager($db);
                $reservationManager->setExternalTransaction(true); // TXN-003: siamo già in transazione
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libro_id); $promoGuard++) {
                    // continua a promuovere finché una prenotazione converte
                }
            } elseif ($copia_id !== null) {
                // Esito perso/danneggiato/riparazione (M1): la copia esce dalla
                // circolazione, ma eventuali ALTRI prestiti futuri sulla stessa
                // copia (prenotato/da_ritirare non sovrapposti, leciti) resterebbero
                // agganciati a una copia morta. Riassegnali subito a un'altra copia,
                // come fa CopyController::updateCopy per lo stesso evento.
                $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                $reassignmentService->setExternalTransaction(true);
                // A lost copy can hold MORE THAN ONE non-overlapping future reservation
                // (period-based pre-booking, see the comment above). reassignOnCopyLost()
                // handles a single hold, so loop until none is left pointing at the dead
                // copy — each call either reassigns the hold to another copy or nulls its
                // copia_id, so it always makes progress (bounded guard for safety).
                for ($reassignGuard = 0; $reassignGuard < 1000; $reassignGuard++) {
                    $stillHeld = $db->query(
                        "SELECT 1 FROM prestiti WHERE copia_id = " . (int) $copia_id
                        . " AND stato IN ('prenotato','da_ritirare') AND attivo = 1 LIMIT 1"
                    );
                    if (!$stillHeld || $stillHeld->num_rows === 0) {
                        break;
                    }
                    $reassignmentService->reassignOnCopyLost((int) $copia_id);
                }
            }

            // Ricalcola le copie disponibili DOPO l'eventuale riassegnazione/conversione
            // delle prenotazioni: solo così copie_disponibili e libri.stato riflettono lo
            // stato finale e un libro restituito torna correttamente prestabile (TXN-002,
            // TXN-005, A2). insideTransaction:true mantiene l'atomicità della transazione.
            $integrity->recalculateBookAvailability($libro_id, insideTransaction: true);

            $db->commit();

            // Send deferred notifications after commit. Each action is isolated
            // in its own try/catch: the data is already durably committed, so a
            // failure in one flush must NOT prevent the others — otherwise a
            // single send error would silently drop notifications that were
            // already queued and committed.
            // Wishlist availability: only now that the return is durably
            // committed do we tell wishlist watchers the book is back (#157).
            if (isset($notifyWishlistForLibroId)) {
                try {
                    (new NotificationService($db))->notifyWishlistBookAvailability($notifyWishlistForLibroId);
                } catch (\Throwable $e) {
                    SecureLogger::warning('Wishlist availability notification failed', ['error' => $e->getMessage()]);
                }
            }
            if (isset($reassignmentService)) {
                try {
                    $reassignmentService->flushDeferredNotifications();
                } catch (\Throwable $e) {
                    SecureLogger::warning('Reassignment deferred flush failed', ['error' => $e->getMessage()]);
                }
            }
            // P2: la conversione di una prenotazione schedulata è avvenuta in
            // transazione esterna → la notifica reservation_book_available è stata
            // accodata e va inviata ora, dopo il commit.
            if (isset($reservationManager)) {
                try {
                    $reservationManager->flushDeferredNotifications();
                } catch (\Throwable $e) {
                    SecureLogger::warning('Reservation deferred flush failed', ['error' => $e->getMessage()]);
                }
            }

            // Conferma di restituzione all'utente (GAP-1) — solo per restituzioni
            // effettive, non per copie segnate perse/danneggiate.
            if ($loan_stato === 'restituito') {
                try {
                    (new NotificationService($db))->sendLoanReturnedNotification($id);
                } catch (\Throwable $e) {
                    SecureLogger::warning('Loan returned notification failed', ['loan_id' => $id, 'error' => $e->getMessage()]);
                }
            }
            $_SESSION['success_message'] = __('Prestito aggiornato correttamente.');
            $successUrl = $redirectTo ?? (url('/admin/loans') . '?updated=1');
            return $response->withHeader('Location', url($successUrl))->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Errore elaborazione restituzione'), ['loan_id' => $id, 'error' => $e->getMessage()]);
            if ($redirectTo) {
                $separator = strpos($redirectTo, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($redirectTo . $separator . 'error=update_failed'))->withStatus(302);
            }
            return $response->withHeader('Location', url('/admin/loans/returned/' . $id) . '?error=update_failed')->withStatus(302);
        }
    }

    /**
     * Return a loan identified by the physical copy's code (numero_inventario).
     *
     * Finds the single ACTIVE (attivo=1, in_corso/in_ritardo) loan whose copy has
     * the given inventory code, then closes it through the SAME canonical return
     * path used by the return button (processReturn) — no duplicated return logic.
     */
    public function returnByCode(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        $code = trim((string) ($data['numero_inventario'] ?? ''));
        if ($code === '') {
            $_SESSION['error_message'] = __('Inserisci un codice inventario.');
            return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
        }

        // Resolve the active loan for this copy code. numero_inventario is UNIQUE and
        // only one loan per copy can be active at a time, so at most one row is
        // expected; the ambiguity guard is defence-in-depth. `copie` has no
        // deleted_at — the soft-delete guard lives on the joined `libri` row.
        $stmt = $db->prepare("
            SELECT p.id
            FROM prestiti p
            JOIN copie c ON c.id = p.copia_id
            JOIN libri l ON l.id = c.libro_id
            WHERE c.numero_inventario = ?
              AND l.deleted_at IS NULL
              AND p.attivo = 1
              AND p.stato IN ('in_corso','in_ritardo')
        ");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($rows) === 0) {
            $_SESSION['error_message'] = __('Nessun prestito attivo trovato per questo codice copia.');
            return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
        }
        if (count($rows) > 1) {
            $_SESSION['error_message'] = __('Trovati più prestiti attivi per questo codice: gestiscili manualmente.');
            return $response->withHeader('Location', url('/admin/loans'))->withStatus(302);
        }

        $loanId = (int) $rows[0]['id'];

        // Delegate to the canonical return handler: mark as 'restituito' and send the
        // operator back to the loans list on both success and failure.
        $delegated = $request->withParsedBody([
            'stato'       => 'restituito',
            'redirect_to' => '/admin/loans',
            'csrf_token'  => $data['csrf_token'] ?? '',
        ]);
        return $this->processReturn($delegated, $response, $db, $loanId);
    }

    public function details(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $stmt = $db->prepare("
            SELECT prestiti.*, libri.titolo AS libro_titolo,
                   CONCAT(utenti.nome, ' ', utenti.cognome) AS utente_nome,
                   utenti.email AS utente_email,
                   CONCAT(staff.nome, ' ', staff.cognome) AS processed_by_name
            FROM prestiti 
            LEFT JOIN libri ON prestiti.libro_id = libri.id AND libri.deleted_at IS NULL
            LEFT JOIN utenti ON prestiti.utente_id = utenti.id
            LEFT JOIN utenti staff ON prestiti.processed_by = staff.id
            WHERE prestiti.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            return $response->withStatus(404);
        }
        $prestito = $result->fetch_assoc();
        $stmt->close();

        ob_start();
        require __DIR__ . '/../Views/prestiti/dettagli_prestito.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Generate and download PDF receipt for a loan
     *
     * @param Request $request PSR-7 request
     * @param Response $response PSR-7 response
     * @param mysqli $db Database connection
     * @param int $id Loan ID
     * @return Response PDF file download
     */
    public function downloadPdf(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }

        try {
            $generator = new \App\Support\LoanPdfGenerator($db);
            $pdfContent = $generator->generate($id);

            $filename = 'prestito_' . $id . '_' . date('Ymd') . '.pdf';

            $response->getBody()->write($pdfContent);
            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"; filename*=UTF-8''" . rawurlencode($filename))
                ->withHeader('Content-Length', (string) strlen($pdfContent))
                ->withHeader('Cache-Control', 'no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');

        } catch (\Throwable $e) {
            SecureLogger::error(__('Errore generazione PDF prestito'), [
                'loan_id' => $id,
                'error' => $e->getMessage()
            ]);
            return $response
                ->withHeader('Location', url('/admin/loans/details/' . $id) . '?error=pdf_failed')
                ->withStatus(302);
        }
    }

    public function renew(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        // CSRF validated by CsrfMiddleware

        $redirectTo = $this->sanitizeRedirect($data['redirect_to'] ?? null);

        // Get current loan
        $stmt = $db->prepare("
            SELECT id, libro_id, utente_id, data_scadenza, stato, renewals, attivo
            FROM prestiti
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_not_found'))->withStatus(302);
        }

        $loan = $result->fetch_assoc();
        $stmt->close();

        // Check if loan is active
        if ((int) $loan['attivo'] !== 1) {
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_not_active'))->withStatus(302);
        }

        // Check if loan is overdue
        $isLate = ($loan['stato'] === 'in_ritardo');
        if ($isLate) {
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_overdue'))->withStatus(302);
        }

        // Un prestito non ancora ritirato (da_ritirare/prenotato/pendente) non è
        // rinnovabile: il rinnovo estende la scadenza di un prestito GIÀ in corso (F2).
        if (in_array($loan['stato'], ['da_ritirare', 'prenotato', 'pendente'], true)) {
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_not_picked_up'))->withStatus(302);
        }

        // Borrower eligibility: a suspended / card-expired user must not keep the book via
        // repeated renewals. This is the single-point-eligibility invariant that store(),
        // update()'s reassignment and approveLoan() all enforce — renew() was the one
        // benefit-conferring write path that skipped it.
        $eligibilityError = \App\Support\LoanEligibility::checkUser($db, (int) $loan['utente_id']);
        if ($eligibilityError !== null) {
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=' . $eligibilityError))->withStatus(302);
        }

        // Check renewal limit (max_renewals configurabile, default 3) — F2
        $settingsRepo = new \App\Models\SettingsRepository($db);
        $maxRenewals = (int) ($settingsRepo->get('loans', 'max_renewals', '3') ?? 3);
        if ($maxRenewals < 0) {
            $maxRenewals = 3;
        }
        $currentRenewals = (int) $loan['renewals'];
        if ($currentRenewals >= $maxRenewals) {
            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=max_renewals'))->withStatus(302);
        }

        // Durata del rinnovo dalla setting di durata prestito (M5b): il vecchio
        // '+14 days' hardcoded ignorava la configurazione dell'admin.
        $renewDays = (int) ($settingsRepo->get('loans', 'loan_duration_days', '30') ?? 30);
        if ($renewDays < 1) {
            $renewDays = 30;
        }

        // Calculate proposed new due date for conflict checking
        $currentDueDate = $loan['data_scadenza'];
        $proposedNewDueDate = date('Y-m-d', strtotime($currentDueDate . " +{$renewDays} days"));
        $libroId = (int) $loan['libro_id'];

        // Start transaction BEFORE conflict check to prevent race conditions
        $db->begin_transaction();

        try {
            // ORDINE DI LOCK CANONICO (P3): la riga `libri` per prima (come store e
            // approveLoan), poi il prestito. $libroId proviene dalla lettura iniziale del
            // prestito (il libro di un prestito non cambia mai).
            $lockBookStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockBookStmt->bind_param('i', $libroId);
            $lockBookStmt->execute();
            $lockedBook = $lockBookStmt->get_result()->fetch_assoc();
            $lockBookStmt->close();

            // Soft-deleted or absent book: the FOR UPDATE returned no row. Stop
            // before renewing a loan for a title removed from the catalogue
            // (libri queries must honour deleted_at IS NULL).
            if (!$lockedBook) {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=book_not_found'))->withStatus(302);
            }

            // Lock the loan row to prevent concurrent renewals and re-validate under lock
            $lockLoanStmt = $db->prepare("
                SELECT id, attivo, stato, renewals, data_scadenza, libro_id, copia_id
                FROM prestiti WHERE id = ? FOR UPDATE
            ");
            $lockLoanStmt->bind_param('i', $id);
            $lockLoanStmt->execute();
            $lockedLoan = $lockLoanStmt->get_result()->fetch_assoc();
            $lockLoanStmt->close();

            // Re-validate loan state after acquiring lock (may have changed since initial fetch)
            if (!$lockedLoan || (int) $lockedLoan['attivo'] !== 1) {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_not_active'))->withStatus(302);
            }
            if ($lockedLoan['stato'] === 'in_ritardo') {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_overdue'))->withStatus(302);
            }
            if (in_array($lockedLoan['stato'], ['da_ritirare', 'prenotato', 'pendente'], true)) {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=loan_not_picked_up'))->withStatus(302);
            }
            if ((int) $lockedLoan['renewals'] >= $maxRenewals) {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=max_renewals'))->withStatus(302);
            }

            // Use the locked values for calculations (in case they changed)
            $currentDueDate = $lockedLoan['data_scadenza'];
            $proposedNewDueDate = date('Y-m-d', strtotime($currentDueDate . " +{$renewDays} days"));
            $currentRenewals = (int) $lockedLoan['renewals'];
            // Refresh libro_id from locked loan to ensure consistency (la riga `libri` è
            // già stata lockata sopra con lo stesso id all'inizio della transazione).
            $libroId = (int) $lockedLoan['libro_id'];

            // Check if renewal conflicts with other reservations/loans
            // Extension is allowed if:
            // 1. No other reservations/loans overlap with the extension period, OR
            // 2. Another copy is available for those overlapping reservations
            $extensionStart = $currentDueDate; // Extension starts from current due date
            $extensionEnd = $proposedNewDueDate;

            // Count other active loans/reservations overlapping with extension period (excluding current loan)
            $conflictStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ? AND id != ? AND attivo = 1
                AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo')
                AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
            ");
            $conflictStmt->bind_param('iiss', $libroId, $id, $extensionEnd, $extensionStart);
            $conflictStmt->execute();
            $overlappingLoans = (int) ($conflictStmt->get_result()->fetch_assoc()['count'] ?? 0);
            $conflictStmt->close();

            // Count overlapping prenotazioni (queue-based reservations)
            $resConflictStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prenotazioni
                WHERE libro_id = ? AND stato = 'attiva'
                AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) <= ?
                AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione)) >= ?
            ");
            $resConflictStmt->bind_param('iss', $libroId, $extensionEnd, $extensionStart);
            $resConflictStmt->execute();
            $overlappingReservations = (int) ($resConflictStmt->get_result()->fetch_assoc()['count'] ?? 0);
            $resConflictStmt->close();

            // Count total lendable copies
            $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')");
            $totalCopiesStmt->bind_param('i', $libroId);
            $totalCopiesStmt->execute();
            $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $totalCopiesStmt->close();

            // Check if extension is possible:
            // - Count this loan as 1 (it will occupy a slot in the extension period)
            // - Add other overlapping loans + reservations
            // - If total > totalCopies, extension not possible (would block another reservation)
            // Note: Use > not >= because the current loan already has its assigned copy
            $totalOccupied = 1 + $overlappingLoans + $overlappingReservations; // 1 = current loan being renewed
            if ($totalOccupied > $totalCopies) {
                $db->rollback();
                $errorUrl = $redirectTo ?? url('/admin/loans');
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', url($errorUrl . $separator . 'error=extension_conflicts'))->withStatus(302);
            }

            // Overlap-check sulla COPIA del prestito rinnovato (M5c): il check di
            // capacità sopra è a livello LIBRO e con ≥2 copie non vede un impegno
            // schedulato proprio sulla stessa copia nel periodo di estensione.
            // Predicato #157 completo (prestito attivo O pendente-con-copia),
            // escludendo il prestito corrente: senza questo check l'UPDATE farebbe
            // scattare il SIGNAL del trigger → catch-all → errore generico invece
            // del conflitto di estensione dedicato.
            $copiaId = $lockedLoan['copia_id'] !== null ? (int) $lockedLoan['copia_id'] : null;
            if ($copiaId !== null) {
                $copyOvlStmt = $db->prepare("
                    SELECT 1 FROM prestiti
                    WHERE copia_id = ? AND id <> ?
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                    AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                          OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                    LIMIT 1
                ");
                $copyOvlStmt->bind_param('iiss', $copiaId, $id, $extensionEnd, $extensionStart);
                $copyOvlStmt->execute();
                $hasCopyOverlap = (bool) $copyOvlStmt->get_result()->fetch_row();
                $copyOvlStmt->close();
                if ($hasCopyOverlap) {
                    $db->rollback();
                    $errorUrl = $redirectTo ?? url('/admin/loans');
                    $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                    return $response->withHeader('Location', url($errorUrl . $separator . 'error=extension_conflicts'))->withStatus(302);
                }
            }

            // All checks passed - proceed with renewal
            $newDueDate = $proposedNewDueDate;
            $newRenewalCount = $currentRenewals + 1;

            // Update loan — azzera pickup_deadline così il cron checkExpiredPickups
            // non scade un prestito già in corso e rinnovato (F2). Azzera anche i
            // flag di notifica (M5a): la nuova scadenza deve ricevere il suo
            // promemoria e l'eventuale sollecito, altrimenti un prestito rinnovato
            // dopo il warning non verrebbe mai più avvisato.
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET data_scadenza = ?, renewals = ?, pickup_deadline = NULL,
                    warning_sent = 0, overdue_notification_sent = 0
                WHERE id = ?
            ");
            $updateStmt->bind_param("sii", $newDueDate, $newRenewalCount, $id);
            $updateStmt->execute();
            $updateStmt->close();

            // Validate and update loan status (insideTransaction: true — TXN-001:
            // siamo dentro la transazione aperta a inizio renew())
            $integrity = new DataIntegrity($db);
            $validationResult = $integrity->validateAndUpdateLoan($id, insideTransaction: true);
            if (!$validationResult['success']) {
                SecureLogger::warning(__('Validazione prestito fallita'), ['loan_id' => $id, 'message' => $validationResult['message']]);
            }

            $db->commit();
            $_SESSION['success_message'] = __('Prestito rinnovato correttamente. Nuova scadenza: %s', format_date($newDueDate, false, '/'));

            $successUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($successUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($successUrl . $separator . 'renewed=1'))->withStatus(302);

        } catch (\Throwable $e) {
            $db->rollback();
            SecureLogger::error(__('Rinnovo prestito fallito'), ['loan_id' => $id, 'error' => $e->getMessage()]);

            $errorUrl = $redirectTo ?? url('/admin/loans');
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', url($errorUrl . $separator . 'error=renewal_failed'))->withStatus(302);
        }
    }

    private function sanitizeRedirect(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $trimmed = str_replace(["\r", "\n"], '', $path);
        if (strpos($trimmed, '://') !== false) {
            return null; // avoid absolute URLs or protocols
        }

        if (!str_starts_with($trimmed, '/')) {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return null;
        }

        // Collapse multiple slashes to avoid traversal quirks
        $normalized = preg_replace('#/+#', '/', $trimmed);
        return $normalized ?: null;
    }

    /**
     * Export loans to CSV file download
     *
     * Generates a UTF-8 CSV file with loan data, optionally filtered
     * by status. Supports multiple states via comma-separated query param.
     *
     * @param Request $request PSR-7 request with optional ?stati=in_corso,restituito
     * @param Response $response PSR-7 response
     * @param mysqli $db Database connection
     * @return Response CSV file download with Content-Disposition header
     */
    public function exportCsv(Request $request, Response $response, mysqli $db): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }

        // Get status filter from query params
        $queryParams = $request->getQueryParams();
        $statiParam = $queryParams['stati'] ?? '';
        $validStates = ['pendente', 'prenotato', 'da_ritirare', 'in_corso', 'in_ritardo', 'restituito', 'perso', 'danneggiato', 'annullato', 'scaduto'];

        // Parse and validate requested states
        $requestedStates = [];
        if (!empty($statiParam)) {
            $requestedStates = array_filter(
                explode(',', $statiParam),
                fn($s) => in_array(trim($s), $validStates, true)
            );
            $requestedStates = array_map('trim', $requestedStates);
        }

        // Build the WHERE clause for status filter
        $whereClause = '';
        $params = [];
        if (!empty($requestedStates)) {
            $placeholders = implode(',', array_fill(0, count($requestedStates), '?'));
            $whereClause = "WHERE p.stato IN ($placeholders)";
            $params = $requestedStates;
        }

        // Query loans with full details
        $sql = "SELECT
                    p.id,
                    l.titolo AS libro_titolo,
                    CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                    u.email AS utente_email,
                    p.data_prestito,
                    p.data_scadenza,
                    p.data_restituzione,
                    p.stato,
                    p.renewals,
                    p.note,
                    c.numero_inventario AS copia_inventario,
                    CONCAT(staff.nome, ' ', staff.cognome) AS processed_by_name
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                LEFT JOIN utenti u ON p.utente_id = u.id
                LEFT JOIN copie c ON p.copia_id = c.id
                LEFT JOIN utenti staff ON p.processed_by = staff.id
                $whereClause
                ORDER BY p.id DESC";

        // Execute query with prepared statement if we have filters
        if (!empty($params)) {
            $stmt = $db->prepare($sql);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        } else {
            $result = $db->query($sql);
            if ($result === false) {
                SecureLogger::error(__('Errore export CSV'), ['error' => $db->error]);
            }
        }
        $loans = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $loans[] = $row;
            }
            $result->free();
        }

        // Generate CSV content
        $output = fopen('php://temp', 'r+');

        // Formula-injection protection is handled by the writer (formulaPrefix "'"):
        // fields starting with =, +, -, @ are prefixed to neutralise CSV injection.
        $writer = Csv::writerToStream($output, ',', "'");

        // CSV header with i18n
        $writer->insertOne([
            __('ID'),
            __('Libro'),
            __('Utente'),
            __('Email'),
            __('Data Prestito'),
            __('Data Scadenza'),
            __('Data Restituzione'),
            __('Stato'),
            __('Rinnovi'),
            __('N. Inventario'),
            __('Elaborato da'),
            __('Note')
        ]);

        // Status translations (shared helper in helpers.php)

        // CSV data rows
        foreach ($loans as $loan) {
            $stato = translate_loan_status($loan['stato']);
            $writer->insertOne([
                $loan['id'],
                $loan['libro_titolo'] ?? '',
                $loan['utente_nome'] ?? '',
                $loan['utente_email'] ?? '',
                $loan['data_prestito'] ? format_date($loan['data_prestito'], false, '/') : '',
                $loan['data_scadenza'] ? format_date($loan['data_scadenza'], false, '/') : '',
                $loan['data_restituzione'] ? format_date($loan['data_restituzione'], false, '/') : '',
                $stato,
                $loan['renewals'] ?? 0,
                $loan['copia_inventario'] ?? '',
                $loan['processed_by_name'] ?? '',
                $loan['note'] ?? ''
            ]);
        }

        // Get CSV content
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        // Prepend UTF-8 BOM for Excel compatibility with accented characters
        $csvContent = "\xEF\xBB\xBF" . $csvContent;

        // Generate filename with date
        $filename = 'prestiti_' . date('Y-m-d_His') . '.csv';

        // Return CSV response
        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    private function guardStaffAccess(Response $response): ?Response
    {
        $role = $_SESSION['user']['tipo_utente'] ?? '';
        if (!in_array($role, ['admin', 'staff'], true)) {
            return $response->withStatus(403);
        }
        return null;
    }
}
