<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DeweyApiController
{
    private static ?array $deweyData = null;

    private function loadDeweyData(): array
    {
        if (self::$deweyData === null) {
            $jsonPath = __DIR__ . '/../../data/dewey/dewey.json';
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
            
            self::$deweyData = $data;
        }
        
        return self::$deweyData;
    }

    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $data = $this->loadDeweyData();
            $categories = [];
            
            foreach ($data['classificazione_dewey'] as $class) {
                if ($class['type'] === 'classe_principale') {
                    $categories[] = [
                        'id' => $class['codice'],
                        'codice' => $class['codice'],
                        'nome' => $class['descrizione']
                    ];
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
            
            foreach ($data['classificazione_dewey'] as $class) {
                if ($class['type'] === 'classe_principale' && $class['codice'] === $categoryId) {
                    foreach ($class['divisioni'] as $division) {
                        $divisions[] = [
                            'id' => $division['codice'],
                            'codice' => $division['codice'],
                            'nome' => $division['descrizione']
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
            
            foreach ($data['classificazione_dewey'] as $class) {
                if ($class['type'] === 'classe_principale') {
                    foreach ($class['divisioni'] as $division) {
                        if ($division['codice'] === $divisionId) {
                            if (isset($division['sezioni'])) {
                                foreach ($division['sezioni'] as $section) {
                                    $specifics[] = [
                                        'id' => $section['codice'],
                                        'codice' => $section['codice'],
                                        'nome' => $section['descrizione']
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

    public function reseed(Request $request, Response $response): Response
    {
        // Per compatibilità con il codice esistente, ma ora non fa nulla
        // perché i dati vengono dal JSON
        $response->getBody()->write(json_encode(['success' => true, 'message' => __('I dati provengono dal file JSON, nessun seeding necessario.')], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
