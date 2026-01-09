<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to redirect all requests to the canonical domain
 * This ensures www -> non-www and http -> https redirects
 * 
 * Important for SEO: Prevents duplicate content issues
 */
class ForceCanonicalDomain
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply in production
        if (app()->environment('production')) {
            $host = $request->getHost();
            $scheme = $request->getScheme();
            
            // Get the expected domain from APP_URL
            $appUrl = config('app.url');
            $parsedUrl = parse_url($appUrl);
            $expectedHost = $parsedUrl['host'] ?? 'finwa.web.id';
            $expectedScheme = $parsedUrl['scheme'] ?? 'https';
            
            // Check if we need to redirect
            $needsRedirect = false;
            
            // Redirect www to non-www
            if (str_starts_with($host, 'www.')) {
                $needsRedirect = true;
            }
            
            // Redirect http to https (if expected scheme is https)
            if ($expectedScheme === 'https' && $scheme === 'http') {
                $needsRedirect = true;
            }
            
            if ($needsRedirect) {
                // Build the canonical URL
                $canonicalUrl = $expectedScheme . '://' . $expectedHost . $request->getRequestUri();
                
                return redirect()->away($canonicalUrl, 301);
            }
        }
        
        $response = $next($request);

        // Note: We do not add the Link header here because we already have a <link rel="canonical"> 
        // tag in the HTML head (app.blade.php). Google advises against using both methods 
        // simultaneously to avoid potential conflicts (e.g., mismatching URLs).

        
        return $response;
    }
}
