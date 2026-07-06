<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\StatsModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stats module (plan §7.11 + export §7.17): member-facing club statistics
 * page, the mirrored admin page, and the full-history JSON/CSV export.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 * Access is gated by the granular governance matrix: the member-facing
 * stats page requires `stats.view` (held by the system 'member' role, by
 * owner/moderator, by Pinakes admin/staff and by custom roles granting
 * it), exports require `exports.run` (managers + custom roles).
 */
class StatsController extends BaseController
{
    private StatsModule $module;
    private StatsRepo $stats;

    public function __construct(\mysqli $db, Repo $repo, StatsModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->stats = new StatsRepo($db);
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

    /**
     * Aggregate dataset shared by the public page, the admin page and the
     * headline part of the export.
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function statsData(array $club): array
    {
        $clubId = (int) $club['id'];
        $states = $this->repo->workflowStates($club);
        $counts = $this->stats->booksPerState($clubId);

        $stateRows = [];
        foreach ($states as $state) {
            $stateRows[] = [
                'key' => $state['key'],
                'label' => $state['label'],
                'color' => $state['color'],
                'count' => $counts[$state['key']] ?? 0,
            ];
        }

        return [
            'states' => $states,
            'stateRows' => $stateRows,
            'pendingCount' => $counts[BookClubPlugin::STATE_PENDING] ?? 0,
            'finished' => $this->stats->finishedBookCount($clubId, StatsRepo::finishedStateKeys($states)),
            'meetingsDone' => $this->stats->meetingsHeld($clubId),
            'membersActive' => $this->repo->countActiveMembers($clubId),
            'avgStars' => $this->stats->avgApprovedStars($clubId),
            'topProposers' => $this->stats->topProposers($clubId, 5),
        ];
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/stats  (stats.view)
    // ------------------------------------------------------------------

    /**
     * Member-facing club statistics page.
     *
     * Permission: `stats.view` (granular matrix — the system 'member' role
     * holds it, so every active member keeps access; owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        if (!$this->can($club, 'stats.view')) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        $data = $this->statsData($club);
        $data['club'] = $club;
        $data['canManage'] = $canManage;
        $data['isAdminView'] = false;
        return $this->renderPublic($response, 'public/club_stats', $data, __('Statistiche') . ' — ' . (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // GET /admin/book-club/{id}/stats  (AdminAuthMiddleware)
    // ------------------------------------------------------------------

    public function adminShow(ServerRequestInterface $request, ResponseInterface $response, int $clubId): ResponseInterface
    {
        $club = $this->repo->clubById($clubId);
        if ($club === null || !$this->module->enabledFor($club)) {
            return $this->notFound($response);
        }
        $data = $this->statsData($club);
        $data['club'] = $club;
        $data['canManage'] = true;
        return $this->renderAdmin($response, 'admin/stats', $data);
    }

    // ------------------------------------------------------------------
    // Export (exports.run)
    // ------------------------------------------------------------------

    /**
     * Full club history, structured. NO member emails, ever.
     *
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function buildExport(array $club): array
    {
        $clubId = (int) $club['id'];
        $states = $this->repo->workflowStates($club);
        $stateLabel = static function (string $key) use ($states): string {
            $state = Repo::stateByKey($states, $key);
            return $state !== null ? (string) $state['label'] : $key;
        };

        $books = [];
        foreach ($this->stats->exportBooks($clubId) as $book) {
            $books[] = [
                'titolo' => (string) $book['titolo'],
                'autori' => (string) ($book['autori'] ?? ''),
                'state' => (string) $book['state'],
                'state_label' => $stateLabel((string) $book['state']),
                'season' => $book['season_name'] !== null ? (string) $book['season_name'] : null,
                'proposer' => (string) ($book['proposer'] ?? ''),
                'reading_starts' => $book['reading_starts'] !== null ? (string) $book['reading_starts'] : null,
                'reading_ends' => $book['reading_ends'] !== null ? (string) $book['reading_ends'] : null,
                'created_at' => (string) $book['created_at'],
            ];
        }

        $stateLog = [];
        foreach ($this->stats->exportStateLog($clubId) as $entry) {
            $stateLog[] = [
                'titolo' => (string) $entry['titolo'],
                'from_state' => (string) $entry['from_state'],
                'to_state' => (string) $entry['to_state'],
                'changed_at' => (string) $entry['changed_at'],
                'changed_by' => (string) ($entry['changed_by_name'] ?? ''),
            ];
        }

        $polls = [];
        foreach ($this->stats->exportPolls($clubId) as $poll) {
            $options = [];
            foreach ($this->repo->pollOptions((int) $poll['id']) as $option) {
                $options[] = [
                    'titolo' => (string) $option['titolo'],
                    'score' => (float) $option['score'],
                    'vote_count' => (int) $option['vote_count'],
                ];
            }
            $polls[] = [
                'title' => (string) $poll['title'],
                'mode' => (string) $poll['mode'],
                'votes_per_member' => (int) $poll['votes_per_member'],
                'anonymity' => (string) $poll['anonymity'],
                'status' => (string) $poll['status'],
                'winner' => $poll['winner_title'] !== null ? (string) $poll['winner_title'] : null,
                'created_at' => (string) $poll['created_at'],
                'closed_at' => $poll['closed_at'] !== null ? (string) $poll['closed_at'] : null,
                'options' => $options,
            ];
        }

        $meetings = [];
        foreach ($this->repo->clubMeetings($clubId) as $meeting) {
            $meetings[] = [
                'title' => (string) $meeting['title'],
                'starts_at' => (string) $meeting['starts_at'],
                'ends_at' => $meeting['ends_at'] !== null ? (string) $meeting['ends_at'] : null,
                'kind' => (string) $meeting['kind'],
                'location' => (string) ($meeting['location'] ?? ''),
                'status' => (string) $meeting['status'],
                'book' => $meeting['book_title'] !== null ? (string) $meeting['book_title'] : null,
                'yes_count' => (int) $meeting['yes_count'],
                'maybe_count' => (int) $meeting['maybe_count'],
            ];
        }

        $members = [];
        foreach ($this->repo->listMembers($clubId) as $member) {
            // Privacy: name + role + status only — never the email.
            $members[] = [
                'name' => trim((string) $member['nome'] . ' ' . (string) $member['cognome']),
                'role' => (string) $member['role_name'],
                'status' => (string) $member['status'],
            ];
        }

        return [
            'club' => [
                'name' => (string) $club['name'],
                'slug' => (string) $club['slug'],
                'privacy' => (string) $club['privacy'],
                'created_at' => (string) $club['created_at'],
            ],
            'generated_at' => date('c'),
            'books' => $books,
            'state_log' => $stateLog,
            'polls' => $polls,
            'meetings' => $meetings,
            'members' => $members,
        ];
    }

    /**
     * Full-history JSON export.
     *
     * Permission: `exports.run` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function exportJson(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->can($club, 'exports.run')) {
            return $this->notFound($response);
        }
        $json = json_encode($this->buildExport($club), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $response->getBody()->write($json === false ? '{}' : $json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="book-club-' . $slug . '-' . date('Ymd') . '.json"');
    }

    /**
     * Full-history CSV export (multi-section, Excel-friendly BOM).
     *
     * Permission: `exports.run` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function exportCsv(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->can($club, 'exports.run')) {
            return $this->notFound($response);
        }
        $data = $this->buildExport($club);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return $this->notFound($response);
        }
        // UTF-8 BOM so Excel opens accented titles correctly.
        fwrite($fh, "\xEF\xBB\xBF");

        $this->csvSection($fh, __('Libri'),
            [__('Titolo'), __('Autori'), __('Stato'), __('Stagione'), __('Proposto da'), __('Inizio lettura'), __('Fine lettura')],
            array_map(static fn(array $b): array => [
                $b['titolo'], $b['autori'], $b['state_label'], $b['season'] ?? '', $b['proposer'],
                $b['reading_starts'] ?? '', $b['reading_ends'] ?? '',
            ], $data['books'])
        );
        $this->csvSection($fh, __('Cronologia stati'),
            [__('Libro'), __('Da'), __('A'), __('Quando'), __('Modificato da')],
            array_map(static fn(array $l): array => [
                $l['titolo'], $l['from_state'], $l['to_state'], $l['changed_at'], $l['changed_by'],
            ], $data['state_log'])
        );
        $this->csvSection($fh, __('Votazioni'),
            [__('Titolo'), __('Modalità'), __('Stato'), __('Vincitore'), __('Chiusa il')],
            array_map(static fn(array $p): array => [
                $p['title'], $p['mode'], $p['status'], $p['winner'] ?? '', $p['closed_at'] ?? '',
            ], $data['polls'])
        );
        $optionRows = [];
        foreach ($data['polls'] as $poll) {
            foreach ($poll['options'] as $option) {
                $optionRows[] = [$poll['title'], $option['titolo'], $option['score'], $option['vote_count']];
            }
        }
        $this->csvSection($fh, __('Opzioni votazione'),
            [__('Votazione'), __('Libro'), __('Punteggio'), __('Voti')],
            $optionRows
        );
        $this->csvSection($fh, __('Incontri'),
            [__('Titolo'), __('Data'), __('Tipo'), __('Stato'), __('Sì'), __('Forse')],
            array_map(static fn(array $m): array => [
                $m['title'], $m['starts_at'], $m['kind'], $m['status'], $m['yes_count'], $m['maybe_count'],
            ], $data['meetings'])
        );
        $this->csvSection($fh, __('Membri'),
            [__('Nome'), __('Ruolo'), __('Stato')],
            array_map(static fn(array $m): array => [$m['name'], $m['role'], $m['status']], $data['members'])
        );

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="book-club-' . $slug . '-' . date('Ymd') . '.csv"');
    }

    /**
     * One export section: "# <name>" header row, column headers, data rows,
     * then a blank line separating it from the next section.
     *
     * @param resource $fh
     * @param list<string> $headers
     * @param list<array<int, mixed>> $rows
     */
    private function csvSection($fh, string $name, array $headers, array $rows): void
    {
        fputcsv($fh, [self::csvSafe('# ' . $name)]);
        fputcsv($fh, array_map([self::class, 'csvSafe'], $headers));
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], $row));
        }
        fwrite($fh, "\n");
    }

    /**
     * Neutralise spreadsheet formula injection: user-controlled cells
     * (book titles, member names, poll titles) starting with =, +, -, @,
     * tab or CR would otherwise execute as formulas in Excel/Sheets.
     */
    private static function csvSafe(mixed $value): string
    {
        $cell = (string) $value;
        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $cell;
        }
        return $cell;
    }
}
