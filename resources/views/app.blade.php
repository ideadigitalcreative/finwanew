<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="google-site-verification" content="HW2rQyxeNMjG46R-Z_4X8wsJe-uQZNIJ9am2DxFVjHs" />
        
        {{-- Critical: Preload font untuk mengurangi FCP --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="dns-prefetch" href="https://fonts.bunny.net">
        <link rel="preload" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet"></noscript>
        
        {{-- Preload critical images --}}
        <link rel="preload" href="/logo.png" as="image" fetchpriority="high">
        
        {{-- SEO Meta Tags - Optimized for "catat keuangan via whatsapp" --}}
        <meta name="description" content="Catat keuangan via WhatsApp dengan mudah! FinWa adalah aplikasi pencatatan keuangan otomatis dari WA. Cukup chat pengeluaran & pemasukan, langsung tercatat. Coba Gratis!">
        <meta name="keywords" content="catat keuangan via whatsapp, catat keuangan dari wa, aplikasi catat keuangan whatsapp, pencatatan keuangan lewat wa, bot keuangan whatsapp, catat pengeluaran via wa, aplikasi keuangan di whatsapp, finwa, catatan keuangan otomatis whatsapp, buku kas whatsapp">
        <meta name="author" content="FinWa - Catat Keuangan via WhatsApp">
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
        <meta name="language" content="Indonesian">
        @php
            // Generate canonical URL based on APP_URL config to ensure consistency
            // This handles scheme (http/https) and domain (www/non-www) enforcement
            $appUrl = config('app.url');
            $parsedUrl = parse_url($appUrl);
            $scheme = $parsedUrl['scheme'] ?? 'https';
            $host = $parsedUrl['host'] ?? request()->getHost();
            $path = request()->getPathInfo();
            $canonicalUrl = $scheme . '://' . $host . $path;
        @endphp
        <link rel="canonical" href="{{ $canonicalUrl }}">
        
        {{-- Open Graph / Facebook --}}
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:title" content="Catat Keuangan via WhatsApp - FinWa | Pencatatan Otomatis dari WA">
        <meta property="og:description" content="Catat keuangan via WhatsApp dengan mudah! Cukup chat pengeluaran & pemasukan di WA, FinWa otomatis mencatat. Aplikasi pencatatan keuangan terbaik dari WhatsApp.">
        <meta property="og:image" content="{{ asset('finwalogo.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="FinWa - Aplikasi Catat Keuangan via WhatsApp">
        <meta property="og:site_name" content="FinWa - Catat Keuangan via WhatsApp">
        <meta property="og:locale" content="id_ID">
        
        {{-- Twitter Card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="{{ $canonicalUrl }}">
        <meta name="twitter:title" content="Catat Keuangan via WhatsApp - FinWa | Pencatatan Otomatis dari WA">
        <meta name="twitter:description" content="Catat keuangan via WhatsApp dengan mudah! Cukup chat pengeluaran & pemasukan di WA, FinWa otomatis mencatat. Aplikasi pencatatan keuangan terbaik dari WhatsApp.">
        <meta name="twitter:image" content="{{ asset('finwalogo.png') }}">
        <meta name="twitter:image:alt" content="FinWa - Aplikasi Catat Keuangan via WhatsApp">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Critical inline styles for instant render --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }
            html.dark {
                background-color: oklch(0.145 0 0);
            }
            /* Critical font fallback to prevent layout shift */
            body {
                font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @vite('resources/js/app.ts')
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
        
        {{-- Deferred Third-Party Scripts for better performance --}}
        <script>
            // Load third-party scripts after page is interactive
            document.addEventListener('DOMContentLoaded', function() {
                // Delay non-critical scripts
                setTimeout(function() {
                    // Google Tag Manager
                    (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
                    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                    })(window,document,'script','dataLayer','GTM-PKWTCMFK');
                    
                    @if(config('services.facebook.pixel_id'))
                    // Facebook Pixel
                    !function(f,b,e,v,n,t,s)
                    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                    n.queue=[];t=b.createElement(e);t.async=!0;
                    t.src=v;s=b.getElementsByTagName(e)[0];
                    s.parentNode.insertBefore(t,s)}(window, document,'script',
                    'https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', '{{ config('services.facebook.pixel_id') }}');
                    fbq('track', 'PageView');
                    @endif
                    
                    @if(config('services.google.analytics_id'))
                    // Google Analytics
                    var gaScript = document.createElement('script');
                    gaScript.async = true;
                    gaScript.src = 'https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}';
                    document.head.appendChild(gaScript);
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', '{{ config('services.google.analytics_id') }}');
                    @endif
                }, 2000); // Delay 2 seconds after DOMContentLoaded
            });
        </script>
        
        {{-- Google Tag Manager (noscript) --}}
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-PKWTCMFK"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    </body>
</html>
