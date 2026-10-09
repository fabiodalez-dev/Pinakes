<?php
/**
 * Emeroteca PDFs answer HTTP range requests (RFC 9110 §14): the browser's
 * viewer asks for byte ranges to show a large scan's first page and to seek.
 *
 * Run: php tests/emeroteca-pdf-range.unit.php
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../storage/plugins/emeroteca/src/Support/PdfRangeResponder.php';

use App\Plugins\Emeroteca\Support\PdfRangeResponder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

// parseRange
$check(PdfRangeResponder::parseRange('', 1000) === null, 'no Range header → whole file');
$check(PdfRangeResponder::parseRange('bytes=0-99', 1000) === [0, 99], 'bytes=0-99 → first 100 bytes');
$check(PdfRangeResponder::parseRange('bytes=900-', 1000) === [900, 999], 'open-ended range runs to the last byte');
$check(PdfRangeResponder::parseRange('bytes=-100', 1000) === [900, 999], 'suffix range → last 100 bytes');
$check(PdfRangeResponder::parseRange('bytes=-5000', 1000) === [0, 999], 'suffix longer than the file → whole file as a range');
$check(PdfRangeResponder::parseRange('bytes=500-99999', 1000) === [500, 999], 'end past the file is clamped');
$check(PdfRangeResponder::parseRange('bytes=1000-', 1000) === false, 'start at the size → unsatisfiable');
$check(PdfRangeResponder::parseRange('bytes=50-10', 1000) === false, 'start after end → unsatisfiable');
$check(PdfRangeResponder::parseRange('bytes=0-1,5-9', 1000) === null, 'multi-range → whole file');
$check(PdfRangeResponder::parseRange('items=0-9', 1000) === null, 'other unit → whole file');
$check(PdfRangeResponder::parseRange('bytes=abc', 1000) === null, 'malformed → whole file');

// respond
$file = tempnam(sys_get_temp_dir(), 'pdfr');
file_put_contents($file, '%PDF-1.4' . str_repeat('x', 992));
$rf = new ResponseFactory();
$req = (new ServerRequestFactory())->createServerRequest('GET', '/x.pdf');

$full = PdfRangeResponder::respond($req, $rf->createResponse(), $file, ['Cache-Control' => 'private, no-store']);
$check($full->getStatusCode() === 200 && $full->getHeaderLine('Content-Length') === '1000', 'no Range → 200 with the full length');
$check($full->getHeaderLine('Accept-Ranges') === 'bytes', 'Accept-Ranges: bytes is advertised');
$check($full->getHeaderLine('Cache-Control') === 'private, no-store', 'caller headers are kept');
$check($full->getHeaderLine('Content-Type') === 'application/pdf', 'served as application/pdf');

$part = PdfRangeResponder::respond($req->withHeader('Range', 'bytes=0-7'), $rf->createResponse(), $file, []);
$check($part->getStatusCode() === 206, 'Range → 206 Partial Content');
$check($part->getHeaderLine('Content-Range') === 'bytes 0-7/1000', 'Content-Range names the slice and the size');
$check((string) $part->getBody() === '%PDF-1.4', 'the body is exactly the requested bytes');
$check($part->getHeaderLine('Content-Length') === '8', 'Content-Length is the slice length');

$tail = PdfRangeResponder::respond($req->withHeader('Range', 'bytes=995-'), $rf->createResponse(), $file, []);
$check((string) $tail->getBody() === 'xxxxx' && $tail->getHeaderLine('Content-Range') === 'bytes 995-999/1000', 'a tail range returns the last bytes');

$bad = PdfRangeResponder::respond($req->withHeader('Range', 'bytes=5000-'), $rf->createResponse(), $file, []);
$check($bad->getStatusCode() === 416 && $bad->getHeaderLine('Content-Range') === 'bytes */1000', 'unsatisfiable → 416 with bytes */size');

$noReq = PdfRangeResponder::respond(null, $rf->createResponse(), $file, []);
$check($noReq->getStatusCode() === 200, 'no request object → whole file');

unlink($file);
echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
