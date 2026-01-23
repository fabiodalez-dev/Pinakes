<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
            WHERE p.stato = 'pendente'
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

    public function createForm(Request $request, Response $response): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
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

        // Se le date sono vuote, usa i valori di default (server timezone for consistency)
        if (empty($data_prestito)) {
            $data_prestito = date('Y-m-d');
        }
        if (empty($data_scadenza)) {
            // Default to 1 month after the loan start date (not from today)
            $data_scadenza = date('Y-m-d', strtotime($data_prestito . ' +1 month'));
        }

        if ($utente_id <= 0 || $libro_id <= 0) {
            return $response->withHeader('Location', '/admin/prestiti/crea?error=missing_fields')->withStatus(302);
        }

        // Verifica che la data di scadenza sia successiva alla data di prestito
        if (strtotime($data_scadenza) <= strtotime($data_prestito)) {
            return $response->withHeader('Location', '/admin/prestiti/crea?error=invalid_dates')->withStatus(302);
        }

        $db->begin_transaction();

        try {
            // Lock the book record to prevent concurrent updates
            $lockStmt = $db->prepare("SELECT id, stato, copie_disponibili FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libro_id);
            $lockStmt->execute();
            $bookResult = $lockStmt->get_result();
            $book = $bookResult ? $bookResult->fetch_assoc() : null;
            $lockStmt->close();

            if (!$book) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti/crea?error=book_not_found')->withStatus(302);
            }

            // Case 8: Prevent multiple active reservations/loans for the same book by the same user
            // Moved inside transaction with FOR UPDATE to prevent race conditions
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
                return $response->withHeader('Location', '/admin/prestiti/crea?error=duplicate_reservation')->withStatus(302);
            }
            $dupStmt->close();

            // Check if loan starts today (immediate loan) or in the future (scheduled loan)
            // Normalize to date-only to handle potential datetime inputs safely
            // Use date() consistently for server timezone
            $today = date('Y-m-d');
            $loanStartDate = date('Y-m-d', strtotime($data_prestito));
            $isImmediateLoan = ($loanStartDate <= $today);

            // Select a copy for the loan
            // For IMMEDIATE loans: find a copy that is currently 'disponibile'
            // For FUTURE loans: find a copy that has no overlapping active loans in the requested period
            $copyRepo = new \App\Models\CopyRepository($db);
            $selectedCopy = null;

            if ($isImmediateLoan) {
                // For immediate loans, verify book-level availability (including prenotazioni)
                // Step 1: Count total lendable copies (exclude perso, danneggiato, manutenzione)
                $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')");
                $totalCopiesStmt->bind_param('i', $libro_id);
                $totalCopiesStmt->execute();
                $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $totalCopiesStmt->close();

                // Step 2: Count overlapping loans for the loan period
                $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ? AND attivo = 1
                AND stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                    AND data_prestito <= ? AND data_scadenza >= ?
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
                    return $response->withHeader('Location', '/admin/prestiti/crea?error=no_copies_available')->withStatus(302);
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
                        AND p.attivo = 1
                        AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                        AND p.data_prestito <= ?
                        AND p.data_scadenza >= ?
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
            } else {
                // For FUTURE loans, find a copy that has no overlapping active loans
                // Also verify book-level availability (considering prenotazioni)

                // Step 1: Count total lendable copies (exclude perso, danneggiato, manutenzione)
                $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')");
                $totalCopiesStmt->bind_param('i', $libro_id);
                $totalCopiesStmt->execute();
                $totalCopies = (int) ($totalCopiesStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $totalCopiesStmt->close();

                // Step 2: Count overlapping loans (approved states only)
                $loanCountStmt = $db->prepare("
                SELECT COUNT(*) as count FROM prestiti
                WHERE libro_id = ? AND attivo = 1
                AND stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                    AND data_prestito <= ? AND data_scadenza >= ?
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
                    return $response->withHeader('Location', '/admin/prestiti/crea?error=no_copies_available')->withStatus(302);
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
                        AND p.attivo = 1
                        AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                        AND p.data_prestito <= ?
                        AND p.data_scadenza >= ?
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
                return $response->withHeader('Location', '/admin/prestiti/crea?error=no_copies_available')->withStatus(302);
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
                AND data_prestito <= ? AND data_scadenza >= ?
                LIMIT 1
            ");
            $overlapCopyStmt->bind_param('iss', $selectedCopy['id'], $data_scadenza, $data_prestito);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti/crea?error=no_copies_available')->withStatus(302);
            }

            $processedBy = null;
            if (isset($_SESSION['user']['id'])) {
                $candidateId = (int) $_SESSION['user']['id'];
                if ($candidateId > 0) {
                    $staffCheck = $db->prepare('SELECT id FROM staff WHERE id = ? LIMIT 1');
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

            // Inserimento del prestito con copia_id
            $stmt = $db->prepare("INSERT INTO prestiti
                (libro_id, copia_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, sanzione, renewals, processed_by, note, attivo, pickup_deadline)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                    // Calculate pickup deadline from settings
                    $settingsRepo = new \App\Models\SettingsRepository($db);
                    $pickupDays = (int) ($settingsRepo->get('loans', 'pickup_expiry_days', '3') ?? 3);
                    $pickupDeadline = date('Y-m-d', strtotime("+{$pickupDays} days"));
                }
            } else {
                // Future loan - user will pick up when loan period starts
                $stato_prestito = 'prenotato';
                $copyStatus = 'prenotato';
            }

            $sanzione = 0.00;
            $renewals = 0;
            $attivo = 1;

            $stmt->bind_param(
                "iiissssdiisis",
                $libro_id,
                $selectedCopy['id'],
                $utente_id,
                $data_prestito,
                $data_scadenza,
                $data_restituzione,
                $stato_prestito,
                $sanzione,
                $renewals,
                $processedBy,
                $note,
                $attivo,
                $pickupDeadline
            );
            $stmt->execute();
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
                if (isset($newLoanId)) {
                    $integrity->validateAndUpdateLoan($newLoanId);
                }
            } catch (\Throwable $e) {
                SecureLogger::warning(__('DataIntegrity warning (store loan)'), ['error' => $e->getMessage()]);
            }

            // Create in-app notification for new loan
            if (isset($newLoanId)) {
                try {
                    $notificationService = new \App\Support\NotificationService($db);

                    // Get book and user details for notification
                    $infoStmt = $db->prepare("
                        SELECT l.titolo, CONCAT(u.nome, ' ', u.cognome) as utente_nome
                        FROM prestiti p
                        JOIN libri l ON p.libro_id = l.id
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
                            'Nuovo prestito creato',
                            sprintf('%s ha preso in prestito "%s"', $loanInfo['utente_nome'], $loanInfo['titolo']),
                            '/admin/prestiti',
                            $newLoanId
                        );
                    }
                } catch (\Throwable $e) {
                    SecureLogger::warning(__('Notifica prestito fallita'), ['error' => $e->getMessage()]);
                }
            }

            return $response->withHeader('Location', '/admin/prestiti?created=1')->withStatus(302);

        } catch (Exception $e) {
            $db->rollback();
            // Se il messaggio di errore contiene un riferimento a un prestito già attivo
            if (strpos($e->getMessage(), 'Esiste già un prestito attivo per questo libro') !== false) {
                return $response->withHeader('Location', '/admin/prestiti/crea?error=libro_in_prestito')->withStatus(302);
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
        // Whitelist esplicita dei campi modificabili per prevenire mass assignment
        // Note: data_restituzione e attivo sono gestiti dal form "Registra Restituzione" dedicato
        $allowedFields = ['libro_id', 'utente_id', 'data_prestito', 'data_scadenza'];
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                switch ($field) {
                    case 'libro_id':
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

        $repo->update($id, $updateData);
        return $response->withHeader('Location', '/admin/prestiti')->withStatus(302);
    }
    public function close(Request $request, Response $response, mysqli $db, int $id): Response
    {
        if ($guard = $this->guardStaffAccess($response)) {
            return $guard;
        }
        // CSRF validated by CsrfMiddleware
        $repo = new \App\Models\LoanRepository($db);
        $repo->close($id);
        return $response->withHeader('Location', '/admin/prestiti')->withStatus(302);
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
            LEFT JOIN libri ON prestiti.libro_id = libri.id
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

        $allowed_status = ['restituito', 'in_ritardo', 'perso', 'danneggiato'];
        if (!in_array($nuovo_stato, $allowed_status)) {
            return $response->withHeader('Location', '/admin/prestiti/restituito/' . $id . '?error=invalid_status')->withStatus(302);
        }

        $data_restituzione = date('Y-m-d');

        // Avvia transazione
        $db->begin_transaction();
        try {
            // Recupera libro_id e copia_id dal prestito
            $stmt = $db->prepare("SELECT libro_id, copia_id FROM prestiti WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $loan = $result->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $db->rollback();
                return $response->withHeader('Location', '/admin/prestiti?error=loan_not_found')->withStatus(302);
            }

            $libro_id = $loan['libro_id'];
            $copia_id = $loan['copia_id'];

            // Aggiorna il prestito
            $stmt = $db->prepare("UPDATE prestiti SET stato = ?, data_restituzione = ?, note = ?, attivo = 0 WHERE id = ?");
            $stmt->bind_param("sssi", $nuovo_stato, $data_restituzione, $note, $id);
            $stmt->execute();
            $stmt->close();

            // Mappa stato prestito → stato copia
            // Nota: questo è il form di RESTITUZIONE, quindi il libro torna sempre
            // 'in_ritardo' qui significa "restituito in ritardo", non "ancora in prestito"
            $copia_stato = match ($nuovo_stato) {
                'restituito' => 'disponibile',
                'in_ritardo' => 'disponibile',  // Restituito in ritardo = disponibile
                'perso' => 'perso',
                'danneggiato' => 'danneggiato',
                default => 'disponibile'
            };

            // Aggiorna lo stato della copia
            $copyRepo = new \App\Models\CopyRepository($db);
            $copyRepo->updateStatus($copia_id, $copia_stato);

            // Ricalcola le copie disponibili con controlli di integrità
            // Questo aggiornerà anche lo stato del libro basandosi sulle copie
            $integrity = new DataIntegrity($db);
            $integrity->recalculateBookAvailability($libro_id);

            // Valida e aggiorna lo stato del prestito
            $validationResult = $integrity->validateAndUpdateLoan($id);
            if (!$validationResult['success']) {
                SecureLogger::warning(__('Validazione prestito fallita'), ['loan_id' => $id, 'message' => $validationResult['message']]);
            }

            // Se copia torna disponibile, gestisci notifiche
            if ($copia_stato === 'disponibile') {
                // Notifica utenti con libro in wishlist
                $notificationService = new NotificationService($db);
                $notificationService->notifyWishlistBookAvailability($libro_id);

                // Case 3: Reassign returned copy to next waiting reservation (NEW SYSTEM - prestiti table)
                try {
                    $reassignmentService = new \App\Services\ReservationReassignmentService($db);
                    $reassignmentService->reassignOnReturn($copia_id);
                } catch (\Exception $e) {
                    SecureLogger::error(__('Riassegnazione copia fallita'), ['copia_id' => $copia_id, 'error' => $e->getMessage()]);
                }

                // Processa prenotazioni attive per questo libro (Future/Scheduled reservations)
                $reservationManager = new \App\Controllers\ReservationManager($db);
                $reservationManager->processBookAvailability($libro_id);
            }

            $db->commit();
            $_SESSION['success_message'] = __('Prestito aggiornato correttamente.');
            $successUrl = $redirectTo ?? '/admin/prestiti?updated=1';
            return $response->withHeader('Location', $successUrl)->withStatus(302);

        } catch (Exception $e) {
            $db->rollback();
            SecureLogger::error(__('Errore elaborazione restituzione'), ['loan_id' => $id, 'error' => $e->getMessage()]);
            if ($redirectTo) {
                $separator = strpos($redirectTo, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $redirectTo . $separator . 'error=update_failed')->withStatus(302);
            }
            return $response->withHeader('Location', '/admin/prestiti/restituito/' . $id . '?error=update_failed')->withStatus(302);
        }
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
            LEFT JOIN libri ON prestiti.libro_id = libri.id 
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
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_not_found')->withStatus(302);
        }

        $loan = $result->fetch_assoc();
        $stmt->close();

        // Check if loan is active
        if ((int) $loan['attivo'] !== 1) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_not_active')->withStatus(302);
        }

        // Check if loan is overdue
        $isLate = ($loan['stato'] === 'in_ritardo');
        if ($isLate) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_overdue')->withStatus(302);
        }

        // Check renewal limit
        $maxRenewals = 3;
        $currentRenewals = (int) $loan['renewals'];
        if ($currentRenewals >= $maxRenewals) {
            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=max_renewals')->withStatus(302);
        }

        // Calculate proposed new due date for conflict checking
        $currentDueDate = $loan['data_scadenza'];
        $proposedNewDueDate = date('Y-m-d', strtotime($currentDueDate . ' +14 days'));
        $libroId = (int) $loan['libro_id'];

        // Start transaction BEFORE conflict check to prevent race conditions
        $db->begin_transaction();

        try {
            // Lock the loan row FIRST to prevent concurrent renewals and match LoanRepository lock order
            $lockLoanStmt = $db->prepare("
                SELECT id, attivo, stato, renewals, data_scadenza, libro_id
                FROM prestiti WHERE id = ? FOR UPDATE
            ");
            $lockLoanStmt->bind_param('i', $id);
            $lockLoanStmt->execute();
            $lockedLoan = $lockLoanStmt->get_result()->fetch_assoc();
            $lockLoanStmt->close();

            // Re-validate loan state after acquiring lock (may have changed since initial fetch)
            if (!$lockedLoan || (int) $lockedLoan['attivo'] !== 1) {
                $db->rollback();
                $errorUrl = $redirectTo ?? '/admin/prestiti';
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_not_active')->withStatus(302);
            }
            if ($lockedLoan['stato'] === 'in_ritardo') {
                $db->rollback();
                $errorUrl = $redirectTo ?? '/admin/prestiti';
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $errorUrl . $separator . 'error=loan_overdue')->withStatus(302);
            }
            if ((int) $lockedLoan['renewals'] >= $maxRenewals) {
                $db->rollback();
                $errorUrl = $redirectTo ?? '/admin/prestiti';
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $errorUrl . $separator . 'error=max_renewals')->withStatus(302);
            }

            // Use the locked values for calculations (in case they changed)
            $currentDueDate = $lockedLoan['data_scadenza'];
            $proposedNewDueDate = date('Y-m-d', strtotime($currentDueDate . ' +14 days'));
            $currentRenewals = (int) $lockedLoan['renewals'];
            // Refresh libro_id from locked loan to ensure consistency
            $libroId = (int) $lockedLoan['libro_id'];

            // Now lock the book row
            $lockStmt = $db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockStmt->bind_param('i', $libroId);
            $lockStmt->execute();
            $lockStmt->close();

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
                AND data_prestito <= ? AND data_scadenza >= ?
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

            // Count total lendable copies (exclude perso, danneggiato, manutenzione)
            $totalCopiesStmt = $db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')");
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
                $errorUrl = $redirectTo ?? '/admin/prestiti';
                $separator = strpos($errorUrl, '?') === false ? '?' : '&';
                return $response->withHeader('Location', $errorUrl . $separator . 'error=extension_conflicts')->withStatus(302);
            }

            // All checks passed - proceed with renewal
            $newDueDate = $proposedNewDueDate;
            $newRenewalCount = $currentRenewals + 1;

            // Update loan
            $updateStmt = $db->prepare("
                UPDATE prestiti
                SET data_scadenza = ?, renewals = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param("sii", $newDueDate, $newRenewalCount, $id);
            $updateStmt->execute();
            $updateStmt->close();

            // Validate and update loan status
            $integrity = new DataIntegrity($db);
            $validationResult = $integrity->validateAndUpdateLoan($id);
            if (!$validationResult['success']) {
                SecureLogger::warning(__('Validazione prestito fallita'), ['loan_id' => $id, 'message' => $validationResult['message']]);
            }

            $db->commit();
            $_SESSION['success_message'] = __('Prestito rinnovato correttamente. Nuova scadenza: %s', format_date($newDueDate, false, '/'));

            $successUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($successUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $successUrl . $separator . 'renewed=1')->withStatus(302);

        } catch (Exception $e) {
            $db->rollback();
            SecureLogger::error(__('Rinnovo prestito fallito'), ['loan_id' => $id, 'error' => $e->getMessage()]);

            $errorUrl = $redirectTo ?? '/admin/prestiti';
            $separator = strpos($errorUrl, '?') === false ? '?' : '&';
            return $response->withHeader('Location', $errorUrl . $separator . 'error=renewal_failed')->withStatus(302);
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
                LEFT JOIN libri l ON p.libro_id = l.id
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

        // CSV header with i18n
        fputcsv($output, [
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
        ], ',', '"', '');

        // Status translations
        $statusLabels = [
            'pendente' => __('Pendente'),
            'prenotato' => __('Prenotato'),
            'da_ritirare' => __('Da Ritirare'),
            'in_corso' => __('In Corso'),
            'in_ritardo' => __('In Ritardo'),
            'restituito' => __('Restituito'),
            'perso' => __('Perso'),
            'danneggiato' => __('Danneggiato'),
            'annullato' => __('Annullato'),
            'scaduto' => __('Scaduto'),
        ];

        // Sanitize CSV values to prevent formula injection (CSV injection)
        // Characters =, +, -, @, tab, carriage return can trigger formula execution in Excel/LibreOffice
        $sanitizeCsv = function ($value): string {
            if ($value === null || $value === '') {
                return '';
            }
            $value = (string) $value;
            if (preg_match('/^[=+\-@\t\r]/', $value)) {
                return "'" . $value;
            }
            return $value;
        };

        // CSV data rows
        foreach ($loans as $loan) {
            $stato = $statusLabels[$loan['stato']] ?? $loan['stato'];
            fputcsv($output, [
                $loan['id'],
                $sanitizeCsv($loan['libro_titolo'] ?? ''),
                $sanitizeCsv($loan['utente_nome'] ?? ''),
                $sanitizeCsv($loan['utente_email'] ?? ''),
                $loan['data_prestito'] ? format_date($loan['data_prestito'], false, '/') : '',
                $loan['data_scadenza'] ? format_date($loan['data_scadenza'], false, '/') : '',
                $loan['data_restituzione'] ? format_date($loan['data_restituzione'], false, '/') : '',
                $stato,
                $loan['renewals'] ?? 0,
                $sanitizeCsv($loan['copia_inventario'] ?? ''),
                $sanitizeCsv($loan['processed_by_name'] ?? ''),
                $sanitizeCsv($loan['note'] ?? '')
            ], ',', '"', '');
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
