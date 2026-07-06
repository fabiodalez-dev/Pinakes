<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\SurveysModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Surveys module (plan §7.13): manager-built questionnaires per club (or per
 * club book), progressive-enhancement form builder (no JS required), one
 * answer per member, aggregate results and manager CSV export.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 *
 * ANONYMITY — for anonymous surveys the member's user_id is still stored to
 * enforce the one-answer-per-member UNIQUE key, but it is never displayed:
 * results and the CSV export show no name and no timestamp for anonymous
 * surveys (a timestamp alone can de-anonymise a small club).
 */
class SurveyController extends BaseController
{
    /** Supported question types (schema_json "type" whitelist). */
    public const TYPES = ['short_text', 'long_text', 'single_choice', 'multi_choice', 'scale_1_5', 'yes_no'];

    /** Question types that carry an options whitelist. */
    public const CHOICE_TYPES = ['single_choice', 'multi_choice'];

    private const MAX_QUESTIONS = 30;
    private const MAX_OPTIONS = 20;

    private SurveysModule $module;
    private SurveyRepo $surveys;

    public function __construct(\mysqli $db, Repo $repo, SurveysModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->surveys = new SurveyRepo($db);
    }

    // ------------------------------------------------------------------
    // Resolution helpers
    // ------------------------------------------------------------------

    /**
     * Resolve a club by slug enforcing module enablement (→ null = 404).
     *
     * @return array<string, mixed>|null
     */
    private function resolveClub(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        return $club;
    }

    /**
     * Survey belonging to the club, or null (→ 404).
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>|null
     */
    private function resolveSurvey(array $club, int $surveyId): ?array
    {
        $survey = $this->surveys->surveyById($surveyId);
        if ($survey === null || (int) $survey['club_id'] !== (int) $club['id']) {
            return null;
        }
        return $survey;
    }

    private function indexPath(string $slug): string
    {
        return '/book-club/' . $slug . '/surveys';
    }

    private function surveyPath(string $slug, int $surveyId): string
    {
        return $this->indexPath($slug) . '/' . $surveyId;
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/surveys
    // ------------------------------------------------------------------

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        $isMember = $this->isActiveMember($club);
        if (!$isMember && !$canManage) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];
        $this->surveys->closeExpired($clubId);

        $all = $this->surveys->listSurveys($clubId, $canManage);
        $grouped = ['open' => [], 'draft' => [], 'closed' => []];
        foreach ($all as $survey) {
            $grouped[(string) $survey['status']][] = $survey;
        }
        $userId = $this->userId();

        return $this->renderPublic($response, 'public/surveys', [
            'club' => $club,
            'grouped' => $grouped,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'answeredIds' => $userId !== null ? $this->surveys->answeredSurveyIds($clubId, $userId) : [],
            'books' => $canManage ? $this->surveys->clubBooks($clubId) : [],
        ], __('Questionari') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/surveys/create  (managers → new draft)
    // ------------------------------------------------------------------

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo del questionario è obbligatorio.'));
            return $this->redirect($response, $this->indexPath($slug));
        }
        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
                $clubBookId = null;
            }
        }
        $anonymous = is_array($body) && !empty($body['anonymous']) ? 1 : 0;
        $opensAt = self::dateTimeOrNull(self::str($body, 'opens_at', 30));
        $closesAt = self::dateTimeOrNull(self::str($body, 'closes_at', 30));
        if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
            $this->flash('error', __('La data di chiusura deve essere successiva a quella di apertura.'));
            return $this->redirect($response, $this->indexPath($slug));
        }

        $surveyId = $this->surveys->createSurvey((int) $club['id'], $clubBookId, $title, $anonymous, $opensAt, $closesAt, $this->userId());
        if ($surveyId === null) {
            $this->flash('error', __('Impossibile creare il questionario. Riprova.'));
            return $this->redirect($response, $this->indexPath($slug));
        }
        $this->flash('success', __('Bozza creata: aggiungi le domande e pubblica quando è pronta.'));
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/surveys/{surveyId}
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        $isMember = $this->isActiveMember($club);
        if (!$isMember && !$canManage) {
            return $this->notFound($response);
        }
        $this->surveys->closeExpired((int) $club['id']);
        $survey = $this->resolveSurvey($club, $surveyId);
        if ($survey === null) {
            return $this->notFound($response);
        }
        // Drafts (the builder) are manager-only.
        if ($survey['status'] === 'draft' && !$canManage) {
            return $this->notFound($response);
        }

        $schema = SurveyRepo::decodeSchema(isset($survey['schema_json']) ? (string) $survey['schema_json'] : null);
        $anonymous = (int) ($survey['anonymous'] ?? 0) === 1;
        $userId = $this->userId();
        $myAnswer = ($userId !== null && $survey['status'] !== 'draft')
            ? $this->surveys->answerRow($surveyId, $userId)
            : null;

        // Results: closed surveys for everyone allowed here; open surveys for
        // managers only (live preview). Never for drafts (nothing to show).
        $results = null;
        if ($survey['status'] === 'closed' || ($survey['status'] === 'open' && $canManage)) {
            $results = $this->aggregateResults($schema, $this->surveys->answers($surveyId), $anonymous);
        }

        return $this->renderPublic($response, 'public/survey', [
            'club' => $club,
            'survey' => $survey,
            'schema' => $schema,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'myAnswer' => $myAnswer,
            'results' => $results,
            'typeLabels' => self::typeLabels(),
            // Draft settings editor (managers): linked-book select options.
            'books' => ($canManage && $survey['status'] === 'draft') ? $this->surveys->clubBooks((int) $club['id']) : [],
        ], (string) $survey['title'] . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // Builder: add / move / delete questions (managers, drafts only)
    // ------------------------------------------------------------------

    /**
     * Common guard for the builder POSTs.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null [club, survey]
     */
    private function resolveDraft(string $slug, int $surveyId): ?array
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canManage($club)) {
            return null;
        }
        $survey = $this->resolveSurvey($club, $surveyId);
        if ($survey === null || $survey['status'] !== 'draft') {
            return null;
        }
        return [$club, $survey];
    }

    public function addQuestion(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [, $survey] = $resolved;
        $schema = SurveyRepo::decodeSchema((string) $survey['schema_json']);
        if (count($schema) >= self::MAX_QUESTIONS) {
            $this->flash('error', sprintf(__('Limite di %d domande raggiunto.'), self::MAX_QUESTIONS));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }

        $body = $request->getParsedBody();
        $label = self::str($body, 'label', 190);
        if ($label === '') {
            $this->flash('error', __('Il testo della domanda è obbligatorio.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        $type = self::str($body, 'type', 30);
        if (!in_array($type, self::TYPES, true)) {
            $type = 'short_text';
        }

        $options = [];
        if (in_array($type, self::CHOICE_TYPES, true)) {
            foreach (preg_split('/\R/u', self::str($body, 'options', 4000)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !in_array($line, $options, true)) {
                    $options[] = mb_substr($line, 0, 190);
                }
                if (count($options) >= self::MAX_OPTIONS) {
                    break;
                }
            }
            if (count($options) < 2) {
                $this->flash('error', __('Le domande a scelta richiedono almeno due opzioni (una per riga).'));
                return $this->redirect($response, $this->surveyPath($slug, $surveyId));
            }
        }

        $schema[] = [
            'key' => SurveyRepo::nextQuestionKey($schema),
            'type' => $type,
            'label' => $label,
            'options' => $options,
            'required' => is_array($body) && !empty($body['required']),
        ];
        if ($this->surveys->updateSchemaJson($surveyId, (string) json_encode($schema, JSON_UNESCAPED_UNICODE))) {
            $this->flash('success', __('Domanda aggiunta.'));
        } else {
            $this->flash('error', __('Impossibile salvare la domanda. Riprova.'));
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    public function moveQuestion(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId, int $index): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [, $survey] = $resolved;
        $schema = SurveyRepo::decodeSchema((string) $survey['schema_json']);
        $dir = self::str($request->getParsedBody(), 'dir', 10) === 'up' ? -1 : 1;
        $target = $index + $dir;
        if (!isset($schema[$index]) || !isset($schema[$target])) {
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        [$schema[$index], $schema[$target]] = [$schema[$target], $schema[$index]];
        $this->surveys->updateSchemaJson($surveyId, (string) json_encode($schema, JSON_UNESCAPED_UNICODE));
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    public function deleteQuestion(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId, int $index): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [, $survey] = $resolved;
        $schema = SurveyRepo::decodeSchema((string) $survey['schema_json']);
        if (isset($schema[$index])) {
            array_splice($schema, $index, 1);
            if ($this->surveys->updateSchemaJson($surveyId, (string) json_encode($schema, JSON_UNESCAPED_UNICODE))) {
                $this->flash('success', __('Domanda eliminata.'));
            }
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    // ------------------------------------------------------------------
    // POST update / delete (managers, drafts only)
    // ------------------------------------------------------------------

    /**
     * Edit a draft's metadata: title, linked book, anonymous flag, opens_at
     * and closes_at. Published surveys are untouchable (freeze-on-publish),
     * enforced both here (resolveDraft) and in the repo (status guard).
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [$club, ] = $resolved;

        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $this->flash('error', __('Il titolo del questionario è obbligatorio.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
                $clubBookId = null;
            }
        }
        $anonymous = is_array($body) && !empty($body['anonymous']) ? 1 : 0;
        $opensAt = self::dateTimeOrNull(self::str($body, 'opens_at', 30));
        $closesAt = self::dateTimeOrNull(self::str($body, 'closes_at', 30));
        if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
            $this->flash('error', __('La data di chiusura deve essere successiva a quella di apertura.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }

        if ($this->surveys->updateDraftMeta($surveyId, $clubBookId, $title, $anonymous, $opensAt, $closesAt)) {
            $this->flash('success', __('Questionario aggiornato.'));
        } else {
            $this->flash('error', __('Impossibile aggiornare il questionario. Riprova.'));
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    /**
     * Hard-delete a draft (drafts only: answers cannot exist before publish,
     * so nothing is lost besides the question schema).
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        if ($this->surveys->deleteDraft($surveyId)) {
            $this->flash('success', __('Bozza eliminata.'));
            return $this->redirect($response, $this->indexPath($slug));
        }
        $this->flash('error', __('Impossibile eliminare la bozza. Riprova.'));
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    // ------------------------------------------------------------------
    // POST publish / close (managers)
    // ------------------------------------------------------------------

    public function publish(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $resolved = $this->resolveDraft($slug, $surveyId);
        if ($resolved === null) {
            return $this->notFound($response);
        }
        [, $survey] = $resolved;
        if (SurveyRepo::decodeSchema((string) $survey['schema_json']) === []) {
            $this->flash('error', __('Aggiungi almeno una domanda prima di pubblicare.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        if ($this->surveys->publish($surveyId)) {
            $this->flash('success', __('Questionario pubblicato: le domande non sono più modificabili.'));
        } else {
            $this->flash('error', __('Impossibile pubblicare il questionario.'));
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    public function close(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $survey = $this->resolveSurvey($club, $surveyId);
        if ($survey === null || $survey['status'] !== 'open') {
            return $this->notFound($response);
        }
        if ($this->surveys->closeSurvey($surveyId)) {
            $this->flash('success', __('Questionario chiuso: i risultati sono ora visibili ai membri.'));
        } else {
            $this->flash('error', __('Impossibile chiudere il questionario.'));
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/surveys/{surveyId}/answer  (active members)
    // ------------------------------------------------------------------

    public function answer(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono rispondere ai questionari.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        // Lazy close BEFORE re-reading: an expired survey must reject answers.
        $this->surveys->closeExpired((int) $club['id']);
        $survey = $this->resolveSurvey($club, $surveyId);
        if ($survey === null) {
            return $this->notFound($response);
        }
        if ($survey['status'] !== 'open') {
            $this->flash('error', __('Questo questionario non accetta più risposte.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        // Scheduled opening: status is already 'open' but answers are gated
        // until opens_at has passed.
        if (SurveyRepo::notYetOpen($survey)) {
            $this->flash('error', sprintf(__('Il questionario aprirà il %s'), date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at']))));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }
        $userId = (int) $this->userId();
        if ($this->surveys->answerRow($surveyId, $userId) !== null) {
            $this->flash('warning', __('Hai già risposto a questo questionario.'));
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }

        $schema = SurveyRepo::decodeSchema((string) $survey['schema_json']);
        $body = $request->getParsedBody();
        [$answers, $error] = $this->validateAnswers($schema, is_array($body) ? $body : []);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect($response, $this->surveyPath($slug, $surveyId));
        }

        // user_id is stored even for anonymous surveys: it enforces one answer
        // per member (the UNIQUE key ignores NULLs). Identity is never shown.
        if ($this->surveys->insertAnswer($surveyId, $userId, (string) json_encode($answers, JSON_UNESCAPED_UNICODE))) {
            $this->flash('success', __('Grazie, la tua risposta è stata registrata.'));
        } else {
            $this->flash('error', __('Impossibile registrare la risposta. Riprova.'));
        }
        return $this->redirect($response, $this->surveyPath($slug, $surveyId));
    }

    /**
     * Validate the posted form against the frozen schema: required fields,
     * options whitelist for choice questions, 1–5 range for scales, yes/no.
     * Field naming: q_<key> (multi_choice posts q_<key>[]).
     *
     * @param list<array{key: string, type: string, label: string, options: list<string>, required: bool}> $schema
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: string|null} [answers map, first error]
     */
    private function validateAnswers(array $schema, array $body): array
    {
        $answers = [];
        foreach ($schema as $q) {
            $key = $q['key'];
            $raw = $body['q_' . $key] ?? null;
            $requiredError = sprintf(__('La domanda "%s" è obbligatoria.'), $q['label']);
            $invalidError = sprintf(__('Risposta non valida alla domanda "%s".'), $q['label']);

            switch ($q['type']) {
                case 'short_text':
                case 'long_text':
                    $value = is_string($raw) ? mb_substr(trim($raw), 0, $q['type'] === 'short_text' ? 500 : 5000) : '';
                    if ($value === '') {
                        if ($q['required']) {
                            return [[], $requiredError];
                        }
                        break;
                    }
                    $answers[$key] = $value;
                    break;

                case 'single_choice':
                    $value = is_string($raw) ? $raw : '';
                    if ($value === '') {
                        if ($q['required']) {
                            return [[], $requiredError];
                        }
                        break;
                    }
                    if (!in_array($value, $q['options'], true)) {
                        return [[], $invalidError];
                    }
                    $answers[$key] = $value;
                    break;

                case 'multi_choice':
                    $values = is_array($raw) ? $raw : ($raw !== null && $raw !== '' ? [$raw] : []);
                    $clean = [];
                    foreach ($values as $value) {
                        if (!is_string($value)) {
                            continue;
                        }
                        if (!in_array($value, $q['options'], true)) {
                            return [[], $invalidError];
                        }
                        if (!in_array($value, $clean, true)) {
                            $clean[] = $value;
                        }
                    }
                    if ($clean === []) {
                        if ($q['required']) {
                            return [[], $requiredError];
                        }
                        break;
                    }
                    $answers[$key] = $clean;
                    break;

                case 'scale_1_5':
                    if ($raw === null || $raw === '') {
                        if ($q['required']) {
                            return [[], $requiredError];
                        }
                        break;
                    }
                    if (!is_numeric($raw) || (int) $raw < 1 || (int) $raw > 5) {
                        return [[], $invalidError];
                    }
                    $answers[$key] = (int) $raw;
                    break;

                case 'yes_no':
                    $value = is_string($raw) ? $raw : '';
                    if ($value === '') {
                        if ($q['required']) {
                            return [[], $requiredError];
                        }
                        break;
                    }
                    if (!in_array($value, ['yes', 'no'], true)) {
                        return [[], $invalidError];
                    }
                    $answers[$key] = $value;
                    break;
            }
        }
        return [$answers, null];
    }

    // ------------------------------------------------------------------
    // Results aggregation
    // ------------------------------------------------------------------

    /**
     * Per-question aggregates: counts per option (choice/yes-no), counts +
     * average for scales, text answers listed (author only when the survey
     * is NOT anonymous).
     *
     * @param list<array{key: string, type: string, label: string, options: list<string>, required: bool}> $schema
     * @param list<array<string, mixed>> $answerRows
     * @return array{total: int, questions: list<array<string, mixed>>}
     */
    private function aggregateResults(array $schema, array $answerRows, bool $anonymous): array
    {
        $decoded = [];
        foreach ($answerRows as $row) {
            $map = json_decode((string) ($row['answers_json'] ?? ''), true);
            $decoded[] = [
                'map' => is_array($map) ? $map : [],
                // WRITE-ONLY identity for anonymous surveys: drop it here so
                // it cannot leak further down (views, exports of this array).
                'author' => $anonymous ? null : trim((string) ($row['user_name'] ?? '')),
            ];
        }

        $questions = [];
        foreach ($schema as $q) {
            $key = $q['key'];
            $item = ['q' => $q, 'answered' => 0, 'counts' => [], 'avg' => null, 'texts' => []];

            if (in_array($q['type'], self::CHOICE_TYPES, true)) {
                $item['counts'] = array_fill_keys($q['options'], 0);
            } elseif ($q['type'] === 'scale_1_5') {
                $item['counts'] = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
            } elseif ($q['type'] === 'yes_no') {
                $item['counts'] = ['yes' => 0, 'no' => 0];
            }

            $scaleSum = 0;
            foreach ($decoded as $entry) {
                if (!array_key_exists($key, $entry['map'])) {
                    continue;
                }
                $value = $entry['map'][$key];
                switch ($q['type']) {
                    case 'short_text':
                    case 'long_text':
                        if (is_string($value) && trim($value) !== '') {
                            $item['answered']++;
                            $item['texts'][] = ['text' => $value, 'author' => $entry['author']];
                        }
                        break;
                    case 'single_choice':
                    case 'yes_no':
                        if (is_string($value) && array_key_exists($value, $item['counts'])) {
                            $item['answered']++;
                            $item['counts'][$value]++;
                        }
                        break;
                    case 'multi_choice':
                        if (is_array($value)) {
                            $any = false;
                            foreach ($value as $option) {
                                if (is_string($option) && array_key_exists($option, $item['counts'])) {
                                    $item['counts'][$option]++;
                                    $any = true;
                                }
                            }
                            if ($any) {
                                $item['answered']++;
                            }
                        }
                        break;
                    case 'scale_1_5':
                        if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 5) {
                            $item['answered']++;
                            $item['counts'][(string) (int) $value]++;
                            $scaleSum += (int) $value;
                        }
                        break;
                }
            }
            if ($q['type'] === 'scale_1_5' && $item['answered'] > 0) {
                $item['avg'] = $scaleSum / $item['answered'];
            }
            $questions[] = $item;
        }

        return ['total' => count($decoded), 'questions' => $questions];
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/surveys/{surveyId}/export.csv  (managers)
    // ------------------------------------------------------------------

    public function exportCsv(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $surveyId): ResponseInterface
    {
        $club = $this->resolveClub($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $survey = $this->resolveSurvey($club, $surveyId);
        if ($survey === null) {
            return $this->notFound($response);
        }
        $schema = SurveyRepo::decodeSchema((string) $survey['schema_json']);
        $anonymous = (int) ($survey['anonymous'] ?? 0) === 1;

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return $this->notFound($response);
        }
        // UTF-8 BOM so Excel opens accented labels correctly.
        fwrite($fh, "\xEF\xBB\xBF");

        // Anonymous surveys: no member name and no timestamp (a timestamp
        // alone can identify the respondent in a small club).
        $headers = ['#'];
        if (!$anonymous) {
            $headers[] = __('Membro');
        }
        foreach ($schema as $q) {
            $headers[] = $q['label'];
        }
        if (!$anonymous) {
            $headers[] = __('Inviata il');
        }
        fputcsv($fh, $headers);

        $n = 0;
        foreach ($this->surveys->answers($surveyId) as $row) {
            $map = json_decode((string) ($row['answers_json'] ?? ''), true);
            $map = is_array($map) ? $map : [];
            $n++;
            $line = [$n];
            if (!$anonymous) {
                $line[] = trim((string) ($row['user_name'] ?? ''));
            }
            foreach ($schema as $q) {
                $line[] = self::csvCell($q, $map[$q['key']] ?? null);
            }
            if (!$anonymous) {
                $line[] = (string) $row['created_at'];
            }
            fputcsv($fh, $line);
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="book-club-' . $slug . '-survey-' . $surveyId . '-' . date('Ymd') . '.csv"');
    }

    /**
     * @param array{key: string, type: string, label: string, options: list<string>, required: bool} $q
     */
    private static function csvCell(array $q, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return match ($q['type']) {
            'multi_choice' => is_array($value) ? implode('; ', array_map('strval', $value)) : '',
            'yes_no' => $value === 'yes' ? __('Sì') : ($value === 'no' ? __('No') : ''),
            'scale_1_5' => is_numeric($value) ? (string) (int) $value : '',
            default => is_string($value) ? $value : '',
        };
    }

    // ------------------------------------------------------------------
    // Shared labels
    // ------------------------------------------------------------------

    /** @return array<string, string> question type → translated label */
    public static function typeLabels(): array
    {
        return [
            'short_text' => __('Testo breve'),
            'long_text' => __('Testo lungo'),
            'single_choice' => __('Scelta singola'),
            'multi_choice' => __('Scelta multipla'),
            'scale_1_5' => __('Scala 1–5'),
            'yes_no' => __('Sì/No'),
        ];
    }
}
