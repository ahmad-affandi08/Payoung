<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Kueri\PemasukanPengeluaran;
use App\Domain\Akuntansi\Layanan\PenggolongUangKas;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Aksi\SimpanBelanjaStok;
use App\Domain\Pembelian\Aksi\SimpanPembayaranHutang;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Data\DataPembayaranHutang;
use App\Domain\Pembelian\Layanan\PemisahBelanjaBahanBaku;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-255 tahap 3: belanja stok di laporan Pemasukan & Pengeluaran dipecah menjadi "Belanja bahan baku" dan "Belanja
 * barang dagangan" menurut isi dokumen yang dibayar. Jumlahnya tidak boleh berubah: bahan baku + dagangan = uang yang
 * keluar. Belanja tunai memakai barisnya sendiri, pembayaran hutang memakai komposisi faktur yang dilunasi.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return array<string, array{Nilai: string, Label: string}> kelompok uang keluar → nilai */
function KelompokKeluarBelanja(int $idTenant): array
{
    BantuanOrganisasi::AturKonteks($idTenant);
    $hari = BantuanPembelian::Hari();
    $laporan = app(PemasukanPengeluaran::class)->Ambil(new SaringLaporanKeuangan($hari->startOfMonth()->toDateString(), $hari->toDateString(), null, null));

    return collect($laporan['KelompokKeluar'])->keyBy('Kelompok')->map(fn (array $k): array => ['Nilai' => $k['Nilai'], 'Label' => $k['Kelompok']])->all();
}

describe('D-255 belanja bahan baku dan dagangan', function (): void {
    it('belanja tunai campuran dipecah menurut jenis produk dan jumlahnya tetap sama dengan uang yang keluar', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $kopi = BantuanKomposisi::BuatBahan();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $kas = BantuanPembelian::AkunKas();

        app(SimpanBelanjaStok::class)->Jalankan(new DataBelanjaStok(null, $t['Gudang']->Id, BantuanPembelian::Hari(), $kas->Uuid, 'NOTA-PASAR-77', Uang::Nol(), 'Belanja pasar', [
            new DataBarisPenerimaanBarang(null, $kopi->Uuid, null, Kuantitas::Dari('1000'), Uang::Dari('300'), Uang::Nol()),
            new DataBarisPenerimaanBarang(null, $mi->Uuid, null, Kuantitas::Dari('1'), Uang::Dari('100000'), Uang::Nol()),
        ], null, $id));

        $keluar = KelompokKeluarBelanja($t['Tenant']->Id);

        expect($keluar[PenggolongUangKas::KELUAR_BELANJA_BAHAN]['Nilai'])->toBe('300000.00')
            ->and($keluar[PenggolongUangKas::KELUAR_BELANJA]['Nilai'])->toBe('100000.00')
            ->and(array_keys($keluar))->toBe([PenggolongUangKas::KELUAR_BELANJA_BAHAN, PenggolongUangKas::KELUAR_BELANJA]);
    });

    it('pembayaran hutang sebagian atas faktur campuran mengikuti komposisi faktur, bukan seluruhnya dagangan', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $kopi = BantuanKomposisi::BuatBahan();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$kopi, '1000', '300'], [$mi, '1', '100000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $kas = BantuanPembelian::AkunKas('1-1200');
        $bayar = app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, $kas->Uuid, BantuanPembelian::Hari(), [$faktur->Uuid => Uang::Dari('200000')], null, null, $id));

        // Faktur 400.000 terdiri dari 75% bahan baku. Bayar 200.000 → 150.000 bahan baku dan 50.000 dagangan.
        $porsi = app(PemisahBelanjaBahanBaku::class);
        expect($porsi->PorsiFaktur([$faktur->Id]))->toBe([$faktur->Id => '0.750000'])
            ->and($porsi->PorsiPembayaranHutang([$bayar->Id]))->toBe([$bayar->Id => '0.750000'])
            ->and($porsi->PorsiPenerimaan([$grn->Id]))->toBe([$grn->Id => '0.750000']);

        $keluar = KelompokKeluarBelanja($t['Tenant']->Id);
        expect($keluar[PenggolongUangKas::KELUAR_BELANJA_BAHAN]['Nilai'])->toBe('150000.00')
            ->and($keluar[PenggolongUangKas::KELUAR_BELANJA]['Nilai'])->toBe('50000.00');
    });

    it('tanpa bahan baku semua belanja tetap barang dagangan, dan dokumen tanpa baris berporsi nol', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$mi, '10', '110000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $kas = BantuanPembelian::AkunKas('1-1200');
        app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, $kas->Uuid, BantuanPembelian::Hari(), [$faktur->Uuid => Uang::Dari('1100000')], null, null, $id));

        $keluar = KelompokKeluarBelanja($t['Tenant']->Id);
        expect(array_keys($keluar))->toBe([PenggolongUangKas::KELUAR_BELANJA])
            ->and($keluar[PenggolongUangKas::KELUAR_BELANJA]['Nilai'])->toBe('1100000.00');

        $porsi = app(PemisahBelanjaBahanBaku::class);
        expect($porsi->PorsiPenerimaan([999999]))->toBe([999999 => '0.000000'])
            ->and($porsi->PorsiFaktur([]))->toBe([])
            ->and($porsi->PorsiPembayaranHutang([999999]))->toBe([999999 => '0.000000']);
    });
});
