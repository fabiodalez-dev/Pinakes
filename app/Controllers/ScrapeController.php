<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ScrapeController
{
    public function byIsbn(Request $request, Response $response): Response
    {
        $isbn = trim((string)($request->getQueryParams()['isbn'] ?? ''));
        if ($isbn === '') {
            $response->getBody()->write(json_encode([
                'error' => __('Parametro ISBN mancante.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // SSRF Protection: Validate ISBN format before constructing URL
        $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Validate ISBN format (ISBN-10 or ISBN-13)
        if (!$this->isValidIsbn($cleanIsbn)) {
            $response->getBody()->write(json_encode([
                'error' => __('Formato ISBN non valido.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Only allow requests to whitelisted domain
        $allowedBaseUrl = 'https://www.libreriauniversitaria.it';
        $url = $allowedBaseUrl . '/morale-anarchica-libro-petr-alekseevic-kropotkin/libro/' . rawurlencode($cleanIsbn);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, // Security: Limit redirects to prevent SSRF
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36',
            CURLOPT_ENCODING => '', // abilita decodifica gzip/deflate
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $html = curl_exec($ch);
        if ($html === false || $html === '') {
            $err = curl_error($ch) ?: 'empty response';
            $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            // Prova un secondo tentativo sull'URL effettivo (dopo redirect)
            if ($eff) {
                curl_setopt($ch, CURLOPT_URL, $eff);
                $html = curl_exec($ch);
            }
            if ($html === false || $html === '') {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $response->getBody()->write(json_encode([
                    'error' => __('Impossibile contattare il servizio di scraping. Riprova più tardi.'),
                    'details' => $err,
                    'code' => $code,
                    'url' => $eff,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
            }
        }
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

        // Security: Validate final URL is still on whitelisted domain (SSRF prevention)
        $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?: '';
        if ($finalHost !== 'www.libreriauniversitaria.it' && $finalHost !== 'libreriauniversitaria.it') {
            curl_close($ch);
            $response->getBody()->write(json_encode([
                'error' => __('Reindirizzamento verso dominio non autorizzato bloccato.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        curl_close($ch);

        // Parsing HTML con XPath (metodo originale testato e funzionante)
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $libxmlFlags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        $previousEntitySetting = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntitySetting = libxml_disable_entity_loader(true);
        }
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, $libxmlFlags);
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader((bool)$previousEntitySetting);
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        // Copertina - prima tenta da Feltrinelli, poi fallback a LibreriaUniversitaria
        $image = $this->scrapeFeltrinelliCover($cleanIsbn);
        if ($image === '') {
            // Fallback al metodo originale
            $imgNode = $xpath->query("//div[@id='image']//img");
            $image = ($imgNode->length > 0) ? $imgNode->item(0)->getAttribute("src") : '';
        }

        // Titolo
        $titleNode = $xpath->query("//div[contains(@class, 'title-wrapper')]//h1[contains(@class, 'pc-title')]");
        $title = ($titleNode->length > 0) ? trim($titleNode->item(0)->textContent) : '';

        // Sottotitolo
        $subtitleNode = $xpath->query("//h2[contains(@class, 'product-subtitle')]");
        $subtitle = ($subtitleNode->length > 0) ? trim($subtitleNode->item(0)->textContent) : '';

        // Autore (singolo)
        $authorNode = $xpath->query("//div[contains(@class, 'product-author')]//a");
        $author = ($authorNode->length > 0) ? trim($authorNode->item(0)->textContent) : '';

        // Tutti gli autori
        $authors = [];
        $authorNodes = $xpath->query("//div[contains(@class, 'product-author')]//a");
        if ($authorNodes->length > 0) {
            foreach ($authorNodes as $authorNode) {
                $authorText = trim($authorNode->textContent);
                if ($authorText !== '') {
                    $authors[] = $authorText;
                }
            }
        }
        if (empty($authors) && !empty($author)) {
            $authors = [$author];
        }

        // Editore
        $publisherNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Editore:')]/following-sibling::dd[1]//span[contains(@class, 'text')]");
        $publisher = ($publisherNode->length > 0) ? trim($publisherNode->item(0)->textContent) : '';

        // Data di Pubblicazione
        $pubDateNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Data di Pubblicazione:')]/following-sibling::dd[1]");
        $pubDate = ($pubDateNode->length > 0) ? trim($pubDateNode->item(0)->textContent) : '';

        // EAN
        $eanNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'EAN:')]/following-sibling::dd[1]//h2");
        $ean = ($eanNode->length > 0) ? trim($eanNode->item(0)->textContent) : '';

        // ISBN
        $isbnNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'ISBN:')]/following-sibling::dd[1]//h2");
        $isbnScraped = ($isbnNode->length > 0) ? trim($isbnNode->item(0)->textContent) : '';

        // Pagine
        $pagesNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Pagine:')]/following-sibling::dd[1]");
        $pages = ($pagesNode->length > 0) ? trim($pagesNode->item(0)->textContent) : '';

        // Formato
        $formatNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Formato:')]/following-sibling::dd[1]");
        $format = ($formatNode->length > 0) ? trim($formatNode->item(0)->textContent) : '';

        // Collana
        $collanaNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Collana:')]/following-sibling::dd[1]//span[contains(@class, 'text')]");
        $collana = ($collanaNode->length > 0) ? trim($collanaNode->item(0)->textContent) : '';

        // Traduttore
        $translatorNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Traduttore')]/following-sibling::dd[1]");
        $translator = ($translatorNode->length > 0) ? trim($translatorNode->item(0)->textContent) : '';

        // Prezzo
        $priceNode = $xpath->query("//div[contains(@class, 'price-row')]//span[contains(@class, 'current-price')]");
        $price = ($priceNode->length > 0) ? trim($priceNode->item(0)->textContent) : '';

        // Descrizione
        $descriptionNode = $xpath->query("//div[contains(@class, 'description-container')]//p[contains(@class, 'more-less-text')]");
        $description = ($descriptionNode->length > 0) ? trim($descriptionNode->item(0)->textContent) : '';

        // Argomento per note
        $subjectNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Argomento:')]/following-sibling::dd[1]");
        $subject = ($subjectNode->length > 0) ? trim($subjectNode->item(0)->textContent) : '';

        // Tipologia
        $tipologiaNode = $xpath->query("//dl[contains(@class, 'product-details-container')]//dt[contains(text(), 'Tipologia:')]/following-sibling::dd[1]");
        $tipologia = ($tipologiaNode->length > 0) ? trim($tipologiaNode->item(0)->textContent) : '';

        $notesParts = [];
        if ($subject !== '') {
            $notesParts[] = 'Argomento: ' . $subject;
        }
        if ($tipologia !== '') {
            $notesParts[] = 'Tipologia: ' . $tipologia;
        }
        $notes = implode("\n", $notesParts);

        $payload = [
            'title' => $title,
            'subtitle' => $subtitle,
            'series' => $collana,
            'author' => $author,
            'authors' => $authors,
            'publisher' => $publisher,
            'isbn' => $isbnScraped ?: $isbn,
            'ean' => $ean,
            'pubDate' => $pubDate,
            'price' => $price,
            'translator' => $translator,
            'pages' => $pages,
            'format' => $format,
            'description' => $description,
            'image' => $image,
            'notes' => $notes,
            'tipologia' => $tipologia,
            'source' => $finalUrl,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $response->getBody()->write(json_encode([
                'error' => __('Impossibile generare la risposta JSON.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Scrape book cover from Feltrinelli website
     */
    private function scrapeFeltrinelliCover(string $isbn): string
    {
        // Only allow requests to whitelisted Feltrinelli domain
        $allowedBaseUrl = 'https://www.lafeltrinelli.it';
        $url = $allowedBaseUrl . '/morale-anarchica-libro-petr-alekseevic-kropotkin/e/' . rawurlencode($isbn);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, // Security: Limit redirects to prevent SSRF
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36',
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $html = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

        // Security: Validate final URL is still on whitelisted domain (SSRF prevention)
        $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?: '';
        if ($finalHost !== 'www.lafeltrinelli.it' && $finalHost !== 'lafeltrinelli.it') {
            curl_close($ch);
            return '';
        }

        curl_close($ch);

        // Se la richiesta fallisce, ritorna stringa vuota per il fallback
        if ($html === false || $html === '' || $httpCode !== 200) {
            return '';
        }

        // Parsing HTML con XPath
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $libxmlFlags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        $previousEntitySetting = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntitySetting = libxml_disable_entity_loader(true);
        }
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, $libxmlFlags);
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader((bool)$previousEntitySetting);
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        // Cerca l'immagine dentro div.cc-content-img
        $imgNode = $xpath->query("//div[contains(@class, 'cc-content-img')]//img[contains(@class, 'cc-img')]");
        if ($imgNode->length > 0) {
            $imgSrc = $imgNode->item(0)->getAttribute('src');
            // Valida che l'URL sia del dominio Feltrinelli
            if (strpos($imgSrc, 'https://www.lafeltrinelli.it/') === 0) {
                return $imgSrc;
            }
        }

        return '';
    }

    /**
     * Validate ISBN format (ISBN-10 or ISBN-13)
     */
    private function isValidIsbn(string $isbn): bool
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Check ISBN-13 format
        if (strlen($isbn) === 13) {
            if (!ctype_digit($isbn)) {
                return false;
            }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$isbn[$i] * (($i % 2) === 0 ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            return ((int)$isbn[12]) === $checkDigit;
        }

        // Check ISBN-10 format
        if (strlen($isbn) === 10) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                if (!ctype_digit($isbn[$i])) {
                    return false;
                }
                $sum += (int)$isbn[$i] * (10 - $i);
            }
            $checkChar = $isbn[9];
            $checkDigit = (11 - ($sum % 11)) % 11;
            $expectedCheck = ($checkDigit === 10) ? 'X' : (string)$checkDigit;
            return $checkChar === $expectedCheck;
        }

        return false;
    }
}
