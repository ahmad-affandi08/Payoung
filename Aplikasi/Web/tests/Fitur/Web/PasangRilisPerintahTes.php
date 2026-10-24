<?php

declare(strict_types=1);

use App\Console\Perintah\PasangRilisPerintah;
use Illuminate\Support\Facades\Artisan;

/*
 * `rilis:pasang` menggantikan daftar perintah pasca-deploy yang harus dihafal. Penjaganya: setiap langkah harus
 * perintah artisan yang benar-benar terdaftar (salah ketik tidak boleh baru ketahuan di server), langkah yang
 * ditandai mendukung `--kering` memang punya opsi itu, dan keputusan sendiri (harga, penimpaan situs) tidak ikut.
 */

it('setiap langkah adalah perintah terdaftar dan penanda --kering sesuai kenyataan', function (): void {
    $semua = Artisan::all();

    foreach ([...PasangRilisPerintah::LANGKAH_DATA_SAAS, ...PasangRilisPerintah::LANGKAH_DATA_LISENSI] as $langkah) {
        expect($semua)->toHaveKey($langkah['Perintah']);
        expect($semua[$langkah['Perintah']]->getDefinition()->hasOption('kering'))->toBe($langkah['Kering'], $langkah['Perintah']);
    }

    foreach (['migrate', 'optimize', 'optimize:clear', 'queue:restart', 'situs:segarkan-bawaan'] as $perintah) {
        expect($semua)->toHaveKey($perintah);
    }
});

it('perintah yang mengubah keputusan pemilik produk tidak ikut otomatis', function (): void {
    $nama = array_column([...PasangRilisPerintah::LANGKAH_DATA_SAAS, ...PasangRilisPerintah::LANGKAH_DATA_LISENSI], 'Perintah');

    expect($nama)->not->toContain('katalog:terapkan')->not->toContain('situs:segarkan-bawaan')->not->toContain('legal:siapkan-pembaruan');
});

it('mode kering hanya menampilkan rencana; masalah satu langkah dilaporkan dan tidak menghentikan yang lain', function (): void {
    // Basis data uji kosong (katalog fitur belum ada), jadi validasi template memang gagal: kode keluar harus gagal
    // supaya skrip deploy tidak menganggapnya berhasil, tetapi langkah sesudahnya tetap dijalankan.
    $this->artisan('rilis:pasang --kering')
        ->expectsOutputToContain('organisasi:siapkan-peran: dilewati')
        ->expectsOutputToContain('Selesai dengan masalah pada: template:terapkan.')
        ->assertExitCode(1);
});
