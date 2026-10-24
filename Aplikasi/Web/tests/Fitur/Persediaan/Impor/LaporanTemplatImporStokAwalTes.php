<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-05a impor stok awal: templat & laporan (anti formula injection)', function (): void {
    it('templat kosong xlsx/csv hanya berisi judul kolom templat', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        foreach (['xlsx', 'csv'] as $format) {
            $respons = $masuk->get("/kelola/persediaan/stok-awal/impor/templat?format={$format}")->assertOk()
                ->assertHeader('Content-Disposition', "attachment; filename=\"templat-stok-awal.{$format}\"");

            expect(BantuanImpor::BacaUnduhan($respons, $format))->toBe([BantuanImporStokAwal::JUDUL]);
        }
    });

    it('templat isi=produk: produk berstok aktif (termasuk bahan baku, produksi, batch, seri), tanpa konsinyasi/jasa/arsip/tenant lain; lokasi = kode lokasi; nama berumus dinetralkan', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');
        BantuanKatalog::BuatProduk(['Nama' => 'Paracetamol 500 mg Strip Isi 10 (tenant lain)', 'Sku' => 'PCT-500'], '5000.00', $lain['Pcs']);
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        BantuanKatalog::BuatProduk(['Nama' => '=HYPERLINK("http://jahat.test","Kopi Murah")', 'Sku' => 'JAHAT-1', 'Jenis' => JenisProduk::Stok], '10000.00', $t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Teh Celup Melati Isi 25 (diarsipkan)', 'Sku' => 'ARSIP-1', 'DiarsipkanPada' => now()], '6500.00', $t['Pcs']);
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        foreach (['xlsx', 'csv'] as $format) {
            $baris = BantuanImpor::BacaUnduhan($masuk->get("/kelola/persediaan/stok-awal/impor/templat?format={$format}&isi=produk&gudang={$t['Gudang']->Uuid}")->assertOk(), $format);
            $nama = array_column(array_slice($baris, 1), 1);

            expect($baris[0])->toBe(BantuanImporStokAwal::JUDUL)
                ->and($nama)->toContain($produk['Stok']->Nama, $produk['BahanBaku']->Nama, $produk['Produksi']->Nama, $produk['Batch']->Nama, $produk['Seri']->Nama)
                ->and($nama)->not->toContain($produk['Konsinyasi']->Nama)
                ->and($nama)->not->toContain($produk['Jasa']->Nama)
                ->and($nama)->not->toContain('Teh Celup Melati Isi 25 (diarsipkan)')
                ->and($nama)->not->toContain('Paracetamol 500 mg Strip Isi 10 (tenant lain)')
                ->and($nama)->toContain('\'=HYPERLINK("http://jahat.test","Kopi Murah")')
                ->and(array_unique(array_column(array_slice($baris, 1), 3)))->toBe([$t['Gudang']->Kode]);

            $minyak = array_values(array_filter($baris, fn (array $b): bool => ($b[1] ?? '') === $produk['Stok']->Nama))[0];
            expect($minyak[0])->toBe($produk['Stok']->Sku)
                ->and($minyak[2])->toBe('pcs')
                ->and(array_slice($minyak, 4))->toBe(['', '', '', '', '']);
        }
    });

    it('templat dengan lokasi stok tenant lain = 404', function (): void {
        $lain = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $masuk->get("/kelola/persediaan/stok-awal/impor/templat?format=xlsx&isi=produk&gudang={$lain['Gudang']->Uuid}")->assertNotFound();
    });

    it('laporan masalah & semua baris: kolom asli + Nomor Baris, Status Impor, Masalah; sel berumus (=, +, -, @) dinetralkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv([
            BantuanImporStokAwal::JUDUL,
            ['=HYPERLINK("http://jahat.test","klik")', '', '', '', '10', '38500'],
            ['@SUM(A1:A9)', '', '', '', '-1+1', '1000'],
            ['+62812', '', '', '', '5', '-5000'],
        ]), $t['Gudang']->Uuid);
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasNoErrors();

        foreach (['csv', 'xlsx'] as $format) {
            $semua = BantuanImpor::BacaUnduhan($masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/laporan?jenis=semua&format={$format}")->assertOk(), $format);
            expect($semua[0])->toBe([...BantuanImporStokAwal::JUDUL, 'Nomor Baris', 'Status Impor', 'Galat'])
                ->and($semua)->toHaveCount(4)
                ->and($semua[1][0])->toBe('\'=HYPERLINK("http://jahat.test","klik")')
                ->and($semua[1][9])->toBe('2')
                ->and($semua[1][10])->toBe('Ada masalah')
                ->and($semua[1][11])->toContain('Produk: Produk "=HYPERLINK');

            foreach (array_merge(...array_slice($semua, 1)) as $sel) {
                expect(preg_match('/^[=+\-@]/', (string) $sel))->toBe(0, "Sel laporan {$format} tidak dinetralkan: {$sel}");
            }

            $galat = BantuanImpor::BacaUnduhan($masuk->get("/kelola/persediaan/stok-awal/impor/{$impor->Uuid}/laporan?format={$format}")->assertOk(), $format);
            expect($galat)->toHaveCount(4);
        }

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->JumlahGalat)->toBe(3);
    });
});
