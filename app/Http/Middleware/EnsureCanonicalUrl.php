<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanonicalUrl
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Define the canonical host
        $canonicalHost = 'finwa.web.id';
        $host = $request->getHost();
        $scheme = $request->getScheme();

        // Logic 1: Redirect WWW or HTTP to Non-WWW HTTPS
        // Only valid if APP_ENV is production to avoid loop in localhost
        if (config('app.env') === 'production') {
            if ($host !== $canonicalHost || $scheme !== 'https') {
                // If Host starts with www. or scheme is http
                if (str_starts_with($host, 'www.') || $scheme === 'http') {
                    $newUrl = 'https://'.$canonicalHost.$request->getRequestUri();

                    return redirect()->to($newUrl, 301);
                }
            }
        }

        $response = $next($request);

        // Logic 2: Add Link Canonical Header
        // This helps search engines identifying the correct URL even if they don't parse the Body HTML
        if (method_exists($response, 'header')) {
            $canonicalUrl = 'https://'.$canonicalHost.$request->getPathInfo();
            // Preserve query string if needed, currently path only for pure canonical
            if ($request->getQueryString()) {
                $canonicalUrl .= '?'.$request->getQueryString();
            }

            $response->header('Link', '<'.$canonicalUrl.'>; rel="canonical"');
        }

        return $response;
    }
}
