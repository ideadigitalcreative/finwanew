<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- PWA --}}
        <meta name="theme-color" content="#0d9488" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0f766e" media="(prefers-color-scheme: dark)">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="FinWa">
        <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
        <meta name="google-site-verification" content="HW2rQyxeNMjG46R-Z_4X8wsJe-uQZNIJ9am2DxFVjHs" />
        
        {{-- Critical: Preload Manrope font non-blocking untuk mengurangi FCP --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
        
        {{-- Preload critical images --}}
        <link rel="preload" href="/logo.png" as="image" fetchpriority="high">
        
        {{-- SEO Meta Tags - Optimized for "catat keuangan via whatsapp" --}}
        @if(isset($seo_page))
            <meta name="description" content="{{ $seo_page->meta_description }}">
        @else
            <meta name="description" content="Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!">
        @endif
        <meta name="keywords" content="aplikasi keuangan whatsapp indonesia, catat keuangan via whatsapp, aplikasi catat keuangan umkm, finwa, bot keuangan whatsapp, pencatatan otomatis dari wa">
        <meta name="author" content="FinWa - Aplikasi Keuangan WhatsApp Indonesia">
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
        <meta property="og:type" content="{{ isset($seo_page) ? 'article' : 'website' }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:title" content="{{ $seo_page->title ?? 'FinWa: Aplikasi Keuangan WhatsApp Indonesia' }}">
        <meta property="og:description" content="{{ $seo_page->meta_description ?? 'Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!' }}">
        <meta property="og:image" content="{{ isset($seo_page) && $seo_page->thumbnail ? asset('storage/' . $seo_page->thumbnail) : asset('finwalogo.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $seo_page->title ?? 'FinWa - Aplikasi Keuangan WhatsApp Indonesia' }}">
        <meta property="og:site_name" content="FinWa - Aplikasi Keuangan WhatsApp Indonesia">
        <meta property="og:locale" content="id_ID">
        
        {{-- Twitter Card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="{{ $canonicalUrl }}">
        <meta name="twitter:title" content="{{ $seo_page->title ?? 'FinWa: Aplikasi Keuangan WhatsApp Indonesia' }}">
        <meta property="twitter:description" content="{{ $seo_page->meta_description ?? 'Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!' }}">
        <meta name="twitter:image" content="{{ isset($seo_page) && $seo_page->thumbnail ? asset('storage/' . $seo_page->thumbnail) : asset('finwalogo.png') }}">
        <meta name="twitter:image:alt" content="{{ $seo_page->title ?? 'FinWa - Aplikasi Keuangan WhatsApp Indonesia' }}">

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
                font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        
        {{-- SEO Fallback Content for Crawlers without JS --}}
        <noscript>
            <div style="padding: 20px; text-align: center;">
                <h1>FinWa: Aplikasi Keuangan WhatsApp Indonesia</h1>
                <h2>Catat Keuangan via WhatsApp dengan Mudah & Otomatis</h2>
                <p>FinWa adalah aplikasi keuangan WhatsApp Indonesia untuk UMKM dan freelancer. Cukup chat pengeluaran dan pemasukan di WA, langsung otomatis tercatat ke dashboard Anda.</p>
                
                <h3>Fitur Utama FinWa</h3>
                <ul>
                    <li>Pencatatan Otomatis dari WhatsApp</li>
                    <li>Laporan Keuangan Real-time</li>
                    <li>Manajemen Hutang Piutang</li>
                </ul>

                <h4>Mulai Sekarang</h4>
                <a href="/finwa/launch" style="display: inline-block; padding: 10px 20px; background: #0f766e; color: white; text-decoration: none; border-radius: 5px;">Coba Gratis</a>
                
                <nav style="margin-top: 30px;">
                    <h3>Tautan Navigasi Internal</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li><a href="/">Beranda</a></li>
                        <li><a href="/panduan-umkm">Panduan UMKM</a></li>
                        <li><a href="/bantuan">Pusat Bantuan</a></li>
                        <li><a href="/privasi">Kebijakan Privasi</a></li>
                        <li><a href="/syarat-ketentuan">Syarat & Ketentuan</a></li>
                    </ul>
                </nav>
            </div>
        </noscript>
    </body>
</html>
