<?php

declare(strict_types=1);

use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Buku stok untuk produk ber-pelacakan batch & nomor seri (DesainF05a C.2 langkah 7, C.4). Kunci & pembaruan
 * BatchStok/NomorSeri lewat layanan Tim D (`PelacakBatchStok`, `PelacakNomorSeri`); Tim A menegakkan sisa batch,
 * ketersediaan seri, dan BR-05.2 (produk berpelacakan tidak pernah minus).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function BuatBatchMasukUjiBuku(string $nomor, string $kedaluwarsa = '2027-03-31'): DataBatchMasuk
{
    return new DataBatchMasuk($nomor, CarbonImmutable::parse($kedaluwarsa));
}

function AmbilIdBatchUjiBuku(string $nomor): int
{
    return (int) BatchStok::query()->where('NomorBatch', $nomor)->value('Id');
}

function AmbilIdSeriUjiBuku(string $nomor): int
{
    return (int) NomorSeri::query()->where('Nomor', $nomor)->value('Id');
}

describe('F-05a buku stok: batch (C.4)', function (): void {
    it('batch masuk membuat/menambah BatchStok per nomor batch. Baris mutasi menyimpan IdBatchStok', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $p['Batch']->Id;
        $g = $t['Gudang']->Id;

        $hasil = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1', $id, $g, '10', '195000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A')),
            BantuanBuku::BuatBaris('P/2', $id, $g, '8', '160000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2610B', '2027-04-30')),
            BantuanBuku::BuatBaris('P/3', $id, $g, '5', '97500.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A')),
        ], JenisReferensiMutasi::StokAwal);

        $a = BatchStok::query()->where('NomorBatch', 'SUSU-2609A')->firstOrFail();
        $b = BatchStok::query()->where('NomorBatch', 'SUSU-2610B')->firstOrFail();

        expect($a->JumlahSisa)->toBe('15.0000')
            ->and($a->TanggalKedaluwarsa?->toDateString())->toBe('2027-03-31')
            ->and($b->JumlahSisa)->toBe('8.0000')
            ->and($hasil->baris['P/1']->idBatchStok)->toBe($a->Id)
            ->and($hasil->baris['P/3']->idBatchStok)->toBe($a->Id)
            ->and(MutasiStok::query()->findOrFail($hasil->baris['P/2']->idMutasiStok)->IdBatchStok)->toBe($b->Id)
            ->and(BantuanBuku::AmbilSaldo($id, $g)?->JumlahTersedia)->toBe('23.0000')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('batch keluar melebihi sisa batch → StokBatchTidakCukup walau total produk cukup dan stok minus diizinkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $p['Batch']->forceFill(['BolehMinus' => true])->save();
        $id = $p['Batch']->Id;
        $g = $t['Gudang']->Id;
        BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1', $id, $g, '10', '195000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A')),
            BantuanBuku::BuatBaris('P/2', $id, $g, '10', '195000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2610B', '2027-04-30')),
        ], JenisReferensiMutasi::StokAwal);

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('J/1', $id, $g, '-12', null, JenisMutasi::Penjualan, idBatchStok: AmbilIdBatchUjiBuku('SUSU-2609A')),
        ], JenisReferensiMutasi::Penjualan));

        expect($galat->kode)->toBe('StokBatchTidakCukup')
            ->and(BatchStok::query()->where('NomorBatch', 'SUSU-2609A')->value('JumlahSisa'))->toBe('10.0000');
    });

    it('BR-05.2: produk batch tidak pernah minus walau produk & tenant membolehkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $p['Batch']->forceFill(['BolehMinus' => true])->save();
        $id = $p['Batch']->Id;
        $g = $t['Gudang']->Id;
        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $id, $g, '4', '78000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A'))], JenisReferensiMutasi::StokAwal);

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('J/1', $id, $g, '-5', null, JenisMutasi::Penjualan, idBatchStok: AmbilIdBatchUjiBuku('SUSU-2609A')),
        ], JenisReferensiMutasi::Penjualan));

        expect($galat->kode)->toBe('StokTidakCukup')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('batch keluar berjalan mengurangi BatchStok; FIFO hanya mengonsumsi lapisan batch itu', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::Fifo);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $p['Batch']->Id;
        $g = $t['Gudang']->Id;
        BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1', $id, $g, '10', '195000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A')),
            BantuanBuku::BuatBaris('P/2', $id, $g, '10', '210000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2610B', '2027-04-30')),
        ], JenisReferensiMutasi::StokAwal);
        $idB = AmbilIdBatchUjiBuku('SUSU-2610B');

        $jual = BantuanBuku::Catat([BantuanBuku::BuatBaris('J/1', $id, $g, '-4', null, JenisMutasi::Penjualan, idBatchStok: $idB)], JenisReferensiMutasi::Penjualan);
        $lapisan = LapisanFifo::query()->where('IdProduk', $id)->orderBy('Id')->get()->all();

        expect($jual->TotalHpp()->KeString())->toBe('-84000.00')
            ->and($jual->baris['J/1']->idBatchStok)->toBe($idB)
            ->and(BatchStok::query()->whereKey($idB)->value('JumlahSisa'))->toBe('6.0000')
            ->and($lapisan[0]->JumlahSisa)->toBe('10.0000')
            ->and($lapisan[0]->IdBatchStok)->toBe(AmbilIdBatchUjiBuku('SUSU-2609A'))
            ->and($lapisan[1]->JumlahSisa)->toBe('6.0000')
            ->and($lapisan[1]->IdBatchStok)->toBe($idB)
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id, true))->toBe([]);
    });

    it('nomor batch sama dengan kedaluwarsa berbeda → BatchKedaluwarsaBerbeda', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $p['Batch']->Id, $t['Gudang']->Id, '4', '78000.00', batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A'))], JenisReferensiMutasi::StokAwal);

        $beda = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('M/1', $p['Batch']->Id, $t['Gudang']->Id, '1', '19500.00', JenisMutasi::PenerimaanPembelian, batchMasuk: BuatBatchMasukUjiBuku('SUSU-2609A', '2027-06-30')),
        ], JenisReferensiMutasi::PenerimaanBarang));

        expect($beda->kode)->toBe('BatchKedaluwarsaBerbeda');
    });
});

describe('F-05a buku stok: nomor seri (C.4)', function (): void {
    it('seri masuk → Tersedia di lokasi. Jual → Terjual. Keluar lagi → NomorSeriTidakTersedia. Retur → aktif kembali', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $p['Seri']->Id;
        $g = $t['Gudang']->Id;

        $awal = BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1/1', $id, $g, '1', '675000.00', nomorSeriMasuk: 'RC18-0001'),
            BantuanBuku::BuatBaris('P/1/2', $id, $g, '1', '675000.00', nomorSeriMasuk: 'RC18-0002'),
        ], JenisReferensiMutasi::StokAwal);
        $idSeri = AmbilIdSeriUjiBuku('RC18-0001');

        $jual = BantuanBuku::Catat([BantuanBuku::BuatBaris('J/1', $id, $g, '-1', null, JenisMutasi::Penjualan, idNomorSeri: $idSeri)], JenisReferensiMutasi::Penjualan);
        $terjual = NomorSeri::query()->findOrFail($idSeri);
        $lagi = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([BantuanBuku::BuatBaris('J/1', $id, $g, '-1', null, JenisMutasi::Penjualan, idNomorSeri: $idSeri)], JenisReferensiMutasi::Penjualan));
        $retur = BantuanBuku::Catat([BantuanBuku::BuatBaris('R/1', $id, $g, '1', '675000.00', JenisMutasi::ReturPenjualan, nomorSeriMasuk: 'RC18-0001')], JenisReferensiMutasi::ReturPenjualan);

        expect($awal->baris['P/1/1']->idNomorSeri)->toBe($idSeri)
            ->and($jual->baris['J/1']->idNomorSeri)->toBe($idSeri)
            ->and($terjual->Status)->toBe(StatusNomorSeri::Terjual)
            ->and($lagi->kode)->toBe('NomorSeriTidakTersedia')
            ->and($retur->baris['R/1']->idNomorSeri)->toBe($idSeri)
            ->and(NomorSeri::query()->findOrFail($idSeri)->Status)->toBe(StatusNomorSeri::Tersedia)
            ->and(NomorSeri::query()->findOrFail($idSeri)->IdGudang)->toBe($g)
            ->and(NomorSeri::query()->count())->toBe(2)
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('seri keluar dari lokasi stok lain (padahal lokasi itu punya stok) → NomorSeriTidakTersedia', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g2 = BantuanPersediaan::BuatGudang($t['Outlet'])->Id;
        BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1', $p['Seri']->Id, $t['Gudang']->Id, '1', '675000.00', nomorSeriMasuk: 'RC18-0001'),
            BantuanBuku::BuatBaris('P/2', $p['Seri']->Id, $g2, '1', '675000.00', nomorSeriMasuk: 'RC18-0002'),
        ], JenisReferensiMutasi::StokAwal);

        $galat = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('K/1', $p['Seri']->Id, $g2, '-1', null, JenisMutasi::PenyesuaianKeluar, idNomorSeri: AmbilIdSeriUjiBuku('RC18-0001')),
        ]));

        expect($galat->kode)->toBe('NomorSeriTidakTersedia')
            ->and(NomorSeri::query()->where('Nomor', 'RC18-0001')->value('IdGudang'))->toBe($t['Gudang']->Id);
    });

    it('seri masuk ganda di satu dokumen atau yang masih tersedia → NomorSeriSudahAda', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $p['Seri']->Id;
        $g = $t['Gudang']->Id;

        $ganda = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('P/1', $id, $g, '1', '675000.00', nomorSeriMasuk: 'RC18-0009'),
            BantuanBuku::BuatBaris('P/2', $id, $g, '1', '675000.00', nomorSeriMasuk: ' RC18-0009 '),
        ], JenisReferensiMutasi::StokAwal));
        BantuanBuku::Catat([BantuanBuku::BuatBaris('P/1', $id, $g, '1', '675000.00', nomorSeriMasuk: 'RC18-0009')], JenisReferensiMutasi::StokAwal);
        $masihAda = BantuanBuku::TangkapPelanggaran(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('M/1', $id, $g, '1', '675000.00', JenisMutasi::PenerimaanPembelian, nomorSeriMasuk: 'RC18-0009'),
        ], JenisReferensiMutasi::PenerimaanBarang));

        expect($ganda->kode)->toBe('NomorSeriSudahAda')
            ->and($masihAda->kode)->toBe('NomorSeriSudahAda')
            ->and(MutasiStok::query()->count())->toBe(1);
    });
});
