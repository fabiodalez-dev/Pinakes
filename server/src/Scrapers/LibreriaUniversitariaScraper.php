<?php
/**
 * Libreria Universitaria Scraper
 * Based on ScrapingProPlugin logic
 */
class LibreriaUniversitariaScraper extends AbstractScraper
{
    public function getName(): string
    {
        return 'LibreriaUniversitaria';
    }

    public function scrape(string $isbn): ?array
    {
        $url = "https://www.libreriauniversitaria.it/libri-search/{$isbn}.htm";

        $html = $this->fetchHtml($url);
        if (!$html) {
            return null;
        }

        $xpath = $this->getDomXPath($html);
        if (!$xpath) {
            return null;
        }

        // Check if book exists (no results page)
        $noResults = $xpath->query("//div[contains(@class, 'no-results')]");
        if ($noResults && $noResults->length > 0) {
            return null;
        }

        $data = [];

        // Title
        $data['title'] = $this->extractText(
            $xpath,
            "//h1[@itemprop='name'] | //div[contains(@class, 'title')]/h1 | //h1[@class='prodotto-title']"
        );

        // Author
        $data['author'] = $this->extractText(
            $xpath,
            "//a[@itemprop='author'] | //span[@itemprop='author'] | //div[contains(@class, 'autore')]/a"
        );

        // Publisher
        $data['publisher'] = $this->extractText(
            $xpath,
            "//a[@itemprop='publisher'] | //span[@itemprop='publisher'] | //div[contains(text(), 'Editore')]/following-sibling::div"
        );

        // Year
        $yearText = $this->extractText(
            $xpath,
            "//span[@itemprop='datePublished'] | //div[contains(text(), 'Anno')]/following-sibling::div | //time[@itemprop='datePublished']"
        );
        if ($yearText && preg_match('/(\d{4})/', $yearText, $matches)) {
            $data['year'] = (int)$matches[1];
        }

        // Pages
        $pagesText = $this->extractText(
            $xpath,
            "//span[@itemprop='numberOfPages'] | //div[contains(text(), 'Pagine')]/following-sibling::div"
        );
        if ($pagesText && preg_match('/(\d+)/', $pagesText, $matches)) {
            $data['pages'] = (int)$matches[1];
        }

        // Language
        $data['language'] = $this->extractText(
            $xpath,
            "//span[@itemprop='inLanguage'] | //div[contains(text(), 'Lingua')]/following-sibling::div"
        );

        // Description
        $data['description'] = $this->extractText(
            $xpath,
            "//div[@itemprop='description'] | //div[contains(@class, 'descrizione')] | //div[@id='descrizione']"
        );

        // Cover URL
        $coverNodes = $xpath->query("//img[@itemprop='image'] | //img[contains(@class, 'cover')] | //div[@class='prodotto-img']//img");
        if ($coverNodes && $coverNodes->length > 0) {
            $coverUrl = $coverNodes->item(0)->getAttribute('src');
            if ($coverUrl && !str_starts_with($coverUrl, 'http')) {
                $coverUrl = 'https://www.libreriauniversitaria.it' . $coverUrl;
            }
            $data['cover_url'] = $coverUrl;
        }

        // Price
        $priceText = $this->extractText(
            $xpath,
            "//span[@itemprop='price'] | //span[contains(@class, 'prezzo')] | //div[@class='price']"
        );
        if ($priceText && preg_match('/(\d+[,.]?\d*)/', $priceText, $matches)) {
            $data['price'] = str_replace(',', '.', $matches[1]);
        }

        $data['isbn'] = $isbn;

        // Check if we got at least title
        if (empty($data['title'])) {
            return null;
        }

        return $this->normalizeBookData($data);
    }
}
