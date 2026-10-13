<?php

declare(strict_types=1);

use App\Domain\Organisasi\Layanan\PembuatKodeOutlet;

/*
 * Kode outlet pertama diturunkan dari nama usaha supaya nomor dokumen dan kode perangkat terbaca sebagai singkatan
 * usahanya (bukan `UTAMA` yang sama untuk semua tenant). Harus selalu memenuhi aturan kode outlet BR-02.2 (3–5
 * karakter, diawali huruf, hanya A–Z dan angka).
 */

it('menurunkan singkatan yang terbaca dari nama usaha', function (string $nama, string $harapan): void {
    expect(PembuatKodeOutlet::Buat($nama))->toBe($harapan);
})->with([
    'dua kata dilengkapi konsonan' => ['Raja Sayur', 'RSY'],
    'dua kata lain' => ['Kopi Nusantara', 'KNS'],
    'kata badan usaha dibuang' => ['Toko Bangunan Sinar Jaya Abadi', 'BSJA'],
    'PT dibuang, satu kata' => ['PT Maju', 'MAJU'],
    'satu kata empat huruf pertama' => ['Kopi', 'KOPI'],
    'tanda baca & huruf kecil' => ['bakery & cake house', 'BCH'],
    'aksen dilatinkan' => ['Café Sérénité Jaya', 'CSJ'],
    'tiga kata' => ['Toko Sembako Makmur Jaya', 'SMJ'],
    'kata sangat panjang dibatasi lima kata' => ['Rumah Makan Padang Sederhana Jaya Raya Sentosa', 'RMPSJ'],
]);

it('selalu menghasilkan kode yang sah menurut BR-02.2, dengan cadangan UTAMA bila tak ada huruf', function (string $nama): void {
    $kode = PembuatKodeOutlet::Buat($nama);

    expect($kode)->toMatch('/^[A-Z][A-Z0-9]{2,4}$/');
})->with(['Warung 88', '1 2 3', 'A B', 'Ä Ö', '!!!', '', 'Toko', '日本語の店']);

it('nama tanpa huruf Latin memakai kode cadangan', function (): void {
    expect(PembuatKodeOutlet::Buat('!!!'))->toBe(PembuatKodeOutlet::KODE_CADANGAN)->and(PembuatKodeOutlet::Buat(''))->toBe('UTAMA');
});
