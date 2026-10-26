<?php

declare(strict_types=1);

use App\Http\Perantara\PasangHeaderKeamanan;
use Illuminate\Support\Facades\Log;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit F-14: header keamanan dipasang aplikasi di halaman web & API; HSTS hanya lewat HTTPS.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('halaman masuk & API punya header keamanan; HSTS hanya di HTTPS', function (): void {
    $respons = $this->get('/masuk')->assertOk();

    expect($respons->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($respons->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($respons->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($respons->headers->get('Content-Security-Policy'))->toBe(PasangHeaderKeamanan::CSP)
        ->and($respons->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($respons->headers->get('Permissions-Policy'))->toContain('microphone=()')
        ->and($respons->headers->has('Strict-Transport-Security'))->toBeFalse();

    $this->get('https://localhost/masuk')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $this->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
});

/*
 * Audit PAY-P1-05: header `X-Forwarded-*` hanya dipercaya dari proksi yang diizinkan (`config/trustedproxy.php`).
 * Dibuktikan lewat HSTS, yang hanya dipasang bila Laravel menganggap koneksi aman (`isSecure()`).
 */
it('tanpa proksi tepercaya, X-Forwarded-Proto dari klien tidak dipercaya (tidak bisa memalsukan https)', function (): void {
    config(['trustedproxy.proxies' => null]);

    $respons = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])->get('/masuk');

    expect($respons->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('proksi tepercaya (*) membuat https dari header dikenali sehingga HSTS terkirim', function (): void {
    config(['trustedproxy.proxies' => '*']);

    $respons = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])->get('/masuk');

    expect($respons->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('daftar CIDR yang tidak memuat pemanggil tidak dipercaya', function (): void {
    config(['trustedproxy.proxies' => '198.51.100.0/24']);

    $respons = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/masuk');

    expect($respons->headers->has('Strict-Transport-Security'))->toBeFalse();
});

/*
 * Audit PAY-P1-04: CSP ketat dikirim sebagai Report-Only (tidak memblokir) bersama laporannya.
 */
it('CSP ketat dikirim Report-Only dengan sumber skrip terdaftar tanpa unsafe-inline, CSP penegak tidak berubah', function (): void {
    $respons = $this->get('/masuk')->assertOk();
    $ketat = (string) $respons->headers->get('Content-Security-Policy-Report-Only');

    expect($ketat)->toBe(PasangHeaderKeamanan::CSP_KETAT)
        ->and($ketat)->toContain("default-src 'self'")
        ->and($ketat)->toContain("form-action 'self'")
        ->and($ketat)->toContain('report-uri /laporan-csp')
        // Gaya boleh inline (atribut style React), skrip tidak: itulah gunanya CSP bila ada XSS.
        ->and(preg_match("/script-src[^;]*'unsafe-inline'/", $ketat))->toBe(0)
        ->and(preg_match("/script-src[^;]*'unsafe-eval'/", $ketat))->toBe(0)
        ->and($respons->headers->get('Content-Security-Policy'))->toBe(PasangHeaderKeamanan::CSP);
});

it('laporan CSP dicatat dengan kunci yang dikenal saja, dipotong, dan dijawab 204. Badan sampah tidak membuat masalah', function (): void {
    Log::spy();

    $this->postJson('/laporan-csp', ['csp-report' => [
        'document-uri' => 'https://dashboard.payoung.id/kelola',
        'violated-directive' => 'script-src-elem',
        'blocked-uri' => 'inline',
        'kunci-asing' => 'tidak dicatat',
        'source-file' => str_repeat('a', 1000),
    ]])->assertNoContent();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $pesan, array $konteks): bool => $pesan === 'Pelanggaran CSP (report-only).'
        && $konteks['violated-directive'] === 'script-src-elem'
        && ! array_key_exists('kunci-asing', $konteks)
        && strlen($konteks['source-file']) === 300)->once();

    $this->call('POST', '/laporan-csp', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'bukan json')->assertNoContent();
    $this->call('POST', '/laporan-csp', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], str_repeat('x', 9000))->assertNoContent();
});
