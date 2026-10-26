<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-19 (F-05g di POS): `GET /api/pos/v1/produk/{uuid}/batch` memberi kasir batch bersisa di lokasi Toko outlet, urut
 * FEFO (sama dengan alokasi server), dengan sisa hari menuju kedaluwarsa. Batch habis tidak tampil; produk tanpa batch
 * = daftar kosong; produk tenant lain = 404.
 */

beforeEach(function (): void {
    // Jam dibekukan di siang hari WIB: sisa hari dihitung dari tanggal kalender outlet (WIB), sedangkan tanggal
    // kedaluwarsa uji dibuat dari `now()`; setelah 17.00 UTC keduanya beda sehari dan hasilnya bergantung jam CI.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @param  list<array{0: string, 1: string, 2: int|null}>  $batch [nomor, jumlah, hari menuju kedaluwarsa] */
function BuatProdukBatchPos(array $k, array $batch): Produk
{
    config(['persediaan.StokAwal.WajibKedaluwarsaBatch' => false]);
    $produk = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter Kemasan Kotak', 'Pelacakan' => PelacakanProduk::Batch], '19500.00');
    BantuanStokAwal::BuatDanPosting($k['Gudang'], array_map(fn (array $b) => BantuanStokAwal::Baris(
        $produk,
        $b[1],
        '10000',
        $b[0],
        $b[2] === null ? null : CarbonImmutable::now()->addDays($b[2])->toDateString(),
    ), $batch), $k['Pemilik']->Id);

    return $produk;
}

it('batch urut FEFO dengan sisa hari. Batch habis hilang setelah dijual. Produk tanpa batch kosong. Tenant lain 404', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $susu = BuatProdukBatchPos($k, [['C-TANPA', '5', null], ['B-LAMA', '5', 40], ['A-DEKAT', '3', 10]]);

    $this->withToken($k['Token'])->getJson("/api/pos/v1/produk/{$susu->Uuid}/batch")->assertOk()
        ->assertJsonPath('Pelacakan', 'Batch')
        ->assertJsonPath('HariSegera', 30)
        ->assertJsonPath('JumlahBatch', 3)
        ->assertJsonPath('Batch.0.NomorBatch', 'A-DEKAT')
        ->assertJsonPath('Batch.0.JumlahSisa', '3.0000')
        ->assertJsonPath('Batch.0.SisaHari', 10)
        ->assertJsonPath('Batch.1.NomorBatch', 'B-LAMA')
        ->assertJsonPath('Batch.2.NomorBatch', 'C-TANPA')
        ->assertJsonPath('Batch.2.SisaHari', null);

    // Penjualan 4 pcs menghabiskan A-DEKAT (FEFO server) → batch pertama kini B-LAMA sisa 4.
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $susu, 'Jumlah' => '4', 'Harga' => '19500.00']]]);
    $this->withToken($k['Token'])->getJson("/api/pos/v1/produk/{$susu->Uuid}/batch")->assertOk()
        ->assertJsonPath('JumlahBatch', 2)
        ->assertJsonPath('Batch.0.NomorBatch', 'B-LAMA')
        ->assertJsonPath('Batch.0.JumlahSisa', '4.0000');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Lampung 250 gram'], '45000.00');
    $this->withToken($k['Token'])->getJson("/api/pos/v1/produk/{$kopi->Uuid}/batch")->assertOk()
        ->assertJsonPath('Pelacakan', 'Tidak')
        ->assertJsonPath('Batch', []);

    $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Sejahtera Abadi');
    $this->withToken($lain['Token'])->getJson("/api/pos/v1/produk/{$susu->Uuid}/batch")->assertNotFound();
    $this->withoutToken()->getJson("/api/pos/v1/produk/{$susu->Uuid}/batch")->assertUnauthorized();
});
