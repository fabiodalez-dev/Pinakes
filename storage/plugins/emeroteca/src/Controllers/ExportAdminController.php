<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';
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
        $testataId = isset($params['testata']) ? (int) $params['testata'] : 0;
        $testata = null;
        if ($testataId > 0) {
            $testata = $this->fetchTestata($testataId);
            if ($testata === null) {
                $this->flashError(__('Testata non trovata.'));
                return $this->redirect($response, '/admin/periodicals');
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
                $testataId > 0 ? '/admin/periodicals/' . $testataId . '/issues' : '/admin/periodicals'
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
            return $this->redirect($response, '/admin/periodicals');
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

        $ids = $this->issueIdsOfTestata($testataId, $requested);
        if ($ids === []) {
            $this->flashError(__('Nessun fascicolo selezionato.'));
            return $this->redirect($response, $back);
        }

        try {
            $pdf = IssueLabelRenderer::labelsPdf($this->db, $ids);
        } catch (\Throwable $e) {
            SecureLogger::error('[Emeroteca] label PDF generation failed: ' . $e->getMessage());
            $this->flashError(__('Stampa etichette non disponibile: la libreria PDF non è installata su questo server.'));
            return $this->redirect($response, $back);
        }
        if ($pdf === '') {
            $this->flashError(__('Nessun fascicolo selezionato.'));
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
     * @param  list<int> $requested
     * @return list<int>
     */
    private function issueIdsOfTestata(int $testataId, array $requested): array
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
            return [];
        }
        $params = array_merge([$testataId], $requested);
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] label id filter failed: ' . $stmt->error);
            $stmt->close();
            return [];
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
        $raw = trim((string) ($params['code'] ?? ''));
        $scopeTestata = isset($params['testata']) ? (int) $params['testata'] : 0;

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

        $issue = $this->findIssueByBarcode($digits, $scopeTestata);
        if ($issue === null && strlen($digits) > self::BARCODE_BASE_LEN) {
            $issue = $this->findIssueByBarcode(substr($digits, 0, self::BARCODE_BASE_LEN), $scopeTestata);
        }
        if ($issue !== null) {
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

        $base = strlen($digits) >= self::BARCODE_BASE_LEN
            ? substr($digits, 0, self::BARCODE_BASE_LEN)
            : $digits;
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
     * One issue with this exact barcode. When a scope testata is given its
     * own issues are preferred, so scanning inside a title never jumps to a
     * homonymous code elsewhere in the emeroteca.
     *
     * @return array<string, mixed>|null
     */
    private function findIssueByBarcode(string $barcode, int $scopeTestata): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.id, f.numero, f.stato, f.annata_id, a.anno, a.testata_id, t.titolo
               FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a  ON a.id = f.annata_id
               JOIN emeroteca_testate t ON t.id = a.testata_id
              WHERE f.barcode = ?
              ORDER BY (a.testata_id = ?) DESC, f.id
              LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Emeroteca] scan lookup prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('si', $barcode, $scopeTestata);
        if (!$stmt->execute()) {
            SecureLogger::error('[Emeroteca] scan lookup failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
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
