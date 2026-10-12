<?php

declare(strict_types=1);

use App\Domain\Integrasi\GerbangPembayaran\ProtokolDoku;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuPartner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * P-05 penyedia DOKU Partner (pendaftaran merchant): Brand ID partner & secret key diuji dengan Generate Token, panggilan
 * bertanda tangan yang hanya menerbitkan token sementara. Skema tanda tangan Partner API diasumsikan sama dengan
 * Checkout non-SNAP (belum terverifikasi dengan UAT nyata).
 */

const ID_KLIEN_UJI_PARTNER = 'BRN-PARTNER-0001';
const KUNCI_UJI_PARTNER = 'SK-rahasia-partner-doku-9988';
const TARGET_TOKEN = '/adv-core-api/partner/v1.0/token';

it('kredensial benar: Generate Token ke api-uat.doku.com dengan tanda tangan dan badan grant_type', function (): void {
    Http::fake(['api-uat.doku.com/*' => Http::response(['token' => 'JWT.rahasia.token'], 200)]);

    $hasil = (new PengujiDokuPartner)->Uji(['Mode' => 'Sandbox', 'IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);

    expect($hasil->berhasil)->toBeTrue()->and($hasil->pesan)->toContain('Sandbox')->not->toContain('JWT.rahasia.token');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://api-uat.doku.com'.TARGET_TOKEN
        && $r->hasHeader('Client-Id', ID_KLIEN_UJI_PARTNER)
        && $r->data() === ['grant_type' => 'client_credentials', 'valid_time' => '360']
        && ($r->header('Signature')[0] ?? '') === ProtokolDoku::Tandatangani(ID_KLIEN_UJI_PARTNER, $r->header('Request-Id')[0], $r->header('Request-Timestamp')[0], TARGET_TOKEN, $r->body(), KUNCI_UJI_PARTNER));
});

it('kredensial ditolak DOKU (401 atau 403): gagal tanpa membocorkan secret key', function (int $kode): void {
    Http::fake(['api-uat.doku.com/*' => Http::response(['message' => ['Invalid signature '.KUNCI_UJI_PARTNER]], $kode)]);

    $hasil = (new PengujiDokuPartner)->Uji(['Mode' => 'Sandbox', 'IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);

    expect($hasil->berhasil)->toBeFalse()->and($hasil->pesan)->toContain('ditolak DOKU')->not->toContain(KUNCI_UJI_PARTNER);
})->with([401, 403]);

it('mode produksi memakai api.doku.com', function (): void {
    Http::fake(['api.doku.com/*' => Http::response(['token' => 'T'], 200)]);

    $hasil = (new PengujiDokuPartner)->Uji(['Mode' => 'Produksi', 'IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);

    expect($hasil->berhasil)->toBeTrue()->and($hasil->pesan)->toContain('Produksi');
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.doku.com'.TARGET_TOKEN);
});

it('isian kosong dilaporkan gagal tanpa menghubungi DOKU', function (array $pengaturan, array $kredensial, string $bagian): void {
    Http::fake();

    $hasil = (new PengujiDokuPartner)->Uji($pengaturan, $kredensial);

    expect($hasil->berhasil)->toBeFalse()->and($hasil->pesan)->toContain($bagian);
    Http::assertNothingSent();
})->with([
    'tanpa Brand ID' => [['Mode' => 'Sandbox'], ['KunciRahasia' => KUNCI_UJI_PARTNER], 'Brand ID'],
    'tanpa secret key' => [['Mode' => 'Sandbox', 'IdKlien' => ID_KLIEN_UJI_PARTNER], [], 'Secret key'],
]);

it('5xx, jawaban tanpa token, dan jaringan putus dilaporkan gagal dengan pesan bersih', function (): void {
    // Stub `Http::fake` bersifat kumulatif (yang pertama cocok menang), jadi satu stub berurutan menggantikan tiga panggilan fake.
    $panggilan = 0;
    Http::fake(['api-uat.doku.com/*' => function () use (&$panggilan) {
        $panggilan++;

        return match ($panggilan) {
            1 => Http::response([], 503),
            2 => Http::response(['hasil' => 'ok'], 200),
            default => throw new ConnectionException('timeout memanggil '.KUNCI_UJI_PARTNER),
        };
    }]);
    $uji = (new PengujiDokuPartner)->Uji(['IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);
    expect($uji->berhasil)->toBeFalse()->and($uji->pesan)->toContain('503');

    $tanpaToken = (new PengujiDokuPartner)->Uji(['IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);
    expect($tanpaToken->berhasil)->toBeFalse()->and($tanpaToken->pesan)->toContain('token tidak terbaca');

    $putus = (new PengujiDokuPartner)->Uji(['IdKlien' => ID_KLIEN_UJI_PARTNER], ['KunciRahasia' => KUNCI_UJI_PARTNER]);
    expect($putus->berhasil)->toBeFalse()->and($putus->pesan)->toContain('Tidak bisa menghubungi DOKU')->not->toContain(KUNCI_UJI_PARTNER);
});
