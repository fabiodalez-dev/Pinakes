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
        return $this->renderView($rs, 'articles', $results + ['source' => $source,
            'term' => $term,'testata' => $testata,'mode' => $this->service()->mode(),
            'destination' => (int)($q['destination'] ?? 0),
            'issues' => $this->service()->rows("SELECT f.id,f.numero,a.anno,a.volume,t.id testata_id,t.titolo FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id JOIN emeroteca_testate t ON t.id=a.testata_id ORDER BY t.titolo,a.anno DESC,f.numero"),
            'testate' => $this->service()->rows('SELECT id,titolo FROM emeroteca_testate ORDER BY titolo')]);
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
            return $this->renderView($rs->withStatus(422), 'article-form', ['row' => array_replace($old ?? [], $body),'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : __('Salvataggio non riuscito.')]);
        }
    }
    public function mode(Request $rq, Response $rs, array $args = []): Response
    {
        if (($_SESSION['user']['tipo_utente'] ?? '') !== 'admin') {
            return $rs->withStatus(403);
        }
        try {
            $this->service()->setMode((string)(((array)$rq->getParsedBody())['mode'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $rs->withStatus(422);
        }
        return $this->redirect($rs, '/admin/periodicals');
    }
    public function associate(Request $rq, Response $rs, array $args = []): Response
    {
        $b = (array)$rq->getParsedBody();
        try {
            if (($b['step'] ?? '') === 'confirm') {
                $token = (string)($b['token'] ?? '');
                $pending = $_SESSION['emeroteca_associate'][$token] ?? null;
                unset($_SESSION['emeroteca_associate'][$token]);
                if (!$pending || time() - $pending['time'] > 1800) {
                    throw new \InvalidArgumentException(__('Anteprima scaduta. Ripeti la selezione.'));
                }
                $target = $this->service()->associate($pending['revisions'], $pending['testata'], $pending['fascicolo'], $pending['new_title'], !empty($b['reassign']));
                $this->flashSuccess(__('Articoli associati. Citazioni e allegati conservati.'));
                return $this->redirect($rs, '/admin/periodicals/articles'.($target ? '?testata='.$target : ''));
            }
            $ids = $b['ids'] ?? [];
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
            $target = (int)($b['testata_id'] ?? 0);
            $new = trim((string)($b['new_title'] ?? ''));
            $host = $target ? $this->service()->rows('SELECT titolo FROM emeroteca_testate WHERE id=?', [$target])[0] ?? null : null;
            if ($target && !$host) {
                throw new \InvalidArgumentException(__('Testata non trovata.'));
            }
            if ($new !== '' && $target) {
                throw new \InvalidArgumentException(__('Scegli una testata esistente oppure creane una.'));
            }
            $issue = (int)($b['fascicolo_id'] ?? 0);
            $issueLabel = '';
            if ($issue) {
                $issueRow = $this->service()->rows('SELECT f.numero,a.anno,a.volume FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id WHERE f.id=? AND a.testata_id=?', [$issue,$target])[0] ?? null;
                if (!$issueRow) {
                    throw new \InvalidArgumentException(__('Il fascicolo non appartiene alla testata.'));
                }
                $issueLabel = implode(' · ', array_filter([(string)$issueRow['anno'], (string)$issueRow['volume'], (string)$issueRow['numero']]));
            }
            $token = bin2hex(random_bytes(20));
            $_SESSION['emeroteca_associate'] = [$token => ['time' => time(),'revisions' => $revisions,'testata' => $target,'fascicolo' => (int)($b['fascicolo_id'] ?? 0),'new_title' => $new]];
            return $this->renderView($rs, 'article-associate', ['rows' => $rows,'token' => $token,'target_title' => $new !== '' ? $new : ($host['titolo'] ?? __('Nessuna testata')),'issue_label' => $issueLabel]);
        } catch (\Throwable $e) {
            if (!$e instanceof \InvalidArgumentException) {
                SecureLogger::error('[Emeroteca] associate: '.$e->getMessage());
            }
            $this->flashError($e instanceof \InvalidArgumentException ? $e->getMessage() : __('Associazione non riuscita.'));
            return $this->redirect($rs, '/admin/periodicals/articles');
        }
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
            return $rs->withStatus(409);
        }
        self::removePdf((string)($row['pdf_path'] ?? ''));
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
            $_SESSION['emeroteca_csv'] = [$token => ['time' => time(),'rows' => $preview]];
            return $this->renderView($rs, 'article-import', ['preview' => $preview,'token' => $token,'report' => null]);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            return $this->redirect($rs, '/admin/periodicals/articles/import');
        }
    }
    public function export(Request $rq, Response $rs, array $args = []): Response
    {
        if (($rq->getQueryParams()['template'] ?? '') === '1') {
            $rs->getBody()->write(implode(',', ContributionService::CSV_FIELDS)."\n");
        } else {
            $rs->getBody()->write((new ContributionCsv($this->service()))->export());
        }
        return $rs->withHeader('Content-Type', 'text/csv; charset=UTF-8')->withHeader('Content-Disposition', 'attachment; filename="emeroteca-articoli.csv"')->withHeader('Cache-Control', 'private, no-store');
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
