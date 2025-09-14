<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GatewayController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Gateway health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'api-gateway',
        'timestamp' => now()->toISOString()
    ]);
});

// Service health check
Route::get('/services/health', [GatewayController::class, 'checkServicesHealth']);

// Queue management routes
Route::prefix('queue')->group(function () {
    Route::get('/status', [GatewayController::class, 'getQueueStatus']);
    Route::get('/requests', [GatewayController::class, 'getQueuedRequests']);
    Route::get('/dead-letter-requests', [GatewayController::class, 'getDeadLetterRequests']);
    Route::post('/process', [GatewayController::class, 'processQueuedRequests']);
    Route::post('/retry', [GatewayController::class, 'retrySpecificRequest']);
    Route::post('/purge', [GatewayController::class, 'purgeQueue']);
    Route::get('/metrics', [GatewayController::class, 'getQueueMetrics']);
    Route::post('/metrics/reset', [GatewayController::class, 'resetQueueMetrics']);
    Route::get('/health', [GatewayController::class, 'getQueueHealth']);
});

// Service-specific health status
Route::get('/services/{serviceName}/health', [GatewayController::class, 'getServiceHealthStatus']);

// Courses & Classes Service Routes
Route::prefix('courses')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardToService']);
    Route::post('/', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}', [GatewayController::class, 'forwardToService']);
});

Route::prefix('classes')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardToService']);
    Route::post('/', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}', [GatewayController::class, 'forwardToService']);

    // Class trainee management routes
    Route::get('/{id}/trainees', [GatewayController::class, 'forwardToService']);
    Route::post('/{id}/trainees', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}/trainees/{trainee}', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}/available-trainees', [GatewayController::class, 'forwardToService']);
});

// Trainees Service Routes
Route::prefix('trainees')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardToService']);
    Route::post('/', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}', [GatewayController::class, 'forwardToService']);

    // Additional trainee routes
    Route::post('/{id}/enroll', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}/unenroll/{class}', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}/classes', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}/results', [GatewayController::class, 'forwardToService']);
});

// Results Routes
Route::prefix('results')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardToService']);
    Route::post('/', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}', [GatewayController::class, 'forwardToService']);

    Route::get('/trainee/{id}', [GatewayController::class, 'forwardToService']);
    Route::get('/exam/{id}', [GatewayController::class, 'forwardToService']);
    Route::post('/bulk', [GatewayController::class, 'forwardToService']);
});

// Exams Service Routes
Route::prefix('exams')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardToService']);
    Route::post('/', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}', [GatewayController::class, 'forwardToService']);

    Route::get('/{id}/trainees', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}/available-trainees', [GatewayController::class, 'forwardToService']);
    Route::post('/{id}/trainees', [GatewayController::class, 'forwardToService']);
    Route::delete('/{id}/trainees', [GatewayController::class, 'forwardToService']);
    Route::put('/{id}/trainees/{trainee}/result', [GatewayController::class, 'forwardToService']);
    Route::get('/{id}/results', [GatewayController::class, 'forwardToService']);
    Route::post('/{id}/trainees/bulk', [GatewayController::class, 'forwardToService']);
});

// Class-based exam routes
Route::get('/classes/{id}/exams', [GatewayController::class, 'forwardToService']);



// Catch-all route for unmatched API paths
Route::any('{any}', function () {
    return response()->json([
        'error' => 'API endpoint not found',
        'message' => 'The requested API endpoint does not exist',
        'supported_endpoints' => [
            'courses/*',
            'classes/*',
            'trainees/*',
            'results/*',
            'exams/*',
            'health',
            'services/health',
            'services/{service}/health',
            'queue/status',
            'queue/requests',
            'queue/dead-letter-requests',
            'queue/process',
            'queue/retry',
            'queue/purge',
            'queue/metrics',
            'queue/metrics/reset',
            'queue/health'
        ],
        'documentation' => 'Please use one of the supported service endpoints'
    ], 404);
})->where('any', '.*');
