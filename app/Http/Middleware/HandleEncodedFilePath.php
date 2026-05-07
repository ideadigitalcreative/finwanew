<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk handle encoded slash dalam file path
 *
 * Ketika URL memiliki encoded slash (%2F), Laravel route tidak bisa match.
 * Middleware ini akan decode URL sebelum routing.
 */
class HandleEncodedFilePath
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this is a file serving request dengan encoded slash
        $path = $request->path();

        if (str_starts_with($path, 'api/files/') && str_contains($request->getRequestUri(), '%2F')) {
            // Extract path setelah /api/files/
            $requestUri = $request->getRequestUri();
            if (preg_match('#/api/files/(.+)$#', $requestUri, $matches)) {
                $encodedPath = $matches[1];

                // Decode path
                $decodedPath = urldecode($encodedPath);

                // Handle multiple encoding
                while (str_contains($decodedPath, '%')) {
                    $newDecoded = urldecode($decodedPath);
                    if ($newDecoded === $decodedPath) {
                        break;
                    }
                    $decodedPath = $newDecoded;
                }

                // Modify request untuk menggunakan decoded path
                // Kita akan redirect internal ke route dengan decoded path
                $newUri = '/api/files/'.$decodedPath;
                $queryString = $request->getQueryString();
                if ($queryString) {
                    $newUri .= '?'.$queryString;
                }

                // Create new request dengan decoded path
                $request->server->set('REQUEST_URI', $newUri);
                $request->server->set('PATH_INFO', $newUri);
            }
        }

        return $next($request);
    }
}
