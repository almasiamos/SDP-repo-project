<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\ProcessDocumentEmbedding;
use MongoDB\BSON\ObjectId;

class DocumentController extends Controller
{
    /**
     * POST /api/documents/upload
     */
    public function upload(Request $request)
    {
        // 1. Validate the incoming request payload
        $request->validate([
            'file' => 'required|file|mimes:pdf',
            'title' => 'required|string',
            'author' => 'required|string',
            'uploaderId' => 'required|string',
        ]);

        // 2. Store the uploaded file in storage/app/documents
        $path = $request->file('file')->store('documents');

        // 3. Create the metadata record in MongoDB
        $document = [
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'uploaderId' => $request->input('uploaderId'),
            'filePath' => $path,
            'processingStatus' => 'PENDING',
            'chunksProcessed' => 0,
            'created_at' => now()->toDateTimeString(),
        ];

        $artifactId = DB::connection('mongodb')->table('documents')->insertGetId($document);
        $artifactIdString = (string) $artifactId;

        // 4. Dispatch the embedding job to the queue
        ProcessDocumentEmbedding::dispatch($artifactIdString, $path);

        // 5. Return Success - 202 Accepted
        return response()->json([
            'status' => 'success',
            'message' => 'File uploaded and queued for processing.',
            'data' => [
                'artifactId' => $artifactIdString,
                'processingStatus' => 'PENDING'
            ]
        ], 202);
    }

    /**
     * GET /api/documents/{artifactId}/status
     */
    public function status($artifactId)
    {
        try {
            // Fetch the document from MongoDB
            $document = DB::connection('mongodb')->collection('documents')
                ->where('_id', new ObjectId($artifactId))
                ->first();

            if (!$document) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Document not found'
                ], 404);
            }

            // Return Success - 200 OK
            return response()->json([
                'status' => 'success',
                'data' => [
                    'artifactId' => (string) $document['_id'],
                    'processingStatus' => $document['processingStatus'],
                    'chunksProcessed' => $document['chunksProcessed'] ?? 0
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid ID format or database error.'
            ], 400);
        }
    }
}