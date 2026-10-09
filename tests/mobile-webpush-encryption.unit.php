<?php
/**
 * Web Push message encryption (RFC 8291, aes128gcm): the RFC's own test
 * vector (Appendix A) and a decrypt round trip with the receiver's key.
 *
 * Run: php tests/mobile-webpush-encryption.unit.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../storage/plugins/mobile-api/src/Push/WebPushEncryption.php';

use App\Plugins\MobileApi\Push\WebPushEncryption;

$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$unb64u = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));

// An EC private key PEM from its raw scalar and public point (SEC 1 ECPrivateKey).
$privatePem = static function (string $d, string $pub): string {
    $der = hex2bin('307702010104') . "\x20" . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
};

// RFC 8291 Appendix A.
$plaintext = 'When I grow up, I want to be a watermelon';
$asPrivate = $unb64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPublic = $unb64u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$uaPrivate = $unb64u('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94');
$uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$auth = 'BTBZMqHH6r4Tts7J_aSIgg';
$salt = $unb64u('DGv6ra1nlYgDCS1FRnbzlw');
$expected = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

$body = WebPushEncryption::encrypt($plaintext, $uaPublic, $auth, $privatePem($asPrivate, $asPublic), $salt);
$check($body !== null, 'the RFC vector encrypts');
$check($body !== null && $b64u($body) === $expected, 'the body is byte-for-byte the RFC 8291 Appendix A result');

// Round trip with a random sender key: the receiver decrypts what we send.
$ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($ua)['ec'];
$uaPub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
$authSecret = random_bytes(16);
$message = json_encode(['type' => 'loan_due', 'title' => 'Scadenza', 'body' => 'Il prestito scade domani']);
$sent = WebPushEncryption::encrypt($message, $b64u($uaPub), $b64u($authSecret));
$check($sent !== null, 'a random-key message encrypts');
if ($sent !== null) {
    $saltR = substr($sent, 0, 16);
    $rs = unpack('N', substr($sent, 16, 4))[1];
    $idlen = ord($sent[20]);
    $asPub = substr($sent, 21, $idlen);
    $cipher = substr($sent, 21 + $idlen);
    $check($rs === 4096 && $idlen === 65 && $asPub[0] === "\x04", 'header: rs 4096, a 65-byte sender key');
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPub;
    $asKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
    $ecdh = openssl_pkey_derive($asKey, $ua, 32);
    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\x00" . $uaPub . $asPub . "\x01", $prkKey, true);
    $prk = hash_hmac('sha256', $ikm, $saltR, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
    $check($plain === $message . "\x02", 'the receiver decrypts the message and the last-record delimiter');
}

// Unusable keys are refused, never sent as garbage.
$check(WebPushEncryption::encrypt('x', 'not-a-key', $auth) === null, 'a malformed p256dh is refused');
$check(WebPushEncryption::encrypt('x', $uaPublic, 'c2hvcnQ') === null, 'an auth secret that is not 16 bytes is refused');
$check(WebPushEncryption::encrypt(str_repeat('x', 5000), $uaPublic, $auth) === null, 'a payload larger than one record is refused');

// Registration-time check.
$check(WebPushEncryption::keysAreUsable($uaPublic, $auth), 'the RFC subscriber keys are usable');
$check(!WebPushEncryption::keysAreUsable($uaPublic, ''), 'a missing auth secret is not usable');
$offCurve = $b64u("\x04" . str_repeat("\x01", 64));
$check(!WebPushEncryption::keysAreUsable($offCurve, $auth), 'a point off the P-256 curve is not usable');

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
