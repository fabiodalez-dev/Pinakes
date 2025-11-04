<?php
declare(strict_types=1);

namespace App\Controllers;

use mysqli;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LibriController
{
    private function logCoverDebug(string $label, array $data): void
    {
        // SECURITY: Logging disabilitato in produzione per prevenire information disclosure
        if (getenv('APP_ENV') === 'development') {
            $file = __DIR__ . '/../../storage/cover_debug.log';
            // Sanitizza dati sensibili prima di loggare
            $sanitized = $data;
            unset($sanitized['password'], $sanitized['token'], $sanitized['csrf_token']);
            $line = date('Y-m-d H:i:s') . " [$label] " . json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($file, $line, FILE_APPEND);
        }
    }
    public function index(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libri = $repo->listWithAuthors(100);

        ob_start();
        $data = ['libri' => $libri];
        // extract($data);
        require __DIR__ . '/../Views/libri/index.php';
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function show(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);
        if (!$libro) { return $response->withStatus(404); }
        $loanRepo = new \App\Models\LoanRepository($db);
        $activeLoan = $loanRepo->getActiveLoanByBook($id);
        $bookPath = '/admin/libri/' . $id;

        // Recupera tutte le copie del libro con informazioni sui prestiti
        $copyRepo = new \App\Models\CopyRepository($db);
        $copie = $copyRepo->getByBookId($id);

        // Get loan history for this book
        $loanHistoryQuery = "
            SELECT
                p.id,
                p.data_prestito,
                p.data_scadenza,
                p.data_restituzione,
                p.stato,
                p.renewals,
                p.note,
                u.nome as utente_nome,
                u.cognome as utente_cognome,
                u.email as utente_email,
                u.id as utente_id,
                staff.nome as staff_nome,
                staff.cognome as staff_cognome
            FROM prestiti p
            LEFT JOIN utenti u ON p.utente_id = u.id
            LEFT JOIN utenti staff ON p.processed_by = staff.id
            WHERE p.libro_id = ?
            ORDER BY p.data_prestito DESC
        ";
        $stmt = $db->prepare($loanHistoryQuery);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $loanHistory = [];
        while ($row = $result->fetch_assoc()) {
            $loanHistory[] = $row;
        }
        $stmt->close();

        ob_start();
        // extract([
        //     'libro' => $libro,
        //     'activeLoan' => $activeLoan,
        //     'bookPath' => $bookPath,
        //     'loanHistory' => $loanHistory,
        // ]);
        require __DIR__ . '/../Views/libri/scheda_libro.php';
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function createForm(Request $request, Response $response, mysqli $db): Response
    {
        // For select boxes, load minimal lists
        $editRepo = new \App\Models\PublisherRepository($db);
        $autRepo = new \App\Models\AuthorRepository($db);
        $editori = $editRepo->listBasic();
        $autori = $autRepo->listBasic(500);
        $colRepo = new \App\Models\CollocationRepository($db);
        $taxRepo = new \App\Models\TaxonomyRepository($db);
        $scaffali = $colRepo->getScaffali();
        $mensole = $colRepo->getMensole();
        $generi = $taxRepo->genres();
        $sottogeneri = $taxRepo->subgenres();
        ob_start();
        $data = ['editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri];
        // extract(['editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri]); 
        require __DIR__ . '/../Views/libri/crea_libro.php'; 
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function store(Request $request, Response $response, mysqli $db): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Sessione scaduta. Riprova.';
            return $response->withHeader('Location', '/admin/libri/crea')->withStatus(302);
        }
        
        // SECURITY: Debug logging rimosso per prevenire information disclosure
        // Il logging dettagliato è disponibile solo in ambiente development tramite AppLog
        
        // Merge all supported fields with defaults
        $fields = [
            'titolo'=>'', 'sottotitolo'=>'', 'isbn10'=>'', 'isbn13'=>'',
            'genere_id'=>0, 'sottogenere_id'=>0, 'editore_id'=>0,
            'data_acquisizione'=>null, 'tipo_acquisizione'=>'', 'descrizione'=>'', 'parole_chiave'=>'',
            'formato'=>'', 'peso'=>null, 'dimensioni'=>'', 'prezzo'=>null,
            'copie_totali'=>1, 'copie_disponibili'=>1, 'numero_inventario'=>'',
            'classificazione_dowey'=>'', 'collana'=>'', 'numero_serie'=>'', 'note_varie'=>'',
            'file_url'=>'', 'audio_url'=>'', 'copertina_url'=>'',
            'scaffale_id'=>0, 'mensola_id'=>0, 'posizione_progressiva'=>0,
            'posizione_id'=>0, 'collocazione'=>'', 'stato'=>''
        ];
        foreach ($fields as $k=>$v) { if (array_key_exists($k, $data)) $fields[$k] = $data[$k]; }

        // Merge scraped subtitle and notes if present
        $subtitleFromScrape = trim((string)($data['subtitle'] ?? ''));
        if ($subtitleFromScrape !== '') {
            $fields['sottotitolo'] = $subtitleFromScrape;
        }

        $notesParts = [];
        $currentNotes = trim((string)($fields['note_varie'] ?? ''));
        if ($currentNotes !== '') {
            $notesParts[] = $currentNotes;
        }
        $notesFromScrape = trim((string)($data['notes'] ?? ''));
        if ($notesFromScrape !== '') {
            $notesParts[] = $notesFromScrape;
        }
        $tipologiaScrape = trim((string)($data['scraped_tipologia'] ?? ''));
        if ($tipologiaScrape !== '') {
            $notesParts[] = 'Tipologia: ' . $tipologiaScrape;
        }
        if ($notesParts) {
            $uniqueNotes = [];
            foreach ($notesParts as $part) {
                $clean = trim($part);
                if ($clean === '') continue;
                $exists = false;
                foreach ($uniqueNotes as $existing) {
                    if (strcasecmp($existing, $clean) === 0) { $exists = true; break; }
                }
                if (!$exists) { $uniqueNotes[] = $clean; }
            }
            $fields['note_varie'] = implode("\n", $uniqueNotes);
        }

        // Sanitize ISBN/EAN: strip spaces and dashes (server-side safety)
        foreach (['isbn10','isbn13','ean'] as $codeKey) {
            if (isset($fields[$codeKey])) {
                $fields[$codeKey] = preg_replace('/[\s-]+/', '', (string)$fields[$codeKey]);
            }
        }

        // Convert 0 to NULL for optional foreign keys to avoid constraint failures
        $fields['editore_id'] = empty($fields['editore_id']) || $fields['editore_id'] == 0 ? null : (int)$fields['editore_id'];
        $fields['genere_id'] = empty($fields['genere_id']) || $fields['genere_id'] == 0 ? null : (int)$fields['genere_id'];
        $fields['sottogenere_id'] = empty($fields['sottogenere_id']) || $fields['sottogenere_id'] == 0 ? null : (int)$fields['sottogenere_id'];
        $fields['copie_totali'] = (int)$fields['copie_totali'];
        // Add bounds checking to prevent integer overflow
        if ($fields['copie_totali'] < 1) {
            $fields['copie_totali'] = 1;
        } elseif ($fields['copie_totali'] > 9999) {
            $fields['copie_totali'] = 9999;
        }
        // In creazione, copie_disponibili = copie_totali (le copie sono tutte nuove e disponibili)
        $fields['copie_disponibili'] = $fields['copie_totali'];
        $fields['scaffale_id'] = empty($fields['scaffale_id']) ? null : (int)$fields['scaffale_id'];
        $fields['mensola_id'] = empty($fields['mensola_id']) ? null : (int)$fields['mensola_id'];
        $fields['posizione_progressiva'] = isset($fields['posizione_progressiva']) && $fields['posizione_progressiva'] !== '' ? (int)$fields['posizione_progressiva'] : null;
        $fields['posizione_id'] = null;
        $fields['peso'] = $fields['peso'] !== null && $fields['peso'] !== '' ? (float)$fields['peso'] : null;
        $fields['prezzo'] = $fields['prezzo'] !== null && $fields['prezzo'] !== '' ? (float)$fields['prezzo'] : null;
        if ($fields['copertina_url'] === '' || $fields['copertina_url'] === null) {
            $fields['copertina_url'] = null;
        }

        // Ensure hierarchical consistency between genere_id (parent) and sottogenere_id (child)
        try {
            $genRepoTmp = new \App\Models\GenereRepository($db);
            if (!empty($fields['sottogenere_id'])) {
                $sub = $genRepoTmp->getById((int)$fields['sottogenere_id']);
                if ($sub && !empty($sub['parent_id'])) {
                    $fields['genere_id'] = (int)$sub['parent_id'];
                }
            } elseif (!empty($fields['genere_id'])) {
                // If a leaf has been posted as genere, promote its parent
                $g = $genRepoTmp->getById((int)$fields['genere_id']);
                if ($g && !empty($g['parent_id'])) {
                    // Posted value is actually a child; move it to sottogenere and set its parent as genere
                    $fields['sottogenere_id'] = (int)$fields['genere_id'];
                    $fields['genere_id'] = (int)$g['parent_id'];
                }
            }
        } catch (\Throwable $e) {
            // fail-safe: ignore and continue
        }


        // DEBUG: Log field processing for store method
        // SECURITY: Logging disabilitato in produzione per prevenire information disclosure
        if (getenv('APP_ENV') === 'development') {
            $debugFile = __DIR__ . '/../../storage/field_debug.log';
            $debugEntry = "FIELD PROCESSING (STORE):\n";
            foreach ($fields as $key => $value) {
                $type = gettype($value);
                $displayValue = $value === null ? 'NULL' : (string)$value;
                if (strlen($displayValue) > 100) $displayValue = substr($displayValue, 0, 100) . '...';
                $debugEntry .= "  {$key} ({$type}): '{$displayValue}'\n";
            }
            @file_put_contents($debugFile, $debugEntry, FILE_APPEND);
        }
        
        // Duplicate check on identifiers (EAN/ISBN)
        $codes = [];
        foreach (['isbn10','isbn13','ean'] as $k) {
            $v = trim((string)($fields[$k] ?? ''));
            if ($v !== '') { $codes[$k] = $v; }
        }
        if (!empty($codes)) {
            $clauses = [];$types='';$params=[];
            foreach ($codes as $k=>$v) { $clauses[] = "l.$k = ?"; $types .= 's'; $params[] = $v; }
            $sql = 'SELECT id, titolo, isbn10, isbn13, ean FROM libri l WHERE '.implode(' OR ', $clauses).' LIMIT 1';
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            if ($dup) {
                // Re-render create form with error message
                $editRepo = new \App\Models\PublisherRepository($db);
                $autRepo = new \App\Models\AuthorRepository($db);
                $editori = $editRepo->listBasic();
                $autori = $autRepo->listBasic(500);
                $colRepo = new \App\Models\CollocationRepository($db);
                $taxRepo = new \App\Models\TaxonomyRepository($db);
                $scaffali = $colRepo->getScaffali();
                $mensole = $colRepo->getMensole();
                $generi = $taxRepo->genres();
                $sottogeneri = $taxRepo->subgenres();
                $error_message = 'Esiste già un libro con lo stesso identificatore (ISBN/EAN). ID esistente: #'
                    . (int)$dup['id'] . ' — "' . (string)($dup['titolo'] ?? '') . '"';
                ob_start(); require __DIR__ . '/../Views/libri/crea_libro.php'; $content = ob_get_clean();
                ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
                $response->getBody()->write($html);
                return $response->withStatus(409);
            }
        }

        $repo = new \App\Models\BookRepository($db);
        $fields['autori_ids'] = array_map('intval', $data['autori_ids'] ?? []);

        // Gestione autori nuovi da creare
        if (!empty($data['autori_new'])) {
            $authRepo = new \App\Models\AuthorRepository($db);
            foreach ((array)$data['autori_new'] as $nomeCompleto) {
                $nomeCompleto = trim((string)$nomeCompleto);
                if ($nomeCompleto !== '') {
                    $authorId = $authRepo->create([
                        'nome' => $nomeCompleto,
                        'pseudonimo' => '',
                        'data_nascita' => null,
                        'data_morte' => null,
                        'nazionalita' => '',
                        'biografia' => '',
                        'sito_web' => ''
                    ]);
                    $fields['autori_ids'][] = $authorId;
                }
            }
        }
        
        // Auto-create author from scraped data if no authors selected
        if (empty($fields['autori_ids']) && !empty($data['scraped_author'])) {
            $authRepo = new \App\Models\AuthorRepository($db);
            $scrapedAuthor = trim((string)$data['scraped_author']);
            if ($scrapedAuthor !== '') {
                $found = $authRepo->findByName($scrapedAuthor);
                if ($found) {
                    $fields['autori_ids'][] = $found;
                } else {
                    $authorId = $authRepo->create([
                        'nome' => $scrapedAuthor,
                        'pseudonimo' => '',
                        'data_nascita' => null,
                        'data_morte' => null,
                        'nazionalita' => '',
                        'biografia' => '',
                        'sito_web' => ''
                    ]);
                    $fields['autori_ids'][] = $authorId;
                }
            }
        }
        
        // Handle publisher auto-creation from manual entry or scraped data
        if ((int)$fields['editore_id'] === 0) {
            $pubRepo = new \App\Models\PublisherRepository($db);
            $publisherName = '';

            // First try manual entry (editore_search field)
            if (!empty($data['editore_search'])) {
                $publisherName = trim((string)$data['editore_search']);
            }
            // Fall back to scraped data
            elseif (!empty($data['scraped_publisher'])) {
                $publisherName = trim((string)$data['scraped_publisher']);
            }

            if ($publisherName !== '') {
                $found = $pubRepo->findByName($publisherName);
                if ($found) {
                    $fields['editore_id'] = $found;
                } else {
                    $fields['editore_id'] = $pubRepo->create(['nome' => $publisherName, 'sito_web' => '']);
                }
            }
        }

        // Handle genere auto-creation from manual entry
        if ((int)$fields['genere_id'] === 0 && !empty($data['genere_search'])) {
            $genereRepo = new \App\Models\GenereRepository($db);
            $genereName = trim((string)$data['genere_search']);

            if ($genereName !== '') {
                $found = $genereRepo->findByName($genereName);
                if ($found) {
                    $fields['genere_id'] = $found;
                } else {
                    $fields['genere_id'] = $genereRepo->create(['nome' => $genereName]);
                }
            }
        }

        // Handle sottogenere auto-creation from manual entry
        if ((int)$fields['sottogenere_id'] === 0 && !empty($data['sottogenere_search'])) {
            $genereRepo = new \App\Models\GenereRepository($db);
            $sottogenereName = trim((string)$data['sottogenere_search']);

            if ($sottogenereName !== '') {
                // If we have a parent genere, use it
                $parent_id = !empty($fields['genere_id']) ? (int)$fields['genere_id'] : null;

                $found = $genereRepo->findByName($sottogenereName, $parent_id);
                if ($found) {
                    $fields['sottogenere_id'] = $found;
                } else {
                    $fields['sottogenere_id'] = $genereRepo->create([
                        'nome' => $sottogenereName,
                        'parent_id' => $parent_id
                    ]);
                }
            }
        }
        $collRepo = new \App\Models\CollocationRepository($db);
        if ($fields['scaffale_id'] && $fields['mensola_id']) {
            $pos = $fields['posizione_progressiva'] ?? null;
            if ($pos === null || $pos <= 0 || $collRepo->isProgressivaOccupied($fields['scaffale_id'], $fields['mensola_id'], $pos)) {
                $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id']);
            }
            $fields['posizione_progressiva'] = $pos;
            $fields['collocazione'] = $collRepo->buildCollocazioneString($fields['scaffale_id'], $fields['mensola_id'], $pos);
        } else {
            $fields['posizione_progressiva'] = null;
            $fields['collocazione'] = $fields['collocazione'] ?? '';
        }

        $id = $repo->createBasic($fields);

        // Genera copie fisiche del libro
        $copyRepo = new \App\Models\CopyRepository($db);
        $copieTotali = (int)($fields['copie_totali'] ?? 1);
        $baseInventario = !empty($fields['numero_inventario'])
            ? $fields['numero_inventario']
            : "LIB-{$id}";

        for ($i = 1; $i <= $copieTotali; $i++) {
            $numeroInventario = $copieTotali > 1
                ? "{$baseInventario}-C{$i}"
                : $baseInventario;

            $note = $copieTotali > 1 ? "Copia {$i} di {$copieTotali}" : null;
            $copyRepo->create($id, $numeroInventario, 'disponibile', $note);
        }

        // Handle simple cover upload
        if (!empty($_FILES['copertina']) && ($_FILES['copertina']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->handleCoverUpload($db, $id, $_FILES['copertina']);
        }
        if (!empty($data['scraped_cover_url'])) {
            $this->handleCoverUrl($db, $id, (string)$data['scraped_cover_url']);
        }
        // Optionals (numero_pagine, ean, data_pubblicazione, traduttore)
        (new \App\Models\BookRepository($db))->updateOptionals($id, $data);

        // Set a success message in the session
        $_SESSION['success_message'] = 'Libro aggiunto con successo!';

        return $response->withHeader('Location', '/admin/libri/'.$id)->withStatus(302);
    }

    public function editForm(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);
        if (!$libro) { return $response->withStatus(404); }
        $editRepo = new \App\Models\PublisherRepository($db);
        $autRepo = new \App\Models\AuthorRepository($db);
        $editori = $editRepo->listBasic();
        $autori = $autRepo->listBasic(500);
        $colRepo = new \App\Models\CollocationRepository($db);
        $taxRepo = new \App\Models\TaxonomyRepository($db);
        $scaffali = $colRepo->getScaffali();
        $mensole = $colRepo->getMensole();
        $generi = $taxRepo->genres();
        $sottogeneri = $taxRepo->subgenres();
        ob_start();
        $data = ['libro'=>$libro,'editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri];
        // extract(['libro'=>$libro,'editori'=>$editori,'autori'=>$autori,'scaffali'=>$scaffali,'mensole'=>$mensole,'generi'=>$generi,'sottogeneri'=>$sottogeneri]); 
        require __DIR__ . '/../Views/libri/modifica_libro.php'; 
        $content = ob_get_clean();
        ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
        $response->getBody()->write($html); return $response;
    }

    public function update(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $data = (array)$request->getParsedBody();
        if (!Csrf::validate($data['csrf_token'] ?? null)) {
            $_SESSION['error_message'] = 'Sessione scaduta. Riprova.';
            return $response->withHeader('Location', '/admin/libri/modifica/'.$id)->withStatus(302);
        }
        $repo = new \App\Models\BookRepository($db);
        $currentBook = $repo->getById($id);
        if (!$currentBook) { return $response->withStatus(404); }
        $fields = [
            'titolo'=>'', 'sottotitolo'=>'', 'isbn10'=>'', 'isbn13'=>'',
            'genere_id'=>0, 'sottogenere_id'=>0, 'editore_id'=>0,
            'data_acquisizione'=>null, 'tipo_acquisizione'=>'', 'descrizione'=>'', 'parole_chiave'=>'',
            'formato'=>'', 'peso'=>null, 'dimensioni'=>'', 'prezzo'=>null,
            'copie_totali'=>1, 'copie_disponibili'=>1, 'numero_inventario'=>'',
            'classificazione_dowey'=>'', 'collana'=>'', 'numero_serie'=>'', 'note_varie'=>'',
            'file_url'=>'', 'audio_url'=>'', 'copertina_url'=>'',
            'scaffale_id'=>0, 'mensola_id'=>0, 'posizione_progressiva'=>0,
            'posizione_id'=>0, 'collocazione'=>'', 'stato'=>''
        ];
        foreach ($fields as $k=>$v) { if (array_key_exists($k, $data)) $fields[$k] = $data[$k]; }

        // Sanitize ISBN/EAN on update as well
        foreach (['isbn10','isbn13','ean'] as $codeKey) {
            if (isset($fields[$codeKey])) {
                $fields[$codeKey] = preg_replace('/[\s-]+/', '', (string)$fields[$codeKey]);
            }
        }

        // Merge scraped subtitle and notes if present
        $subtitleFromScrape = trim((string)($data['subtitle'] ?? ''));
        if ($subtitleFromScrape !== '' && trim((string)($fields['sottotitolo'] ?? '')) === '') {
            $fields['sottotitolo'] = $subtitleFromScrape;
        }

        $notesParts = [];
        $currentNotes = trim((string)($fields['note_varie'] ?? ''));
        if ($currentNotes !== '') {
            $notesParts[] = $currentNotes;
        }
        $notesFromScrape = trim((string)($data['notes'] ?? ''));
        if ($notesFromScrape !== '') {
            $notesParts[] = $notesFromScrape;
        }
        $tipologiaScrape = trim((string)($data['scraped_tipologia'] ?? ''));
        if ($tipologiaScrape !== '') {
            $notesParts[] = 'Tipologia: ' . $tipologiaScrape;
        }
        if ($notesParts) {
            $uniqueNotes = [];
            foreach ($notesParts as $part) {
                $clean = trim($part);
                if ($clean === '') continue;
                $exists = false;
                foreach ($uniqueNotes as $existing) {
                    if (strcasecmp($existing, $clean) === 0) { $exists = true; break; }
                }
                if (!$exists) { $uniqueNotes[] = $clean; }
            }
            $fields['note_varie'] = implode("\n", $uniqueNotes);
        }

        // Convert 0 to NULL for optional foreign keys to avoid constraint failures
        $fields['editore_id'] = empty($fields['editore_id']) || $fields['editore_id'] == 0 ? null : (int)$fields['editore_id'];
        $fields['genere_id'] = empty($fields['genere_id']) || $fields['genere_id'] == 0 ? null : (int)$fields['genere_id'];
        $fields['sottogenere_id'] = empty($fields['sottogenere_id']) || $fields['sottogenere_id'] == 0 ? null : (int)$fields['sottogenere_id'];
        $fields['copie_totali'] = (int)$fields['copie_totali'];

        // Validazione copie: verifica che sia possibile ridurre il numero di copie
        $copyRepo = new \App\Models\CopyRepository($db);
        $currentCopieCount = $copyRepo->countByBookId($id);
        $newCopieCount = $fields['copie_totali'];

        if ($newCopieCount < $currentCopieCount) {
            // Conta quante copie sono disponibili per la rimozione
            $copie = $copyRepo->getByBookId($id);
            $removableCopies = 0;
            $nonRemovableCopies = 0;

            foreach ($copie as $copia) {
                if ($copia['stato'] === 'disponibile' && empty($copia['prestito_id'])) {
                    $removableCopies++;
                } else {
                    $nonRemovableCopies++;
                }
            }

            $requiredReduction = $currentCopieCount - $newCopieCount;

            if ($requiredReduction > $removableCopies) {
                $_SESSION['error_message'] = sprintf(
                    'Impossibile ridurre le copie a %d. Ci sono %d copie non disponibili (in prestito, perse o danneggiate). Il numero minimo di copie totali è %d.',
                    $newCopieCount,
                    $nonRemovableCopies,
                    $nonRemovableCopies
                );
                return $response->withHeader('Location', '/admin/libri/modifica/'.$id)->withStatus(302);
            }
        }

        // Non aggiorniamo copie_disponibili dall'utente, sarà ricalcolato automaticamente
        unset($fields['copie_disponibili']);

        $fields['scaffale_id'] = empty($fields['scaffale_id']) || $fields['scaffale_id'] == 0 ? null : (int)$fields['scaffale_id'];
        $fields['mensola_id'] = empty($fields['mensola_id']) || $fields['mensola_id'] == 0 ? null : (int)$fields['mensola_id'];
        $fields['posizione_progressiva'] = isset($fields['posizione_progressiva']) && $fields['posizione_progressiva'] !== '' ? (int)$fields['posizione_progressiva'] : null;
        $fields['posizione_id'] = null;
        $fields['peso'] = $fields['peso'] !== null && $fields['peso'] !== '' ? (float)$fields['peso'] : null;
        $fields['prezzo'] = $fields['prezzo'] !== null && $fields['prezzo'] !== '' ? (float)$fields['prezzo'] : null;

        // Gestione rimozione copertina
        if (isset($data['remove_cover']) && $data['remove_cover'] === '1') {
            // Cancella il file della copertina esistente se presente
            if (!empty($currentBook['copertina_url'])) {
                $oldCoverPath = $currentBook['copertina_url'];
                // Solo se è un file locale (non URL esterno)
                if (strpos($oldCoverPath, '/uploads/') === 0) {
                    $fullPath = __DIR__ . '/../../public' . $oldCoverPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            // Imposta a NULL per rimuovere dal database
            $fields['copertina_url'] = null;
        } elseif ($fields['copertina_url'] === '' || $fields['copertina_url'] === null) {
            $fields['copertina_url'] = null;
        }

        // Ensure hierarchical consistency between genere_id and sottogenere_id also on update
        try {
            $genRepoTmp = new \App\Models\GenereRepository($db);
            if (!empty($fields['sottogenere_id'])) {
                $sub = $genRepoTmp->getById((int)$fields['sottogenere_id']);
                if ($sub && !empty($sub['parent_id'])) {
                    $fields['genere_id'] = (int)$sub['parent_id'];
                }
            } elseif (!empty($fields['genere_id'])) {
                $g = $genRepoTmp->getById((int)$fields['genere_id']);
                if ($g && !empty($g['parent_id'])) {
                    $fields['sottogenere_id'] = (int)$fields['genere_id'];
                    $fields['genere_id'] = (int)$g['parent_id'];
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        
        // Duplicate check on update (exclude current record)
        $codes = [];
        foreach (['isbn10','isbn13','ean'] as $k) {
            $v = trim((string)($fields[$k] ?? ''));
            if ($v !== '') { $codes[$k] = $v; }
        }
        if (!empty($codes)) {
            $clauses = [];$types='';$params=[];
            foreach ($codes as $k=>$v) { $clauses[] = "l.$k = ?"; $types .= 's'; $params[] = $v; }
            $sql = 'SELECT id, titolo FROM libri l WHERE ('.implode(' OR ', $clauses).') AND id <> ? LIMIT 1';
            $types .= 'i'; $params[] = $id;
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            if ($dup) {
                $editRepo = new \App\Models\PublisherRepository($db);
                $autRepo = new \App\Models\AuthorRepository($db);
                $editori = $editRepo->listBasic();
                $autori = $autRepo->listBasic(500);
                $colRepo = new \App\Models\CollocationRepository($db);
                $taxRepo = new \App\Models\TaxonomyRepository($db);
                $scaffali = $colRepo->getScaffali();
                $generi = $taxRepo->genres();
                $sottogeneri = $taxRepo->subgenres();
                $error_message = 'Esiste già un altro libro con lo stesso identificatore (ISBN/EAN). ID: #'
                    . (int)$dup['id'] . ' — "' . (string)($dup['titolo'] ?? '') . '"';
                $libroView = array_merge($currentBook, $fields);
                ob_start(); require __DIR__ . '/../Views/libri/modifica_libro.php'; $content = ob_get_clean();
                ob_start(); require __DIR__ . '/../Views/layout.php'; $html = ob_get_clean();
                $response->getBody()->write($html);
                return $response->withStatus(409);
            }
        }
        $fields['autori_ids'] = array_map('intval', $data['autori_ids'] ?? []);

        $collRepo = new \App\Models\CollocationRepository($db);
        if ($fields['scaffale_id'] && $fields['mensola_id']) {
            $pos = $fields['posizione_progressiva'] ?? null;
            if ($pos === null || $pos <= 0) {
                $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id'], $id);
            } elseif ($collRepo->isProgressivaOccupied($fields['scaffale_id'], $fields['mensola_id'], $pos, $id)) {
                $pos = $collRepo->computeNextProgressiva($fields['scaffale_id'], $fields['mensola_id'], $id);
            }
            $fields['posizione_progressiva'] = $pos;
            $fields['collocazione'] = $collRepo->buildCollocazioneString($fields['scaffale_id'], $fields['mensola_id'], $pos);
        } else {
            $fields['posizione_progressiva'] = null;
            $fields['collocazione'] = '';
        }
        
        // Gestione autori nuovi da creare
        if (!empty($data['autori_new'])) {
            $authRepo = new \App\Models\AuthorRepository($db);
            foreach ((array)$data['autori_new'] as $nomeCompleto) {
                $nomeCompleto = trim((string)$nomeCompleto);
                if ($nomeCompleto !== '') {
                    $authorId = $authRepo->create([
                        'nome' => $nomeCompleto,
                        'pseudonimo' => '',
                        'data_nascita' => null,
                        'data_morte' => null,
                        'nazionalita' => '',
                        'biografia' => '',
                        'sito_web' => ''
                    ]);
                    $fields['autori_ids'][] = $authorId;
                }
            }
        }
        
        // Auto-create author from scraped data if no authors selected
        if (empty($fields['autori_ids']) && !empty($data['scraped_author'])) {
            $authRepo = new \App\Models\AuthorRepository($db);
            $scrapedAuthor = trim((string)$data['scraped_author']);
            if ($scrapedAuthor !== '') {
                $found = $authRepo->findByName($scrapedAuthor);
                if ($found) {
                    $fields['autori_ids'][] = $found;
                } else {
                    $authorId = $authRepo->create([
                        'nome' => $scrapedAuthor,
                        'pseudonimo' => '',
                        'data_nascita' => null,
                        'data_morte' => null,
                        'nazionalita' => '',
                        'biografia' => '',
                        'sito_web' => ''
                    ]);
                    $fields['autori_ids'][] = $authorId;
                }
            }
        }
        
        // Handle publisher auto-creation from manual entry or scraped data
        if ((int)$fields['editore_id'] === 0) {
            $pubRepo = new \App\Models\PublisherRepository($db);
            $publisherName = '';

            // First try manual entry (editore_search field)
            if (!empty($data['editore_search'])) {
                $publisherName = trim((string)$data['editore_search']);
            }
            // Fall back to scraped data
            elseif (!empty($data['scraped_publisher'])) {
                $publisherName = trim((string)$data['scraped_publisher']);
            }

            if ($publisherName !== '') {
                $found = $pubRepo->findByName($publisherName);
                if ($found) {
                    $fields['editore_id'] = $found;
                } else {
                    $fields['editore_id'] = $pubRepo->create(['nome' => $publisherName, 'sito_web' => '']);
                }
            }
        }

        // Handle genere auto-creation from manual entry
        if ((int)$fields['genere_id'] === 0 && !empty($data['genere_search'])) {
            $genereRepo = new \App\Models\GenereRepository($db);
            $genereName = trim((string)$data['genere_search']);

            if ($genereName !== '') {
                $found = $genereRepo->findByName($genereName);
                if ($found) {
                    $fields['genere_id'] = $found;
                } else {
                    $fields['genere_id'] = $genereRepo->create(['nome' => $genereName]);
                }
            }
        }

        // Handle sottogenere auto-creation from manual entry
        if ((int)$fields['sottogenere_id'] === 0 && !empty($data['sottogenere_search'])) {
            $genereRepo = new \App\Models\GenereRepository($db);
            $sottogenereName = trim((string)$data['sottogenere_search']);

            if ($sottogenereName !== '') {
                // If we have a parent genere, use it
                $parent_id = !empty($fields['genere_id']) ? (int)$fields['genere_id'] : null;

                $found = $genereRepo->findByName($sottogenereName, $parent_id);
                if ($found) {
                    $fields['sottogenere_id'] = $found;
                } else {
                    $fields['sottogenere_id'] = $genereRepo->create([
                        'nome' => $sottogenereName,
                        'parent_id' => $parent_id
                    ]);
                }
            }
        }
        $repo->updateBasic($id, $fields);

        // Gestione copie: aggiorna il numero di copie se cambiato
        $copyRepo = new \App\Models\CopyRepository($db);
        $currentCopieCount = $copyRepo->countByBookId($id);
        $newCopieCount = (int)($fields['copie_totali'] ?? 1);

        if ($newCopieCount > $currentCopieCount) {
            // Aggiungi nuove copie
            $baseInventario = !empty($fields['numero_inventario'])
                ? $fields['numero_inventario']
                : "LIB-{$id}";

            for ($i = $currentCopieCount + 1; $i <= $newCopieCount; $i++) {
                $numeroInventario = $newCopieCount > 1
                    ? "{$baseInventario}-C{$i}"
                    : $baseInventario;

                $note = "Copia {$i} di {$newCopieCount}";
                $copyRepo->create($id, $numeroInventario, 'disponibile', $note);
            }
        } elseif ($newCopieCount < $currentCopieCount) {
            // Rimuovi copie in eccesso (solo quelle disponibili, non in prestito)
            $copie = $copyRepo->getByBookId($id);
            $toRemove = $currentCopieCount - $newCopieCount;
            $removed = 0;

            foreach ($copie as $copia) {
                if ($removed >= $toRemove) break;

                // Rimuovi solo copie disponibili senza prestiti attivi
                if ($copia['stato'] === 'disponibile' && empty($copia['prestito_id'])) {
                    $copyRepo->delete($copia['id']);
                    $removed++;
                }
            }

            // Se non riusciamo a rimuovere abbastanza copie, avvisa l'utente
            if ($removed < $toRemove) {
                $_SESSION['warning_message'] = "Attenzione: Non è stato possibile rimuovere tutte le copie richieste. Alcune copie sono attualmente in prestito.";
            }
        }

        // Ricalcola disponibilità dopo aver modificato le copie
        $integrity = new \App\Support\DataIntegrity($db);
        $integrity->recalculateBookAvailability($id);

        if (!empty($_FILES['copertina']) && ($_FILES['copertina']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->handleCoverUpload($db, $id, $_FILES['copertina']);
        }
        if (!empty($data['scraped_cover_url'])) {
            $this->handleCoverUrl($db, $id, (string)$data['scraped_cover_url']);
        }
        (new \App\Models\BookRepository($db))->updateOptionals($id, $data);

        // Set a success message in the session
        $_SESSION['success_message'] = 'Libro aggiornato con successo!';

        return $response->withHeader('Location', '/admin/libri/'.$id)->withStatus(302);
    }

    private function handleCoverUrl(mysqli $db, int $bookId, string $url): void
    {
        if (!$url) return;
        $this->logCoverDebug('handleCoverUrl.start', ['bookId' => $bookId, 'url' => $url]);

        // Security: Validate URL against whitelist for external downloads
        if (!$this->isUrlAllowed($url)) {
            $this->logCoverDebug('handleCoverUrl.security.blocked', ['bookId' => $bookId, 'url' => $url]);
            return;
        }

        // Case 1: local path already in /uploads/copertine => just persist it (normalize leading slash)
        if (strpos($url, '/uploads/copertine/') === 0 || strpos($url, 'uploads/copertine/') === 0) {
            if (strpos($url, '/') !== 0) { $url = '/' . $url; }
            $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('si', $url, $bookId);
            $stmt->execute();
            $this->logCoverDebug('handleCoverUrl.local.persist', ['bookId' => $bookId, 'stored' => $url]);
            return;
        }

        // Case 2: absolute URL
        if (strpos($url, 'http') === 0) {
            // If it points to our own uploads path, just persist the relative path
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if ($path && (strpos($path, '/uploads/copertine/') === 0 || strpos($path, 'uploads/copertine/') === 0)) {
                if (strpos($path, '/') !== 0) { $path = '/' . $path; }
                $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
                $stmt->bind_param('si', $path, $bookId);
                $stmt->execute();
                $this->logCoverDebug('handleCoverUrl.absolute.local', ['bookId' => $bookId, 'stored' => $path]);
                return;
            }

            // Otherwise, download and save locally
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: BibliotecaBot/1.0\r\n"]]);
            $img = @file_get_contents($url, false, $ctx);
            if ($img === false) { $this->logCoverDebug('handleCoverUrl.download.fail', ['bookId' => $bookId, 'url' => $url]); return; }

            // Security: Validate MIME type of downloaded content
            if (!$this->isValidImageMimeType($img)) {
                $this->logCoverDebug('handleCoverUrl.security.invalid_mime', ['bookId' => $bookId, 'url' => $url]);
                return;
            }
            $dir = __DIR__ . '/../../public/uploads/copertine/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg';
            $name = 'libro_'.$bookId.'_'.time().'.'.$ext;
            $dst = $dir.$name;
            if (@file_put_contents($dst, $img) !== false) {
                $cover = '/uploads/copertine/'.$name;
                $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
                $stmt->bind_param('si', $cover, $bookId);
                $stmt->execute();
                $this->logCoverDebug('handleCoverUrl.download.ok', ['bookId' => $bookId, 'stored' => $cover]);
            }
        }
    }

    private function handleCoverUpload(mysqli $db, int $bookId, array $file): void
    {
        // Security: Do NOT trust $file['type'] from client - always validate actual content

        // 1. Verify this is an uploaded file
        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpPath)) {
            $this->logCoverDebug('handleCoverUpload.skip.not_uploaded', ['bookId'=>$bookId]);
            return;
        }

        // 2. Check file size
        if (($file['size'] ?? 0) > 2*1024*1024 || ($file['size'] ?? 0) === 0) {
            $this->logCoverDebug('handleCoverUpload.skip.size', ['bookId'=>$bookId,'size'=>$file['size'] ?? 0]);
            return;
        }

        // 3. Read actual file content
        $content = @file_get_contents($tmpPath);
        if ($content === false || $content === '') {
            $this->logCoverDebug('handleCoverUpload.skip.read_fail', ['bookId'=>$bookId]);
            return;
        }

        // 4. Validate MIME type using magic bytes (NOT $file['type'])
        if (!$this->isValidImageMimeType($content)) {
            $this->logCoverDebug('handleCoverUpload.skip.invalid_magic', ['bookId'=>$bookId]);
            return;
        }

        // 5. Get safe extension based on magic bytes (NOT from user-supplied filename)
        $ext = $this->getExtensionFromMagicBytes($content);
        if ($ext === null) {
            $this->logCoverDebug('handleCoverUpload.skip.unknown_format', ['bookId'=>$bookId]);
            return;
        }

        // 6. Save file with safe name
        $dir = __DIR__ . '/../../public/uploads/copertine/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $name = 'libro_'.$bookId.'_'.time().'.'.$ext;
        $dst = $dir.$name;

        if (@file_put_contents($dst, $content) !== false) {
            $url = '/uploads/copertine/'.$name;
            $stmt = $db->prepare('UPDATE libri SET copertina_url=?, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('si', $url, $bookId);
            $stmt->execute();
            $this->logCoverDebug('handleCoverUpload.ok', ['bookId'=>$bookId,'stored'=>$url]);
        } else {
            $this->logCoverDebug('handleCoverUpload.fail', ['bookId'=>$bookId]);
        }
    }

    public function delete(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $token = ($request->getParsedBody()['csrf_token'] ?? '') ?: '';
        if (!\App\Support\Csrf::validate($token)) { return $response->withStatus(400); }
        $repo = new \App\Models\BookRepository($db);
        $repo->delete($id);
        return $response->withHeader('Location', '/admin/libri')->withStatus(302);
    }

    /**
     * Security: Validate URL against whitelist for external image downloads
     */
    private function isUrlAllowed(string $url): bool
    {
        // Allow local paths
        if (strpos($url, '/uploads/') === 0 || strpos($url, 'uploads/') === 0) {
            return true;
        }

        // Only allow external downloads if URL starts with http/https
        if (strpos($url, 'http') !== 0) {
            return false;
        }

        // Whitelist of allowed domains for cover image downloads
        $allowedDomains = [
            'img.libreriauniversitaria.it',
            'img2.libreriauniversitaria.it',
            'img3.libreriauniversitaria.it',
            'covers.openlibrary.org',
            'images.amazon.com',
            'images-na.ssl-images-amazon.com',
            'books.google.com',
            'books.google.it'
        ];

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        // Block localhost, private IPs, and internal networks
        if (in_array($host, ['localhost', '127.0.0.1', '::1']) ||
            preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)) {
            return false;
        }

        return in_array($host, $allowedDomains, true);
    }

    /**
     * Security: Validate MIME type of downloaded image content
     */
    private function isValidImageMimeType(string $content): bool
    {
        if (strlen($content) < 12) {
            return false;
        }

        // Check magic bytes for common image formats
        $magicBytes = substr($content, 0, 12);

        // JPEG: FF D8 FF
        if (substr($magicBytes, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($magicBytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return true;
        }

        // GIF: 47 49 46 38 (GIF8)
        if (substr($magicBytes, 0, 4) === "GIF8") {
            return true;
        }

        // WebP: 52 49 46 46 + 57 45 42 50 (RIFF + WEBP)
        if (substr($magicBytes, 0, 4) === "RIFF" && substr($magicBytes, 8, 4) === "WEBP") {
            return true;
        }

        return false;
    }

    /**
     * Get file extension from magic bytes (binary signature)
     * Security: Determines actual file type, not based on user-supplied name
     */
    private function getExtensionFromMagicBytes(string $content): ?string
    {
        if (strlen($content) < 4) {
            return null;
        }

        $magic = substr($content, 0, 12);

        // JPEG: FF D8 FF
        if (substr($magic, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (strlen($magic) >= 8 && substr($magic, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'png';
        }

        // GIF: GIF8
        if (substr($magic, 0, 4) === "GIF8") {
            return 'gif';
        }

        // WebP: RIFF + WEBP
        if (strlen($magic) >= 12 && substr($magic, 0, 4) === "RIFF" && substr($magic, 8, 4) === "WEBP") {
            return 'webp';
        }

        return null;
    }

    public function generateLabelPDF(Request $request, Response $response, mysqli $db, int $id): Response
    {
        $repo = new \App\Models\BookRepository($db);
        $libro = $repo->getById($id);

        if (!$libro) {
            $response->getBody()->write('Libro non trovato');
            return $response->withStatus(404);
        }

        // Get application name and label settings from settings
        $settingsRepo = new \App\Models\SettingsRepository($db);
        $appName = $settingsRepo->get('app', 'name', 'Biblioteca');

        // Get label dimensions from settings (default to 25x38mm)
        $labelWidth = (int)($settingsRepo->get('label', 'width', (string)\App\Support\ConfigStore::get('label.width', 25)));
        $labelHeight = (int)($settingsRepo->get('label', 'height', (string)\App\Support\ConfigStore::get('label.height', 38)));

        // Ensure dimensions are within reasonable bounds
        if ($labelWidth < 10 || $labelWidth > 100) $labelWidth = 25;
        if ($labelHeight < 10 || $labelHeight > 100) $labelHeight = 38;

        // Determine orientation based on dimensions
        $orientation = $labelWidth > $labelHeight ? 'L' : 'P';

        // Get collocazione data
        $collocazione = '';
        if (!empty($libro['scaffale_id']) && !empty($libro['mensola_id']) && !empty($libro['posizione_progressiva'])) {
            $stmt = $db->prepare("SELECT s.codice as scaffale_codice, m.numero_livello
                                  FROM scaffali s, mensole m
                                  WHERE s.id = ? AND m.id = ?");
            $scaffaleId = (int)$libro['scaffale_id'];
            $mensolaId = (int)$libro['mensola_id'];
            $stmt->bind_param('ii', $scaffaleId, $mensolaId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $collocazione = $row['scaffale_codice'] . '.' . $row['numero_livello'] . '.' . $libro['posizione_progressiva'];
            }
        }

        // Create PDF with TCPDF using configured dimensions
        $pdf = new \TCPDF($orientation, 'mm', [$labelWidth, $labelHeight], true, 'UTF-8', false);

        // Document settings
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle('Etichetta - ' . $libro['titolo']);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Calculate scaled dimensions based on label size
        $isPortrait = $labelHeight > $labelWidth;

        // Set dynamic margins based on label size (proportional to width)
        $margin = max(1, min(3, $labelWidth * 0.05));
        $pdf->SetMargins($margin, $margin, $margin);
        $pdf->SetAutoPageBreak(false, 0);

        // Add page
        $pdf->AddPage();

        // Calculate available space
        $availableWidth = $labelWidth - ($margin * 2);
        $availableHeight = $labelHeight - ($margin * 2);

        // Handle autori
        $autoriStr = '';
        if (!empty($libro['autori'])) {
            if (is_array($libro['autori'])) {
                $autoriStr = implode(', ', array_map(function($a) {
                    return $a['nome'] ?? '';
                }, $libro['autori']));
            } else {
                $autoriStr = (string)$libro['autori'];
            }
        }

        // Barcode data
        $barcodeData = $libro['isbn13'] ?? $libro['ean'] ?? $libro['isbn10'] ?? '';

        // Position text
        $positionText = '';
        if (!empty($libro['scaffale_codice']) && !empty($libro['mensola_livello']) && !empty($libro['posizione_progressiva'])) {
            $positionText = $libro['scaffale_codice'] . '.' . $libro['mensola_livello'] . '.' . $libro['posizione_progressiva'];
        } elseif (isset($libro['posizione_progressiva']) && $libro['posizione_progressiva'] > 0) {
            $positionText = 'Pos. ' . $libro['posizione_progressiva'];
        }

        if ($isPortrait) {
            // PORTRAIT LAYOUT (vertical label - most common for book spines)
            $this->renderPortraitLabel($pdf, $appName, $libro, $autoriStr, $collocazione, $barcodeData, $positionText, $availableWidth, $availableHeight, $margin);
        } else {
            // LANDSCAPE LAYOUT (horizontal label - for larger internal labels)
            $this->renderLandscapeLabel($pdf, $appName, $libro, $autoriStr, $collocazione, $barcodeData, $positionText, $availableWidth, $availableHeight, $margin);
        }

        // Output PDF
        $pdfContent = $pdf->Output('', 'S');

        $response->getBody()->write($pdfContent);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="etichetta_' . $id . '.pdf"');
    }

    /**
     * Render portrait (vertical) label layout
     * Optimized for narrow spine labels like 25x38mm, 25x40mm, 34x48mm
     */
    private function renderPortraitLabel($pdf, string $appName, array $libro, string $autoriStr, string $collocazione, string $barcodeData, string $positionText, float $availableWidth, float $availableHeight, float $margin): void
    {
        // Calculate font sizes proportional to label width
        $fontSizeApp = max(4, min(7, $availableWidth * 0.25));
        $fontSizeTitle = max(4, min(6, $availableWidth * 0.22));
        $fontSizeAuthor = max(3, min(5, $availableWidth * 0.18));
        $fontSizePosition = max(4, min(6, $availableWidth * 0.20));

        // Prepare text content
        $appNameShort = mb_substr($appName, 0, 12);
        $maxTitleChars = (int)($availableWidth * 1.8);
        $titolo = mb_substr($libro['titolo'], 0, $maxTitleChars);
        if (mb_strlen($libro['titolo']) > $maxTitleChars) $titolo .= '...';

        $maxAuthorChars = (int)($availableWidth * 1.5);
        $autoreShort = !empty($autoriStr) ? mb_substr($autoriStr, 0, $maxAuthorChars) : '';

        // Calculate total content height
        $totalHeight = 0;
        $totalHeight += 3.5; // App name

        // Calculate title height (using getStringHeight)
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $titleHeight = $pdf->getStringHeight($availableWidth, $titolo);
        $totalHeight += $titleHeight + 0.5;

        // Author
        $includeAuthor = !empty($autoreShort);
        if ($includeAuthor) {
            $totalHeight += 2.5;
        }

        // Barcode
        $includeBarcode = !empty($barcodeData);
        $barcodeHeight = 0;
        if ($includeBarcode) {
            $barcodeHeight = min(8, $availableHeight * 0.20);
            $totalHeight += $barcodeHeight + 2;
        }

        // Position/Collocazione
        $posColText = $collocazione ?: $positionText;
        if ($posColText) {
            $totalHeight += 3;
        }

        // Calculate vertical centering offset
        $verticalOffset = ($availableHeight - $totalHeight) / 2;
        if ($verticalOffset < 0) $verticalOffset = 0;

        // Start rendering with vertical centering
        $currentY = $margin + $verticalOffset;

        // App name
        $pdf->SetFont('helvetica', 'B', $fontSizeApp);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 3, $appNameShort, 0, 0, 'C');
        $currentY += 3.5;

        // Titolo libro (wrapped)
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $pdf->SetXY($margin, $currentY);
        $pdf->MultiCell($availableWidth, 2.5, $titolo, 0, 'C', false, 1);
        $currentY = $pdf->GetY() + 0.5;

        // Autore
        if ($includeAuthor) {
            $pdf->SetFont('helvetica', '', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 2, $autoreShort, 0, 0, 'C');
            $currentY += 2.5;
        }

        // Barcode
        if ($includeBarcode) {
            $barcodeWidth = $availableWidth * 0.85;
            $barcodeX = $margin + (($availableWidth - $barcodeWidth) / 2);
            $currentY += 1;
            $pdf->write1DBarcode($barcodeData, 'EAN13', $barcodeX, $currentY, $barcodeWidth, $barcodeHeight, 0.3, ['stretch' => true, 'fitwidth' => true]);
            $currentY += $barcodeHeight + 1;
        }

        // Position/Collocazione
        if ($posColText) {
            $pdf->SetFont('helvetica', 'B', $fontSizePosition);
            $pdf->SetXY($margin, $currentY);
            $posShort = mb_substr($posColText, 0, 12);
            $pdf->Cell($availableWidth, 3, $posShort, 0, 0, 'C');
        }
    }

    /**
     * Render landscape (horizontal) label layout
     * Optimized for larger labels like 70x36mm, 50x25mm, 52x30mm
     */
    private function renderLandscapeLabel($pdf, string $appName, array $libro, string $autoriStr, string $collocazione, string $barcodeData, string $positionText, float $availableWidth, float $availableHeight, float $margin): void
    {
        // Calculate font sizes proportional to label height
        $fontSizeApp = max(6, min(10, $availableHeight * 0.25));
        $fontSizeTitle = max(5, min(8, $availableHeight * 0.20));
        $fontSizeAuthor = max(4, min(6, $availableHeight * 0.15));
        $fontSizePosition = max(5, min(8, $availableHeight * 0.22));

        // Prepare text content
        $maxTitleChars = (int)($availableWidth * 0.8);
        $titolo = mb_substr($libro['titolo'], 0, $maxTitleChars);
        if (mb_strlen($libro['titolo']) > $maxTitleChars) $titolo .= '...';

        // Autore ed editore
        $autorEditore = [];
        if (!empty($autoriStr)) {
            $autorEditore[] = mb_substr($autoriStr, 0, 30);
        }
        if (!empty($libro['editore_nome'])) {
            $autorEditore[] = mb_substr((string)$libro['editore_nome'], 0, 20);
        }
        $infoText = '';
        if (!empty($autorEditore)) {
            $infoText = implode(' - ', $autorEditore);
            $maxInfoChars = (int)($availableWidth * 0.9);
            $infoText = mb_substr($infoText, 0, $maxInfoChars);
        }

        // Calculate total content height
        $totalHeight = 0;
        $totalHeight += 4.5; // App name

        $totalHeight += 4; // Title

        // Author/Publisher
        $includeInfo = !empty($infoText);
        if ($includeInfo) {
            $totalHeight += 3;
        }

        // Barcode
        $includeBarcode = !empty($barcodeData);
        $barcodeHeight = 0;
        if ($includeBarcode) {
            $barcodeHeight = min(10, $availableHeight * 0.30);
            $totalHeight += $barcodeHeight + 1;
        }

        // Position text
        $includePosition = !empty($positionText) && !$collocazione;
        if ($includePosition) {
            $totalHeight += 3.5;
        }

        // Collocazione
        $includeCollocazione = !empty($collocazione);
        if ($includeCollocazione) {
            $totalHeight += 4;
        }

        // Calculate vertical centering offset
        $verticalOffset = ($availableHeight - $totalHeight) / 2;
        if ($verticalOffset < 0) $verticalOffset = 0;

        // Start rendering with vertical centering
        $currentY = $margin + $verticalOffset;

        // App name
        $pdf->SetFont('helvetica', 'B', $fontSizeApp);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 4, $appName, 0, 0, 'C');
        $currentY += 4.5;

        // Titolo libro
        $pdf->SetFont('helvetica', 'B', $fontSizeTitle);
        $pdf->SetXY($margin, $currentY);
        $pdf->Cell($availableWidth, 3.5, $titolo, 0, 0, 'C');
        $currentY += 4;

        // Autore ed editore
        if ($includeInfo) {
            $pdf->SetFont('helvetica', '', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 2.5, $infoText, 0, 0, 'C');
            $currentY += 3;
        }

        // Barcode (centered horizontally)
        if ($includeBarcode) {
            $barcodeWidth = min($availableWidth * 0.65, 44);
            $barcodeX = $margin + (($availableWidth - $barcodeWidth) / 2);
            $currentY += 0.5;
            $pdf->write1DBarcode($barcodeData, 'EAN13', $barcodeX, $currentY, $barcodeWidth, $barcodeHeight, 0.4, ['stretch' => true]);
            $currentY += $barcodeHeight + 0.5;
        }

        // Position text
        if ($includePosition) {
            $pdf->SetFont('helvetica', 'B', $fontSizeAuthor);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 3, $positionText, 0, 0, 'C');
            $currentY += 3.5;
        }

        // Collocazione
        if ($includeCollocazione) {
            $pdf->SetFont('helvetica', 'B', $fontSizePosition);
            $pdf->SetXY($margin, $currentY);
            $pdf->Cell($availableWidth, 4, $collocazione, 0, 0, 'C');
        }
    }

    /**
     * Export libri to CSV in import-compatible format
     */
    public function exportCsv(Request $request, Response $response, mysqli $db): Response
    {
        $repo = new \App\Models\BookRepository($db);

        // Get filters from query parameters
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $stato = $params['stato'] ?? '';
        $editoreId = isset($params['editore_id']) && is_numeric($params['editore_id']) ? (int)$params['editore_id'] : 0;
        $genereId = isset($params['genere_id']) && is_numeric($params['genere_id']) ? (int)$params['genere_id'] : 0;
        $autoreId = isset($params['autore_id']) && is_numeric($params['autore_id']) ? (int)$params['autore_id'] : 0;

        // Build WHERE clause based on filters
        $whereClauses = [];
        $bindTypes = '';
        $bindValues = [];

        // Global search filter
        if (!empty($search)) {
            $whereClauses[] = "(l.titolo LIKE ? OR l.sottotitolo LIKE ? OR l.isbn13 LIKE ? OR l.isbn10 LIKE ? OR a.nome LIKE ? OR e.nome LIKE ?)";
            $searchParam = "%{$search}%";
            for ($i = 0; $i < 6; $i++) {
                $bindTypes .= 's';
                $bindValues[] = $searchParam;
            }
        }

        // Status filter
        if (!empty($stato)) {
            $whereClauses[] = "l.stato = ?";
            $bindTypes .= 's';
            $bindValues[] = $stato;
        }

        // Editore filter
        if ($editoreId > 0) {
            $whereClauses[] = "l.editore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $editoreId;
        }

        // Genere filter
        if ($genereId > 0) {
            $whereClauses[] = "l.genere_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $genereId;
        }

        // Autore filter
        if ($autoreId > 0) {
            $whereClauses[] = "la.autore_id = ?";
            $bindTypes .= 'i';
            $bindValues[] = $autoreId;
        }

        // Build the query
        $query = "
            SELECT
                l.*,
                GROUP_CONCAT(DISTINCT a.nome ORDER BY la.ordine_credito SEPARATOR ';') as autori_nomi,
                e.nome as editore_nome,
                g.nome as genere_nome
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
        ";

        if (!empty($whereClauses)) {
            $query .= " WHERE " . implode(' AND ', $whereClauses);
        }

        $query .= " GROUP BY l.id ORDER BY l.id DESC";

        // Execute query with prepared statement if filters are applied
        if (!empty($bindValues)) {
            $stmt = $db->prepare($query);
            $refs = [];
            foreach ($bindValues as $key => $value) {
                $refs[$key] = &$bindValues[$key];
            }
            array_unshift($refs, $bindTypes);
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($query);
        }

        $libri = [];
        while ($row = $result->fetch_assoc()) {
            $libri[] = $row;
        }

        if (isset($stmt)) {
            $stmt->close();
        }

        // Generate CSV with same format as import
        $headers = [
            'id',
            'isbn10',
            'isbn13',
            'ean',
            'titolo',
            'sottotitolo',
            'autori',
            'editore',
            'anno_pubblicazione',
            'lingua',
            'edizione',
            'numero_pagine',
            'genere',
            'descrizione',
            'formato',
            'prezzo',
            'copie_totali',
            'collana',
            'numero_serie',
            'traduttore',
            'parole_chiave'
        ];

        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        $output .= implode(';', $headers) . "\n";

        foreach ($libri as $libro) {
            // Use anno_pubblicazione directly (YEAR type in DB)
            $anno = $libro['anno_pubblicazione'] ?? '';

            $row = [
                $libro['id'] ?? '',
                $libro['isbn10'] ?? '',
                $libro['isbn13'] ?? '',
                $libro['ean'] ?? '',
                $libro['titolo'] ?? '',
                $libro['sottotitolo'] ?? '',
                $libro['autori_nomi'] ?? '',
                $libro['editore_nome'] ?? '',
                $anno,
                $libro['lingua'] ?? '',
                $libro['edizione'] ?? '',
                $libro['numero_pagine'] ?? '',
                $libro['genere_nome'] ?? '',
                $libro['descrizione'] ?? '',
                $libro['formato'] ?? '',
                $libro['prezzo'] ?? '',
                $libro['copie_totali'] ?? '1',
                $libro['collana'] ?? '',
                $libro['numero_serie'] ?? '',
                $libro['traduttore'] ?? '',
                $libro['parole_chiave'] ?? ''
            ];

            // Escape fields for CSV
            $escapedRow = array_map(function($field) {
                $field = str_replace('"', '""', (string)$field);
                // Only quote if contains semicolon, newline, or quotes
                if (strpos($field, ';') !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $row);

            $output .= implode(';', $escapedRow) . "\n";
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $output);
        rewind($stream);

        $filename = 'libri_export_' . date('Y-m-d_His') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withBody(new \Slim\Psr7\Stream($stream));
    }
}
