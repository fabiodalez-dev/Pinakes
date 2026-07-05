<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\AiController;
use App\Plugins\BookClub\AiService;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/service owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../AiService.php';
require_once __DIR__ . '/../AiController.php';

/**
 * AI module — Estensioni IA (plan §7.17, strictly OPT-IN: defaultEnabled
 * false, plus a global admin switch and an API key that only the Pinakes
 * admin can configure).
 *
 * For club managers, when the Pinakes admin has configured an Anthropic-
 * compatible endpoint + API key (stored AES-encrypted in plugin_settings via
 * PluginManager):
 *  - generate 5 discussion questions for a club book (title + authors +
 *    catalog description in the prompt);
 *  - generate a short structured summary (Sintesi / Decisioni prese /
 *    Prossimi passi) of a meeting's minutes.
 * Every generation is persisted in bookclub_ai_outputs (history on the
 * module page) and a hard cost cap refuses new generations once a club has
 * produced AiService::MAX_OUTPUTS_PER_DAY rows in the last 24 hours.
 *
 * Table: bookclub_ai_outputs.
 */
class AiModule extends AbstractModule
{
    public function slug(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return __('Assistente IA');
    }

    public function description(): string
    {
        return __('Domande di discussione e riassunti dei verbali generati con l\'IA (richiede chiave API configurata dall\'amministratore)');
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
        return $this->runDdl([
            'bookclub_ai_outputs' => "CREATE TABLE IF NOT EXISTS bookclub_ai_outputs (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                kind ENUM('questions','minutes') NOT NULL,
                entity_id INT NOT NULL,
                content MEDIUMTEXT NOT NULL,
                model VARCHAR(100) NOT NULL DEFAULT '',
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcai_club (club_id, created_at),
                KEY idx_bcai_entity (kind, entity_id),
                CONSTRAINT fk_bcai_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcai_user FOREIGN KEY (created_by)
                    REFERENCES utenti (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new AiController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);
        $adminMw = new \App\Middleware\AdminAuthMiddleware();

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/ai',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/ai/questions',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->generateQuestions($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/ai/minutes',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->generateMinutes($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);

        $app->get(
            '/admin/book-club/ai',
            fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->adminSettings($rq, $rs)
        )->add($adminMw);
        $app->post(
            '/admin/book-club/ai',
            fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $controller->adminSettingsSave($rq, $rs)
        )->add($csrfMw)->add($adminMw);
    }

    // ------------------------------------------------------------------
    // Club page panel — small teaser for managers when the key is set
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club)) {
            return '';
        }
        if (empty($ctx['canManage'])) {
            return '';
        }
        try {
            $service = new AiService($this->db);
            if (!$service->isConfigured()) {
                return '';
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:ai] panel failed: ' . $e->getMessage());
            return '';
        }
        return $this->renderPartial('partials/ai_panel', [
            'club' => $club,
        ]);
    }
}
