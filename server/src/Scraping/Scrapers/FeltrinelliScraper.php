<?php
/**
 * Feltrinelli Scraper
 * Based on ScrapingProPlugin logic
 */
class FeltrinelliScraper extends AbstractScraper
{
    public function getName(): string
    {
        return 'Feltrinelli';
    }

    public function scrape(string $isbn): ?array
    {
        $url = "https://www.lafeltrinelli.it/search?query={$isbn}";

        $html = $this->fetchHtml($url);
        if (!$html) {
            return null;
        }

        $xpath = $this->getDomXPath($html);
        if (!$xpath) {
            return null;
        }

        // Check if book exists
        $noResults = $xpath->query("//div[contains(@class, 'no-result')]");
        if ($noResults && $noResults->length > 0) {
            return null;
        }

        $data = [];

        // Title
        $data['title'] = $this->extractText(
            $xpath,
            "//h1[contains(@class, 'title')] | //div[@class='product-title']/h1 | //h1[@itemprop='name']"
        );

        // Author
        $data['author'] = $this->extractText(
            $xpath,
            "//a[contains(@class, 'author')] | //div[@class='product-author']//a | //span[@itemprop='author']"
        );

        // Publisher
        $data['publisher'] = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Editore')]/following-sibling::div | //a[@class='editor'] | //span[@itemprop='publisher']"
        );

        // Year
        $yearText = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Anno')]/following-sibling::div | //div[contains(text(), 'Data di Pubblicazione')]/following-sibling::div"
        );
        if ($yearText && preg_match('/(\d{4})/', $yearText, $matches)) {
            $data['year'] = (int)$matches[1];
        }

        // Pages
        $pagesText = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Pagine')]/following-sibling::div | //span[@class='pages']"
        );
        if ($pagesText && preg_match('/(\d+)/', $pagesText, $matches)) {
            $data['pages'] = (int)$matches[1];
        }

        // Language
        $data['language'] = $this->extractText(
            $xpath,
            "//div[contains(text(), 'Lingua')]/following-sibling::div"
        );

        // Description
        $data['description'] = $this->extractText(
            $xpath,
            "//div[@class='description'] | //div[contains(@class, 'product-description')] | //div[@itemprop='description']"
        );

        // Cover URL
        $coverNodes = $xpath->query("//img[@class='cover'] | //div[@class='product-image']//img | //img[@itemprop='image']");
        if ($coverNodes && $coverNodes->length > 0) {
            $coverUrl = $coverNodes->item(0)->getAttribute('src');
            // Handle data-src for lazy loading
            if (empty($coverUrl) || str_starts_with($coverUrl, 'data:')) {
                $coverUrl = $coverNodes->item(0)->getAttribute('data-src');
            }
            if ($coverUrl && !str_starts_with($coverUrl, 'http')) {
                $coverUrl = 'https://www.lafeltrinelli.it' . $coverUrl;
            }
            $data['cover_url'] = $coverUrl;
        }

        // Price
        $priceText = $this->extractText(
            $xpath,
            "//span[@class='price'] | //div[contains(@class, 'price')]//span | //span[@itemprop='price']"
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
