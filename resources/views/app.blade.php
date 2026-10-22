<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Primary Meta Tags -->
    <title inertia>Ruang Periksa — Sistem Pemeriksaan Publikasi BPS</title>
    <meta name="title" content="Ruang Periksa — Sistem Pemeriksaan Publikasi BPS">
    <meta name="description" content="Aplikasi audit dan kendali mutu publikasi resmi Badan Pusat Statistik (BPS) berbasis Vision AI multimodal. Memeriksa kover, tata letak, anatomi dokumen, perataan tabel statistik, dan menghasilkan lembar telaah Excel resmi kolom B–M.">
    <meta name="keywords" content="BPS, Badan Pusat Statistik, Ruang Periksa, Audit Publikasi BPS, Telaah Publikasi, Kecamatan Dalam Angka, KcDA, Kendali Mutu Publikasi, Vision AI, Excel BPS, Cek Publikasi, Pedoman Publikasi BPS">
    <meta name="author" content="Badan Pusat Statistik (BPS)">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="theme-color" content="#305e58">
    <meta name="color-scheme" content="light">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Favicons and Touch Icons -->
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/site.webmanifest">

    <!-- Open Graph / Facebook / WhatsApp / Telegram -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Ruang Periksa BPS">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="Ruang Periksa — Sistem Pemeriksaan Publikasi BPS">
    <meta property="og:description" content="Aplikasi audit visual dan kendali mutu naskah publikasi resmi Badan Pusat Statistik berbasis AI Vision multimodal, 32 aturan pedoman tata letak, dan ekspor laporan Excel resmi (B–M).">
    <meta property="og:image" content="{{ url('/images/og-thumbnail.png') }}">
    <meta property="og:image:secure_url" content="{{ url('/images/og-thumbnail.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="Ruang Periksa BPS — Sistem Audit & Kendali Mutu Publikasi Resmi Badan Pusat Statistik">
    <meta property="og:locale" content="id_ID">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@bps_statistics">
    <meta name="twitter:creator" content="@bps_statistics">
    <meta name="twitter:url" content="{{ url()->current() }}">
    <meta name="twitter:title" content="Ruang Periksa — Sistem Pemeriksaan Publikasi BPS">
    <meta name="twitter:description" content="Aplikasi audit visual dan kendali mutu naskah publikasi resmi Badan Pusat Statistik berbasis AI Vision multimodal dan ekspor laporan Excel resmi (B–M).">
    <meta name="twitter:image" content="{{ url('/images/og-thumbnail.png') }}">
    <meta name="twitter:image:alt" content="Ruang Periksa BPS">

    <!-- Schema.org Structured Data (JSON-LD) -->
    @verbatim
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebApplication",
          "@id": "https://bps-cek-publikasi.pinnhost.my.id/#webapp",
          "name": "Ruang Periksa BPS",
          "url": "https://bps-cek-publikasi.pinnhost.my.id/",
          "description": "Sistem audit dan kendali mutu publikasi resmi Badan Pusat Statistik (BPS) berbasis Vision AI multimodal dan ekspor Excel resmi kolom B–M.",
          "applicationCategory": "QualityControlApplication",
          "operatingSystem": "Web Browser",
          "inLanguage": "id-ID",
          "image": "https://bps-cek-publikasi.pinnhost.my.id/images/og-thumbnail.png",
          "provider": {
            "@type": "GovernmentOrganization",
            "name": "Badan Pusat Statistik",
            "alternateName": "BPS Statistics Indonesia",
            "url": "https://www.bps.go.id"
          },
          "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "IDR"
          }
        },
        {
          "@type": "WebSite",
          "@id": "https://bps-cek-publikasi.pinnhost.my.id/#website",
          "url": "https://bps-cek-publikasi.pinnhost.my.id/",
          "name": "Ruang Periksa BPS",
          "description": "Kendali Mutu dan Audit Publikasi Statistik Resmi BPS",
          "inLanguage": "id-ID",
          "publisher": {
            "@type": "GovernmentOrganization",
            "name": "Badan Pusat Statistik"
          }
        }
      ]
    }
    </script>
    @endverbatim

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
