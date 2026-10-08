<?php
declare(strict_types=1);

// #461: staff can open an attached private PDF from the public article page;
// visitors still see only files explicitly published by the library.
require dirname(__DIR__) . '/vendor/autoload.php';

function renderArticlePdf461(?string $path, int $public, bool $canEdit): string
{
    $article = [
        'id' => 461, 'titolo' => 'An article with an attachment',
        'pdf_path' => $path, 'pdf_pubblico' => $public,
        'risorsa_url' => 'https://example.org/article', 'risorsa_pubblica' => 1,
    ];
    ob_start();
    include dirname(__DIR__) . '/storage/plugins/emeroteca/src/Views/public/article.php';
    return (string) ob_get_clean();
}

$checks = 0;
foreach ([null, '', str_repeat('a', 40) . '.pdf'] as $path) {
    foreach ([0, 1] as $public) {
        foreach ([false, true] as $staff) {
            $html = renderArticlePdf461($path, $public, $staff);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $pdfLinks = $xpath->query('//a[substring(@href, string-length(@href) - 3) = "/pdf"]');
            $expected = $path !== null && $path !== '' && ($public === 1 || $staff);
            if ($pdfLinks === false || $pdfLinks->length !== (int) $expected) {
                throw new RuntimeException("PDF visibility mismatch: path=" . var_export($path, true) . ", public={$public}, staff=" . (int) $staff);
            }
            if ($expected) {
                $suffix = $public === 1 ? '/emeroteca/articolo/461/pdf' : '/admin/periodicals/articles/461/pdf';
                $link = $pdfLinks->item(0);
                if (!$link instanceof DOMElement || !str_ends_with($link->getAttribute('href'), $suffix)
                    || !str_contains($link->getAttribute('class'), 'btn-primary')) {
                    throw new RuntimeException('The PDF must use the authorized streaming route and be the primary action');
                }
            }
            $primary = $xpath->query('//div[contains(@class,"resource-action-buttons")]/a[contains(@class,"btn-primary")]');
            if ($primary === false || $primary->length !== 1 || str_contains($html, str_repeat('a', 40))) {
                throw new RuntimeException('Keep exactly one primary resource action and never expose the stored filename');
            }
            $checks++;
        }
    }
}
echo "SUCCESS {$checks} article PDF access/rendering cases\n";
