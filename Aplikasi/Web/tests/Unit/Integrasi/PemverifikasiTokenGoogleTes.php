<?php

declare(strict_types=1);

use App\Domain\Integrasi\MasukGoogle\PemverifikasiTokenGoogle;
use App\Domain\Integrasi\MasukGoogle\TokenGoogleTidakSah;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Tenant\BantuanGoogle;

/*
 * D-57: verifikasi token ID Google tanpa SDK. Tanda tangan RS256 diperiksa terhadap kunci publik (JWKS) lalu klaim
 * penerbit, audiens, kedaluwarsa, email terverifikasi, dan nonce. Token palsu apa pun harus ditolak.
 */

beforeEach(function (): void {
    BantuanGoogle::Aktifkan();
});

it('menerima token sah dan mengembalikan identitas (email huruf kecil, nama)', function (): void {
    $identitas = app(PemverifikasiTokenGoogle::class)->Verifikasi(BantuanGoogle::BuatToken(['email' => 'Rina@KopiNusantara.ID']));

    expect($identitas->sub)->toBe('110248495921238986420')
        ->and($identitas->email)->toBe('rina@kopinusantara.id')
        ->and($identitas->nama)->toBe('Rina Wulandari');
});

it('menerima audiens Client ID tambahan (token dari aplikasi Android/iOS)', function (): void {
    $identitas = app(PemverifikasiTokenGoogle::class)->Verifikasi(BantuanGoogle::BuatToken(['aud' => BantuanGoogle::CLIENT_ID_ANDROID]));

    expect($identitas->email)->toBe('rina@kopinusantara.id');
});

it('menerima email_verified berbentuk string "true" dan penerbit tanpa skema', function (): void {
    $identitas = app(PemverifikasiTokenGoogle::class)->Verifikasi(BantuanGoogle::BuatToken(['email_verified' => 'true', 'iss' => 'accounts.google.com']));

    expect($identitas->sub)->not->toBe('');
});

it('menolak token yang ditolak: :alasan', function (array $klaim, string $alasan): void {
    expect(fn () => app(PemverifikasiTokenGoogle::class)->Verifikasi(BantuanGoogle::BuatToken($klaim)))
        ->toThrow(TokenGoogleTidakSah::class);
})->with([
    'audiens bukan milik kita' => [['aud' => 'orang-lain.apps.googleusercontent.com'], 'aud'],
    'penerbit palsu' => [['iss' => 'https://akun-palsu.example'], 'iss'],
    'sudah kedaluwarsa' => [['exp' => time() - 3600], 'exp'],
    'email belum terverifikasi' => [['email_verified' => false], 'email_verified'],
    'tanpa email' => [['email' => null], 'email'],
    'tanpa sub' => [['sub' => null], 'sub'],
]);

it('menolak token yang ditandatangani kunci lain (pemalsuan)', function (): void {
    $token = BantuanGoogle::BuatToken(kunci: BantuanGoogle::BuatKunciAsing());

    expect(fn () => app(PemverifikasiTokenGoogle::class)->Verifikasi($token))->toThrow(TokenGoogleTidakSah::class, 'Tanda tangan');
});

it('menolak kid yang tidak dikenal, format rusak, dan algoritma none', function (): void {
    $verifikator = app(PemverifikasiTokenGoogle::class);

    expect(fn () => $verifikator->Verifikasi(BantuanGoogle::BuatToken(kid: 'kid-asing')))->toThrow(TokenGoogleTidakSah::class)
        ->and(fn () => $verifikator->Verifikasi('bukan-token'))->toThrow(TokenGoogleTidakSah::class);

    $kepala = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'none', 'kid' => BantuanGoogle::KID])), '+/', '-_'), '=');
    $badan = rtrim(strtr(base64_encode((string) json_encode(['aud' => BantuanGoogle::CLIENT_ID, 'sub' => '1', 'email' => 'a@b.id', 'email_verified' => true, 'iss' => 'accounts.google.com', 'exp' => time() + 60])), '+/', '-_'), '=');

    expect(fn () => $verifikator->Verifikasi($kepala.'.'.$badan.'.'))->toThrow(TokenGoogleTidakSah::class);
});

it('mencocokkan nonce bila diminta dan menolak yang berbeda', function (): void {
    $token = BantuanGoogle::BuatToken(['nonce' => 'nonce-benar']);
    $verifikator = app(PemverifikasiTokenGoogle::class);

    expect($verifikator->Verifikasi($token, 'nonce-benar')->sub)->not->toBe('')
        ->and(fn () => $verifikator->Verifikasi($token, 'nonce-lain'))->toThrow(TokenGoogleTidakSah::class, 'Nonce');
});

it('kunci publik di-cache. Kid baru memicu muat ulang sekali (rotasi kunci Google)', function (): void {
    $verifikator = app(PemverifikasiTokenGoogle::class);
    $verifikator->Verifikasi(BantuanGoogle::BuatToken());
    $verifikator->Verifikasi(BantuanGoogle::BuatToken());

    Http::assertSentCount(1);
});

it('kegagalan mengambil kunci tidak menyimpan daftar kosong ke cache', function (): void {
    Cache::forget(PemverifikasiTokenGoogle::KUNCI_CACHE);
    // Buang stub JWKS sah dari beforeEach: stub pertama yang cocok selalu menang.
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstances();
    Http::fake([PemverifikasiTokenGoogle::URL_KUNCI => Http::response(['keys' => []], 500)]);

    expect(fn () => app(PemverifikasiTokenGoogle::class)->Verifikasi(BantuanGoogle::BuatToken()))->toThrow(TokenGoogleTidakSah::class)
        ->and(Cache::has(PemverifikasiTokenGoogle::KUNCI_CACHE))->toBeFalse();
});

it('konversi JWK ke PEM menghasilkan kunci publik yang bisa dipakai openssl', function (): void {
    $kunci = BantuanGoogle::BuatKunciAsing();
    $rsa = openssl_pkey_get_details($kunci)['rsa'];
    $pem = PemverifikasiTokenGoogle::UbahJwkKePem(rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='), rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='));

    openssl_sign('pesan', $tandaTangan, $kunci, OPENSSL_ALGO_SHA256);

    expect($pem)->toStartWith('-----BEGIN PUBLIC KEY-----')
        ->and(openssl_verify('pesan', $tandaTangan, (string) $pem, OPENSSL_ALGO_SHA256))->toBe(1);
});
