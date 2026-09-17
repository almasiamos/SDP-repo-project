<?php

use Illuminate\Support\Facades\Route;
use Qdrant\Qdrant;
use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\VectorParams;

Route::get('/test-qdrant', function () {
    // 1. Initialize the Qdrant Client using your .env credentials
    $config = new Config(env('QDRANT_HOST'));
    $config->setApiKey(env('QDRANT_API_KEY'));
    
    $transport = (new Builder())->build($config);
    $client = new Qdrant($transport);
    
    // 2. Configure a new collection named 'idr_documents'
    $createCollection = new CreateCollection();
    
    // Gemini outputs 768-dimensional vectors. We use Cosine similarity for text relevance.
    $createCollection->addVector(
        new VectorParams(768, VectorParams::DISTANCE_COSINE), 
        'content'
    );
    
    // 3. Send the creation request to Qdrant Cloud
    $response = $client->collections('idr_documents')->create($createCollection);
    
    return response()->json([
        'status' => 'success',
        'message' => 'Qdrant collection created successfully!',
        'qdrant_response' => $response
    ]);
});