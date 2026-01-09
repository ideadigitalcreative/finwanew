<?php

use App\Http\Middleware\ForceCanonicalDomain;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Add canonical domain redirect as prepended (runs first)
        $middleware->web(prepend: [
            ForceCanonicalDomain::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Apply tenant access middleware to authenticated routes only
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantAccess::class,
            'superadmin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'subscription' => \App\Http\Middleware\CheckSubscription::class,
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle 404 untuk file serving dengan encoded slash
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            // Check if this is a file serving request dengan encoded slash
            $requestUri = $request->getRequestUri();
            $path = $request->path();
            
            // Log untuk debugging - termasuk semua 404 untuk debugging route issues
            \Illuminate\Support\Facades\Log::info('404 Exception Handler: Checking request', [
                'request_uri' => $requestUri,
                'path' => $path,
                'method' => $request->method(),
                'contains_encoded_slash' => str_contains($requestUri, '%2F'),
                'is_files_request' => str_starts_with($path, 'api/files') || str_starts_with($requestUri, '/api/files/'),
                'is_superadmin_whatsapp' => $path === 'superadmin/whatsapp' && $request->method() === 'POST',
                'user_id' => $request->user()?->id,
                'is_super_admin' => $request->user()?->is_super_admin ?? false,
            ]);
            
            // Check if this is a file serving request dengan encoded slash
            if ((str_starts_with($path, 'api/files') || str_starts_with($requestUri, '/api/files/')) && str_contains($requestUri, '%2F')) {
                \Illuminate\Support\Facades\Log::info('404 Exception Handler: Handling file request with encoded slash', [
                    'request_uri' => $requestUri
                ]);
                
                try {
                    $fileController = app(\App\Http\Controllers\Api\FileController::class);
                    return $fileController->serveFromRequest($request);
                } catch (\Exception $ex) {
                    \Illuminate\Support\Facades\Log::error('404 Exception Handler: Error serving file', [
                        'error' => $ex->getMessage(),
                        'trace' => $ex->getTraceAsString()
                    ]);
                    return null; // Let Laravel handle error
                }
            }
            return null; // Let Laravel handle other 404s
        });
    })->create();
