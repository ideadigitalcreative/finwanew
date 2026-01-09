<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FileController extends Controller
{
    /**
     * Serve file untuk OCR/STT worker dengan API key authentication
     * 
     * Route: GET /api/files/{path}
     * Path parameter akan di-decode oleh Laravel route secara otomatis
     */
    public function serve(Request $request, string $path = null)
    {
        // Verify API key
        $apiKey = $request->header('X-API-Key');
        $expectedKey = config('services.ocr_worker.api_key');
        
        if (empty($expectedKey)) {
            Log::error('FileController: OCR API key not configured');
            return response()->json(['error' => 'API key not configured'], 500);
        }
        
        if ($apiKey !== $expectedKey) {
            Log::warning('FileController: Unauthorized request', [
                'received_key' => $apiKey ? substr($apiKey, 0, 10) . '...' : 'null',
                'expected_key' => substr($expectedKey, 0, 10) . '...',
                'request_uri' => $request->getRequestUri()
            ]);
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }
        
        try {
            // Jika path tidak ada di parameter, coba ambil dari query string
            if (empty($path)) {
                $path = $request->query('path');
            }
            
            if (empty($path)) {
                Log::warning('FileController: Path parameter is empty', [
                    'request_uri' => $request->getRequestUri(),
                    'query_params' => $request->query()
                ]);
                return response()->json([
                    'error' => 'Path parameter is required'
                ], 400);
            }
            
            // Decode path (handle URL encoding)
            // Laravel route parameter sudah di-decode, tapi mungkin masih ada encoding
            $decodedPath = $path;
            
            // Decode path jika masih encoded (mengandung %)
            $maxIterations = 5;
            $iteration = 0;
            while (str_contains($decodedPath, '%') && $iteration < $maxIterations) {
                $newDecoded = urldecode($decodedPath);
                if ($newDecoded === $decodedPath) {
                    break; // No more decoding needed
                }
                $decodedPath = $newDecoded;
                $iteration++;
            }
            
            // Normalize path: remove leading/trailing slashes, but keep internal slashes
            $decodedPath = trim($decodedPath, '/');
            
            Log::info('FileController: Serving file request', [
                'path_param' => $path,
                'decoded_path' => $decodedPath,
                'path_length' => strlen($decodedPath),
                'decode_iterations' => $iteration,
                'request_uri' => $request->getRequestUri(),
                'full_url' => $request->fullUrl(),
                'request_method' => $request->method()
            ]);
            
            // Security: hanya allow path yang valid (whatsapp/...)
            if (!str_starts_with($decodedPath, 'whatsapp/')) {
                Log::warning('FileController: Invalid file path (not starting with whatsapp/)', [
                    'path_param' => $path,
                    'decoded_path' => $decodedPath,
                    'request_uri' => $request->getRequestUri()
                ]);
                return response()->json([
                    'error' => 'Invalid file path',
                    'path' => $decodedPath,
                    'message' => 'Path must start with whatsapp/'
                ], 403);
            }
            
            // Use public disk (storage/app/public)
            $disk = Storage::disk('public');
            
            // Check if file exists in Storage
            $fileExists = $disk->exists($decodedPath);
            
            if (!$fileExists) {
                // Check filesystem directly (fallback)
                $fullPath = storage_path('app/public/' . $decodedPath);
                $fileExistsInFs = file_exists($fullPath) && is_file($fullPath);
                
                // List files in directory untuk debugging
                $directory = dirname($decodedPath);
                $files = [];
                try {
                    if ($disk->exists($directory) || is_dir(storage_path('app/public/' . $directory))) {
                        $files = $disk->files($directory);
                    }
                } catch (\Exception $e) {
                    Log::warning('FileController: Cannot list files in directory', [
                        'directory' => $directory,
                        'error' => $e->getMessage()
                    ]);
                }
                
                Log::warning('FileController: File not found', [
                    'requested_path' => $decodedPath,
                    'directory' => $directory,
                    'files_in_directory' => array_slice($files, 0, 10),
                    'file_count' => count($files),
                    'storage_root' => storage_path('app/public'),
                    'full_path' => $fullPath,
                    'file_exists_in_storage' => $fileExists,
                    'file_exists_in_fs' => $fileExistsInFs
                ]);
                
                // Try to serve directly from filesystem if exists
                if ($fileExistsInFs) {
                    Log::info('FileController: File exists in filesystem, serving directly', [
                        'full_path' => $fullPath,
                        'file_size' => filesize($fullPath)
                    ]);
                    $fileContent = file_get_contents($fullPath);
                    $mimeType = mime_content_type($fullPath) ?? 'application/octet-stream';
                    return response($fileContent, 200)
                        ->header('Content-Type', $mimeType)
                        ->header('Content-Disposition', 'inline')
                        ->header('Cache-Control', 'public, max-age=3600');
                }
                
                return response()->json([
                    'error' => 'File not found',
                    'path' => $decodedPath,
                    'directory' => $directory,
                    'available_files' => array_slice($files, 0, 10),
                    'full_path_checked' => $fullPath,
                    'file_exists_in_storage' => $fileExists,
                    'file_exists_in_fs' => $fileExistsInFs
                ], 404);
            }
            
            // File exists, serve it
            $fileContent = $disk->get($decodedPath);
            $mimeType = $disk->mimeType($decodedPath) ?? 'application/octet-stream';
            
            Log::info('FileController: File served successfully', [
                'path' => $decodedPath,
                'file_size' => strlen($fileContent),
                'mime_type' => $mimeType
            ]);
            
            return response($fileContent, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline')
                ->header('Cache-Control', 'public, max-age=3600');
                
        } catch (\Exception $e) {
            Log::error('FileController: Error serving file', [
                'path_param' => $path ?? null,
                'decoded_path' => $decodedPath ?? null,
                'request_uri' => $request->getRequestUri(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to serve file',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Serve file dari request URI langsung (untuk handle encoded slash %2F)
     * Route: GET /api/files?path=... atau GET /api/files/whatsapp%2F12%2F...
     */
    public function serveFromRequest(Request $request)
    {
        // Verify API key
        $apiKey = $request->header('X-API-Key');
        $expectedKey = config('services.ocr_worker.api_key', 'ocr_worker_api_key_123');
        
        if ($apiKey !== $expectedKey) {
            Log::warning('FileController: Unauthorized request (serveFromRequest)', [
                'received_key' => $apiKey ? substr($apiKey, 0, 10) . '...' : 'null',
                'expected_key' => substr($expectedKey, 0, 10) . '...',
                'request_uri' => $request->getRequestUri()
            ]);
            return response()->json([
                'error' => 'Unauthorized'
            ], 401);
        }
        
        try {
            // Ambil path dari query parameter atau dari request URI
            $path = $request->query('path');
            $requestUri = $request->getRequestUri();
            
            Log::info('FileController: serveFromRequest called', [
                'request_uri' => $requestUri,
                'path_query' => $path,
                'method' => $request->method(),
                'full_url' => $request->fullUrl()
            ]);
            
            // Jika tidak ada di query, extract dari request URI
            if (empty($path)) {
                // Extract path setelah /api/files/
                // Handle both /api/files/path dan /api/files?path=...
                if (preg_match('#/api/files/(.+?)(?:\?|$)#', $requestUri, $matches)) {
                    $path = $matches[1];
                } elseif (str_contains($requestUri, '/api/files')) {
                    // Jika request URI mengandung /api/files tapi tidak match pattern di atas
                    // Coba extract dengan cara lain
                    $parts = parse_url($requestUri);
                    if (isset($parts['path'])) {
                        $pathPart = str_replace('/api/files/', '', $parts['path']);
                        if (!empty($pathPart)) {
                            $path = $pathPart;
                        }
                    }
                }
            }
            
            if (empty($path)) {
                Log::warning('FileController: Path not found in request (serveFromRequest)', [
                    'request_uri' => $requestUri,
                    'query_params' => $request->query(),
                    'request_path' => $request->path(),
                    'request_url' => $request->url()
                ]);
                return response()->json([
                    'error' => 'Path parameter is required',
                    'request_uri' => $requestUri
                ], 400);
            }
            
            // Decode path (handle URL encoding, termasuk %2F untuk slash)
            $decodedPath = urldecode($path);
            
            // Handle multiple encoding
            $maxIterations = 5;
            $iteration = 0;
            while (str_contains($decodedPath, '%') && $iteration < $maxIterations) {
                $newDecoded = urldecode($decodedPath);
                if ($newDecoded === $decodedPath) {
                    break;
                }
                $decodedPath = $newDecoded;
                $iteration++;
            }
            
            // Normalize path
            $decodedPath = trim($decodedPath, '/');
            
            Log::info('FileController: Serving file from request (serveFromRequest)', [
                'original_path' => $path,
                'decoded_path' => $decodedPath,
                'path_length' => strlen($decodedPath),
                'decode_iterations' => $iteration,
                'request_uri' => $request->getRequestUri(),
                'full_url' => $request->fullUrl()
            ]);
            
            // Security: hanya allow path yang valid (whatsapp/...)
            if (!str_starts_with($decodedPath, 'whatsapp/')) {
                Log::warning('FileController: Invalid file path (serveFromRequest)', [
                    'path' => $path,
                    'decoded_path' => $decodedPath,
                    'request_uri' => $request->getRequestUri()
                ]);
                return response()->json([
                    'error' => 'Invalid file path',
                    'path' => $decodedPath,
                    'message' => 'Path must start with whatsapp/'
                ], 403);
            }
            
            // Use public disk
            $disk = Storage::disk('public');
            
            // Check if file exists
            $fileExists = $disk->exists($decodedPath);
            
            if (!$fileExists) {
                // Check filesystem directly
                $fullPath = storage_path('app/public/' . $decodedPath);
                $fileExistsInFs = file_exists($fullPath) && is_file($fullPath);
                
                if ($fileExistsInFs) {
                    Log::info('FileController: File exists in filesystem (serveFromRequest)', [
                        'full_path' => $fullPath,
                        'file_size' => filesize($fullPath)
                    ]);
                    $fileContent = file_get_contents($fullPath);
                    $mimeType = mime_content_type($fullPath) ?? 'application/octet-stream';
                    return response($fileContent, 200)
                        ->header('Content-Type', $mimeType)
                        ->header('Content-Disposition', 'inline')
                        ->header('Cache-Control', 'public, max-age=3600');
                }
                
                Log::warning('FileController: File not found (serveFromRequest)', [
                    'requested_path' => $decodedPath,
                    'full_path' => $fullPath,
                    'file_exists_in_storage' => $fileExists,
                    'file_exists_in_fs' => $fileExistsInFs
                ]);
                
                return response()->json([
                    'error' => 'File not found',
                    'path' => $decodedPath,
                    'full_path_checked' => $fullPath
                ], 404);
            }
            
            // File exists, serve it
            $fileContent = $disk->get($decodedPath);
            $mimeType = $disk->mimeType($decodedPath) ?? 'application/octet-stream';
            
            Log::info('FileController: File served successfully (serveFromRequest)', [
                'path' => $decodedPath,
                'file_size' => strlen($fileContent),
                'mime_type' => $mimeType
            ]);
            
            return response($fileContent, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline')
                ->header('Cache-Control', 'public, max-age=3600');
                
        } catch (\Exception $e) {
            Log::error('FileController: Error serving file (serveFromRequest)', [
                'request_uri' => $request->getRequestUri(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to serve file',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

