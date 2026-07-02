<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\ContactController;
use App\Controllers\ProfileController;
use App\Controllers\UserActionsController;
use App\Controllers\UserWishlistController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\DateHelper;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * User actions for the Mobile API (slice: Actions).
 *
 * Every write/read here is STRICTLY scoped to the token-resolved user — the id
 * comes only from AppAuthMiddleware::ATTR_USER (and the session mirror it sets),
 * never from a client-supplied user_id (spec §Data isolation).
 *
 * Loan/reservation overlap + availability rules are NOT reimplemented here. The
 * canonical web logic (multi-copy availability, queue position, duplicate guard,
 * max-active-loans, race-safe locking) lives in
 * App\Controllers\UserActionsController and App\Controllers\ReservationManager;
 * this controller delegates to it by handing the existing methods a request with
 * the right parsed body, then translates their redirect outcome (a query string
 * such as ?loan_request_success=1 / ?reserve_error=not_available) into the JSON
 * envelope. Wishlist / profile / password / contact reuse the same pattern.
 *
 * Endpoints:
 *   GET    /me/loans            — own loans (active + history).
 *   GET    /me/reservations     — own active reservations (queue).
 *   POST   /reservations        — request loan/reservation (honors overlap+availability).
 *   DELETE /reservations/{id}   — cancel own pending reservation (or pending loan).
 *   GET    /me/wishlist         — own wishlist.
 *   POST   /me/wishlist         — add {book_id} to own wishlist.
 *   DELETE /me/wishlist/{book_id} — remove from own wishlist.
 *   GET    /me                  — own profile.
 *   PATCH  /me                  — edit own profile.
 *   POST   /me/password         — change own password.
 *   POST   /messages            — send a contact message (web contact form parity).
 *   GET    /me/notifications    — derived in-app feed (fallback when push off).
 */
final class ActionsController
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ─── GET /me/loans ───────────────────────────────────────────────────────

    /**
     * Own loans: pending requests, active (scheduled/holding/overdue), and the
     * most recent concluded history. Every JOIN on `libri` carries
     * `AND l.deleted_at IS NULL`. Scoped to the authenticated user only.
     */
    public function myLoans(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $active  = [];
            $pending = [];
            $history = [];

            // Pending loan requests (awaiting admin approval).
            $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.stato, pr.created_at,
                           l.titolo, l.copertina_url
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.stato = 'pendente'
                    ORDER BY pr.created_at DESC";
            foreach ($this->fetchScoped($sql, $userId) as $r) {
                $pending[] = $this->mapLoan($r);
            }

            // Active loans (scheduled / to-pickup / in-progress / overdue).
            $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_scadenza, pr.data_restituzione,
                           pr.stato, pr.renewals, l.titolo, l.copertina_url
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.attivo = 1
                      AND pr.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')
                    ORDER BY pr.data_prestito ASC";
            foreach ($this->fetchScoped($sql, $userId) as $r) {
                $active[] = $this->mapLoan($r);
            }

            // Concluded history (most recent 30).
            $sql = "SELECT pr.id, pr.libro_id, pr.data_prestito, pr.data_restituzione, pr.stato,
                           l.titolo, l.copertina_url
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.attivo = 0 AND pr.stato != 'prestato'
                    ORDER BY pr.data_restituzione DESC, pr.data_prestito DESC
                    LIMIT 30";
            foreach ($this->fetchScoped($sql, $userId) as $r) {
                $history[] = $this->mapLoan($r);
            }

            $data = [
                'pending' => $pending,
                'active'  => $active,
                'history' => $history,
            ];
            $meta = [
                'pending_count' => count($pending),
                'active_count'  => count($active),
                'history_count' => count($history),
            ];

            return ResponseEnvelope::success($response, $data, $meta, 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] my loans failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Prestiti non disponibili.'), 500);
        }
    }

    // ─── GET /me/reservations ────────────────────────────────────────────────

    public function myReservations(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $sql = "SELECT p.id, p.libro_id, p.data_prenotazione, p.data_inizio_richiesta,
                           p.data_fine_richiesta, p.data_scadenza_prenotazione, p.queue_position, p.stato,
                           l.titolo, l.copertina_url, l.copie_disponibili
                    FROM prenotazioni p
                    JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
                    WHERE p.utente_id = ? AND p.stato = 'attiva'
                    ORDER BY p.queue_position ASC, p.data_prenotazione DESC";

            $items = [];
            foreach ($this->fetchScoped($sql, $userId) as $r) {
                $items[] = [
                    'id'             => (int) $r['id'],
                    'book_id'        => (int) $r['libro_id'],
                    'title'          => (string) ($r['titolo'] ?? ''),
                    'cover_url'      => absoluteUrl($this->coverPath($r['copertina_url'] ?? null)),
                    'queue_position' => $r['queue_position'] !== null ? (int) $r['queue_position'] : null,
                    'status'         => (string) ($r['stato'] ?? ''),
                    'requested_from' => $this->nullableString($r['data_inizio_richiesta'] ?? null),
                    'requested_to'   => $this->nullableString($r['data_fine_richiesta'] ?? null),
                    'reserved_at'    => $this->nullableString($r['data_prenotazione'] ?? null),
                    'expires_at'     => $this->nullableString($r['data_scadenza_prenotazione'] ?? null),
                ];
            }

            return ResponseEnvelope::success($response, $items, ['count' => count($items)], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] my reservations failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Prenotazioni non disponibili.'), 500);
        }
    }

    // ─── POST /reservations ──────────────────────────────────────────────────

    /**
     * Request a loan or a reservation, honoring the existing overlap/availability
     * rules. The body is `{ book_id, desired_date? }`.
     *
     *   - With NO desired_date AND a copy currently loanable → immediate loan
     *     request (UserActionsController::loan), pending admin approval.
     *   - Otherwise (a future desired_date, or no copy free now) → reservation in
     *     the queue (UserActionsController::reserve).
     *
     * Both paths run the canonical race-safe availability + duplicate + capacity
     * logic. We never bypass it; we only choose which existing entry point to call
     * and translate its redirect outcome to JSON.
     */
    public function requestReservation(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body    = JsonBody::parse($request);
        $bookId  = (int) ($body['book_id'] ?? $body['libro_id'] ?? 0);
        $desired = isset($body['desired_date']) ? trim((string) $body['desired_date']) : '';

        if ($bookId <= 0) {
            return ResponseEnvelope::error($response, 'invalid_book', __('Identificativo libro non valido.'), 422);
        }
        if ($desired !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desired)) {
            return ResponseEnvelope::error($response, 'invalid_date', __('Data richiesta non valida.'), 422);
        }
        // Domain "today" MUST come from DateHelper::today() (app timezone), not
        // date() (process tz, often UTC): near midnight they disagree by a day.
        $today = DateHelper::today();
        if ($desired !== '' && strtotime($desired) < strtotime($today)) {
            return ResponseEnvelope::error($response, 'past_date', __('La data richiesta è nel passato.'), 422);
        }

        try {
            $controller = new UserActionsController();

            // Decide loan vs reservation using the SAME availability gate the web
            // uses (ReservationManager), so the two surfaces agree. Immediate loan
            // when the user asked for "now" — either no date, OR today's date with
            // a copy free. The app's "Request loan" on an available title sends
            // today (its date picker pre-selects the first free day = today), so
            // without the today case that flow wrongly became a reservation
            // instead of a pending loan. A FUTURE date is always a reservation.
            $immediate = false;
            if ($desired === '' || $desired === $today) {
                $manager   = new \App\Controllers\ReservationManager($this->db);
                $immediate = $manager->isBookAvailableForImmediateLoan($bookId, null, null, $userId);
            }

            if ($immediate) {
                $delegated = $request->withParsedBody(['libro_id' => $bookId]);
                $result    = $controller->loan($delegated, new SlimResponse(), $this->db);
                $outcome   = $this->redirectOutcome($result);

                if (isset($outcome['loan_request_success'])) {
                    return ResponseEnvelope::success(
                        $response,
                        ['type' => 'loan', 'book_id' => $bookId],
                        ['message' => __('Richiesta di prestito inviata. In attesa di approvazione.')],
                        201
                    );
                }

                return $this->mapActionError($response, (string) ($outcome['loan_error'] ?? 'db'));
            }

            $delegatedBody = ['libro_id' => $bookId];
            if ($desired !== '') {
                $delegatedBody['desired_date'] = $desired;
            }
            $delegated = $request->withParsedBody($delegatedBody);
            $result    = $controller->reserve($delegated, new SlimResponse(), $this->db);
            $outcome   = $this->redirectOutcome($result);

            if (isset($outcome['reserve_success'])) {
                // Register an availability watcher so the push dispatcher notifies
                // this user (and clears the watcher) when the title is loanable
                // again. Best-effort: a failure here never breaks the reservation.
                $this->watchAvailability($userId, $bookId);

                return ResponseEnvelope::success(
                    $response,
                    ['type' => 'reservation', 'book_id' => $bookId],
                    ['message' => __('Prenotazione registrata.')],
                    201
                );
            }

            return $this->mapActionError($response, (string) ($outcome['reserve_error'] ?? 'db'));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] request reservation failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Richiesta non disponibile.'), 500);
        }
    }

    // ─── DELETE /reservations/{id} ───────────────────────────────────────────

    /**
     * Cancel one of THIS user's pending commitments. Tries the reservation queue
     * first (prenotazioni), then a pending/scheduled loan (prestiti) — both core
     * cancel paths are user-scoped (they include `AND utente_id = ?`), so a
     * foreign or unknown id can never be cancelled (spec §Data isolation).
     */
    public function cancelReservation(Request $request, ResponseInterface $response, int $reservationId): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }
        if ($reservationId <= 0) {
            return ResponseEnvelope::error($response, 'not_found', __('Prenotazione non trovata.'), 404);
        }

        try {
            // Resolve, scoped to the user, what kind of cancellable row this id is.
            $kind = $this->classifyCancellable($userId, $reservationId);
            if ($kind === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Prenotazione non trovata.'), 404);
            }

            $controller = new UserActionsController();
            if ($kind === 'reservation') {
                $delegated = $request->withParsedBody(['reservation_id' => $reservationId]);
                $result    = $controller->cancelReservation($delegated, new SlimResponse(), $this->db);
            } else {
                $delegated = $request->withParsedBody(['loan_id' => $reservationId]);
                $result    = $controller->cancelLoan($delegated, new SlimResponse(), $this->db);
            }

            $outcome = $this->redirectOutcome($result);
            if (isset($outcome['canceled'])) {
                return ResponseEnvelope::success($response, null, ['message' => __('Prenotazione annullata.')], 200);
            }
            if (($outcome['error'] ?? '') === 'not_found') {
                return ResponseEnvelope::error($response, 'not_found', __('Prenotazione non trovata.'), 404);
            }

            return ResponseEnvelope::error($response, 'cancel_failed', __('Impossibile annullare la prenotazione.'), 409);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] cancel reservation failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── GET /me/wishlist ────────────────────────────────────────────────────

    public function getWishlist(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $sql = "SELECT l.id, l.titolo, l.copertina_url, l.copie_disponibili, l.anno_pubblicazione,
                           (SELECT a.nome FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                            WHERE la.libro_id = l.id AND la.ruolo = 'principale' LIMIT 1) AS autore
                    FROM wishlist w
                    JOIN libri l ON l.id = w.libro_id AND l.deleted_at IS NULL
                    WHERE w.utente_id = ?
                    ORDER BY w.id DESC";

            $items = [];
            foreach ($this->fetchScoped($sql, $userId) as $r) {
                $available = (int) ($r['copie_disponibili'] ?? 0);
                $items[] = [
                    'book_id'          => (int) $r['id'],
                    'title'            => (string) ($r['titolo'] ?? ''),
                    'author'           => $this->nullableString($r['autore'] ?? null),
                    'year'             => $r['anno_pubblicazione'] !== null ? (int) $r['anno_pubblicazione'] : null,
                    'cover_url'        => absoluteUrl($this->coverPath($r['copertina_url'] ?? null)),
                    'copies_available' => $available,
                    'loanable_now'     => $available > 0,
                ];
            }

            return ResponseEnvelope::success($response, $items, ['count' => count($items)], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] get wishlist failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Preferiti non disponibili.'), 500);
        }
    }

    // ─── POST /me/wishlist ───────────────────────────────────────────────────

    public function addWishlist(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body   = JsonBody::parse($request);
        $bookId = (int) ($body['book_id'] ?? $body['libro_id'] ?? 0);
        if ($bookId <= 0) {
            return ResponseEnvelope::error($response, 'invalid_book', __('Identificativo libro non valido.'), 422);
        }

        try {
            // Validate the book exists and is not soft-deleted before inserting —
            // mirrors UserWishlistController::toggle's guard. Idempotent add.
            $check = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            if ($check === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
            }
            $check->bind_param('i', $bookId);
            $check->execute();
            $exists = ($r = $check->get_result()) !== false && $r->num_rows > 0;
            $check->close();
            if (!$exists) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }

            $ins = $this->db->prepare('INSERT IGNORE INTO wishlist (utente_id, libro_id) VALUES (?, ?)');
            if ($ins === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
            }
            $ins->bind_param('ii', $userId, $bookId);
            $ins->execute();
            $ins->close();

            // If the book is not loanable right now, register an availability
            // watcher so the push dispatcher pings the user when it returns to
            // stock (and clears the watcher). Best-effort.
            $this->watchAvailability($userId, $bookId);

            return ResponseEnvelope::success(
                $response,
                ['book_id' => $bookId, 'favorite' => true],
                ['message' => __('Aggiunto ai preferiti.')],
                201
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] add wishlist failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── DELETE /me/wishlist/{book_id} ───────────────────────────────────────

    public function removeWishlist(Request $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }
        if ($bookId <= 0) {
            return ResponseEnvelope::error($response, 'invalid_book', __('Identificativo libro non valido.'), 422);
        }

        try {
            // Scoped DELETE — a user can only ever remove from their OWN wishlist.
            $del = $this->db->prepare('DELETE FROM wishlist WHERE utente_id = ? AND libro_id = ?');
            if ($del === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
            }
            $del->bind_param('ii', $userId, $bookId);
            $del->execute();
            $removed = $del->affected_rows > 0;
            $del->close();

            if (!$removed) {
                return ResponseEnvelope::error($response, 'not_found', __('Elemento non trovato nei preferiti.'), 404);
            }

            return ResponseEnvelope::success(
                $response,
                ['book_id' => $bookId, 'favorite' => false],
                ['message' => __('Rimosso dai preferiti.')],
                200
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] remove wishlist failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── GET /me ─────────────────────────────────────────────────────────────

    public function getProfile(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, nome, cognome, email, telefono, indirizzo, codice_tessera, tipo_utente,
                        data_nascita, sesso, cod_fiscale, data_scadenza_tessera, data_ultimo_accesso, locale
                   FROM utenti WHERE id = ? LIMIT 1'
            );
            if ($stmt === false) {
                return ResponseEnvelope::error($response, 'internal_error', __('Profilo non disponibile.'), 500);
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res !== false) ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row === null) {
                return $this->unauth($response);
            }

            $data = [
                'id'                => (int) $row['id'],
                'nome'              => (string) ($row['nome'] ?? ''),
                'cognome'           => (string) ($row['cognome'] ?? ''),
                'email'             => (string) ($row['email'] ?? ''),
                'telefono'          => $this->nullableString($row['telefono'] ?? null),
                'indirizzo'         => $this->nullableString($row['indirizzo'] ?? null),
                'codice_tessera'    => $this->nullableString($row['codice_tessera'] ?? null),
                'tipo_utente'       => (string) ($row['tipo_utente'] ?? ''),
                'data_nascita'      => $this->nullableString($row['data_nascita'] ?? null),
                'sesso'             => $this->nullableString($row['sesso'] ?? null),
                'cod_fiscale'       => $this->nullableString($row['cod_fiscale'] ?? null),
                'card_expires_at'   => $this->nullableString($row['data_scadenza_tessera'] ?? null),
                'last_access_at'    => $this->nullableString($row['data_ultimo_accesso'] ?? null),
                'locale'            => $this->nullableString($row['locale'] ?? null),
            ];

            return ResponseEnvelope::success($response, $data, [], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] get profile failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Profilo non disponibile.'), 500);
        }
    }

    // ─── PATCH /me ───────────────────────────────────────────────────────────

    /**
     * Edit own profile. Delegates to ProfileController::update (same validation,
     * sanitization, locale handling). The update is implicitly scoped to the
     * session user it sets — AppAuthMiddleware populated $_SESSION['user'] from the
     * token, so the core controller updates only this user's row.
     */
    public function updateProfile(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body = JsonBody::parse($request);

        // True PATCH semantics: load the current editable values and overlay only
        // the fields the caller actually sent. ProfileController::update writes
        // every column it receives, so without this merge an omitted field would be
        // wiped. id/email are never editable here.
        $current = $this->currentEditableProfile($userId);
        if ($current === null) {
            return $this->unauth($response);
        }

        $allowed = ['nome', 'cognome', 'telefono', 'data_nascita', 'cod_fiscale', 'sesso', 'indirizzo'];
        $forward = $current;
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $forward[$field] = (string) $body[$field];
            }
        }
        // locale only when explicitly provided (single-locale installs omit it).
        if (array_key_exists('locale', $body)) {
            $forward['locale'] = (string) $body['locale'];
        }

        if (trim((string) $forward['nome']) === '' || trim((string) $forward['cognome']) === '') {
            return ResponseEnvelope::error($response, 'required_fields', __('Nome e cognome sono obbligatori.'), 422);
        }

        try {
            $delegated = $request->withParsedBody($forward);
            $result    = (new ProfileController())->update($delegated, new SlimResponse(), $this->db);
            $outcome   = $this->redirectOutcome($result);

            if (isset($outcome['error'])) {
                $code = (string) $outcome['error'];
                if ($code === 'required_fields') {
                    return ResponseEnvelope::error($response, 'required_fields', __('Nome e cognome sono obbligatori.'), 422);
                }
                return ResponseEnvelope::error($response, 'update_failed', __('Errore durante l\'aggiornamento del profilo.'), 500);
            }

            // No error param on the redirect → success. Return the fresh profile.
            return $this->getProfile($request, $response);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] update profile failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── POST /me/password ───────────────────────────────────────────────────

    /**
     * Change own password. Delegates to ProfileController::changePassword (same
     * current-password verification + complexity rules), scoped to the session
     * user set from the token.
     */
    public function changePassword(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body    = JsonBody::parse($request);
        $current = (string) ($body['current_password'] ?? '');
        $p1      = (string) ($body['password'] ?? '');
        $p2      = (string) ($body['password_confirm'] ?? '');

        if ($current === '' || $p1 === '') {
            return ResponseEnvelope::error($response, 'missing_fields', __('Compila tutti i campi obbligatori.'), 422);
        }
        if ($p1 !== $p2) {
            return ResponseEnvelope::error($response, 'password_mismatch', __('Le password non coincidono.'), 422);
        }

        try {
            $delegated = $request->withParsedBody([
                'current_password' => $current,
                'password'         => $p1,
                'password_confirm' => $p2,
            ]);
            $result  = (new ProfileController())->changePassword($delegated, new SlimResponse(), $this->db);
            $outcome = $this->redirectOutcome($result);

            if (isset($outcome['error'])) {
                return $this->mapPasswordError($response, (string) $outcome['error']);
            }

            // After a password change, revoke OTHER device tokens for safety; the
            // caller's own token (resolved by middleware) stays valid so the app
            // session is not broken.
            $this->revokeOtherTokens($userId, $request);

            return ResponseEnvelope::success($response, null, ['message' => __('Password aggiornata con successo.')], 200);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] change password failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Operazione non disponibile.'), 500);
        }
    }

    // ─── POST /messages ──────────────────────────────────────────────────────

    /**
     * Send a contact message exactly like the web contact form. Delegates to
     * ContactController::submitForm. The authenticated user's name/email may be
     * pre-filled from the token if the body omits them, but the body wins so the
     * app can let the user edit before sending. ReCAPTCHA is bypassed only when no
     * secret is configured (same as web); when configured, the app must supply a
     * recaptcha_token like the web form.
     */
    public function sendMessage(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        $body = JsonBody::parse($request);
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
        $user = is_array($user) ? $user : [];

        $nome     = trim((string) ($body['nome'] ?? ($user['nome'] ?? '')));
        $cognome  = trim((string) ($body['cognome'] ?? ($user['cognome'] ?? '')));
        $email    = trim((string) ($body['email'] ?? ($user['email'] ?? '')));
        $messaggio = trim((string) ($body['messaggio'] ?? $body['message'] ?? $body['body'] ?? ''));

        if ($nome === '' || $cognome === '' || $email === '' || $messaggio === '') {
            return ResponseEnvelope::error($response, 'required_fields', __('Compila tutti i campi obbligatori.'), 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ResponseEnvelope::error($response, 'invalid_email', __('Indirizzo email non valido.'), 422);
        }

        $forward = [
            'nome'      => $nome,
            'cognome'   => $cognome,
            'email'     => $email,
            'telefono'  => trim((string) ($body['telefono'] ?? '')),
            'indirizzo' => trim((string) ($body['indirizzo'] ?? '')),
            'messaggio' => $messaggio,
            'privacy'   => '1', // an authenticated user already accepted privacy at registration
        ];
        if (isset($body['recaptcha_token'])) {
            $forward['recaptcha_token'] = (string) $body['recaptcha_token'];
        }

        try {
            $delegated = $request->withParsedBody($forward);
            $result    = (new ContactController())->submitForm($delegated, new SlimResponse(), $this->db);
            $outcome   = $this->redirectOutcome($result);

            if (isset($outcome['success'])) {
                return ResponseEnvelope::success($response, null, ['message' => __('Messaggio inviato. Ti risponderemo al più presto.')], 201);
            }

            $err = (string) ($outcome['error'] ?? 'db');
            return $this->mapMessageError($response, $err);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] send message failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Invio messaggio non disponibile.'), 500);
        }
    }

    // ─── GET /me/notifications ───────────────────────────────────────────────

    /**
     * In-app notification feed (fallback when push is off — spec §endpoint
     * manifest). Core has no per-user notification table (admin_notifications is
     * admin-only), so the feed is DERIVED, read-only, from the user's own
     * actionable state: loans due-soon, loans overdue, reservations ready for
     * pickup, and watched titles that became available again (book_available,
     * from mobile_availability_watchers). Strictly user-scoped.
     *
     * This intentionally mirrors the push trigger taxonomy (loan_due,
     * loan_overdue, reservation_ready, book_available) so the app renders the same
     * items whether they arrive by push or by polling this endpoint.
     */
    public function notifications(Request $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        if ($userId === 0) {
            return $this->unauth($response);
        }

        try {
            $items = [];
            // Domain "today" from DateHelper::today() (app timezone), so the
            // due/overdue day boundary matches the web/cron pipeline.
            $today = DateHelper::today();
            // Due-soon window from settings (fallback 3 days), same idea as the web reminders.
            $dueSoonDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'reminder_days_before', '3') ?? 3);
            if ($dueSoonDays < 1) {
                $dueSoonDays = 3;
            }
            $dueSoonLimit = date('Y-m-d', strtotime($today . " +{$dueSoonDays} days"));

            // Loans due soon (active, in-progress, not yet overdue).
            $sql = "SELECT pr.id, pr.libro_id, pr.data_scadenza, l.titolo
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato = 'in_corso'
                      AND pr.data_scadenza >= ? AND pr.data_scadenza <= ?
                    ORDER BY pr.data_scadenza ASC";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param('iss', $userId, $today, $dueSoonLimit);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res !== false && ($r = $res->fetch_assoc()) !== null) {
                    $items[] = $this->notif(
                        'loan_due',
                        'loan:' . (int) $r['id'],
                        __('Prestito in scadenza'),
                        sprintf(__('Il prestito di "%s" scade il %s.'), (string) $r['titolo'], (string) $r['data_scadenza']),
                        (int) $r['libro_id'],
                        (string) $r['data_scadenza']
                    );
                }
                $stmt->close();
            }

            // Overdue loans.
            $sql = "SELECT pr.id, pr.libro_id, pr.data_scadenza, l.titolo
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.attivo = 1
                      AND (pr.stato = 'in_ritardo' OR (pr.stato = 'in_corso' AND pr.data_scadenza < ?))
                    ORDER BY pr.data_scadenza ASC";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param('is', $userId, $today);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res !== false && ($r = $res->fetch_assoc()) !== null) {
                    $items[] = $this->notif(
                        'loan_overdue',
                        'overdue:' . (int) $r['id'],
                        __('Prestito scaduto'),
                        sprintf(__('Il prestito di "%s" è scaduto il %s.'), (string) $r['titolo'], (string) $r['data_scadenza']),
                        (int) $r['libro_id'],
                        (string) $r['data_scadenza']
                    );
                }
                $stmt->close();
            }

            // Reservations promoted to a pending pickup loan (ready to collect).
            // Only 'da_ritirare' is actually ready — matches the push dispatcher's
            // reservation_ready sweep. 'prenotato' is still queued (not yet ready)
            // and would be mislabelled "pronto per il ritiro", so it is excluded.
            $sql = "SELECT pr.id, pr.libro_id, pr.data_scadenza, l.titolo
                    FROM prestiti pr
                    JOIN libri l ON l.id = pr.libro_id AND l.deleted_at IS NULL
                    WHERE pr.utente_id = ? AND pr.attivo = 1 AND pr.stato = 'da_ritirare'
                      AND pr.origine = 'prenotazione'
                    ORDER BY pr.created_at DESC";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res !== false && ($r = $res->fetch_assoc()) !== null) {
                    $items[] = $this->notif(
                        'reservation_ready',
                        'pickup:' . (int) $r['id'],
                        __('Prenotazione pronta'),
                        sprintf(__('"%s" è pronto per il ritiro.'), (string) $r['titolo']),
                        (int) $r['libro_id'],
                        null
                    );
                }
                $stmt->close();
            }

            // Watched titles that became loanable again (the push dispatcher clears
            // the watcher once a push is actually delivered, so anything still here
            // is either pending the next push pass or a push-off user — either way
            // the user should see it by polling).
            $sql = "SELECT w.libro_id, l.titolo
                    FROM mobile_availability_watchers w
                    JOIN libri l ON l.id = w.libro_id AND l.deleted_at IS NULL
                    WHERE w.user_id = ? AND l.copie_disponibili > 0
                    ORDER BY w.created_at DESC";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res !== false && ($r = $res->fetch_assoc()) !== null) {
                    $items[] = $this->notif(
                        'book_available',
                        'available:' . (int) $r['libro_id'],
                        __('Libro di nuovo disponibile'),
                        sprintf(__('"%s" è di nuovo disponibile per il prestito.'), (string) $r['titolo']),
                        (int) $r['libro_id'],
                        null
                    );
                }
                $stmt->close();
            }

            return ResponseEnvelope::success(
                $response,
                $items,
                ['count' => count($items), 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')],
                200
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] notifications failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Notifiche non disponibili.'), 500);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Current editable profile fields for the user, as strings (empty when NULL),
     * for the PATCH merge. Scoped to the user id.
     *
     * @return array{nome:string, cognome:string, telefono:string, data_nascita:string, cod_fiscale:string, sesso:string, indirizzo:string}|null
     */
    private function currentEditableProfile(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT nome, cognome, telefono, data_nascita, cod_fiscale, sesso, indirizzo
               FROM utenti WHERE id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res !== false) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return null;
        }

        return [
            'nome'         => (string) ($row['nome'] ?? ''),
            'cognome'      => (string) ($row['cognome'] ?? ''),
            'telefono'     => (string) ($row['telefono'] ?? ''),
            'data_nascita' => (string) ($row['data_nascita'] ?? ''),
            'cod_fiscale'  => (string) ($row['cod_fiscale'] ?? ''),
            'sesso'        => (string) ($row['sesso'] ?? ''),
            'indirizzo'    => (string) ($row['indirizzo'] ?? ''),
        ];
    }

    /** Resolve the token-authenticated user id, or 0 if absent. */
    private function userId(Request $request): int
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return (is_array($user) && isset($user['id'])) ? (int) $user['id'] : 0;
    }

    private function unauth(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'unauthorized', __('Non autenticato.'), 401);
    }

    /**
     * Run a user-scoped SELECT whose ONLY bound parameter is the user id.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchScoped(string $sql, int $userId): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($res !== false && ($row = $res->fetch_assoc()) !== null) {
            $out[] = $row;
        }
        $stmt->close();

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapLoan(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'book_id'      => (int) $r['libro_id'],
            'title'        => (string) ($r['titolo'] ?? ''),
            'cover_url'    => absoluteUrl($this->coverPath($r['copertina_url'] ?? null)),
            'status'       => (string) ($r['stato'] ?? ''),
            'loaned_at'    => $this->nullableString($r['data_prestito'] ?? null),
            'due_at'       => $this->nullableString($r['data_scadenza'] ?? null),
            'returned_at'  => $this->nullableString($r['data_restituzione'] ?? null),
            'renewals'     => isset($r['renewals']) ? (int) $r['renewals'] : null,
        ];
    }

    /**
     * Classify a candidate id, scoped to the user, as a cancellable 'reservation'
     * (active prenotazione) or a cancellable 'loan' (pending/scheduled prestito),
     * or null if neither belongs to the user in a cancellable state.
     */
    private function classifyCancellable(int $userId, int $id): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM prenotazioni WHERE id = ? AND utente_id = ? AND stato = 'attiva' LIMIT 1"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $isRes = ($res = $stmt->get_result()) !== false && $res->num_rows > 0;
            $stmt->close();
            if ($isRes) {
                return 'reservation';
            }
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM prestiti
              WHERE id = ? AND utente_id = ?
                AND ((attivo = 0 AND stato = 'pendente') OR (attivo = 1 AND stato = 'prenotato'))
              LIMIT 1"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $isLoan = ($res = $stmt->get_result()) !== false && $res->num_rows > 0;
            $stmt->close();
            if ($isLoan) {
                return 'loan';
            }
        }

        return null;
    }

    /**
     * Extract the query-string params from a delegated controller's redirect
     * Location header into an associative array, so the JSON layer can branch on
     * the web controller's outcome without reimplementing its logic.
     *
     * @return array<string, string>
     */
    private function redirectOutcome(ResponseInterface $result): array
    {
        $location = $result->getHeaderLine('Location');
        if ($location === '') {
            return [];
        }
        $query = parse_url($location, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }
        $out = [];
        parse_str($query, $out);

        /** @var array<string, string> $flat */
        $flat = [];
        foreach ($out as $k => $v) {
            $flat[(string) $k] = is_array($v) ? '' : (string) $v;
        }

        return $flat;
    }

    private function mapActionError(ResponseInterface $response, string $code): ResponseInterface
    {
        switch ($code) {
            case 'duplicate':
                return ResponseEnvelope::error($response, 'duplicate', __('Hai già una richiesta attiva per questo libro.'), 409);
            case 'not_available':
                return ResponseEnvelope::error($response, 'not_available', __('Il libro non è disponibile per il periodo richiesto.'), 409);
            case 'max_loans_reached':
                return ResponseEnvelope::error($response, 'max_loans_reached', __('Hai raggiunto il numero massimo di prestiti attivi.'), 409);
            case 'book_not_found':
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            case 'past_date':
                return ResponseEnvelope::error($response, 'past_date', __('La data richiesta è nel passato.'), 422);
            case 'invalid_date':
                return ResponseEnvelope::error($response, 'invalid_date', __('Data richiesta non valida.'), 422);
            case 'invalid':
                return ResponseEnvelope::error($response, 'invalid_book', __('Identificativo libro non valido.'), 422);
            default:
                return ResponseEnvelope::error($response, 'request_failed', __('Richiesta non riuscita.'), 500);
        }
    }

    private function mapPasswordError(ResponseInterface $response, string $code): ResponseInterface
    {
        switch ($code) {
            case 'wrong_current_password':
                return ResponseEnvelope::error($response, 'wrong_current_password', __('La password attuale non è corretta.'), 422);
            case 'password_too_short':
                return ResponseEnvelope::error($response, 'weak_password', __('La password deve avere almeno 8 caratteri.'), 422);
            case 'password_too_long':
                return ResponseEnvelope::error($response, 'weak_password', __('La password non può superare i 72 caratteri.'), 422);
            case 'password_needs_upper_lower_number':
                return ResponseEnvelope::error($response, 'weak_password', __('La password deve contenere maiuscole, minuscole e numeri.'), 422);
            case 'invalid':
                return ResponseEnvelope::error($response, 'password_mismatch', __('Le password non coincidono.'), 422);
            case 'server':
                return ResponseEnvelope::error($response, 'server_error', __('Errore del server. Riprova più tardi.'), 500);
            default:
                return ResponseEnvelope::error($response, 'password_change_failed', __('Impossibile aggiornare la password.'), 500);
        }
    }

    private function mapMessageError(ResponseInterface $response, string $code): ResponseInterface
    {
        switch ($code) {
            case 'required':
                return ResponseEnvelope::error($response, 'required_fields', __('Compila tutti i campi obbligatori.'), 422);
            case 'email':
                return ResponseEnvelope::error($response, 'invalid_email', __('Indirizzo email non valido.'), 422);
            case 'privacy':
                return ResponseEnvelope::error($response, 'privacy_required', __('Devi accettare la privacy policy.'), 422);
            case 'recaptcha':
                return ResponseEnvelope::error($response, 'recaptcha_failed', __('Verifica anti-spam non superata.'), 422);
            default:
                return ResponseEnvelope::error($response, 'message_failed', __('Invio messaggio non riuscito.'), 500);
        }
    }

    /**
     * Revoke every OTHER active token for this user, keeping the caller's own
     * token (resolved by AppAuthMiddleware) valid.
     */
    private function revokeOtherTokens(int $userId, Request $request): void
    {
        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        $keepId  = is_int($tokenId) ? $tokenId : 0;
        // Defensive: the caller's token id is always set by AppAuthMiddleware, but
        // if it were ever absent/0 the "id <> 0" predicate would match EVERY token
        // and revoke the caller's own device too (self-DoS). Abort instead.
        if ($keepId <= 0) {
            SecureLogger::warning('[MobileApi] revokeOtherTokens skipped: missing caller token id');
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE mobile_app_tokens SET revoked_at = NOW()
              WHERE user_id = ? AND id <> ? AND revoked_at IS NULL'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $userId, $keepId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array{id:string, type:string, title:string, message:string, book_id:?int, date:?string}
     */
    private function notif(string $type, string $id, string $title, string $message, ?int $bookId, ?string $date): array
    {
        return [
            'id'      => $id,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'book_id' => $bookId,
            'date'    => $date,
        ];
    }

    /**
     * Register an availability watcher for (user, book) — but ONLY when the book
     * is currently NOT loanable (no available copies). If it is already loanable,
     * there is nothing to wait for, so no watcher is created (otherwise the next
     * cron pass would immediately fire a "back available" push for a title the
     * user just saw in stock). Idempotent (UNIQUE (user_id, libro_id)). Scoped to
     * the caller's own user_id. Never throws — push wiring is best-effort.
     */
    private function watchAvailability(int $userId, int $bookId): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL AND copie_disponibili > 0 LIMIT 1'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $available = ($res = $stmt->get_result()) !== false && $res->num_rows > 0;
            $stmt->close();

            if ($available) {
                return; // already loanable — nothing to watch for
            }

            $ins = $this->db->prepare(
                'INSERT IGNORE INTO mobile_availability_watchers (user_id, libro_id) VALUES (?, ?)'
            );
            if ($ins === false) {
                return;
            }
            $ins->bind_param('ii', $userId, $bookId);
            $ins->execute();
            $ins->close();
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] watchAvailability failed: ' . $e->getMessage());
        }
    }

    private function coverPath(mixed $raw): string
    {
        $cover = is_string($raw) ? trim($raw) : '';

        return $cover !== '' ? $cover : '/uploads/copertine/placeholder.jpg';
    }

    private function nullableString(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);

        return $s !== '' ? $s : null;
    }
}
