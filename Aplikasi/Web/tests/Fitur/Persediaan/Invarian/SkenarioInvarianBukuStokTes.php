<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Layanan\PembangunUlangSaldoStok;
use App\Domain\Persediaan\Layanan\PemeriksaKonsistensiStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Satu dokumen lewat mesin buku stok Tim A (tanpa jurnal: jurnal milik dokumen sumber).
 *
 * @param  list<DataBarisMutasi>  $baris
 */
function CatatDokumenInvarianBuku(JenisReferensiMutasi $jenis, int $idReferensi, array $baris): HasilCatatMutasi
{
    return app(CatatMutasiStok::class)->Jalankan(new DataDokumenMutasi(
        jenisReferensi: $jenis,
        idReferensi: $idReferensi,
        uuidReferensi: null,
        nomorReferensi: "UJI/{$idReferensi}",
        tanggalBisnis: CarbonImmutable::parse('2026-09-24'),
        idPengguna: null,
        idPerangkat: null,
        baris: $baris,
    ));
}

function CatatMasukInvarianBuku(string $kunci, int $idProduk, int $idGudang, JenisMutasi $jenis, string $jumlah, string $nilai, ?string $hpp = null, ?DataBatchMasuk $batch = null, ?string $seri = null): DataBarisMutasi
{
    return new DataBarisMutasi(
        kunciBaris: $kunci, idProduk: $idProduk, idGudang: $idGudang, jenisMutasi: $jenis, jumlah: Kuantitas::Dari($jumlah),
        modeNilai: ModeNilaiMutasi::Ditentukan, nilai: Uang::Dari($nilai), hppSatuan: $hpp === null ? null : BigDecimal::of($hpp),
        batchMasuk: $batch, nomorSeriMasuk: $seri,
    );
}

function CatatKeluarInvarianBuku(string $kunci, int $idProduk, int $idGudang, string $jumlah, ?int $idBatch = null, ?int $idSeri = null): DataBarisMutasi
{
    return new DataBarisMutasi(
        kunciBaris: $kunci, idProduk: $idProduk, idGudang: $idGudang, jenisMutasi: JenisMutasi::Penjualan,
        jumlah: Kuantitas::Dari($jumlah)->Negasi(), modeNilai: ModeNilaiMutasi::Berjalan, idBatchStok: $idBatch, idNomorSeri: $idSeri,
    );
}

/** @return list<string> */
function PeriksaInvarianBukuSkenario(int $idTenant, bool $fifo): array
{
    return [
        ...PemeriksaInvarian::PeriksaSaldoStok($idTenant),
        ...PemeriksaInvarian::PeriksaRantaiMutasi($idTenant),
        ...PemeriksaInvarian::PeriksaNilaiNolSaatJumlahNol($idTenant),
        ...($fifo ? PemeriksaInvarian::PeriksaLapisanFifo($idTenant) : []),
        ...PemeriksaInvarian::PeriksaBatch($idTenant),
        ...PemeriksaInvarian::PeriksaNomorSeri($idTenant),
    ];
}

/** @return array{Saldo: list<array<string, mixed>>, Batch: list<array<string, mixed>>} */
function AmbilPotretCacheStok(int $idTenant): array
{
    return [
        'Saldo' => DB::table('SaldoStok')->where('IdTenant', $idTenant)->orderBy('IdProduk')->orderBy('IdGudang')
            ->get(['IdProduk', 'IdGudang', 'JumlahTersedia', 'NilaiPersediaan', 'HppRataRata', 'IdMutasiStokTerakhir'])->map(fn ($b) => (array) $b)->all(),
        'Batch' => DB::table('BatchStok')->where('IdTenant', $idTenant)->orderBy('Id')->get(['Id', 'JumlahSisa'])->map(fn ($b) => (array) $b)->all(),
    ];
}

describe('F-05a invarian buku stok nyata × bangun ulang (Tim A + Tim H)', function (): void {
    it('BR-05.1 BR-04.2: masuk, terima, jual (biasa/batch/seri, dua lokasi) → invarian terpenuhi. Cache yang dirusak dibangun ulang persis seperti hasil mesin', function (MetodeHpp $metode): void {
        $fifo = $metode === MetodeHpp::Fifo;
        $t = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya', $metode);
        $id = $t['Tenant']->Id;
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $toko = $t['Gudang']->Id;
        $belakang = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Toko')->Id;
        $batchA = new DataBatchMasuk('UHT-2609-A', CarbonImmutable::parse('2027-03-31'));

        // Contoh C.3 #2 (10 @ 1000, terima 5 @ 1200, jual 12) + bahan baku desimal, batch, seri, lokasi kedua.
        CatatDokumenInvarianBuku(JenisReferensiMutasi::StokAwal, 1, [
            CatatMasukInvarianBuku('P/1', $p['Stok']->Id, $toko, JenisMutasi::StokAwal, '10', '10000.00', '1000'),
            CatatMasukInvarianBuku('P/2', $p['BahanBaku']->Id, $belakang, JenisMutasi::StokAwal, '25.5', '388878.15', '15250.123456'),
            CatatMasukInvarianBuku('P/3', $p['Batch']->Id, $toko, JenisMutasi::StokAwal, '24', '428400.00', '17850', $batchA),
            CatatMasukInvarianBuku('P/4/1', $p['Seri']->Id, $toko, JenisMutasi::StokAwal, '1', '512500.33', '512500.333333', seri: 'RC18-2026-000121'),
            CatatMasukInvarianBuku('P/4/2', $p['Seri']->Id, $toko, JenisMutasi::StokAwal, '1', '512500.34', '512500.333333', seri: 'RC18-2026-000122'),
        ]);
        CatatDokumenInvarianBuku(JenisReferensiMutasi::PenerimaanBarang, 2, [
            CatatMasukInvarianBuku('T/1', $p['Stok']->Id, $toko, JenisMutasi::PenerimaanPembelian, '5', '6000.00'),
            CatatMasukInvarianBuku('T/2', $p['Stok']->Id, $belakang, JenisMutasi::PenerimaanPembelian, '40', '41000.00'),
        ]);
        $idBatch = BatchStok::query()->where('NomorBatch', 'UHT-2609-A')->value('Id');
        $idSeri = NomorSeri::query()->where('Nomor', 'RC18-2026-000122')->value('Id');
        $jual = [
            CatatKeluarInvarianBuku('J/1', $p['Stok']->Id, $toko, '12'),
            CatatKeluarInvarianBuku('J/2', $p['BahanBaku']->Id, $belakang, '3.25'),
            CatatKeluarInvarianBuku('J/3', $p['Batch']->Id, $toko, '4', idBatch: is_int($idBatch) ? $idBatch : null),
            CatatKeluarInvarianBuku('J/4', $p['Seri']->Id, $toko, '1', idSeri: is_int($idSeri) ? $idSeri : null),
        ];
        $hasilJual = CatatDokumenInvarianBuku(JenisReferensiMutasi::Penjualan, 3, $jual);

        // MA: −12800.00 (A 1066.666667); FIFO: −(10000.00 + 2400.00) = −12400.00.
        expect($hasilJual->baris['J/1']->totalHpp->KeString())->toBe($fifo ? '-12400.00' : '-12800.00')
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->where('IdGudang', $toko)->sole()->only(['JumlahTersedia', 'NilaiPersediaan']))
            ->toBe(['JumlahTersedia' => '3.0000', 'NilaiPersediaan' => $fifo ? '3600.00' : '3200.00'])
            ->and(PeriksaInvarianBukuSkenario($id, $fifo))->toBe([])
            ->and(app(PemeriksaKonsistensiStok::class)->Periksa())->toBe([]);

        // Idempoten: dokumen yang sama dikirim ulang = replay tanpa baris baru; invarian tetap.
        $jumlahMutasi = MutasiStok::query()->count();
        expect(CatatDokumenInvarianBuku(JenisReferensiMutasi::Penjualan, 3, $jual)->sudahAda)->toBeTrue()
            ->and(MutasiStok::query()->count())->toBe($jumlahMutasi)
            ->and(PeriksaInvarianBukuSkenario($id, $fifo))->toBe([]);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();

        // Rusak cache (saldo, HPP rata-rata, mutasi terakhir, baris hilang, batch) → bangun ulang = potret mesin.
        $potret = AmbilPotretCacheStok($id);
        DB::table('SaldoStok')->where('IdTenant', $id)->where('IdProduk', $p['Stok']->Id)
            ->update(['JumlahTersedia' => '99.0000', 'NilaiPersediaan' => '1.00', 'HppRataRata' => null, 'IdMutasiStokTerakhir' => null]);
        DB::table('SaldoStok')->where('IdTenant', $id)->where('IdProduk', $p['BahanBaku']->Id)->delete();
        DB::table('BatchStok')->where('IdTenant', $id)->update(['JumlahSisa' => '0.0000']);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertExitCode(1);
        expect(app(PembangunUlangSaldoStok::class)->Jalankan())->toBe(4)
            ->and(AmbilPotretCacheStok($id))->toEqual($potret)
            ->and(PeriksaInvarianBukuSkenario($id, $fifo))->toBe([]);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);
});
