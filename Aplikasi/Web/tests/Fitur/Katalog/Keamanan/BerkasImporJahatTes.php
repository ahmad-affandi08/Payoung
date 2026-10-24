<?php

declare(strict_types=1);

use App\Domain\Katalog\Impor\Model\ImporProduk;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * QA F-03 impor (BR-03.6): berkas jahat. Bom ZIP xlsx yang MEMBOHONGI ukuran di direktori pusat (ukuran terdeklarasi
 * kecil, isi sebenarnya besar), CSV UTF-16, sel sangat panjang, dan rumus di laporan galat.
 */

/**
 * xlsx sah + entri `xl/media/isi.bin` berisi `$ukuranAsli` bita nol, dengan ukuran tak terkompresi yang
 * dideklarasikan (header lokal & direktori pusat) = `$ukuranDeklarasi`.
 */
function BuatXlsxBomZipQa(int $ukuranAsli, int $ukuranDeklarasi): string
{
    $path = (string) BantuanImpor::BuatXlsx([['Nama Produk', 'Harga Jual'], ['Kopi Susu Gula Aren 250 ml', 18000]])->getRealPath();
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString('xl/media/isi.bin', str_repeat("\x00", $ukuranAsli));
    $zip->close();

    $isi = (string) file_get_contents($path);
    $nama = 'xl/media/isi.bin';
    $lokal = strpos($isi, $nama) - 30;
    $pusat = strpos($isi, $nama, $lokal + 31) - 46;
    $isi = substr_replace($isi, pack('V', $ukuranDeklarasi), $lokal + 22, 4);

    return substr_replace($isi, pack('V', $ukuranDeklarasi), $pusat + 24, 4);
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

it('menolak bom ZIP xlsx yang membohongi ukuran terdeklarasi (isi nyata melewati UkuranEkstrakMaksimalKb)', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    config(['katalog.Impor.UkuranEkstrakMaksimalKb' => 1024]);

    $bohong = BuatXlsxBomZipQa(3 * 1024 * 1024, 100);
    expect(strlen($bohong))->toBeLessThan(64 * 1024);
    $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah($bohong, 'produk.xlsx'), 'Sumber' => 'Umum'])
        ->assertSessionHasErrors(['Berkas' => 'Isi berkas bukan lembar kerja Excel .xlsx yang sah.']);

    $jujur = BuatXlsxBomZipQa(3 * 1024 * 1024, 3 * 1024 * 1024);
    $masuk->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatBerkasMentah($jujur, 'produk.xlsx'), 'Sumber' => 'Umum'])
        ->assertSessionHasErrors('Berkas');

    // xlsx biasa di bawah batas tetap diterima.
    BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx([['Nama Produk'], ['Kopi Tubruk']]));

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(ImporProduk::query()->count())->toBe(1);
});

it('CSV UTF-16LE ber-BOM dibaca benar; sel 100.000 karakter menjadi masalah baris, bukan masalah sistem', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $teks = "Nama Produk,Harga Jual\r\nKéripik Singkong Balado,12000\r\n".str_repeat('A', 100000).",5000\r\n";
    $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatBerkasMentah("\xFF\xFE".mb_convert_encoding($teks, 'UTF-16LE', 'UTF-8'), 'produk.csv'));
    expect(array_column($impor->KolomSumber ?? [], 'Judul'))->toBe(['Nama Produk', 'Harga Jual'])
        ->and($impor->KolomSumber[0]['Contoh'][0] ?? null)->toBe('Kéripik Singkong Balado');

    BantuanImpor::Petakan($masuk, $impor, ['UuidKelompokPajakBawaan' => $t['KelompokPajak']->Uuid])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $impor->refresh();
    expect($impor->JumlahValid)->toBe(1)->and($impor->JumlahGalat)->toBe(1);
});

it('laporan masalah menetralkan rumus dari sel asli berkas (=, +, -, @)', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk();
    $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([
        ['Nama Produk', 'Harga Jual'],
        ['=HYPERLINK("http://jahat.test","klik")', 'bukan angka'],
        ['@SUM(A1:A9)', '-1+1'],
    ]));
    BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

    foreach (['csv', 'xlsx'] as $format) {
        $baris = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/impor/{$impor->Uuid}/laporan?jenis=semua&format={$format}")->assertOk(), $format);

        foreach (array_merge(...array_slice($baris, 1)) as $sel) {
            expect(preg_match('/^[=+\-@]/', (string) $sel))->toBe(0, "Sel laporan {$format} tidak dinetralkan: {$sel}");
        }
    }
});
