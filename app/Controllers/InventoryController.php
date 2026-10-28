<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\StocktakeService;
use App\Support\Csv;
use App\Support\SecureLogger;
use App\Support\StocktakePdfGenerator;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin → Inventory stocktake (/admin/inventory). Route access is
 * `inventory.manage` (Permissions::permissionForPath); closing a session while
 * marking the missing copies as lost is destructive and needs an administrator,
 * checked inline because AdminAuthMiddleware also admits Staff.
 */
class InventoryController
{
    private const UPLOAD_MAX_BYTES = 2_097_152;

    private function operatorId(): ?int
    {
        $id = (int) ($_SESSION['user']['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['user']['tipo_utente'] ?? '') === 'admin';
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', url($path))->withStatus(302);
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    private function render(Response $response, string $view, array $vars): Response
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/../Views/inventario/' . $view . '.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response;
    }

    /** @return array<string,string> */
    public static function outcomeLabels(): array
    {
        return [
            'trovata' => __('Trovata'),
            'fuori_posto' => __('Fuori posto'),
            'in_prestito' => __('In prestito o in attesa di ritiro'),
            'mancante' => __('Mancante'),
            'sconosciuta' => __('Codice sconosciuto'),
            'fuori_ambito' => __('Fuori ambito'),
            'duplicata' => __('Già scansionata'),
        ];
    }

    /** @return array{scaffali:list<array<string,mixed>>,mensole:list<array<string,mixed>>,generi:list<array{id:int,label:string}>,sedi:list<array<string,mixed>>} */
    private function lookups(mysqli $db): array
    {
        $repo = new \App\Models\CollocationRepository($db);
        $genres = [];
        $res = $db->query('SELECT id, nome, parent_id FROM generi ORDER BY nome');
        $byParent = [];
        if ($res) {
            foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
                $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
            }
        }
        $walk = static function (int $parent, int $depth, array &$seen) use (&$walk, &$genres, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id]) || $depth > 6) {
                    continue;
                }
                $seen[$id] = true;
                $genres[] = ['id' => $id, 'label' => str_repeat('— ', $depth) . (string) $row['nome']];
                $walk($id, $depth + 1, $seen);
            }
        };
        $seen = [];
        $walk(0, 0, $seen);
        $sedi = [];
        $res = $db->query('SELECT id, nome FROM sedi WHERE is_active = 1 ORDER BY nome');
        if ($res) {
            $sedi = $res->fetch_all(MYSQLI_ASSOC);
        }
        return ['scaffali' => $repo->getScaffali(), 'mensole' => $repo->getMensole(), 'generi' => $genres, 'sedi' => $sedi];
    }

    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $sessions = (new StocktakeService($db))->listSessions();
        return $this->render($response, 'index', ['sessions' => $sessions] + $this->lookups($db));
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array) $request->getParsedBody();
        $name = is_string($data['nome'] ?? null) ? $data['nome'] : '';
        $note = is_string($data['note'] ?? null) ? $data['note'] : '';
        $sede = is_scalar($data['sede_id'] ?? null) ? (int) $data['sede_id'] : 0;
        try {
            $id = (new StocktakeService($db))->open($name, $sede > 0 ? $sede : null, $data, $this->operatorId(), $note);
        } catch (\InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            return $this->redirect($response, '/admin/inventory');
        } catch (\Throwable $e) {
            SecureLogger::error('[Stocktake] open failed', ['error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile aprire la revisione. Riprova più tardi.');
            return $this->redirect($response, '/admin/inventory');
        }
        $_SESSION['success_message'] = __('Revisione aperta: le copie attese sono state fissate. Puoi iniziare a scansionare.');
        return $this->redirect($response, '/admin/inventory/' . $id);
    }

    public function show(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $service = new StocktakeService($db);
        $session = $service->find($id);
        if ($session === null) {
            $_SESSION['error_message'] = __('Revisione non trovata.');
            return $this->redirect($response, '/admin/inventory');
        }
        $operatorId = $this->operatorId();
        $mine = ($request->getQueryParams()['mine'] ?? '') === '1';
        $filterLabels = $this->filterLabels($db, $service, $session['filtro']);
        return $this->render($response, 'show', [
            'session' => $session,
            'summary' => $service->summary($id, $operatorId),
            'scans' => $service->scans($id, $mine ? $operatorId : null, 50),
            'mineOnly' => $mine,
            'reportRows' => $session['stato'] === 'aperta' ? [] : $service->reportRows($id),
            'filterLabels' => $filterLabels,
            'isAdmin' => $this->isAdmin(),
            'outcomeLabels' => self::outcomeLabels(),
            'scaffali' => (new \App\Models\CollocationRepository($db))->getScaffali(),
        ]);
    }

    /**
     * @param array<string,mixed> $filter
     * @return list<string>
     */
    private function filterLabels(mysqli $db, StocktakeService $service, array $filter): array
    {
        $labels = [];
        if (isset($filter['scaffale_id'])) {
            $labels[] = __('Scaffale') . ': ' . $service->shelfLabel((int) $filter['scaffale_id']);
        }
        if (isset($filter['mensola_id'])) {
            $stmt = $db->prepare('SELECT m.numero_livello, s.codice FROM mensole m JOIN scaffali s ON s.id = m.scaffale_id WHERE m.id = ?');
            if ($stmt !== false) {
                $mid = (int) $filter['mensola_id'];
                $stmt->bind_param('i', $mid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (is_array($row)) {
                    $labels[] = __('Mensola') . ': ' . (string) $row['codice'] . ' - ' . __('Livello') . ' ' . (int) $row['numero_livello'];
                }
            }
        }
        if (isset($filter['genere_id'])) {
            $stmt = $db->prepare('SELECT nome FROM generi WHERE id = ?');
            if ($stmt !== false) {
                $gid = (int) $filter['genere_id'];
                $stmt->bind_param('i', $gid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $labels[] = __('Genere') . ': ' . (string) ($row[0] ?? ('#' . $gid));
            }
        }
        if (isset($filter['tipo_media'])) {
            $labels[] = __('Tipo media') . ': ' . \App\Support\MediaLabels::tipoMediaDisplayName((string) $filter['tipo_media']);
        }
        if (isset($filter['collocazione_da']) || isset($filter['collocazione_a'])) {
            $labels[] = __('Collocazione') . ': ' . (string) ($filter['collocazione_da'] ?? '…') . ' → ' . (string) ($filter['collocazione_a'] ?? '…');
        }
        return $labels;
    }

    private function scanShelf(array $data): ?int
    {
        $shelf = $data['scaffale_corrente'] ?? null;
        return is_scalar($shelf) && (int) $shelf > 0 ? (int) $shelf : null;
    }

    public function scan(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array) $request->getParsedBody();
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);
            $data = is_array($decoded) ? $decoded : [];
        }
        $code = is_string($data['codice'] ?? null) ? $data['codice'] : '';
        $service = new StocktakeService($db);
        try {
            $result = $service->scan($id, $code, $this->operatorId(), $this->scanShelf($data));
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            SecureLogger::error('[Stocktake] scan failed', ['sessione_id' => $id, 'error' => $e->getMessage()]);
            return $this->json($response, ['success' => false, 'message' => __('Impossibile registrare la scansione. Riprova.')], 500);
        }
        $labels = self::outcomeLabels();
        return $this->json($response, [
            'success' => true,
            'result' => $result + ['label' => $labels[$result['esito']] ?? $result['esito']],
            'summary' => $service->summary($id, $this->operatorId()),
        ]);
    }

    public function state(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $service = new StocktakeService($db);
        $session = $service->find($id);
        if ($session === null) {
            return $this->json($response, ['success' => false, 'message' => __('Revisione non trovata.')], 404);
        }
        $operatorId = $this->operatorId();
        $mine = ($request->getQueryParams()['mine'] ?? '') === '1';
        $labels = self::outcomeLabels();
        $scans = array_map(static fn (array $row): array => [
            'codice' => (string) $row['codice'],
            'esito' => (string) $row['esito'],
            'label' => $labels[(string) $row['esito']] ?? (string) $row['esito'],
            'titolo' => (string) ($row['titolo'] ?? ''),
            'operatore' => (string) ($row['operatore'] ?? ''),
            'quando' => format_date((string) $row['scansionato_il'], true, '/'),
        ], $service->scans($id, $mine ? $operatorId : null, 50));
        return $this->json($response, [
            'success' => true,
            'stato' => $session['stato'],
            'summary' => $service->summary($id, $operatorId),
            'scans' => $scans,
        ]);
    }

    public function upload(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $files = $request->getUploadedFiles();
        $file = $files['lista'] ?? null;
        $back = '/admin/inventory/' . $id;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = __('Seleziona un file .txt o .csv con un codice per riga.');
            return $this->redirect($response, $back);
        }
        $extension = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'csv'], true) || (int) $file->getSize() > self::UPLOAD_MAX_BYTES) {
            $_SESSION['error_message'] = __('Il file deve essere un .txt o .csv di al massimo 2 MB.');
            return $this->redirect($response, $back);
        }
        $data = (array) $request->getParsedBody();
        $service = new StocktakeService($db);
        try {
            $codes = StocktakeService::parseCodeList((string) $file->getStream());
            $result = $service->importCodes($id, $codes, $this->operatorId(), $this->scanShelf($data));
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            return $this->redirect($response, $back);
        } catch (\Throwable $e) {
            SecureLogger::error('[Stocktake] upload failed', ['sessione_id' => $id, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile elaborare il file. Riprova.');
            return $this->redirect($response, $back);
        }
        $labels = self::outcomeLabels();
        $parts = [];
        foreach ($result['outcomes'] as $outcome => $count) {
            if ($count > 0) {
                $parts[] = ($labels[$outcome] ?? $outcome) . ': ' . $count;
            }
        }
        $_SESSION['success_message'] = sprintf(__('Elaborati %d codici dal file.'), $result['total']) . ($parts !== [] ? ' ' . implode(' · ', $parts) : '');
        return $this->redirect($response, $back);
    }

    public function close(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array) $request->getParsedBody();
        $markLost = ($data['azione_mancanti'] ?? '') === 'perso';
        $back = '/admin/inventory/' . $id;
        if ($markLost && !$this->isAdmin()) {
            // Destructive: AdminAuthMiddleware admits Staff and custom roles too.
            $_SESSION['error_message'] = __('Solo un amministratore può chiudere la revisione segnando come perse le copie mancanti.');
            return $this->redirect($response, $back)->withStatus(302);
        }
        if ($markLost) {
            // The session is claimed before the copies are marked, so a close
            // cut off halfway cannot be retried from the page: let it finish
            // even on a whole-catalogue session or if the browser goes away.
            @set_time_limit(0);
            ignore_user_abort(true);
        }
        try {
            $result = (new StocktakeService($db))->close($id, $markLost, $this->operatorId());
        } catch (\DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            return $this->redirect($response, $back);
        } catch (\Throwable $e) {
            SecureLogger::error('[Stocktake] close failed', ['sessione_id' => $id, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile chiudere la revisione. Riprova più tardi.');
            return $this->redirect($response, $back);
        }
        $message = $markLost
            ? sprintf(__('Revisione chiusa: %d copie mancanti segnate come perse.'), $result['marked'])
            : __('Revisione chiusa: le copie mancanti sono rimaste invariate.');
        if ($result['failed'] > 0) {
            $_SESSION['error_message'] = sprintf(__('%d copie non sono state aggiornate: controlla il registro degli errori.'), $result['failed']);
        }
        $_SESSION['success_message'] = $message;
        return $this->redirect($response, $back);
    }

    public function cancel(Request $request, Response $response, mysqli $db, int $id): Response
    {
        try {
            (new StocktakeService($db))->cancel($id, $this->operatorId());
        } catch (\DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            return $this->redirect($response, '/admin/inventory/' . $id);
        } catch (\Throwable $e) {
            SecureLogger::error('[Stocktake] cancel failed', ['sessione_id' => $id, 'error' => $e->getMessage()]);
            $_SESSION['error_message'] = __('Impossibile annullare la revisione. Riprova più tardi.');
            return $this->redirect($response, '/admin/inventory/' . $id);
        }
        $_SESSION['success_message'] = __('Revisione annullata. Nessuna copia è stata modificata.');
        return $this->redirect($response, '/admin/inventory');
    }

    private function fileBase(array $session): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $session['nome']) ?? '';
        $slug = trim($slug, '-');
        return 'revisione-' . (int) $session['id'] . ($slug !== '' ? '-' . strtolower(substr($slug, 0, 40)) : '');
    }

    public function exportCsv(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $service = new StocktakeService($db);
        $session = $service->find($id);
        if ($session === null) {
            $response->getBody()->write(__('Revisione non trovata.'));
            return $response->withStatus(404);
        }
        $labels = self::outcomeLabels();
        $writer = Csv::writerToString(',', "'");
        $writer->insertOne([__('Esito'), __('Codice copia'), __('Titolo'), __('Collocazione'), __('Scaffale atteso'), __('Stato copia'), __('Operatore'), __('Data scansione')]);
        foreach ($service->reportRows($id) as $row) {
            $writer->insertOne([
                $labels[$row['categoria']] ?? $row['categoria'],
                $row['codice'],
                $row['titolo'],
                $row['collocazione'],
                $row['scaffale_atteso'],
                $row['stato_copia'],
                $row['operatore'],
                $row['quando'],
            ]);
        }
        $response->getBody()->write("\xEF\xBB\xBF" . $writer->toString());
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->fileBase($session) . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function exportPdf(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $service = new StocktakeService($db);
        $session = $service->find($id);
        if ($session === null) {
            $response->getBody()->write(__('Revisione non trovata.'));
            return $response->withStatus(404);
        }
        $pdf = (new StocktakePdfGenerator())->generate(
            $session,
            $service->summary($id),
            $service->reportRows($id),
            $this->filterLabels($db, $service, $session['filtro']),
            self::outcomeLabels()
        );
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->fileBase($session) . '.pdf"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
