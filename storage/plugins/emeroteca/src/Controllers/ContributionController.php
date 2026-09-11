<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Controllers;

require_once __DIR__ . '/AbstractAdminController.php';
require_once __DIR__ . '/../Services/ContributionService.php';
require_once __DIR__ . '/../Services/ContributionCsv.php';
use App\Plugins\Emeroteca\Services\ContributionService;
use App\Plugins\Emeroteca\Services\ContributionCsv;
use App\Support\SecureLogger;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class ContributionController extends AbstractAdminController
{
    private function service(): ContributionService
    {
        return new ContributionService($this->db);
    }
    public function index(Request $rq, Response $rs, array $args = []): Response
    {
        $q = $rq->getQueryParams();
        $term = is_string($q['q'] ?? null) ? $q['q'] : '';
        $testata = (int)($q['testata'] ?? 0);
        $source = ($q['source'] ?? '') === 'spoglio' ? 'spoglio' : 'autonomo';
        $results = $source === 'spoglio' ? $this->service()->indexedSearch($term, $testata, (int)($q['page'] ?? 1)) : $this->service()->search($term, $testata, false, (int)($q['page'] ?? 1));
        // The association form exists only on the standalone tab with rows to act on.
        $canAssociate = $source === 'autonomo' && !empty($results['rows']);
        return $this->renderView($rs, 'articles', $results + ['source' => $source,
            'term' => $term,'testata' => $testata,'mode' => $this->service()->mode(),
            'destination' => (int)($q['destination'] ?? 0),
            'issues' => $canAssociate ? $this->service()->rows("SELECT f.id,f.numero,a.anno,a.volume,t.id testata_id,t.titolo FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id JOIN emeroteca_testate t ON t.id=a.testata_id ORDER BY t.titolo,a.anno DESC,f.numero") : [],
            'testate' => $canAssociate ? $this->service()->rows('SELECT id,titolo FROM emeroteca_testate ORDER BY titolo') : []]);
    }
    public function form(Request $rq, Response $rs, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? $this->service()->get($id) : [];
        if ($row === null) {
            return $rs->withStatus(404);
        }
        return $this->renderView($rs, 'article-form', ['row' => $row,'error' => null]);
    }
    public function save(Request $rq, Response $rs, array $args = []): Response
    {
        $body = (array)$rq->getParsedBody();
        $id = (int)($body['id'] ?? 0);
        $old = $id ? $this->service()->get($id) : null;
        if ($id && !$old) {
            return $rs->withStatus(404);
        }
        $pdf = [];
        $newPath = null;
        try {
            ContributionService::normalize($body);
            $file = $rq->getUploadedFiles()['pdf'] ?? null;
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                if ($file->getError() !== UPLOAD_ERR_OK || !$file->getSize() || $file->getSize() > 25 * 1024 * 1024) {
                    throw new \InvalidArgumentException(__('PDF non valido o superiore a 25 MB.'));
                }
                $head = $file->getStream()->read(1024);
                $file->getStream()->rewind();
                if (!str_starts_with($head, '%PDF-') || strtolower(pathinfo($file->getClientFilename() ?? '', PATHINFO_EXTENSION)) !== 'pdf') {
                    throw new \InvalidArgumentException(__('Carica un documento PDF.'));
                }
                $dir = self::pdfDir();
                if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException('PDF directory unavailable');
                }
                $name = bin2hex(random_bytes(20)).'.pdf';
                $newPath = $dir.'/'.$name;
                $file->moveTo($newPath);
                if ((new \finfo(FILEINFO_MIME_TYPE))->file($newPath) !== 'application/pdf') {
                    throw new \InvalidArgumentException(__('Carica un documento PDF.'));
                }
                $pdf = ['pdf_path' => $name,'pdf_nome_originale' => mb_substr(basename($file->getClientFilename() ?? 'articolo.pdf'), 0, 255),'pdf_dimensione' => filesize($newPath)];
            } elseif (!empty($body['remove_pdf'])) {
                $pdf = ['pdf_path' => null,'pdf_nome_originale' => null,'pdf_dimensione' => null];
                $body['pdf_pubblico'] = 0;
            }
            $id = $this->service()->save($body, $id, isset($body['revision']) ? (int)$body['revision'] : null, $pdf);
            if ($pdf && !empty($old['pdf_path'])) {
                self::removePdf((string)$old['pdf_path']);
            }
            $this->flashSuccess(__('Articolo salvato.'));
            return $this->redirect($rs, '/admin/periodicals/articles/'.$id);
        } catch (\Throwable $e) {
            if ($newPath && is_file($newPath)) {
                unlink($newPath);
            }
            if (!$e instanceof \InvalidArgumentException) {
                SecureLogger::error('[Emeroteca] contribution save: '.$e->getMessage());
            }
            // An unchecked box is absent from the POST body, so the stored row
            // would win and the re-rendered form would show the article as
            // still published: the operator would republish it by resubmitting.
            $flags = [];
            foreach (['pubblico','pdf_pubblico','remove_pdf'] as $flag) {
                $flags[$flag] = empty($body[$flag]) ? 0 : 1;
            }
            return $this->renderView($rs->withStatus(422), 'article-form', ['row' => array_replace($old ?? [], $body, $flags),'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : __('Salvataggio non riuscito.')]);
        }
    }
    public function mode(Request $rq, Response $rs, array $args = []): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $rs->withStatus(403);
        }
        try {
            $this->service()->setMode((string)(((array)$rq->getParsedBody())['mode'] ?? ''));
            $this->flashSuccess(__('Impostazioni salvate.'));
        } catch (\InvalidArgumentException $e) {
            // A bare status code leaves a blank page: this is a plain form POST.
            $this->flashError($e->getMessage());
        }
        return $this->redirect($rs, self::modeReturnTo($rq));
    }

    /**
     * Where to send the operator back after choosing the workflow.
     *
     * The chooser is required by three views — the mastheads list, the
     * articles list and the plugin settings page — so a fixed target would
     * always be wrong for two of them. The view posts where it was rendered;
     * an allowlist keeps that from becoming an open redirect, and the bare
     * mastheads path is deliberately absent because in Simple mode it only
     * bounces on to the articles list.
     */
    private static function modeReturnTo(Request $rq): string
    {
        $allowed = [
            '/admin/periodicals?view=titles',
            '/admin/periodicals/articles',
            '/admin/plugins',
        ];
        $wanted = (string)(((array)$rq->getParsedBody())['return_to'] ?? '');
        return in_array($wanted, $allowed, true) ? $wanted : '/admin/periodicals/articles';
    }

    public function associate(Request $rq, Response $rs, array $args = []): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $rs->withStatus(403);
        }
        $b = (array)$rq->getParsedBody();
        try {
            if (($b['step'] ?? '') === 'confirm') {
                $token = (string)($b['token'] ?? '');
                $pending = $_SESSION['emeroteca_associate'][$token] ?? null;
                if (!$pending || time() - $pending['time'] > 1800) {
                    unset($_SESSION['emeroteca_associate'][$token]);
                    throw new \InvalidArgumentException(__('Anteprima scaduta. Ripeti la selezione.'));
                }
                // The preview used to be destroyed before this call, so a failure
                // that is only detectable here — a reassignment not yet confirmed,
                // a selection edited meanwhile — cost the operator a selection of
                // up to 500 articles. Nothing is committed when associate() throws
                // (it runs in a transaction), so rebuild the preview from the
                // stored selection instead: fresh revisions and a fresh token, so a
                // stale-revision rejection cannot repeat on the same data.
                unset($_SESSION['emeroteca_associate'][$token]);
                try {
                    $target = $this->service()->associate($pending['revisions'], $pending['testata'], $pending['fascicolo'], $pending['new_title'], !empty($b['reassign']));
                } catch (\InvalidArgumentException $e) {
                    $this->flashError($e->getMessage());
                    return $this->associatePreview($rs, array_keys($pending['revisions']), (int)$pending['testata'], (string)$pending['new_title'], (int)$pending['fascicolo']);
                }
                $this->flashSuccess(__('Articoli associati. Citazioni e allegati conservati.'));
                return $this->redirect($rs, '/admin/periodicals/articles'.($target ? '?testata='.$target : ''));
            }
            return $this->associatePreview($rs, $b['ids'] ?? [], (int)($b['testata_id'] ?? 0), trim((string)($b['new_title'] ?? '')), (int)($b['fascicolo_id'] ?? 0));
        } catch (\Throwable $e) {
            if (!$e instanceof \InvalidArgumentException) {
                SecureLogger::error('[Emeroteca] associate: '.$e->getMessage());
            }
            $this->flashError($e instanceof \InvalidArgumentException ? $e->getMessage() : __('Associazione non riuscita.'));
            return $this->redirect($rs, '/admin/periodicals/articles');
        }
    }

    /**
     * Validate a selection and render the association preview with a new token.
     *
     * Shared by the first request and by a confirm that failed recoverably, so
     * both show exactly the same checks.
     *
     * @param mixed $ids the submitted article ids
     */
    private function associatePreview(Response $rs, mixed $ids, int $target, string $new, int $issue): Response
    {
        if (!is_array($ids) || !$ids || count($ids) > 500) {
            throw new \InvalidArgumentException(__('Seleziona da 1 a 500 articoli.'));
        }
        $rows = [];
        $revisions = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $row = $this->service()->get($id);
            if (!$row) {
                throw new \InvalidArgumentException(__('Articolo non trovato.'));
            }
            $row['testata_titolo'] = $row['testata_id'] ? ($this->service()->rows('SELECT titolo FROM emeroteca_testate WHERE id=?', [$row['testata_id']])[0]['titolo'] ?? '') : '';
            $rows[] = $row;
            $revisions[$id] = (int)$row['revision'];
        }
        $host = $target ? $this->service()->rows('SELECT titolo FROM emeroteca_testate WHERE id=?', [$target])[0] ?? null : null;
        if ($target && !$host) {
            throw new \InvalidArgumentException(__('Testata non trovata.'));
        }
        if ($new !== '' && $target) {
            throw new \InvalidArgumentException(__('Scegli una testata esistente oppure creane una.'));
        }
        $issueLabel = '';
        if ($issue) {
            $issueRow = $this->service()->rows('SELECT f.numero,a.anno,a.volume FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id WHERE f.id=? AND a.testata_id=?', [$issue,$target])[0] ?? null;
            if (!$issueRow) {
                throw new \InvalidArgumentException(__('Il fascicolo non appartiene alla testata.'));
            }
            $issueLabel = implode(' · ', array_filter([(string)$issueRow['anno'], (string)$issueRow['volume'], (string)$issueRow['numero']]));
        }
        $token = bin2hex(random_bytes(20));
        $pending = self::pendingSlot('emeroteca_associate');
        $pending[$token] = ['time' => time(),'revisions' => $revisions,'testata' => $target,'fascicolo' => $issue,'new_title' => $new];
        $_SESSION['emeroteca_associate'] = self::keepRecentPending($pending);
        return $this->renderView($rs, 'article-associate', ['rows' => $rows,'token' => $token,'target_title' => $new !== '' ? $new : ($host['titolo'] ?? __('Nessuna testata')),'issue_label' => $issueLabel]);
    }
    public function delete(Request $rq, Response $rs, array $args = []): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $rs->withStatus(403);
        }
        $id = (int)($args['id'] ?? 0);
        $row = $this->service()->get($id);
        if (!$row) {
            return $rs->withStatus(404);
        }
        $b = (array)$rq->getParsedBody();
        $this->service()->rows('DELETE FROM emeroteca_contributi WHERE id=? AND revision=?', [$id,(int)($b['revision'] ?? 0)]);
        if ($this->service()->get($id)) {
            // A bare 409 leaves a blank page: this is a plain form POST.
            $this->flashError(__('L’articolo è stato modificato da qualcun altro. Ricarica la pagina e riprova.'));
            return $this->redirect($rs, '/admin/periodicals/articles/'.$id);
        }
        self::removePdf((string)($row['pdf_path'] ?? ''));
        $this->flashSuccess(__('Articolo eliminato.'));
        return $this->redirect($rs, '/admin/periodicals/articles');
    }
    public function importForm(Request $rq, Response $rs, array $args = []): Response
    {
        return $this->renderView($rs, 'article-import', ['preview' => null,'report' => null]);
    }
    public function importSubmit(Request $rq, Response $rs, array $args = []): Response
    {
        $csv = new ContributionCsv($this->service());
        $b = (array)$rq->getParsedBody();
        try {
            if (isset($b['token'])) {
                $token = (string)$b['token'];
                $pending = $_SESSION['emeroteca_csv'][$token] ?? null;
                unset($_SESSION['emeroteca_csv'][$token]);
                if (!$pending || time() - $pending['time'] > 1800) {
                    throw new \InvalidArgumentException(__('Anteprima scaduta. Ricarica il CSV.'));
                }
                return $this->renderView($rs, 'article-import', ['preview' => null,'report' => $csv->commit($pending['rows'])]);
            }
            $file = $rq->getUploadedFiles()['csv'] ?? null;
            if (!$file || $file->getError() !== UPLOAD_ERR_OK || $file->getSize() > 5 * 1024 * 1024) {
                throw new \InvalidArgumentException(__('Carica un CSV fino a 5 MB.'));
            }
            $preview = $csv->preview((string)$file->getStream());
            $token = bin2hex(random_bytes(20));
            $pending = self::pendingSlot('emeroteca_csv');
            $pending[$token] = ['time' => time(),'rows' => $preview];
            $_SESSION['emeroteca_csv'] = self::keepRecentPending($pending);
            return $this->renderView($rs, 'article-import', ['preview' => $preview,'token' => $token,'report' => null]);
        } catch (\Throwable $e) {
            if (!$e instanceof \InvalidArgumentException) {
                SecureLogger::error('[Emeroteca] contribution import: '.$e->getMessage());
            }
            $this->flashError($e instanceof \InvalidArgumentException ? $e->getMessage() : __('Errore di sistema durante l\'importazione'));
            return $this->redirect($rs, '/admin/periodicals/articles/import');
        }
    }
    public function export(Request $rq, Response $rs, array $args = []): Response
    {
        // The dump carries note_private and collocazione of unpublished rows.
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $rs->withStatus(403);
        }
        if (($rq->getQueryParams()['template'] ?? '') === '1') {
            $rs->getBody()->write(implode(',', ContributionService::CSV_HEADER)."\n");
        } else {
            $rs->getBody()->write((new ContributionCsv($this->service()))->export());
        }
        return $rs->withHeader('Content-Type', 'text/csv; charset=UTF-8')->withHeader('Content-Disposition', 'attachment; filename="emeroteca-articoli.csv"')->withHeader('Cache-Control', 'private, no-store');
    }
    /**
     * Previews already parked in the session, tolerating a missing or
     * tampered slot.
     *
     * @return array<string, mixed>
     */
    private static function pendingSlot(string $key): array
    {
        $slot = $_SESSION[$key] ?? null;
        return is_array($slot) ? $slot : [];
    }

    /**
     * Newest previews only. Replacing the whole slot would let a second tab
     * destroy the first preview, which then reported a false expiry; keeping
     * every preview instead would let the session grow without bound.
     *
     * @param  array<string, mixed> $pending
     * @return array<string, mixed>
     */
    private static function keepRecentPending(array $pending, int $max = 5): array
    {
        uasort($pending, static function ($a, $b): int {
            $left = is_array($a) && isset($a['time']) && is_numeric($a['time']) ? (int)$a['time'] : 0;
            $right = is_array($b) && isset($b['time']) && is_numeric($b['time']) ? (int)$b['time'] : 0;
            return $right <=> $left;
        });
        return array_slice($pending, 0, $max, true);
    }

    private static function pdfDir(): string
    {
        return __DIR__.'/../../../../uploads/plugins/emeroteca/contributi';
    }
    private static function removePdf(string $name): void
    {
        if (preg_match('/^[a-f0-9]{40}\.pdf$/D', $name) && is_file(self::pdfDir().'/'.$name)) {
            unlink(self::pdfDir().'/'.$name);
        }
    }
    public function pdf(Request $rq, Response $rs, array $args = []): Response
    {
        return $this->servePdf($rs, (int)($args['id'] ?? 0), false);
    }
    public function publicPdf(Request $rq, Response $rs, array $args = []): Response
    {
        return $this->servePdf($rs, (int)($args['id'] ?? 0), true);
    }
    private function servePdf(Response $rs, int $id, bool $public): Response
    {
        $row = $this->service()->get($id, $public);
        $name = (string)($row['pdf_path'] ?? '');
        if (!$row || ($public && !$row['pdf_pubblico']) || !preg_match('/^[a-f0-9]{40}\.pdf$/D', $name)) {
            return $rs->withStatus(404)->withHeader('Cache-Control', 'private, no-store');
        }
        $base = realpath(self::pdfDir());
        $path = $base ? realpath($base.'/'.$name) : false;
        if (!$path || !str_starts_with($path, $base.DIRECTORY_SEPARATOR) || !is_file($path)) {
            return $rs->withStatus(404);
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return $rs->withStatus(404);
        }
        return $rs->withBody(new \Slim\Psr7\Stream($handle))->withHeader('Content-Type','application/pdf')->withHeader('Content-Length',(string)filesize($path))->withHeader('X-Content-Type-Options','nosniff')->withHeader('Cache-Control','private, no-store')->withHeader('Content-Disposition','inline; filename="articolo.pdf"');
    }
}
