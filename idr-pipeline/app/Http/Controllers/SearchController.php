<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Gemini;
use Qdrant\Qdrant;
use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Models\Request\SearchRequest;

class SearchController extends Controller
{
    /**
     * GET /api/search
     */
    public function index(Request $request)
    {
        // 1. Validate the search query
        $request->validate([
            'q' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:20'
        ]);

        $queryText = $request->input('q');
        $limit = $request->input('limit', 5);

        try {
            // 2. Embed the user's query using Gemini
            $gemini = Gemini::client(env('GEMINI_API_KEY'));
            $embeddingResponse = $gemini->embeddings()->embedContent($queryText);
            $queryVector = $embeddingResponse->embedding->values;

            // 3. Search Qdrant for the nearest vector chunks
            $qdrantConfig = new Config(env('QDRANT_HOST'));
            $qdrantConfig->setApiKey(env('QDRANT_API_KEY'));
            $qdrant = new Qdrant((new Builder())->build($qdrantConfig));

            $searchRequest = (new SearchRequest($queryVector))
                ->setLimit($limit)
                ->setWithPayload(true); // Ensures we get the raw text and artifactId back
                
            $searchResponse = $qdrant->collections('idr_documents')->points()->search($searchRequest);

            // 4. Map Qdrant results to their parent MongoDB documents
            $qdrantResults = $searchResponse['result'] ?? [];
            
            if (empty($qdrantResults)) {
                return response()->json([
                    'status' => 'success',
                    'query' => $queryText,
                    'results' => []
                ]);
            }

            $documentSnippets = [];
            $documentScores = [];
            $objectIds = [];

            // Group snippets and keep the highest relevance score per document
            foreach ($qdrantResults as $point) {
                $payload = $point['payload'];
                $artId = $payload['artifactId'];
                
                $documentSnippets[$artId][] = $payload['text'];
                
                if (!isset($documentScores[$artId]) || $point['score'] > $documentScores[$artId]) {
                    $documentScores[$artId] = $point['score'];
                }

                $objectIds[] = new ObjectId($artId);
            }

            // 5. Fetch Document Metadata from MongoDB
            $documents = DB::connection('mongodb')->collection('documents')
                ->whereIn('_id', array_unique($objectIds))
                ->get()
                ->keyBy(function($item) { 
                    return (string) $item['_id']; 
                });

            // 6. Format the final output to match the API contract
            $finalResults = [];
            foreach ($documentScores as $artId => $score) {
                if (isset($documents[$artId])) {
                    $finalResults[] = [
                        'artifactId' => $artId,
                        'title' => $documents[$artId]['title'] ?? 'Unknown Title',
                        'relevanceScore' => round($score, 4),
                        'relevantSnippets' => $documentSnippets[$artId]
                    ];
                }
            }

            // Sort by relevance score descending
            usort($finalResults, fn($a, $b) => $b['relevanceScore'] <=> $a['relevanceScore']);

            return response()->json([
                'status' => 'success',
                'query' => $queryText,
                'results' => $finalResults
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }
}