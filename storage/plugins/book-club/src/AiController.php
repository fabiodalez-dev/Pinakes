<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\AiModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * AI module (plan §7.17, opt-in): manager-only generation page
 * (/book-club/{slug}/ai — discussion questions for a club book, structured
 * summary of a meeting's minutes) and the Pinakes-admin settings page
 * (/admin/book-club/ai — API key, model, endpoint, global on/off).
 *
 * Every club handler re-checks per-club module enablement (routes are
 * global). Generation is gated three times: canManage(), the global
 * "configured" switch (admin enabled + API key present) and the hard
 * per-club daily cap (AiService::MAX_OUTPUTS_PER_DAY rows / 24h).
 */
class AiController extends BaseController
{
    private AiModule $module;
    private AiService $ai;

    public function __construct(\mysqli $db, Repo $repo, AiModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->ai = new AiService($db);
    }

    /**
     * Resolve a club by slug enforcing module enablement (→ null = 404).
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        return $club;
    }

    private function aiPath(string $slug): string
    {
        return '/book-club/' . $slug . '/ai';
    }

    /**
     * Meetings of the club that actually have minutes text (the only ones
     * summarisable).
     *
     * @return list<array<string, mixed>>
     */
    private function meetingsWithMinutes(int $clubId): array
    {
        return array_values(array_filter(
            $this->repo->clubMeetings($clubId),
            static fn(array $m): bool => trim((string) ($m['minutes'] ?? '')) !== ''
        ));
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/ai  (managers only)
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club) || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $configured = $this->ai->isConfigured();

        return $this->renderPublic($response, 'public/ai', [
            'club' => $club,
            'configured' => $configured,
            'isPinakesAdmin' => $this->isPinakesAdmin(),
            'model' => $configured ? $this->ai->model() : '',
            'books' => $configured ? $this->repo->clubBooks($clubId) : [],
            'meetings' => $configured ? $this->meetingsWithMinutes($clubId) : [],
            'outputs' => $configured ? $this->ai->listOutputs($clubId, 20) : [],
            'recentCount' => $configured ? $this->ai->countRecentOutputs($clubId, 24) : 0,
            'dailyCap' => AiService::MAX_OUTPUTS_PER_DAY,
        ], __('Assistente IA') . ' — ' . (string) $club['name']);
    }

    /**
     * Shared POST gate: club + manager + configured + daily cap.
     *
     * @return array<string, mixed>|ResponseInterface the club, or the ready
     *                                                redirect/404 response
     */
    private function gateGeneration(ResponseInterface $response, string $slug)
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club) || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        if (!$this->ai->isConfigured()) {
            $this->flash('error', __('Il modulo IA non è configurato: chiedi all\'amministratore di Pinakes di impostare la chiave API.'));
            return $this->redirect($response, $this->aiPath($slug));
        }
        if (!$this->ai->underDailyCap((int) $club['id'])) {
            $this->flash('warning', sprintf(__('Limite di sicurezza raggiunto: massimo %d generazioni IA per club nelle ultime 24 ore. Riprova più tardi.'), AiService::MAX_OUTPUTS_PER_DAY));
            return $this->redirect($response, $this->aiPath($slug));
        }
        return $club;
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/ai/questions  (managers)
    // ------------------------------------------------------------------

    public function generateQuestions(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->gateGeneration($response, $slug);
        if ($club instanceof ResponseInterface) {
            return $club;
        }
        $clubId = (int) $club['id'];

        $clubBookId = self::intOrNull($request->getParsedBody(), 'club_book_id');
        $clubBook = $clubBookId !== null ? $this->repo->clubBook($clubBookId) : null;
        if ($clubBook === null || (int) $clubBook['club_id'] !== $clubId) {
            $this->flash('error', __('Seleziona un libro del club.'));
            return $this->redirect($response, $this->aiPath($slug));
        }

        $title = (string) $clubBook['titolo'];
        $authors = trim((string) ($clubBook['autori'] ?? ''));
        $description = $this->ai->bookDescription((int) $clubBook['libro_id']);

        $system = __('Sei un assistente per club di lettura di una biblioteca. Rispondi sempre in italiano, con tono cordiale e concreto.');
        $user = sprintf(
            __('Genera esattamente 5 domande di discussione per un club del libro che ha letto «%s»%s. Le domande devono stimolare il confronto tra i membri, toccare temi, personaggi e stile, ed essere aperte (mai a risposta sì/no). Formattale come elenco numerato da 1 a 5, una domanda per riga, senza testo introduttivo né conclusivo.'),
            $title,
            $authors !== '' ? sprintf(__(' di %s'), $authors) : ''
        );
        if ($description !== '') {
            $user .= "\n\n" . __('Descrizione del libro:') . "\n" . $description;
        }

        $text = $this->ai->generate($system, $user);
        if ($text === null) {
            $this->flash('error', __('Generazione non riuscita: il servizio IA non ha risposto. Riprova più tardi.'));
            return $this->redirect($response, $this->aiPath($slug));
        }

        if ($this->ai->saveOutput($clubId, 'questions', (int) $clubBook['id'], $text, $this->ai->model(), $this->userId()) !== null) {
            $this->flash('success', sprintf(__('Domande di discussione generate per «%s».'), $title));
        } else {
            $this->flash('error', __('Impossibile salvare il risultato generato.'));
        }
        return $this->redirect($response, $this->aiPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/ai/minutes  (managers)
    // ------------------------------------------------------------------

    public function generateMinutes(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->gateGeneration($response, $slug);
        if ($club instanceof ResponseInterface) {
            return $club;
        }
        $clubId = (int) $club['id'];

        $meetingId = self::intOrNull($request->getParsedBody(), 'meeting_id');
        $meeting = $meetingId !== null ? $this->repo->meeting($meetingId) : null;
        if ($meeting === null || (int) $meeting['club_id'] !== $clubId) {
            $this->flash('error', __('Seleziona un incontro del club.'));
            return $this->redirect($response, $this->aiPath($slug));
        }
        $minutes = trim((string) ($meeting['minutes'] ?? ''));
        if ($minutes === '') {
            $this->flash('error', __('L\'incontro selezionato non ha un verbale da riassumere.'));
            return $this->redirect($response, $this->aiPath($slug));
        }

        $system = __('Sei un assistente per club di lettura di una biblioteca. Rispondi sempre in italiano, con tono cordiale e concreto.');
        $user = sprintf(
            __('Riassumi il verbale dell\'incontro «%s» di un club del libro. Produci un riassunto breve e strutturato in tre sezioni con questi titoli: "Sintesi", "Decisioni prese", "Prossimi passi". Usa elenchi puntati sintetici. Non inventare informazioni assenti dal verbale.'),
            (string) $meeting['title']
        );
        $user .= "\n\n" . __('Verbale:') . "\n" . mb_substr($minutes, 0, 6000);

        $text = $this->ai->generate($system, $user);
        if ($text === null) {
            $this->flash('error', __('Generazione non riuscita: il servizio IA non ha risposto. Riprova più tardi.'));
            return $this->redirect($response, $this->aiPath($slug));
        }

        if ($this->ai->saveOutput($clubId, 'minutes', (int) $meeting['id'], $text, $this->ai->model(), $this->userId()) !== null) {
            $this->flash('success', sprintf(__('Riassunto del verbale generato per «%s».'), (string) $meeting['title']));
        } else {
            $this->flash('error', __('Impossibile salvare il risultato generato.'));
        }
        return $this->redirect($response, $this->aiPath($slug));
    }

    // ------------------------------------------------------------------
    // GET /admin/book-club/ai  (AdminAuthMiddleware)
    // ------------------------------------------------------------------

    public function adminSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderAdmin($response, 'admin/ai_settings', [
            'pluginFound' => $this->ai->pluginId() !== null,
            'enabled' => $this->ai->isEnabled(),
            'maskedKey' => $this->ai->maskedKey(),
            'model' => $this->ai->model(),
            'endpoint' => $this->ai->endpoint(),
            'defaultModel' => AiService::DEFAULT_MODEL,
            'defaultEndpoint' => AiService::DEFAULT_ENDPOINT,
            'title' => __('Book Club — Impostazioni IA'),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /admin/book-club/ai  (AdminAuthMiddleware + CSRF)
    // ------------------------------------------------------------------

    public function adminSettingsSave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->ai->pluginId() === null) {
            $this->flash('error', __('Plugin Book Club non trovato nel registro dei plugin.'));
            return $this->redirect($response, '/admin/book-club/ai');
        }
        $body = $request->getParsedBody();

        $enabled = is_array($body) && !empty($body['enabled']) ? '1' : '0';
        $clearKey = is_array($body) && !empty($body['clear_key']);
        $apiKey = self::str($body, 'api_key', 500);
        $model = self::str($body, 'model', 100);
        if ($model === '') {
            $model = AiService::DEFAULT_MODEL;
        }
        $endpoint = self::str($body, 'endpoint', 500);
        if ($endpoint === '') {
            $endpoint = AiService::DEFAULT_ENDPOINT;
        }
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || !str_starts_with($endpoint, 'https://')) {
            $this->flash('error', __('L\'endpoint deve essere un URL HTTPS valido.'));
            return $this->redirect($response, '/admin/book-club/ai');
        }

        $ok = $this->ai->saveSetting(AiService::SETTING_ENABLED, $enabled)
            && $this->ai->saveSetting(AiService::SETTING_MODEL, $model)
            && $this->ai->saveSetting(AiService::SETTING_ENDPOINT, $endpoint);

        // Key handling: blank input = keep the stored key (the form shows
        // only the mask); "clear" checkbox wipes it; non-blank replaces it.
        if ($clearKey) {
            $ok = $this->ai->saveSetting(AiService::SETTING_API_KEY, '') && $ok;
        } elseif ($apiKey !== '') {
            $ok = $this->ai->saveSetting(AiService::SETTING_API_KEY, $apiKey) && $ok;
        }

        if ($ok) {
            $this->flash('success', __('Impostazioni IA salvate.'));
        } else {
            $this->flash('error', __('Impossibile salvare le impostazioni IA: controlla i log (chiave di cifratura configurata?).'));
        }
        return $this->redirect($response, '/admin/book-club/ai');
    }
}
