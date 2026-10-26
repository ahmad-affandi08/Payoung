<?php

declare(strict_types=1);

/*
 * Draf Tim D, dipasang Tim A. Invarian memakai `BantuanBuku::PeriksaInvarianBuku` (tanpa invarian akun persediaan,
 * karena di tingkat buku stok tidak ada jurnal yang diposting).
 */

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPelacakan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  list<DataBarisMutasi>  $baris
 */
function CatatMutasiPelacakanUji(int $idReferensi, array $baris, JenisReferensiMutasi $jenis = JenisReferensiMutasi::StokAwal): void
{
    app(CatatMutasiStok::class)->Jalankan(new DataDokumenMutasi($jenis, $idReferensi, null, null, CarbonImmutable::parse('2026-09-24'), null, null, $baris));
}

function AmbilKodeGalatBukuPelacakan(Closure $jalankan): ?string
{
    try {
        $jalankan();
    } catch (PelanggaranAturanBisnis $e) {
        return $e->kode;
    }

    return null;
}

describe('F-05a buku stok untuk produk ber-pelacakan (DesainF05a C.4)', function (): void {
    it('BR-05.2: batch tidak pernah minus lewat mesin buku stok walau produk & tenant BolehMinus', function (): void {
        $t = BantuanPelacakan::SiapkanTenant(stokBolehMinus: true);
        $susu = $t['Produk']['Batch'];
        $susu->forceFill(['BolehMinus' => true])->save();
        // Dua batch: total produk (20) cukup untuk keluar 11, tetapi batch A (10) tidak.
        CatatMutasiPelacakanUji(1, [
            new DataBarisMutasi('P/1', $susu->Id, $t['Gudang']->Id, JenisMutasi::StokAwal, Kuantitas::Dari('10'), ModeNilaiMutasi::Ditentukan,
                nilai: Uang::Dari('195000.00'), batchMasuk: new DataBatchMasuk('UHT-2026-0917A', CarbonImmutable::parse('2027-03-17'))),
            new DataBarisMutasi('P/2', $susu->Id, $t['Gudang']->Id, JenisMutasi::StokAwal, Kuantitas::Dari('10'), ModeNilaiMutasi::Ditentukan,
                nilai: Uang::Dari('195000.00'), batchMasuk: new DataBatchMasuk('UHT-2026-0924B', CarbonImmutable::parse('2027-03-24'))),
        ]);
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2026-0917A')->sole();

        expect(AmbilKodeGalatBukuPelacakan(fn () => CatatMutasiPelacakanUji(2, [new DataBarisMutasi('K/1', $susu->Id, $t['Gudang']->Id, JenisMutasi::PenyesuaianKeluar, Kuantitas::Dari('-11'),
            ModeNilaiMutasi::Berjalan, idBatchStok: $batch->Id)], JenisReferensiMutasi::PenyesuaianStok)))->toBe('StokBatchTidakCukup')
            ->and($batch->refresh()->JumlahSisa)->toBe('10.0000')
            ->and(MutasiStok::query()->count())->toBe(2)
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('F-05g: batch sama berkedaluwarsa beda ditolak lewat mesin buku stok', function (): void {
        $t = BantuanPelacakan::SiapkanTenant();
        $susu = $t['Produk']['Batch'];
        $baris = fn (string $kunci, string $kedaluwarsa): DataBarisMutasi => new DataBarisMutasi($kunci, $susu->Id, $t['Gudang']->Id, JenisMutasi::StokAwal, Kuantitas::Dari('5'),
            ModeNilaiMutasi::Ditentukan, nilai: Uang::Dari('97500.00'), batchMasuk: new DataBatchMasuk('UHT-2026-0917A', CarbonImmutable::parse($kedaluwarsa)));
        CatatMutasiPelacakanUji(1, [$baris('P/1', '2027-03-17')]);

        expect(AmbilKodeGalatBukuPelacakan(fn () => CatatMutasiPelacakanUji(2, [$baris('P/1', '2027-04-01')])))->toBe('BatchKedaluwarsaBerbeda')
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });

    it('seri: satu baris = satu unit. Nomor ganda NomorSeriSudahAda. Keluar hanya bila Tersedia di lokasi itu', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya', stokBolehMinus: true);
        $rice = $t['Produk']['Seri'];
        $masuk = fn (string $kunci, string $nomor, string $jumlah = '1'): DataBarisMutasi => new DataBarisMutasi($kunci, $rice->Id, $t['Gudang']->Id, JenisMutasi::StokAwal,
            Kuantitas::Dari($jumlah), ModeNilaiMutasi::Ditentukan, nilai: Uang::Dari('675000.00'), nomorSeriMasuk: $nomor);

        expect(AmbilKodeGalatBukuPelacakan(fn () => CatatMutasiPelacakanUji(1, [$masuk('P/1/0', 'RC-01', '2')])))->toBe('JumlahTidakValid');

        CatatMutasiPelacakanUji(1, [$masuk('P/1/0', 'RC-01'), $masuk('P/1/1', 'RC-02')]);
        expect(AmbilKodeGalatBukuPelacakan(fn () => CatatMutasiPelacakanUji(2, [$masuk('P/1/0', 'RC-01')])))->toBe('NomorSeriSudahAda');

        $rc01 = NomorSeri::query()->where('Nomor', 'RC-01')->sole();
        expect(AmbilKodeGalatBukuPelacakan(fn () => CatatMutasiPelacakanUji(3, [new DataBarisMutasi('K/1', $rice->Id, $t['GudangBelakang']->Id, JenisMutasi::PenyesuaianKeluar,
            Kuantitas::Dari('-1'), ModeNilaiMutasi::Berjalan, idNomorSeri: $rc01->Id)], JenisReferensiMutasi::PenyesuaianStok)))->toBe('NomorSeriTidakTersedia');

        CatatMutasiPelacakanUji(4, [new DataBarisMutasi('K/1', $rice->Id, $t['Gudang']->Id, JenisMutasi::PenyesuaianKeluar, Kuantitas::Dari('-1'), ModeNilaiMutasi::Berjalan,
            idNomorSeri: $rc01->Id)], JenisReferensiMutasi::PenyesuaianStok);
        expect($rc01->refresh()->Status)->not->toBe(StatusNomorSeri::Tersedia)
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });
});
