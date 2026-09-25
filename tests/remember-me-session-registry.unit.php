<?php
declare(strict_types=1);

/**
 * The note that keeps concurrent remember-me sign-ins inside one session.
 *
 * Measured on a real page load before this existed: a browser carrying only
 * the remember-me cookie asks for the page and, at the same moment, for
 * whatever the page fetches itself. Neither request has a session, both
 * authenticate from the same cookie, and both used to mint their own with
 * their own CSRF token. About half the time the dashboard's own
 * /api/stats/active-loans-count request won the race, so the browser kept a
 * session the served HTML knew nothing about and the next form post was
 * refused with "Errore di Sicurezza".
 *
 * These checks cover the note itself: what it will hand back, what it refuses
 * to hand back, and that it never holds the token it is named after.
 *
 *   php tests/remember-me-session-registry.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\RememberMeSessionRegistry;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$dir = sys_get_temp_dir() . '/pinakes-rm-registry-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
RememberMeSessionRegistry::useDirectory($dir);

$token = 'a-remember-me-token-' . bin2hex(random_bytes(8));
$sid = 'abcdef0123456789abcdef01';

try {
    // -----------------------------------------------------------------------
    echo "\nA. Nothing to join\n";
    $check(RememberMeSessionRegistry::lookup($token) === null,
        'an unknown token has no session to join');
    $check(RememberMeSessionRegistry::lookup('') === null,
        'and neither does an empty one');

    // -----------------------------------------------------------------------
    echo "\nB. A sibling writes it down, the next request joins\n";
    $check(RememberMeSessionRegistry::claim($token, $sid) === $sid,
        'the first sign-in publishes its own session and is told so');
    $check(RememberMeSessionRegistry::lookup($token) === $sid, 'and a sibling is handed that same id');

    // The first writer wins, not the last. Two requests can get past the read
    // at the same instant; if the second then replaced the note, a third
    // sibling would join whichever happened to be on disk when it looked, and
    // the burst would end up split across two sessions again.
    $other = 'ffffffff11111111ffffffff';
    $check(RememberMeSessionRegistry::claim($token, $other) === $sid,
        'a request that loses the race is told which session won');
    $check(RememberMeSessionRegistry::lookup($token) === $sid,
        'and the published note is still the first one');

    $check(RememberMeSessionRegistry::lookup($token . 'x') === null,
        'a different token gets nothing — notes are per token');

    // -----------------------------------------------------------------------
    echo "\nC. The token itself is never written down\n";
    $files = array_values(array_filter(scandir($dir) ?: [], static fn ($f) => $f !== '.' && $f !== '..'));
    $check(count($files) === 1, 'one note on disk, and no half-built one left beside it');
    $check($files !== [] && $files[0] === hash('sha256', $token),
        'named after a hash of the token, not the token');
    $onDisk = (string) file_get_contents($dir . '/' . $files[0]);
    $check(strpos($onDisk, $token) === false,
        'and the token appears nowhere inside it');
    $check(preg_match('/^[A-Za-z0-9,\-]+\|\d+$/', trim($onDisk)) === 1,
        'the note holds a session id and a timestamp, nothing else');

    // -----------------------------------------------------------------------
    echo "\nD. A note stops being worth joining\n";
    // Older than the window: a later visit must start cleanly rather than be
    // pulled into a session from an earlier one.
    file_put_contents($dir . '/' . hash('sha256', $token), $sid . '|' . (time() - 31));
    $check(RememberMeSessionRegistry::lookup($token) === null,
        'a note older than the window is ignored');

    file_put_contents($dir . '/' . hash('sha256', $token), $sid . '|' . (time() - 5));
    $check(RememberMeSessionRegistry::lookup($token) === $sid,
        'one inside the window is still good');

    // -----------------------------------------------------------------------
    echo "\nE. What it refuses to hand to session_id()\n";
    // This value decides which session a request joins, so a malformed or
    // hostile one must never reach session_id().
    foreach ([
        'has spaces here' => 'an id with spaces',
        "abc\n/etc/passwd" => 'an id with a newline and a path',
        '../../../etc/passwd' => 'a traversal attempt',
        'short' => 'an id too short to be one',
        '' => 'an empty id',
        'ok*chars!' => 'an id with punctuation the handler never emits',
    ] as $candidate => $why) {
        file_put_contents($dir . '/' . hash('sha256', $token), $candidate . '|' . time());
        $check(RememberMeSessionRegistry::lookup($token) === null, "refused: {$why}");
        $check(!RememberMeSessionRegistry::isWellFormedSessionId((string) $candidate),
            "  and rejected on its own: {$why}");
    }

    foreach ([
        'malformed note' => 'no separator at all',
        'abcdef0123456789abcdef01|notanumber' => 'a timestamp that is not a number',
    ] as $candidate => $why) {
        file_put_contents($dir . '/' . hash('sha256', $token), (string) $candidate);
        $check(RememberMeSessionRegistry::lookup($token) === null, "refused: {$why}");
    }

    $check(RememberMeSessionRegistry::claim($token, 'not a session id') === null,
        'and a malformed id is refused on the way in too');

    // -----------------------------------------------------------------------
    echo "\nF. A note that is nobody's session gets out of the way\n";
    // First-writer-wins must not mean a dead note blocks the token forever.
    // Anything the reader refuses — past its window, malformed, truncated by a
    // process that died mid-write — belongs to no session and is replaced.
    foreach ([
        'abcdef0123456789abcdef01|' . (time() - 120) => 'a note past its window',
        'garbage with no separator' => 'a malformed note',
        '' => 'an empty note, as a truncated write would leave',
    ] as $stale => $why) {
        file_put_contents($dir . '/' . hash('sha256', $token), (string) $stale);
        $check(RememberMeSessionRegistry::claim($token, $sid) === $sid,
            "replaced: {$why}");
        $check(RememberMeSessionRegistry::lookup($token) === $sid,
            "  and siblings now join the live session: {$why}");
        RememberMeSessionRegistry::forget($token);
    }

    // -----------------------------------------------------------------------
    echo "\nG. Old notes do not pile up\n";
    // One note per remembered sign-in, kept for seconds: without a sweep the
    // directory would only ever grow. Writes tidy up on a fraction of calls,
    // so this keeps writing until one of them does.
    foreach (range(1, 12) as $i) {
        $stale = $dir . '/' . hash('sha256', "old-token-{$i}");
        file_put_contents($stale, 'abcdef0123456789abcdef01|' . (time() - 120));
        touch($stale, time() - 120);
    }
    $before = count(glob($dir . '/*') ?: []);
    $check($before >= 12, "stale notes are on disk to begin with ({$before})");

    // A published note is never rewritten, so the sweep rides on new ones:
    // each pass claims a token of its own and then drops it again.
    $fresh = 'sweep-token-' . bin2hex(random_bytes(4));
    RememberMeSessionRegistry::claim($fresh, $sid);
    for ($i = 0; $i < 400 && count(glob($dir . '/*') ?: []) > 2; $i++) {
        $passing = 'sweep-pass-' . $i . '-' . bin2hex(random_bytes(4));
        RememberMeSessionRegistry::claim($passing, $sid);
        RememberMeSessionRegistry::forget($passing);
    }
    $after = count(glob($dir . '/*') ?: []);
    $check($after < $before, "a sweep ran and removed them ({$before} -> {$after})");
    $check(RememberMeSessionRegistry::lookup($fresh) === $sid,
        'while the note still inside the window survived it');
    RememberMeSessionRegistry::forget($fresh);

    // -----------------------------------------------------------------------
    echo "\nH. Retiring the cookie drops the note\n";
    RememberMeSessionRegistry::claim($token, $sid);
    $check(RememberMeSessionRegistry::lookup($token) === $sid, 'a note is there to drop');
    $check(RememberMeSessionRegistry::forget($token), 'forget() reports success');
    $check(RememberMeSessionRegistry::lookup($token) === null, 'and nothing is left to join');
    $check(RememberMeSessionRegistry::forget($token),
        'forgetting again is success: there is nothing left to join either way');
} finally {
    RememberMeSessionRegistry::useDirectory(null);
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
