<?php
declare(strict_types=1);

/**
 * Regression guard for the Choices.js server-side-search pickers on the book form
 * (authors, contributors, publishers).
 *
 * These pickers fetch matches from /api/search/autori on keystroke and feed them
 * in via setChoices(). Choices.js's OWN client-side Fuse filter (searchChoices,
 * default true) races that async populate: for a short/partial query it renders
 * the dropdown ("no results") BEFORE the fetch lands and never re-filters, so
 * fresh server results are hidden until the next keystroke. The fix disables the
 * client filter (searchChoices: false) and makes each server response REPLACE the
 * previous query's options WITHOUT resetting the user's in-progress input
 * (setChoices(..., replaceChoices=true, clearSearchFlag=false)) — so results
 * always show, no stale options linger, and neither the typed text nor an
 * already-selected chip is cleared.
 *
 * The race itself is timing-dependent and can't be asserted deterministically, so
 * this locks in the structural fix: a revert (dropping searchChoices:false, or
 * flipping the setChoices flags back) fails here. Behaviour is covered by manual
 * E2E on the live form.
 *
 * Run:  php tests/contributor-autocomplete-config.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/app/Views/libri/partials/book_form.php');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// Normalise whitespace so multi-line / re-indented matches still hold.
$flat = (string) preg_replace('/\s+/', ' ', $src);

echo "A. Client-side Fuse filter disabled on the three server-search pickers\n";
// Each picker's `new Choices(...)` config must carry `searchChoices: false`.
$configs = [
    'authors'      => 'authorsChoice = new Choices(element, {',
    'contributors' => 'const choice = new Choices(el, {',
    'publishers'   => 'publishersChoice = new Choices(element, {',
];
foreach ($configs as $name => $ctor) {
    $pos = strpos($flat, $ctor);
    // Look at the ~400 chars following the constructor for searchChoices:false.
    $window = $pos !== false ? substr($flat, $pos, 400) : '';
    $check($pos !== false && (bool) preg_match('/searchChoices:\s*false/', $window),
        "{$name} picker sets searchChoices: false");
}

echo "B. Server results REPLACE options without resetting the search input\n";
// The three search-handler setChoices(newChoices, ...) calls must pass
// replaceChoices=true AND clearSearchFlag=false — i.e. `, true, false)`.
$searchSetChoices = [
    'authors'      => 'authorsChoice.setChoices(newChoices',
    'contributors' => 'choice.setChoices(newChoices',
    'publishers'   => 'publishersChoice.setChoices(newChoices',
];
foreach ($searchSetChoices as $name => $call) {
    // Match the specific call and assert its trailing args are `, true, false)`.
    $ok = (bool) preg_match(
        '/' . preg_quote($call, '/') . ",\s*'value',\s*'label',\s*true,\s*false\s*\)/",
        $flat
    );
    $check($ok, "{$name} search handler uses setChoices(..., replace=true, clearSearchFlag=false)");
}

echo "C. No server-search handler still uses the racy append form\n";
// Guard against a partial revert: none of the three newChoices setChoices calls
// may use the old 4-arg append form `, 'value', 'label', false)`.
foreach ($searchSetChoices as $name => $call) {
    $regressed = (bool) preg_match(
        '/' . preg_quote($call, '/') . ",\s*'value',\s*'label',\s*false\s*\)/",
        $flat
    );
    $check(!$regressed, "{$name} search handler does NOT use the old append form (racy)");
}

echo "D. Server results are applied unconditionally (clears stale on no-results)\n";
// With the client filter off, a `if (newChoices.length > 0)` guard before
// setChoices would leave the previous query's options visible when a search
// returns nothing. The setChoices must run even for an empty array so replace
// clears the dropdown (CodeRabbit #272). No such guard may remain.
$check(!str_contains($src, 'newChoices.length > 0'),
    'no `if (newChoices.length > 0)` guard gates the server-search setChoices calls');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
