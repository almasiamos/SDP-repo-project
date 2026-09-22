<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Gemini;
use Qdrant\Qdrant;
use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\VectorStruct;

class ProcessDocumentEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $artifactId;
    protected $filePath;

    public function __construct($artifactId, $filePath)
    {
        $this->artifactId = $artifactId;
        $this->filePath = $filePath;
    }

    public function handle(): void
    {
        // 1. Update MongoDB status to PROCESSING
        DB::connection('mongodb')->table('documents')
            ->where('_id', $this->artifactId)
            ->update(['processingStatus' => 'PROCESSING']);

        try {
            // 2. Extract raw text from the PDF
            $fullPath = \Illuminate\Support\Facades\Storage::path($this->filePath);
            $parser = new Parser();
            $pdf = $parser->parseFile($fullPath);
            $text = $pdf->getText();

            // 3. Split text into semantic chunks
            $chunks = $this->chunkText($text, 500, 100);

            // 4. Initialize API Clients
            $gemini = \Gemini::client(env('GEMINI_API_KEY'));
            
            $qdrantConfig = new Config(env('QDRANT_HOST'));
            $qdrantConfig->setApiKey(env('QDRANT_API_KEY'));
            $qdrant = new Qdrant((new Builder())->build($qdrantConfig));

            // 5. Generate Embeddings and Structure Qdrant Points
            $points = new PointsStruct();
            
            // app/Jobs/ProcessDocumentEmbedding.php (around line 61)

            foreach ($chunks as $index => $chunk) {
                // 1. Generate the embedding
                $response = $gemini->embeddingModel('gemini-embedding-001') 
                    ->embedContent($chunk);

                $vector = $response->embedding->values;

                // 2. Create the point using VectorStruct and include 'text' in the payload
                $points->addPoint(
                    new PointStruct(
                        (string) Str::uuid(),
                        new VectorStruct($vector), 
                        [
                            'artifactId' => $this->artifactId,
                            'chunkIndex' => $index,
                            'text' => $chunk // Required by SearchController for relevantSnippets
                        ]
                    )
                );
            }

            // 6. Upsert the batch of vectors to Qdrant
            $qdrant->collections('idr_documents')->points()->upsert($points);

            // 7. Update MongoDB status to EMBEDDED upon success
            DB::connection('mongodb')->table('documents')
                ->where('_id', $this->artifactId)
                ->update([
                    'processingStatus' => 'EMBEDDED',
                    'chunksProcessed' => count($chunks)
                ]);

        } catch (\Exception $e) {
            Log::error("Document processing failed for ID {$this->artifactId}: " . $e->getMessage());
            
            DB::connection('mongodb')->table('documents')
                ->where('_id', $this->artifactId)
                ->update(['processingStatus' => 'FAILED']);
                
            throw $e;
        }
    }

    private function chunkText(string $text, int $chunkSize = 500, int $overlap = 100): array
    {
        $words = explode(' ', preg_replace('/\s+/', ' ', trim($text)));
        $chunks = [];
        $i = 0;
        $totalWords = count($words);

        while ($i < $totalWords) {
            $chunk = array_slice($words, $i, $chunkSize);
            $chunks[] = implode(' ', $chunk);
            $i += ($chunkSize - $overlap);
        }

        return $chunks;
    }
}