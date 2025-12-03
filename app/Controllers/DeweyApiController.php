<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\I18n;

class DeweyApiController
{
    private static array $deweyDataCache = [];

    private function loadDeweyData(): array
    {
        // Cache per locale
        $locale = $this->getActiveLocale();

        if (!isset(self::$deweyDataCache[$locale])) {
            // Prima prova a caricare il nuovo formato completo (con decimali)
            $jsonFile = ($locale === 'en_US') ? 'dewey_completo_en.json' : 'dewey_completo_it.json';
            $jsonPath = __DIR__ . '/../../data/dewey/' . $jsonFile;

            // Fallback al vecchio formato se il completo non esiste
            if (!file_exists($jsonPath)) {
                $jsonFile = ($locale === 'en_US') ? 'dewey_en.json' : 'dewey.json';
                $jsonPath = __DIR__ . '/../../data/dewey/' . $jsonFile;
            }

            // Fallback finale al file italiano
            if (!file_exists($jsonPath)) {
                $jsonPath = __DIR__ . '/../../data/dewey/dewey_completo_it.json';
            }

            if (!file_exists($jsonPath)) {
                throw new \Exception('Dewey JSON file not found');
            }

            $jsonContent = file_get_contents($jsonPath);
            if ($jsonContent === false) {
                throw new \Exception('Unable to read Dewey JSON file');
            }

            $data = json_decode($jsonContent, true);
            if ($data === null) {
                throw new \Exception('Invalid JSON in Dewey file');
            }

            self::$deweyDataCache[$locale] = $data;
        }

        return self::$deweyDataCache[$locale];
    }

    /**
     * Determina se i dati caricati sono nel nuovo formato (code, name, level, children)
     */
    private function isNewFormat(array $data): bool
    {
        // Il nuovo formato è un array diretto, non un oggetto con chiave
        return !empty($data) && isset($data[0]['code']) && isset($data[0]['level']) && isset($data[0]['children']);
    }

    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $data = $this->loadDeweyData();
            $categories = [];

            if ($this->isNewFormat($data)) {
                // Nuovo formato: estrai tutti i nodi con level = 1 (classi principali)
                foreach ($data as $item) {
                    if ($item['level'] === 1) {
                        $categories[] = [
                            'id' => $item['code'],
                            'codice' => $item['code'],
                            'nome' => $item['name']
                        ];
                    }
                }
            } else {
                // Vecchio formato: mantieni la logica esistente
                $deweyKey = isset($data['classificazione_dewey']) ? 'classificazione_dewey' : 'dewey_classification';
                $typeKey = isset($data[$deweyKey][0]['type']) && $data[$deweyKey][0]['type'] === 'classe_principale' ? 'classe_principale' : 'main_class';
                $descKey = isset($data[$deweyKey][0]['descrizione']) ? 'descrizione' : 'description';
                $codeKey = isset($data[$deweyKey][0]['codice']) ? 'codice' : 'code';

                foreach ($data[$deweyKey] as $class) {
                    if ($class['type'] === $typeKey) {
                        $categories[] = [
                            'id' => $class[$codeKey],
                            'codice' => $class[$codeKey],
                            'nome' => $class[$descKey]
                        ];
                    }
                }
            }

            $response->getBody()->write(json_encode($categories, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Log detailed error internally but don't expose to client
            error_log("Dewey API categories error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nel recupero delle categorie.')], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function getDivisions(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $categoryId = $params['category_id'] ?? '';

            if (empty($categoryId)) {
                $response->getBody()->write(json_encode(['error' => __('Parametro category_id obbligatorio.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $data = $this->loadDeweyData();
            $divisions = [];

            // Supporta sia la chiave italiana che quella inglese
            $deweyKey = isset($data['classificazione_dewey']) ? 'classificazione_dewey' : 'dewey_classification';
            $typeKey = isset($data[$deweyKey][0]['type']) && $data[$deweyKey][0]['type'] === 'classe_principale' ? 'classe_principale' : 'main_class';
            $divisionsKey = isset($data[$deweyKey][0]['divisioni']) ? 'divisioni' : 'divisions';
            $divisionTypeKey = 'divisione'; // Italian
            if (isset($data[$deweyKey][0][$divisionsKey][0]['type']) && $data[$deweyKey][0][$divisionsKey][0]['type'] === 'division') {
                $divisionTypeKey = 'division'; // English
            }
            $descKey = isset($data[$deweyKey][0]['descrizione']) ? 'descrizione' : 'description';
            $codeKey = isset($data[$deweyKey][0]['codice']) ? 'codice' : 'code';

            foreach ($data[$deweyKey] as $class) {
                if ($class['type'] === $typeKey && $class[$codeKey] === $categoryId) {
                    foreach ($class[$divisionsKey] as $division) {
                        $divisions[] = [
                            'id' => $division[$codeKey],
                            'codice' => $division[$codeKey],
                            'nome' => $division[$descKey]
                        ];
                    }
                    break;
                }
            }

            $response->getBody()->write(json_encode($divisions, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Log detailed error internally but don't expose to client
            error_log("Dewey API divisions error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nel recupero delle divisioni.')], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function getSpecifics(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $divisionId = $params['division_id'] ?? '';

            if (empty($divisionId)) {
                $response->getBody()->write(json_encode(['error' => __('Parametro division_id obbligatorio.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $data = $this->loadDeweyData();
            $specifics = [];

            // Supporta sia la chiave italiana che quella inglese
            $deweyKey = isset($data['classificazione_dewey']) ? 'classificazione_dewey' : 'dewey_classification';
            $typeKey = isset($data[$deweyKey][0]['type']) && $data[$deweyKey][0]['type'] === 'classe_principale' ? 'classe_principale' : 'main_class';
            $divisionsKey = isset($data[$deweyKey][0]['divisioni']) ? 'divisioni' : 'divisions';
            $sectionsKey = 'sezioni';
            if (isset($data[$deweyKey][0][$divisionsKey][0]['sections'])) {
                $sectionsKey = 'sections';
            }
            $descKey = isset($data[$deweyKey][0]['descrizione']) ? 'descrizione' : 'description';
            $codeKey = isset($data[$deweyKey][0]['codice']) ? 'codice' : 'code';

            foreach ($data[$deweyKey] as $class) {
                if ($class['type'] === $typeKey) {
                    foreach ($class[$divisionsKey] as $division) {
                        if ($division[$codeKey] === $divisionId) {
                            if (isset($division[$sectionsKey])) {
                                foreach ($division[$sectionsKey] as $section) {
                                    $specifics[] = [
                                        'id' => $section[$codeKey],
                                        'codice' => $section[$codeKey],
                                        'nome' => $section[$descKey]
                                    ];
                                }
                            }
                            break 2;
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($specifics, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Log detailed error internally but don't expose to client
            error_log("Dewey API specifics error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nel recupero delle specifiche.')], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Nuovo endpoint universale: ottieni i figli di un nodo Dewey (supporta livelli infiniti)
     * GET /api/dewey/children?parent_code=599
     * Risposta: [{"code": "599.2", "name": "Marsupiali", "level": 4, "has_children": false}, ...]
     */
    public function getChildren(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $parentCode = $params['parent_code'] ?? null;

            $data = $this->loadDeweyData();
            $children = [];

            if ($this->isNewFormat($data)) {
                if ($parentCode === null) {
                    // Se non c'è parent_code, ritorna le classi principali (level 1)
                    foreach ($data as $item) {
                        if ($item['level'] === 1) {
                            $children[] = [
                                'code' => $item['code'],
                                'name' => $item['name'],
                                'level' => $item['level'],
                                'has_children' => !empty($item['children'])
                            ];
                        }
                    }
                } else {
                    // Cerca il nodo parent e ritorna i suoi figli
                    $parent = $this->findNodeByCode($data, $parentCode);
                    if ($parent === null) {
                        $response->getBody()->write(json_encode(['error' => __('Codice parent non trovato.')], JSON_UNESCAPED_UNICODE));
                        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                    }
                    if (isset($parent['children'])) {
                        foreach ($parent['children'] as $child) {
                            $children[] = [
                                'code' => $child['code'],
                                'name' => $child['name'],
                                'level' => $child['level'],
                                'has_children' => !empty($child['children'])
                            ];
                        }
                    }
                }
            } else {
                // Per il vecchio formato, fai fallback ai vecchi endpoint
                $response->getBody()->write(json_encode(['error' => __('Usa gli endpoint specifici per il formato legacy.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $response->getBody()->write(json_encode($children, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("Dewey API children error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nel recupero dei figli.')], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Ricerca ricorsiva di un nodo per codice
     */
    private function findNodeByCode(array $data, string $code): ?array
    {
        foreach ($data as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
            if (!empty($item['children'])) {
                $found = $this->findNodeByCode($item['children'], $code);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Cerca e ritorna un nodo Dewey con tutte le sue informazioni
     * GET /api/dewey/search?code=599.9
     */
    public function search(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $code = $params['code'] ?? '';

            if (empty($code)) {
                $response->getBody()->write(json_encode(['error' => __('Parametro code obbligatorio.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $data = $this->loadDeweyData();
            $result = null;

            if ($this->isNewFormat($data)) {
                $node = $this->findNodeByCode($data, $code);
                if ($node) {
                    $result = [
                        'code' => $node['code'],
                        'name' => $node['name'],
                        'level' => $node['level'],
                        'has_children' => !empty($node['children'])
                    ];
                }
            } else {
                // Per il vecchio formato, fai fallback ai vecchi endpoint
                $response->getBody()->write(json_encode(['error' => __('Usa gli endpoint specifici per il formato legacy.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if ($result) {
                $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
            } else {
                $response->getBody()->write(json_encode(['error' => __('Codice Dewey non trovato.')], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("Dewey API search error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['error' => __('Errore nella ricerca.')], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function reseed(Request $request, Response $response): Response
    {
        // CSRF validated by CsrfMiddleware
        // Per compatibilità con il codice esistente, ma ora non fa nulla
        // perché i dati vengono dal JSON
        $response->getBody()->write(json_encode(['success' => true, 'message' => __('I dati provengono dal file JSON, nessun seeding necessario.')], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Determina la lingua attiva tenendo conto di sessione, I18n e fallback.
     */
    private function getActiveLocale(): string
    {
        // Session override (es. utente che cambia lingua dal frontend)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['locale'])) {
            $sessionLocale = I18n::normalizeLocaleCode((string) $_SESSION['locale']);
            if (I18n::isValidLocaleCode($sessionLocale)) {
                return $sessionLocale;
            }
        }

        // Usa il locale corrente impostato da I18n (che legge APP_LOCALE / languages)
        $locale = I18n::getLocale();
        if (!I18n::isValidLocaleCode($locale)) {
            $locale = I18n::getInstallationLocale();
        }

        // Fallback finale alla lingua italiana per sicurezza
        if (!I18n::isValidLocaleCode($locale)) {
            return 'it_IT';
        }

        return I18n::normalizeLocaleCode($locale);
    }
}
