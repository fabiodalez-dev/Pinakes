<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';
// dispatch() only loads the controller it routes to: require the class that
// owns LIST_PATH so the mastheads-list target stays in one place.
require_once __DIR__ . '/PeriodicalAdminController.php';
require_once __DIR__ . '/../Support/IssnHelper.php';
require_once __DIR__ . '/../Support/KbartExporter.php';
require_once __DIR__ . '/../Support/IssueLabelRenderer.php';

use App\Plugins\Emeroteca\Support\IssueLabelRenderer;
use App\Plugins\Emeroteca\Support\KbartExporter;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP surface for the Emeroteca exports, the issue labels and the
 * Kardex barcode lookup (review #140).
 *
 * The heavy lifting lives in the pure Support components
 * (KbartExporter, IssueLabelRenderer): this controller only resolves the
 * requested scope, applies the ownership checks and puts the right
 * headers on the response. Nothing here re-implements a serialization.
 *
 * Routes (registered by EmerotecaPlugin::registerRoutes):
 *   GET  /admin/periodicals/export/kbart[?testata=ID]  → kbart
 *   GET  /admin/periodicals/export/acnp[?testata=ID]   → acnp
 *   POST /admin/periodicals/{id}/issues/labels         → labels
 *   GET  /admin/periodicals/scan-lookup?code=…         → scanLookup (JSON)
 */
class ExportAdminController extends AbstractAdminController
{
    /**
     * A periodical EAN-13 barcode base is 13 digits; the printed symbol on
     * an issue may carry a 2- or 5-digit add-on (issue number / price), so a
     * scan can be up to 18 characters — the width of emeroteca_fascicoli.barcode.
     */
    private const BARCODE_BASE_LEN = 13;
    private const BARCODE_MAX_LEN = 18;

    /** Hard ceiling on one label batch — a print job, not an export. */
    private const MAX_LABELS = 500;

    /**
     * How many issues sharing one barcode the lookup will load before it
     * calls the code ambiguous. Two is already ambiguous; the extra rows are
     * only there to report an honest count to the operator.
     */
    private const AMBIGUITY_PROBE_LIMIT = 25;

    // ── Union-catalogue exports ───────────────────────────────────────

    /**
     * GET /admin/periodicals/export/kbart — KBART Phase II TSV of the whole
     * emeroteca, or of a single testata with ?testata=ID.
     *
     * @param array<string,string> $args
     */
    public function kbart(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->export($request, $response, 'kbart');
    }

    /**
     * GET /admin/periodicals/export/acnp — ACNP-style holdings CSV, whole
     * emeroteca or a single testata with ?testata=ID.
     *
     * @param array<string,string> $args
     */
    public function acnp(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->export($request, $response, 'acnp');
    }

    /**
     * Shared body of the two exports: resolve the optional scope, run the
     * exporter, wrap the string in a download response. An unknown
     * ?testata=ID is a redirect with a flash, never an empty file passed off
     * as a successful export.
     */
    private function export(ServerRequestInterface $request, ResponseInterface $response, string $format): ResponseInterface
    {
        $params = (array) $request->getQueryParams();
        $testataId = (int) self::scalarParam($params, 'testata');
        $testata = null;
        if ($testataId > 0) {
            $testata = $this->fetchTestata($testataId);
            if ($testata === null) {
                $this->flashError(__('Testata non trovata.'));
                return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
            }
        }

        try {
            $body = $format === 'kbart'
                ? KbartExporter::kbart($this->db, $testataId > 0 ? $testataId : null)
                : KbartExporter::acnp($this->db, $testataId > 0 ? $testataId : null);
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] ' . $format . ' export failed: ' . $e->getMessage());
            $this->flashError(__('Errore durante la generazione dell\'export.'));
            return $this->redirect(
                $response,
                $testataId > 0 ? '/admin/periodicals/' . $testataId . '/issues' : PeriodicalAdminController::LIST_PATH
            );
        }

        $filename = $this->exportFilename(
            $format,
            $format === 'kbart' ? 'tsv' : 'csv',
            $testata
        );
        $contentType = $format === 'kbart'
            ? 'text/tab-separated-values; charset=UTF-8'
            : 'text/csv; charset=UTF-8';

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Download filename: format, optional title slug, ISO date. Only
     * [A-Za-z0-9._-] survives the slug, so the name is safe to interpolate
     * into the Content-Disposition header verbatim (no quote/CR injection).
     *
     * @param array<string, mixed>|null $testata
     */
    private function exportFilename(string $format, string $extension, ?array $testata): string
    {
        $parts = ['emeroteca', $format];
        if ($testata !== null) {
            $slug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($testata['titolo'] ?? ''));
            $slug = trim(is_string($slug) ? $slug : '', '-');
            $parts[] = $slug !== '' ? strtolower(substr($slug, 0, 40)) : (string) (int) ($testata['id'] ?? 0);
        }
        $parts[] = date('Y-m-d');
        return implode('-', $parts) . '.' . $extension;
    }

    // ── Issue labels ──────────────────────────────────────────────────

    /**
     * POST /admin/periodicals/{id}/issues/labels — batch spine labels for
     * the issues ticked on the manage page.
     *
     * The selection is filtered against the testata in the path: an id that
     * does not belong to it is dropped rather than printed, so a tampered
     * form cannot pull labels for someone else's holdings.
     *
     * TCPDF is optional in a Pinakes install; when it is missing the
     * renderer throws and the operator gets a flash explaining why, not a
     * 500 (review #140).
     *
     * @param array<string,string> $args
     */
    public function labels(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        // CSRF validated by CsrfMiddleware.
        $testataId = (int) ($args['id'] ?? 0);
        if ($this->fetchTestata($testataId) === null) {
            $this->flashError(__('Testata non trovata.'));
            return $this->redirect($response, PeriodicalAdminController::LIST_PATH);
        }
        $body = (array) $request->getParsedBody();
        $back = '/admin/periodicals/' . $testataId . '/issues';
        $annataId = (int) ($body['annata_id'] ?? 0);
        if ($annataId > 0) {
            $back .= '?annata=' . $annataId;
        }

        $requested = $body['ids'] ?? [];
        if (!is_array($requested)) {
            $requested = [$requested];
        }
        $requested = array_values(array_unique(array_filter(
            array_map(static fn(mixed $v): int => (int) $v, $requested),
            static fn(int $id): bool => $id > 0
        )));
        if ($requested === []) {
            $this->flashError(__('Nessun fascicolo selezionato.'));
            return $this->redirect($response, $back);
        }
        if (count($requested) > self::MAX_LABELS) {
            $this->flashError(sprintf(
                __('Troppi fascicoli selezionati: al massimo %d etichette per stampa.'),
                self::MAX_LABELS
            ));
            return $this->redirect($response, $back);
        }

        // null = the ownership filter itself failed. Reporting that as "no
        // issues selected" blamed the operator for a server-side fault
        // (review #140): a real error and an empty selection must not share
        // a message.
        $ids = $this->issueIdsOfTestata($testataId, $requested);
        if ($ids === null) {
            $this->flashError(__('Errore durante la preparazione delle etichette.'));
            return $this->redirect($response, $back);
        }
        if ($ids === []) {
            $this->flashError(__('Nessun fascicolo valido nella selezione.'));
            return $this->redirect($response, $back);
        }

        try {
            $pdf = IssueLabelRenderer::labelsPdf($this->db, $ids);
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] label PDF generation failed: ' . $e->getMessage());
            // Only a missing TCPDF deserves the "install the PDF library"
            // message; a failed query or a degraded schema is a plain error.
            $this->flashError(class_exists(\TCPDF::class)
                ? __('Errore durante la generazione delle etichette.')
                : __('Stampa etichette non disponibile: la libreria PDF non è installata su questo server.'));
            return $this->redirect($response, $back);
        }
        if ($pdf === '') {
            // The ids were validated against the DB a moment ago, so an empty
            // render means the rows vanished meanwhile — not a bad selection.
            $this->flashError(__('I fascicoli selezionati non sono più disponibili.'));
            return $this->redirect($response, $back);
        }

        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string) strlen($pdf))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader(
                'Content-Disposition',
                'inline; filename="etichette-fascicoli-' . $testataId . '-' . date('Y-m-d') . '.pdf"'
            );
    }

    /**
     * Narrow a requested id list down to the issues that really hang off
     * this testata, preserving the caller's order.
     *
     * Returns NULL when the filter query itself failed, so the caller can
     * tell a server-side fault from an empty selection; [] means the
     * selection genuinely contains nothing belonging to this testata.
     *
     * @param  list<int> $requested
     * @return list<int>|null
     */
    private function issueIdsOfTestata(int $testataId, array $requested): ?array
    {
        if ($requested === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requested), '?'));
        $stmt = $this->db->prepare(
            "SELECT f.id
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id
              WHERE a.testata_id = ? AND f.id IN ({$placeholders})"
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] label id filter prepare failed: ' . $this->db->error);
            return null;
        }
        $params = array_merge([$testataId], $requested);
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] label id filter failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $allowed = [];
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $allowed[(int) $row['id']] = true;
            }
        }
        $stmt->close();

        $out = [];
        foreach ($requested as $id) {
            if (isset($allowed[$id])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    // ── Kardex barcode lookup ─────────────────────────────────────────

    /**
     * GET /admin/periodicals/scan-lookup?code=…[&testata=ID] — resolve a
     * scanned (or typed) barcode to something the Kardex can act on.
     *
     * Resolution order, most specific first:
     *   1. an issue whose own `barcode` is exactly the scanned code;
     *   2. an issue whose `barcode` is the code without its EAN add-on
     *      (the printed symbol carries a 2/5-digit issue-or-price add-on
     *      that the stored code may not have);
     *   3. the testata whose `barcode_base` is the 13-digit EAN prefix of
     *      the code — the usual case, since most periodicals print the same
     *      977 base on every issue and vary only the add-on.
     *
     * NEVER picks one issue out of several. A barcode that matches more than
     * one issue is reported as AMBIGUOUS and resolved to the testata, so the
     * operator finishes the job by hand: the previous `ORDER BY … LIMIT 1`
     * silently chose a row, and "receive" on the wrong issue is a holdings
     * error nobody notices. The three outcomes are distinct in the payload:
     *   match = 'issue'      → exactly one issue (unique)
     *   match = 'ambiguous'  → several issues, `title` carries where to go
     *   match = 'title'      → no issue, the code is the title's EAN base
     *   match = 'none'       → nothing at all
     *
     * Scoping is a legitimate narrowing, not an arbitrary pick: with
     * ?testata=ID the issues of that title are searched FIRST, and only if
     * that yields nothing does the search widen to the whole emeroteca.
     *
     * When an issue is found in a state the Kardex can close ('atteso' or
     * 'reclamato'), the payload suggests action "receive": the caller then
     * posts to the EXISTING receive_issue action of the manage page. No
     * receiving logic is duplicated here, and the lookup itself is a pure
     * read (a GET must never change state).
     *
     * @param array<string,string> $args
     */
    public function scanLookup(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $params = (array) $request->getQueryParams();
        // scalarParam(), not a bare (string) cast: `?code[]=x` would otherwise
        // raise "Array to string conversion" and that warning is printed
        // BEFORE the JSON body, so the caller's response.json() throws.
        $raw = trim(self::scalarParam($params, 'code'));
        $scopeTestata = (int) self::scalarParam($params, 'testata');

        // Keep digits only: scanners emit stray separators and the operator
        // may paste "977-0028-0836".
        $digits = preg_replace('/\D+/', '', $raw);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '' || strlen($digits) > self::BARCODE_MAX_LEN) {
            return $this->json($response, [
                'found'   => false,
                'match'   => 'none',
                'message' => __('Codice a barre non valido.'),
            ]);
        }

        $matches = $this->matchIssues($digits, $scopeTestata);
        if ($matches === [] && strlen($digits) > self::BARCODE_BASE_LEN) {
            $matches = $this->matchIssues(substr($digits, 0, self::BARCODE_BASE_LEN), $scopeTestata);
        }

        $base = strlen($digits) >= self::BARCODE_BASE_LEN
            ? substr($digits, 0, self::BARCODE_BASE_LEN)
            : $digits;

        if (count($matches) > 1) {
            return $this->json($response, $this->ambiguousPayload($matches, $base));
        }

        if (count($matches) === 1) {
            $issue = $matches[0];
            $stato = (string) $issue['stato'];
            $receivable = in_array($stato, ['atteso', 'reclamato'], true);
            return $this->json($response, [
                'found'   => true,
                'match'   => 'issue',
                'action'  => $receivable ? 'receive' : null,
                'issue'   => [
                    'id'         => (int) $issue['id'],
                    'numero'     => (string) $issue['numero'],
                    'stato'      => $stato,
                    'annata_id'  => (int) $issue['annata_id'],
                    'anno'       => (int) $issue['anno'],
                    'testata_id' => (int) $issue['testata_id'],
                    'titolo'     => (string) $issue['titolo'],
                    'url'        => url('/admin/periodicals/issue/' . (int) $issue['id']),
                ],
                'message' => $receivable
                    ? sprintf(__('Fascicolo n. %s in attesa: puoi registrarne la ricezione.'), (string) $issue['numero'])
                    : sprintf(__('Fascicolo n. %s trovato.'), (string) $issue['numero']),
            ]);
        }

        $title = $this->findTestataByBarcodeBase($base);
        if ($title !== null) {
            return $this->json($response, [
                'found'   => true,
                'match'   => 'title',
                'action'  => null,
                'title'   => [
                    'id'     => (int) $title['id'],
                    'titolo' => (string) $title['titolo'],
                    'url'    => url('/admin/periodicals/' . (int) $title['id'] . '/issues'),
                ],
                'message' => sprintf(
                    __('Nessun fascicolo con questo codice; il codice appartiene alla testata «%s».'),
                    (string) $title['titolo']
                ),
            ]);
        }

        return $this->json($response, [
            'found'   => false,
            'match'   => 'none',
            'action'  => null,
            'message' => __('Nessuna corrispondenza per questo codice a barre.'),
        ]);
    }

    /**
     * Every issue carrying this exact barcode, scope first.
     *
     * Scoping narrows, it does not choose: with ?testata=ID the title's own
     * issues are searched first and the emeroteca-wide search only runs when
     * that came back empty. Whatever the search, ALL matching rows are
     * returned — the caller decides between "unique" and "ambiguous"; it can
     * no longer be handed a row that a `LIMIT 1` picked for it.
     *
     * @return list<array<string, mixed>>
     */
    private function matchIssues(string $barcode, int $scopeTestata): array
    {
        if ($scopeTestata > 0) {
            $scoped = $this->findIssuesByBarcode($barcode, $scopeTestata);
            if ($scoped !== []) {
                return $scoped;
            }
        }
        return $this->findIssuesByBarcode($barcode, 0);
    }

    /**
     * @param  int $scopeTestata 0 = the whole emeroteca.
     * @return list<array<string, mixed>>
     */
    private function findIssuesByBarcode(string $barcode, int $scopeTestata): array
    {
        $sql = 'SELECT f.id, f.numero, f.stato, f.annata_id, a.anno, a.testata_id, t.titolo
                  FROM emeroteca_fascicoli f
                  JOIN emeroteca_annate a  ON a.id = f.annata_id
                  JOIN emeroteca_testate t ON t.id = a.testata_id
                 WHERE f.barcode = ?';
        if ($scopeTestata > 0) {
            $sql .= ' AND a.testata_id = ?';
        }
        // Constant, not a bound parameter: LIMIT takes no placeholder here.
        $sql .= ' ORDER BY f.id LIMIT ' . self::AMBIGUITY_PROBE_LIMIT;

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] scan lookup prepare failed: ' . $this->db->error);
            return [];
        }
        if ($scopeTestata > 0) {
            $stmt->bind_param('si', $barcode, $scopeTestata);
        } else {
            $stmt->bind_param('s', $barcode);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] scan lookup failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $rows = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Payload for a code that resolves to more than one issue: no issue is
     * proposed, the operator is sent to the testata to finish by hand.
     *
     * The testata shown is the one ALL the matches share; when they belong to
     * different titles there is nothing honest to link to, so the code's own
     * EAN base is tried and, failing that, no link is offered at all.
     *
     * @param  list<array<string, mixed>> $matches
     * @return array<string, mixed>
     */
    private function ambiguousPayload(array $matches, string $base): array
    {
        $testataIds = [];
        foreach ($matches as $match) {
            $testataIds[(int) $match['testata_id']] = (string) $match['titolo'];
        }

        $title = null;
        if (count($testataIds) === 1) {
            $id = (int) array_key_first($testataIds);
            $title = ['id' => $id, 'titolo' => $testataIds[$id]];
        } else {
            $found = $this->findTestataByBarcodeBase($base);
            if ($found !== null) {
                $title = ['id' => (int) $found['id'], 'titolo' => (string) $found['titolo']];
            }
        }

        $payload = [
            'found'     => true,
            'match'     => 'ambiguous',
            'action'    => null,
            'ambiguous' => true,
            'matches'   => count($matches),
        ];

        if ($title === null) {
            $payload['title'] = null;
            $payload['message'] = sprintf(
                __('Codice ambiguo: %d fascicoli di testate diverse hanno questo codice a barre. Individua il fascicolo a mano.'),
                count($matches)
            );
            return $payload;
        }

        $payload['title'] = [
            'id'     => $title['id'],
            'titolo' => $title['titolo'],
            'url'    => url('/admin/periodicals/' . $title['id'] . '/issues'),
        ];
        $payload['message'] = sprintf(
            __('Codice ambiguo: %1$d fascicoli hanno questo codice a barre. Apri la testata «%2$s» e scegli il fascicolo.'),
            count($matches),
            $title['titolo']
        );

        return $payload;
    }

    /**
     * First scalar value of a query parameter.
     *
     * `?code[]=x` makes Slim hand over an ARRAY: a bare (string) cast raises
     * "Array to string conversion", and that warning is emitted before the
     * JSON body, so the caller's response.json() fails on a malformed
     * document. Arrays collapse to '' — a scanned barcode is never a list.
     *
     * @param array<string, mixed> $params
     */
    private static function scalarParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The testata owning this 977 EAN-13 base.
     *
     * @return array<string, mixed>|null
     */
    private function findTestataByBarcodeBase(string $base): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, titolo FROM emeroteca_testate WHERE barcode_base = ? ORDER BY id LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] scan title lookup prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('s', $base);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] scan title lookup failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * JSON response with the safe flags: the payload is consumed by
     * fetch(), never injected into HTML, but the HEX flags cost nothing and
     * keep the body inert if it ever ends up inline.
     *
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $response->getBody()->write($json === false ? '{"found":false,"match":"none"}' : $json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
