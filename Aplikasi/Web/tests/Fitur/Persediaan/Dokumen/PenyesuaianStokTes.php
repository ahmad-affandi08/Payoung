<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\BatalkanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\SetujuiPenyesuaianStok;
use App\Domain\Persediaan\Aksi\TolakPenyesuaianStok;
use App\Domain\Persediaan\Aksi\UbahPengaturanPersediaan;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b penyesuaian stok (J-05.4/J-05.5, §19.2): alasan wajib; keluar dinilai HPP berjalan (Rusak/Hilang/Kedaluwarsa
 * = Susut → Susut & Barang Rusak; Sampel/Konsumsi internal/Lainnya = PenyesuaianKeluar → Selisih HPP); masuk hanya
 * alasan Lainnya dengan harga modal wajib (Cr Selisih HPP). Nilai di atas BatasPersetujuanPenyesuaian (bawaan
 * Rp 500.000) menunggu persetujuan orang lain. Invarian di akhir setiap skenario.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SiapkanPenyesuaian(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
        BantuanStokAwal::Baris($p['Batch'], '40', '19500', 'UHT-2609A', '2027-03-31'),
        BantuanStokAwal::Baris($p['Seri'], '2', '675000', nomorSeri: ['RC-0001', 'RC-0002']),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p];
}

describe('F-05b penyesuaian stok: posting & jurnal', function (): void {
    it('rusak di bawah batas langsung diposting: mutasi Susut, jurnal Dr Susut & Barang Rusak / Cr Persediaan, nomor PS', function (): void {
        $t = SiapkanPenyesuaian();
        $draf = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Rusak, [B::Baris($t['Produk']['Stok'], '-4')]);

        expect($draf->Status)->toBe(StatusPenyesuaianStok::Draf)->and($draf->NilaiPerkiraan)->toBe('154000.00');

        $dokumen = app(AjukanPenyesuaianStok::class)->Jalankan($draf, $t['Pemilik']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'PenyesuaianStok')->where('IdSumber', $dokumen->Id)->sole();

        expect($dokumen->Status)->toBe(StatusPenyesuaianStok::Diposting)
            ->and($dokumen->Nomor)->toBe('PS/'.$t['Gudang']->Kode.'/'.CarbonImmutable::parse(B::Kemarin())->format('ym').'/0001')
            ->and($dokumen->PerluPersetujuan)->toBeFalse()
            ->and($dokumen->TotalNilaiKeluar)->toBe('154000.00')
            ->and(MutasiStok::query()->where('JenisReferensi', 'PenyesuaianStok')->sole()->JenisMutasi)->toBe(JenisMutasi::Susut)
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe(['96.0000', '3696000.00'])
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['SusutPersediaan', '154000.00', '0.00', $t['Outlet']->Id],
                ['PersediaanBarangDagang', '0.00', '154000.00', $t['Outlet']->Id],
            ])
            ->and(LogAudit::query()->where('Peristiwa', 'penyesuaian-stok.posting')->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('Lainnya boleh masuk (harga modal wajib, Cr Selisih HPP) dan keluar (PenyesuaianKeluar, Dr Selisih HPP). Alasan lain tidak boleh masuk', function (): void {
        $t = SiapkanPenyesuaian();

        expect(B::KodeGalat(fn () => B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Rusak, [B::Baris($t['Produk']['Stok'], '2', '38500')])))->toBe('BarisTidakValid')
            ->and(B::KodeGalat(fn () => B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Lainnya, [B::Baris($t['Produk']['Stok'], '2', '38500')])))->toBe('KeteranganWajib')
            ->and(B::KodeGalat(fn () => B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Lainnya, [B::Baris($t['Produk']['Stok'], '2')], 'Salah catat penerimaan')))->toBe('BarisTidakValid');

        $dokumen = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Lainnya, [
            B::Baris($t['Produk']['Stok'], '3', '40000'),
            B::Baris($t['Produk']['Batch'], '5', '20000', nomorBatch: 'UHT-2610B', kedaluwarsa: '2027-06-30'),
        ], 'Salah catat penerimaan minggu lalu'), $t['Pemilik']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'PenyesuaianStok')->where('IdSumber', $dokumen->Id)->sole();

        expect($dokumen->TotalNilaiMasuk)->toBe('220000.00')
            ->and(MutasiStok::query()->where('JenisReferensi', 'PenyesuaianStok')->pluck('JenisMutasi')->map(fn ($j) => $j->value)->unique()->values()->all())->toBe(['PenyesuaianMasuk'])
            ->and(BatchStok::query()->where('NomorBatch', 'UHT-2610B')->value('JumlahSisa'))->toBe('5.0000')
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanBarangDagang', '220000.00', '0.00', $t['Outlet']->Id],
                ['SelisihHpp', '0.00', '220000.00', $t['Outlet']->Id],
            ]);

        $sampel = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Sampel, [B::Baris($t['Produk']['Stok'], '-1')]), $t['Pemilik']->Id);
        expect(MutasiStok::query()->where('JenisReferensi', 'PenyesuaianStok')->where('IdReferensi', $sampel->Id)->sole()->JenisMutasi)->toBe(JenisMutasi::PenyesuaianKeluar)
            ->and(collect(B::BarisJurnal(Jurnal::query()->where('IdSumber', $sampel->Id)->where('JenisSumber', 'PenyesuaianStok')->sole()->Id))->pluck(0)->sort()->values()->all())->toBe(['PersediaanBarangDagang', 'SelisihHpp'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('batch & nomor seri keluar menyebut batch/nomor seri yang ada di lokasi', function (): void {
        $t = SiapkanPenyesuaian();
        $batch = BatchStok::query()->where('NomorBatch', 'UHT-2609A')->sole();
        $seri = NomorSeri::query()->where('Nomor', 'RC-0001')->sole();

        expect(B::KodeGalat(fn () => B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Kedaluwarsa, [B::Baris($t['Produk']['Batch'], '-2')])))->toBe('BarisTidakValid')
            ->and(B::KodeGalat(fn () => B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [B::Baris($t['Produk']['Seri'], '-2', idSeri: $seri->Id)])))->toBe('BarisTidakValid');

        $draf = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Kedaluwarsa, [B::Baris($t['Produk']['Batch'], '-2', idBatch: $batch->Id), B::Baris($t['Produk']['Seri'], '-1', idSeri: $seri->Id)]);
        // 2 × 19.500 + 675.000 di atas batas → menunggu persetujuan.
        $draf = app(AjukanPenyesuaianStok::class)->Jalankan($draf, $t['Pemilik']->Id);
        $penyetuju = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        app(SetujuiPenyesuaianStok::class)->Jalankan($draf, $penyetuju->Id);

        expect($batch->refresh()->JumlahSisa)->toBe('38.0000')
            ->and($seri->refresh()->Status)->toBe(StatusNomorSeri::Keluar)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05b penyesuaian stok: batas persetujuan (§19.2)', function (): void {
    it('di atas batas → Menunggu persetujuan. Pembuat/pengaju tidak boleh menyetujui. Orang lain menyetujui → Diposting', function (): void {
        $t = SiapkanPenyesuaian();
        $draf = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [B::Baris($t['Produk']['Stok'], '-13')]);
        // D-38: pengaju bukan-Pemilik (Pemilik sendiri langsung memposting, diuji di bawah).
        $pengaju = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $menunggu = app(AjukanPenyesuaianStok::class)->Jalankan($draf, $pengaju->Id);

        expect($menunggu->Status)->toBe(StatusPenyesuaianStok::MenungguPersetujuan)
            ->and($menunggu->NilaiPerkiraan)->toBe('500500.00')
            ->and($menunggu->PerluPersetujuan)->toBeTrue()
            ->and(MutasiStok::query()->where('JenisReferensi', 'PenyesuaianStok')->count())->toBe(0)
            ->and(B::KodeGalat(fn () => app(SetujuiPenyesuaianStok::class)->Jalankan($menunggu, $pengaju->Id)))->toBe('PenyetujuTidakBoleh');

        $penyetuju = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $dokumen = app(SetujuiPenyesuaianStok::class)->Jalankan($menunggu, $penyetuju->Id);

        expect($dokumen->Status)->toBe(StatusPenyesuaianStok::Diposting)
            ->and($dokumen->DisetujuiOleh)->toBe($penyetuju->Id)
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('87.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('D-38: Pemilik mengajukan di atas batas → langsung diposting, Pemilik tercatat penyetuju, alasan di audit', function (): void {
        $t = SiapkanPenyesuaian();
        $dokumen = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [B::Baris($t['Produk']['Stok'], '-13')]), $t['Pemilik']->Id);

        expect($dokumen->Status)->toBe(StatusPenyesuaianStok::Diposting)
            ->and($dokumen->PerluPersetujuan)->toBeTrue()
            ->and($dokumen->DisetujuiOleh)->toBe($t['Pemilik']->Id)
            ->and(LogAudit::query()->where('Peristiwa', 'penyesuaian-stok.posting')->sole()->NilaiBaru['DisetujuiLangsung'] ?? null)->toBe('Pemilik')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('D-38: tanpa penyetuju lain yang aktif (Pemilik nonaktif, staf tunggal) → langsung diposting dengan alasan PenyetujuTunggal', function (): void {
        $t = SiapkanPenyesuaian();
        $staf = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        TenantPengguna::query()->where('IdTenant', $t['Tenant']->Id)->where('IdPengguna', $t['Pemilik']->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $dokumen = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [B::Baris($t['Produk']['Stok'], '-13')]), $staf->Id);
        expect($dokumen->Status)->toBe(StatusPenyesuaianStok::Diposting)
            ->and(LogAudit::query()->where('Peristiwa', 'penyesuaian-stok.posting')->sole()->NilaiBaru['DisetujuiLangsung'] ?? null)->toBe('PenyetujuTunggal');
    });

    it('tepat di batas langsung diposting. Ditolak kembali ke Draf beralasan lalu bisa dibatalkan', function (): void {
        $t = SiapkanPenyesuaian();
        app(UbahPengaturanPersediaan::class)->Jalankan(MetodeHpp::RataRata, false, Uang::Dari('77000'));
        expect(app(PengaturanPersediaanTenant::class)->Ambil()->batasPersetujuanPenyesuaian->KeString())->toBe('77000.00');

        $tepat = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::KonsumsiInternal, [B::Baris($t['Produk']['Stok'], '-2')]), $t['Pemilik']->Id);
        expect($tepat->Status)->toBe(StatusPenyesuaianStok::Diposting);

        $pengaju = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::StafGudang);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $lewat = app(AjukanPenyesuaianStok::class)->Jalankan(B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::KonsumsiInternal, [B::Baris($t['Produk']['Stok'], '-3')]), $pengaju->Id);
        $penyetuju = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $ditolak = app(TolakPenyesuaianStok::class)->Jalankan($lewat, 'Konsumsi internal dibatasi 2 botol', $penyetuju->Id);

        expect($lewat->Status)->toBe(StatusPenyesuaianStok::MenungguPersetujuan)
            ->and($ditolak->Status)->toBe(StatusPenyesuaianStok::Draf)
            ->and($ditolak->AlasanTolak)->toBe('Konsumsi internal dibatasi 2 botol')
            ->and(app(BatalkanPenyesuaianStok::class)->Jalankan($ditolak, $t['Pemilik']->Id)->Status)->toBe(StatusPenyesuaianStok::Dibatalkan)
            ->and(B::KodeGalat(fn () => app(BatalkanPenyesuaianStok::class)->Jalankan($tepat, $t['Pemilik']->Id)))->toBe('StatusTidakSesuai')
            ->and(PenyesuaianStok::query()->count())->toBe(2)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('periode terkunci menolak posting (PeriodeTerkunci) tanpa perubahan stok', function (): void {
        $t = SiapkanPenyesuaian();
        $draf = B::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Rusak, [B::Baris($t['Produk']['Stok'], '-1')]);
        BantuanPersediaan::KunciPeriode(CarbonImmutable::parse(B::Kemarin())->format('Y-m'));

        expect(B::KodeGalat(fn () => app(AjukanPenyesuaianStok::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('PeriodeTerkunci')
            ->and(PenyesuaianStok::query()->findOrFail($draf->Id)->Status)->toBe(StatusPenyesuaianStok::Draf)
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('100.0000');
    });
});
