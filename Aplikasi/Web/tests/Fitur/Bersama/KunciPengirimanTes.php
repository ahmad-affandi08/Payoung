<?php

declare(strict_types=1);

use App\Domain\Bersama\Antrean\KunciPengiriman;
use Illuminate\Support\Facades\Cache;

/*
 * Audit race: satu pengirim aktif per pesan. Pekerja kedua untuk pesan yang sama harus melewatinya, dan kunci harus
 * selalu dilepas (juga saat pekerja pertama gagal) supaya percobaan ulang tidak tertahan sampai kunci kedaluwarsa.
 */

it('menjalankan pekerjaan dan melepas kunci setelahnya', function (): void {
    $hasil = KunciPengiriman::Jalankan('uji:1', fn (): string => 'terkirim', 'dilewati');

    expect($hasil)->toBe('terkirim');
    // Kunci sudah lepas: pekerja berikutnya (percobaan ulang) bisa mengambilnya lagi.
    expect(KunciPengiriman::Jalankan('uji:1', fn (): string => 'terkirim lagi', 'dilewati'))->toBe('terkirim lagi');
});

it('melewati pesan yang sedang dikirim pekerja lain tanpa menjalankan pekerjaannya', function (): void {
    $kunci = Cache::lock('kirim:uji:2', 60);
    expect($kunci->get())->toBeTrue();

    $dijalankan = false;
    $hasil = KunciPengiriman::Jalankan('uji:2', function () use (&$dijalankan): string {
        $dijalankan = true;

        return 'terkirim';
    }, 'dilewati');

    expect($hasil)->toBe('dilewati')->and($dijalankan)->toBeFalse();
    $kunci->release();
});

it('kunci dilepas walau pekerjaan melempar galat, dan pesan lain tidak saling menghalangi', function (): void {
    expect(fn () => KunciPengiriman::Jalankan('uji:3', fn () => throw new RuntimeException('penyedia mati'), 'dilewati'))
        ->toThrow(RuntimeException::class);

    expect(KunciPengiriman::Jalankan('uji:3', fn (): string => 'ulang', 'dilewati'))->toBe('ulang');

    $lain = Cache::lock('kirim:uji:4', 60);
    $lain->get();
    expect(KunciPengiriman::Jalankan('uji:5', fn (): string => 'jalan', 'dilewati'))->toBe('jalan');
    $lain->release();
});
