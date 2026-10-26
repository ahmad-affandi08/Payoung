<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('menampilkan beranda situs pemasaran (D-21) lewat Inertia dengan isi bawaan', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('Situs')
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Situs/Halaman')
            ->where('Halaman.Slug', 'beranda')
            ->where('Halaman.Bagian.0.Jenis', 'HeroGeser')
            ->has('Halaman.Bagian.0.Sorotan', 8)
            ->has('Situs.NamaSitus'));
});

it('menulis JSON-LD Organization + WebSite yang valid, bukan kode PHP mentah dari direktif @context Blade', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('$__contextArgs');
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', (string) $html, $cocok);
    $data = json_decode($cocok[1] ?? '', true);

    // D-89: halaman non-artikel memuat @graph berisi Organization dan WebSite.
    expect($data)->toBeArray()
        ->and($data['@context'])->toBe('https://schema.org')
        ->and(array_column($data['@graph'], '@type'))->toBe(['Organization', 'WebSite']);
});

it('mengirim kerangka HTML sorotan pertama di #app supaya halaman tidak kosong sebelum JavaScript siap', function (): void {
    // Alamat gambar spesimen dibuat dari manifest build (ter-commit di public/build), jadi Vite sungguhan dipakai di sini.
    $this->withVite();
    $html = (string) $this->get('/')->assertOk()->getContent();

    // Judul sorotan pertama sudah ada di HTML sebagai satu-satunya <h1>, lengkap dengan tombol dan gambar pertama.
    expect($html)->toContain('<div id="app"><div data-kerangka')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('Kasir tetap mencatat walau internet mati')
        ->and($html)->toContain('/build/assets/KasirSektorKafe-')
        // Blok lain tersedia sebagai teks untuk perayap tanpa JavaScript.
        ->and($html)->toContain('<noscript>')
        ->and($html)->toContain('Satu aplikasi, enam cara pakai');
});

it('menyediakan health check di /sehat', function (): void {
    $this->get('/sehat')->assertOk();
});
