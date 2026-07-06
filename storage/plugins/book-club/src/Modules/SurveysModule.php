<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\SurveyController;
use App\Plugins\BookClub\SurveyRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../SurveyRepo.php';
require_once __DIR__ . '/../SurveyController.php';

/**
 * Surveys module — Questionari (plan §7.13). Default OFF.
 *
 * Managers build questionnaires with a progressive-enhancement form builder
 * (add one question at a time — short/long text, single/multi choice, 1–5
 * scale, yes/no — reorder and delete with plain forms, no JS required),
 * optionally linked to a club book, nominal or anonymous, with an optional
 * scheduled opening (opens_at: published surveys stay gated until then) and
 * an optional closing date. Drafts can be edited (title, book, anonymity,
 * dates) and hard-deleted. Publishing freezes the schema; members answer once
 * each
 * (UNIQUE (survey_id, user_id)); results are aggregated per question and
 * exportable as CSV by managers.
 *
 * ANONYMITY — the answer row always stores user_id (that is what enforces
 * one-answer-per-member: the UNIQUE key permits unlimited NULL user_id rows,
 * so NULLing it would allow ballot stuffing). For anonymous surveys the
 * identity is write-only: results and exports never show names or per-answer
 * timestamps.
 *
 * Tables: bookclub_surveys, bookclub_survey_answers.
 */
class SurveysModule extends AbstractModule
{
    public function slug(): string
    {
        return 'surveys';
    }

    public function label(): string
    {
        return __('Questionari');
    }

    public function description(): string
    {
        return __('Questionari del club per libro o generici, anonimi o nominali, con risultati aggregati ed export CSV');
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function ensureSchema(): array
    {
        $result = $this->runDdl([
            'bookclub_surveys' => "CREATE TABLE IF NOT EXISTS bookclub_surveys (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                club_book_id INT NULL,
                title VARCHAR(190) NOT NULL,
                schema_json TEXT NULL,
                status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
                anonymous TINYINT(1) NOT NULL DEFAULT 0,
                opens_at DATETIME NULL,
                closes_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcsurv_club (club_id, status),
                KEY idx_bcsurv_book (club_book_id),
                CONSTRAINT fk_bcsurv_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcsurv_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE SET NULL,
                CONSTRAINT fk_bcsurv_creator FOREIGN KEY (created_by)
                    REFERENCES utenti (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_survey_answers' => "CREATE TABLE IF NOT EXISTS bookclub_survey_answers (
                id INT NOT NULL AUTO_INCREMENT,
                survey_id INT NOT NULL,
                user_id INT NULL,
                answers_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_survey_user (survey_id, user_id),
                KEY idx_bcsurvans_user (user_id),
                CONSTRAINT fk_bcsurvans_survey FOREIGN KEY (survey_id)
                    REFERENCES bookclub_surveys (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcsurvans_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);

        // Idempotent guards for installs whose bookclub_surveys predates the
        // scheduling columns (CREATE TABLE IF NOT EXISTS skips existing
        // tables, so the columns above are only guaranteed on fresh installs).
        $this->addColumnIfMissing('bookclub_surveys', 'opens_at', 'DATETIME NULL AFTER anonymous');
        $this->addColumnIfMissing('bookclub_surveys', 'closes_at', 'DATETIME NULL AFTER opens_at');

        return $result;
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new SurveyController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/surveys',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->index($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/create',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->create($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/export.csv',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->exportCsv($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/questions/add',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->addQuestion($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/questions/{index:[0-9]+}/move',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->moveQuestion($rq, $rs, (string) $a['slug'], (int) $a['surveyId'], (int) $a['index'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/questions/{index:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->deleteQuestion($rq, $rs, (string) $a['slug'], (int) $a['surveyId'], (int) $a['index'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/update',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->update($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->delete($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/publish',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->publish($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/close',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->close($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/surveys/{surveyId:[0-9]+}/answer',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->answer($rq, $rs, (string) $a['slug'], (int) $a['surveyId'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panel
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club)) {
            return '';
        }
        $isMember = !empty($ctx['isMember']);
        $canManage = !empty($ctx['canManage']);
        // Surveys are member activity: the panel is members/managers-only.
        if (!$isMember && !$canManage) {
            return '';
        }
        $clubId = (int) $club['id'];
        $userId = isset($ctx['userId']) ? (int) $ctx['userId'] : null;

        try {
            $surveys = new SurveyRepo($this->db);
            $surveys->closeExpired($clubId);
            $open = $surveys->openSurveysWithCounts($clubId);
            $answeredIds = ($isMember && $userId !== null) ? $surveys->answeredSurveyIds($clubId, $userId) : [];
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:surveys] panel failed: ' . $e->getMessage());
            return '';
        }
        if ($open === [] && !$canManage) {
            return '';
        }

        return $this->renderPartial('partials/surveys_panel', [
            'club' => $club,
            'open' => $open,
            'answeredIds' => $answeredIds,
            'isMember' => $isMember,
            'canManage' => $canManage,
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance tick: auto-close expired surveys
    // ------------------------------------------------------------------

    public function onMaintenanceTick(): void
    {
        try {
            $surveys = new SurveyRepo($this->db);
            foreach ($this->repo->listAllClubs() as $club) {
                if ((int) ($club['is_active'] ?? 0) !== 1) {
                    continue;
                }
                // listAllClubs returns raw rows: decode settings so the
                // per-club enablement check sees the modules list.
                $settings = json_decode((string) ($club['settings'] ?? ''), true);
                $club['settings'] = is_array($settings) ? $settings : [];
                if (!$this->enabledFor($club)) {
                    continue;
                }
                try {
                    $closed = $surveys->closeExpired((int) $club['id']);
                    if ($closed > 0) {
                        SecureLogger::info('[BookClub:' . (string) $club['slug'] . '] auto-closed ' . $closed . ' expired survey(s)');
                    }
                } catch (\Throwable $e) {
                    SecureLogger::warning('[BookClub:surveys] auto-close failed for club ' . (int) $club['id'] . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:surveys] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
