@php
    $seo = $page['props']['Halaman']['Seo'] ?? [];
    $pratinjau = (bool) ($page['props']['Halaman']['Pratinjau'] ?? false);
    $namaSitus = $seo['NamaSitus'] ?? config('app.name');
    // Kunci "@context" JSON-LD harus berada di dalam blok @php: di luar blok, Blade membacanya sebagai direktif
    // @context dan menulis kode PHP mentah ke dalam skrip (bug di produksi: skema Organization rusak).
    $jsonLd = json_encode(! empty($seo['Artikel']) ? array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $seo['Artikel']['Judul'] ?? null,
        'description' => $seo['Deskripsi'] ?? null,
        'image' => $seo['Gambar'] ?? null,
        'datePublished' => $seo['Artikel']['DiterbitkanPada'] ?? null,
        'dateModified' => $seo['Artikel']['DiubahPada'] ?? null,
        'author' => ['@type' => 'Organization', 'name' => $seo['Artikel']['Penulis'] ?? $namaSitus],
        'publisher' => ['@type' => 'Organization', 'name' => $namaSitus],
        'mainEntityOfPage' => $seo['Kanonik'] ?? null,
    ]) : [
        // D-89: Organisasi + situs web (nama situs & logo untuk hasil pencarian merek), alamat dasar dari domain pemasaran.
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'Organization',
                '@id' => \App\Domain\Bersama\Web\AlamatDomain::BuatUrlAbsolutPemasaran('/').'#organisasi',
                'name' => $namaSitus,
                'url' => \App\Domain\Bersama\Web\AlamatDomain::BuatUrlAbsolutPemasaran('/'),
                'logo' => \App\Domain\Bersama\Web\AlamatDomain::BuatUrlAbsolutPemasaran('/apple-touch-icon.png'),
            ]),
            [
                '@type' => 'WebSite',
                'name' => $namaSitus,
                'url' => \App\Domain\Bersama\Web\AlamatDomain::BuatUrlAbsolutPemasaran('/'),
                'inLanguage' => 'id-ID',
                'publisher' => ['@id' => \App\Domain\Bersama\Web\AlamatDomain::BuatUrlAbsolutPemasaran('/').'#organisasi'],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $bagianHalaman = is_array($page['props']['Halaman']['Bagian'] ?? null) ? $page['props']['Halaman']['Bagian'] : [];
    $kerangka = [
        'pembuka' => \App\Domain\Situs\Layanan\KerangkaHalamanSitus::AmbilPembuka($bagianHalaman),
        'ringkasan' => \App\Domain\Situs\Layanan\KerangkaHalamanSitus::SusunRingkasan($bagianHalaman),
        'namaSitus' => $page['props']['Situs']['NamaSitus'] ?? $namaSitus,
        'tombolDaftar' => $page['props']['Situs']['TombolDaftar'] ?? ['Label' => 'Coba gratis', 'Tautan' => '/daftar'],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- D-21: meta SEO & pratinjau tautan diisi server (tanpa SSR Node di hosting bersama). --}}
    <title inertia>{{ $seo['Judul'] ?? $namaSitus }}</title>
    <meta name="description" content="{{ $seo['Deskripsi'] ?? '' }}">
    @if (! empty($seo['KataKunci']))
        <meta name="keywords" content="{{ $seo['KataKunci'] }}">
    @endif
    @if ($pratinjau)
        <meta name="robots" content="noindex, nofollow">
    @elseif (! empty($seo['Kanonik']))
        <link rel="canonical" href="{{ $seo['Kanonik'] }}">
    @endif
    @if (! empty($seo['VerifikasiGoogle']))
        <meta name="google-site-verification" content="{{ $seo['VerifikasiGoogle'] }}">
    @endif
    <meta property="og:type" content="{{ ($seo['Jenis'] ?? 'website') === 'article' ? 'article' : 'website' }}">
    @if (! empty($seo['Artikel']['DiterbitkanPada']))
        <meta property="article:published_time" content="{{ $seo['Artikel']['DiterbitkanPada'] }}">
    @endif
    <meta property="og:site_name" content="{{ $namaSitus }}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $seo['Judul'] ?? $namaSitus }}">
    <meta property="og:description" content="{{ $seo['Deskripsi'] ?? '' }}">
    @if (! empty($seo['Kanonik']))
        <meta property="og:url" content="{{ $seo['Kanonik'] }}">
    @endif
    @if (! empty($seo['Gambar']))
        <meta property="og:image" content="{{ $seo['Gambar'] }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <script type="application/ld+json">{!! $jsonLd !!}</script>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @viteReactRefresh
    @vite(['resources/js/Situs.tsx'])
    @inertiaHead
</head>
<body>
    {{-- Isi #app dengan kerangka server (KerangkaHalamanSitus); React menggantinya begitu halaman siap. --}}
    @php ob_start(); @endphp
    @inertia
    @php
        echo str_replace('<div id="app"></div>', '<div id="app">'.view('situs.kerangka', $kerangka)->render().'</div>', ob_get_clean());
    @endphp
</body>
</html>
