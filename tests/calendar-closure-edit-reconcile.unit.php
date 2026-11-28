<?php
declare(strict_types=1);

/**
 * Editing a closure reconciles the due dates its removed days had pushed
 * forward, the way deleting one does.
 *
 * A closure typed a year too long (start 2026-12-24 instead of 2027-12-24,
 * end 2028-01-06) moves a loan due 2027-03-10 to 2028-01-07. Correcting the
 * start through the edit form used to leave that loan a year away, and the
 * later "delete and restore due dates" skipped it: the corrected closure no
 * longer covered the loan's own day.
 *
 * update() now takes the same choice delete() offers (undo_extensions=1):
 *  - with it, the due dates held only by the days the edit removes come back
 *    to the day the corrected calendar gives them;
 *  - without it they stay, and are recorded as granted so a later undo of
 *    the closure cannot take them back (the plain delete does the same);
 *  - the days the edit adds keep moving due dates forward, as before.
 *
 * The real controller against a sandbox database built from the installer
 * schema and triggers (settings-pr-independent-25 harness, dropped at the end).
 *
 * Run: php tests/calendar-closure-edit-reconcile.unit.php
 */

use App\Controllers\Admin\LibraryCalendarController;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\LibraryCalendar;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

/** What the controller left for the next page: read after the call, not inferred from before it. */
$flash = static fn (string $key): mixed => $_SESSION[$key] ?? null;
$edit = static function (SettingsReview25 $t, int $id, string $start, string $end, bool $undo, array $extra = []) use ($flash): void {
    unset($_SESSION['error_message'], $_SESSION['success_message']);
    (new LibraryCalendarController())->update(
        $t->request('/admin/calendar/closures/' . $id . '/update', $extra + [
            'data_inizio' => $start, 'data_fine' => $end, 'descrizione' => 'Edited closure', 'sede_id' => '',
        ] + ($undo ? ['undo_extensions' => '1'] : [])),
        new Response(),
        $t->db,
        $id
    );
    SettingsReview25::eq($flash('error_message'), null, 'The edit must be saved');
};
$delete = static function (SettingsReview25 $t, int $id, bool $undo): void {
    unset($_SESSION['error_message']);
    (new LibraryCalendarController())->delete(
        $t->request('/admin/calendar/closures/' . $id . '/delete', $undo ? ['undo_extensions' => '1'] : []),
        new Response(),
        $t->db,
        $id
    );
};
/** How many history events of one kind a book carries. */
$events = static fn (SettingsReview25 $t, array $book, string $event): int => (int) $t->scalar(
    "SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id={$book['id']}
       AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi,'$._activity.event'))='{$event}'"
);
/** Day offsets from the application's today, the day every due-date rule reads. */
$day = static fn (int $offset): string => LibraryCalendar::shift(DateHelper::today(), $offset);

$cases = [
    'correcting a closure typed a year too long brings the loan back' => static function (SettingsReview25 $t) use ($edit, $delete, $events, $day, $flash): void {
        $book = $t->book();
        $loan = $t->loan($book, $day(104));
        $closure = $t->closure($day(28), $day(406));
        SettingsReview25::eq($t->due($loan), $day(407), 'The mistyped closure moves the loan past its end');

        $edit($t, $closure, $day(393), $day(406), undo: true);
        SettingsReview25::eq($flash('success_message'), __('Chiusura aggiornata.'), 'The edit answers as before');
        SettingsReview25::eq($t->due($loan), $day(104), 'The corrected closure no longer covers the loan: it is due on its own day again');
        SettingsReview25::eq($events($t, $book, 'loan.due_restored'), 1, 'The history records the restored due date');

        // As idempotent as the delete path: nothing left to take back.
        $edit($t, $closure, $day(393), $day(406), undo: true);
        $delete($t, $closure, undo: true);
        SettingsReview25::eq($t->due($loan), $day(104), 'A repeated edit and a later undo leave the restored date alone');
        SettingsReview25::eq($events($t, $book, 'loan.due_restored'), 1, 'No second restore is recorded');
    },
    'without the choice the reader keeps the date, recorded as granted' => static function (SettingsReview25 $t) use ($edit, $delete, $events, $day): void {
        $book = $t->book();
        $loan = $t->loan($book, $day(35));
        $closure = $t->closure($day(30), $day(40));
        SettingsReview25::eq($t->due($loan), $day(41), 'The closure moves the loan');

        $edit($t, $closure, $day(30), $day(36), undo: false);
        SettingsReview25::eq($t->due($loan), $day(41), 'A plain edit keeps the due date the reader was given');
        SettingsReview25::eq($events($t, $book, 'loan.due_preserved'), 1, 'The kept date is recorded as granted by the removed days');
        SettingsReview25::eq($events($t, $book, 'loan.due_restored'), 0, 'Nothing is taken back');

        // Without that record this undo would retract the loan to day 35.
        $delete($t, $closure, undo: true);
        SettingsReview25::eq($t->due($loan), $day(41), 'A later undo of the closure cannot retract the date the edit kept');
    },
    'an edit that only lengthens moves due dates forward, as before' => static function (SettingsReview25 $t) use ($edit, $delete, $events, $day): void {
        foreach ([false, true] as $undo) {
            $t->reset();
            $book = $t->book();
            $loan = $t->loan($book, $day(31));
            $closure = $t->closure($day(30), $day(32));
            SettingsReview25::eq($t->due($loan), $day(33), 'The closure moves the loan');

            $edit($t, $closure, $day(30), $day(35), $undo);
            SettingsReview25::eq($t->due($loan), $day(36), 'The added days move the loan forward');
            SettingsReview25::eq($events($t, $book, 'loan.due_restored') + $events($t, $book, 'loan.due_preserved'), 0, 'No day was removed: nothing restored, nothing recorded as kept');

            $delete($t, $closure, undo: true);
            SettingsReview25::eq($t->due($loan), $day(31), 'Undoing the lengthened closure still recovers the original day');
        }
    },
    'an edit that shifts the window: removed days come back, added days move forward' => static function (SettingsReview25 $t) use ($edit, $day): void {
        $inside = $t->loan($t->book(), $day(31));
        $after = $t->loan($t->book(), $day(34));
        $closure = $t->closure($day(30), $day(32));
        SettingsReview25::eq([$t->due($inside), $t->due($after)], [$day(33), $day(34)], 'Only the loan inside the closure moves');

        $edit($t, $closure, $day(33), $day(36), undo: true);
        SettingsReview25::eq($t->due($inside), $day(31), 'Its day is open again: the loan comes back to it');
        SettingsReview25::eq($t->due($after), $day(37), 'The loan the new window covers moves past it');
    },
    'a shifted window without the choice only moves forward' => static function (SettingsReview25 $t) use ($edit, $day): void {
        $inside = $t->loan($t->book(), $day(31));
        $after = $t->loan($t->book(), $day(34));
        $closure = $t->closure($day(30), $day(32));
        $edit($t, $closure, $day(33), $day(36), undo: false);
        SettingsReview25::eq([$t->due($inside), $t->due($after)], [$day(37), $day(37)], 'The kept date now falls in the new window and moves with it');
    },
    'a shortened closure keeps the days it still closes' => static function (SettingsReview25 $t) use ($edit, $day): void {
        $early = $t->loan($t->book(), $day(33));
        $late = $t->loan($t->book(), $day(38));
        $closure = $t->closure($day(30), $day(40));
        SettingsReview25::eq([$t->due($early), $t->due($late)], [$day(41), $day(41)], 'Both loans wait for the reopening');

        $edit($t, $closure, $day(30), $day(36), undo: true);
        SettingsReview25::eq($t->due($early), $day(37), 'A loan still inside the closure is due on the new reopening day');
        SettingsReview25::eq($t->due($late), $day(38), 'A loan on a reopened day is due on that day');
    },
    'a closure narrowed to one branch frees the loans of the others' => static function (SettingsReview25 $t) use ($edit, $day): void {
        $here = $t->loan($t->book($t->branchA), $day(31));
        $there = $t->loan($t->book($t->branchB), $day(31));
        $closure = $t->closure($day(30), $day(32));
        SettingsReview25::eq([$t->due($here), $t->due($there)], [$day(33), $day(33)], 'A closure of every branch moves both loans');

        $edit($t, $closure, $day(30), $day(32), undo: true, extra: ['sede_id' => (string) $t->branchB]);
        SettingsReview25::eq($t->due($here), $day(31), 'The branch that is open after all gets its day back');
        SettingsReview25::eq($t->due($there), $day(33), 'The branch still closed keeps the moved date');
    },
    'an edit that changes no day touches no loan' => static function (SettingsReview25 $t) use ($edit, $events, $day): void {
        $book = $t->book();
        $loan = $t->loan($book, $day(31));
        $closure = $t->closure($day(30), $day(32));
        $edit($t, $closure, $day(30), $day(32), undo: true, extra: ['descrizione' => 'Only renamed']);
        SettingsReview25::eq($t->due($loan), $day(33), 'A renamed closure leaves the due date alone');
        SettingsReview25::eq($events($t, $book, 'loan.due_restored') + $events($t, $book, 'loan.due_preserved'), 0, 'And records nothing');
        SettingsReview25::eq((string) $t->scalar("SELECT descrizione FROM calendario_chiusure WHERE id={$closure}"), 'Only renamed', 'The new description is saved');
    },
    'a manual due date set after the move is not taken back' => static function (SettingsReview25 $t) use ($edit, $day): void {
        $loan = $t->loan($t->book(), $day(31));
        $closure = $t->closure($day(30), $day(32));
        SettingsReview25::eq($t->due($loan), $day(33), 'The closure moves the loan');
        // The librarian agreed a later day by hand: no calendar move leads to it.
        $t->db->query("UPDATE prestiti SET data_scadenza='{$day(50)}' WHERE id={$loan}");
        $edit($t, $closure, $day(45), $day(46), undo: true);
        SettingsReview25::eq($t->due($loan), $day(50), 'The hand-set due date stays');
    },
];

$suite = null;
$failed = 0;
$passed = 0;
try {
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$failed, &$passed): void {
        foreach ($cases as $name => $case) {
            try {
                $suite->reset();
                $case($suite);
                $passed++;
                echo "PASS {$name}\n";
            } catch (Throwable $e) {
                $failed++;
                echo "FAIL {$name}: {$e->getMessage()}\n";
            }
        }
    });
} finally {
    LibraryCalendar::reset();
    $suite?->close();
}
echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
