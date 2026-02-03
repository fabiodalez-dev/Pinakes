<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Helper class for handling entity merge operations
 *
 * Reduces code duplication between author and publisher merge endpoints
 */
class MergeHelper
{
    /**
     * Handle a merge request for any entity type
     *
     * @param Request $request The HTTP request
     * @param Response $response The HTTP response
     * @param \mysqli $db Database connection
     * @param string $entityType 'autori' or 'editori'
     * @return Response
     */
    public static function handleMergeRequest(
        Request $request,
        Response $response,
        \mysqli $db,
        string $entityType
    ): Response {
        // Parse request body
        $data = $request->getParsedBody();
        if (!$data) {
            $rawBody = (string) $request->getBody();
            if ($rawBody !== '') {
                $data = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => __('Formato JSON non valido')
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            } else {
                $data = [];
            }
        }

        // Deduplicate and normalize IDs to integers
        $ids = array_values(array_unique(array_map('intval', $data['ids'] ?? [])));
        $requestedPrimaryId = isset($data['primary_id']) ? (int)$data['primary_id'] : null;
        $newName = isset($data['new_name']) ? trim($data['new_name']) : '';

        // Get entity-specific labels
        $labels = self::getLabels($entityType);

        // Validate minimum IDs
        if (count($ids) < 2) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $labels['min_error']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate primary_id is in the ids array
        if ($requestedPrimaryId !== null && !in_array($requestedPrimaryId, $ids, true)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $labels['primary_error']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Get the appropriate repository
            $repo = self::getRepository($db, $entityType);
            $mergeMethod = $entityType === 'autori' ? 'mergeAuthors' : 'mergePublishers';
            $primaryId = $repo->$mergeMethod($ids, $requestedPrimaryId);

            if ($primaryId) {
                // Rename if requested
                if ($newName !== '') {
                    $current = $repo->getById($primaryId);
                    if ($current !== null) {
                        $repo->update($primaryId, array_merge($current, ['nome' => $newName]));
                    }
                }

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => $labels['success'],
                    'primary_id' => $primaryId
                ]));
            } else {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $labels['merge_error']
                ]));
            }
        } catch (\Throwable $e) {
            error_log("[API] {$labels['log_prefix']} merge error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $labels['unexpected_error']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get the appropriate repository for the entity type
     */
    private static function getRepository(\mysqli $db, string $entityType): object
    {
        return match ($entityType) {
            'autori' => new \App\Models\AuthorRepository($db),
            'editori' => new \App\Models\PublisherRepository($db),
            default => throw new \InvalidArgumentException("Unknown entity type: $entityType")
        };
    }

    /**
     * Get localized labels for the entity type
     */
    private static function getLabels(string $entityType): array
    {
        return match ($entityType) {
            'autori' => [
                'min_error' => __('Seleziona almeno 2 autori da unire'),
                'primary_error' => __('L\'ID primario deve essere presente nella lista degli autori da unire'),
                'success' => __('Autori uniti con successo'),
                'merge_error' => __('Errore durante l\'unione degli autori'),
                'unexpected_error' => __('Errore imprevisto durante l\'unione degli autori'),
                'log_prefix' => 'Author'
            ],
            'editori' => [
                'min_error' => __('Seleziona almeno 2 editori da unire'),
                'primary_error' => __('L\'ID primario deve essere presente nella lista degli editori da unire'),
                'success' => __('Editori uniti con successo'),
                'merge_error' => __('Errore durante l\'unione degli editori'),
                'unexpected_error' => __('Errore imprevisto durante l\'unione degli editori'),
                'log_prefix' => 'Publisher'
            ],
            default => throw new \InvalidArgumentException("Unknown entity type: $entityType")
        };
    }
}
