<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\MutasiKas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Tenant\Model\Tenant;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant + shift terbuka milik kasir di perangkat utama.
 *
 * @return array<string, mixed>
 */
function SiapkanShiftKasUji(object $tes, bool $bersama = false): array
{
    $k = BantuanKasir::Siapkan($tes);
    $shift = BantuanKasir::ItemBukaShift($k['Kasir'], timpa: $bersama ? ['Bersama' => true] : []);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$shift]))->toBe([['Diterima', null]]);

    return $k + ['UuidShift' => $shift['Uuid']];
}

/**
 * Baris jurnal mutasi kas sebagai `[IdAkun, Debit, Kredit]`, urut debit dulu.
 *
 * @return list<array{0: int, 1: string, 2: string}>
 */
function AmbilBarisJurnalKasUji(MutasiKas $mutasi): array
{
    return array_values(JurnalDetail::query()->where('IdJurnal', $mutasi->IdJurnal)->orderBy('Urutan')->get()
        ->map(fn (JurnalDetail $d): array => [$d->IdAkun, (string) $d->Debit, (string) $d->Kredit])->all());
}

describe('F-06 kas masuk/keluar/setoran lewat sinkron', function (): void {
    it('J-06.1 kas keluar di bawah batas diterima: Dr akun kategori / Cr Kas Outlet, jurnal seimbang, audit tercatat', function (): void {
        $k = SiapkanShiftKasUji($this);
        $item = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '45000.00', $k['KategoriKeluar']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $mutasi = MutasiKas::query()->where('Uuid', $item['Uuid'])->sole();
        $jurnal = Jurnal::query()->findOrFail($mutasi->IdJurnal);

        expect($mutasi->Jumlah)->toBe('45000.00')
            ->and($mutasi->DicatatOleh)->toBe($k['Kasir']->Id)
            ->and($mutasi->DisetujuiOleh)->toBeNull()
            ->and($jurnal->JenisSumber)->toBe(JenisSumberJurnal::MutasiKas)
            ->and(AmbilBarisJurnalKasUji($mutasi))->toBe([
                [$k['KategoriKeluar']->IdAkun, '45000.00', '0.00'],
                [BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet), '0.00', '45000.00'],
            ])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('kas masuk (Dr Kas Outlet / Cr akun kategori) dan setoran J-11.3 (Dr Kas Brankas / Cr Kas Outlet). Setoran tanpa kategori', function (): void {
        $k = SiapkanShiftKasUji($this);
        $masuk = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Masuk', '20000.00', $k['KategoriMasuk']);
        $setoran = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Setoran', '1500000.00', null);
        $setoranBerkategori = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Setoran', '10000.00', $k['KategoriKeluar']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$masuk, $setoran, $setoranBerkategori]))
            ->toBe([['Diterima', null], ['Diterima', null], ['Ditolak', 'KategoriTidakValid']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet);

        expect(AmbilBarisJurnalKasUji(MutasiKas::query()->where('Uuid', $masuk['Uuid'])->sole()))->toBe([
            [$kas, '20000.00', '0.00'],
            [$k['KategoriMasuk']->IdAkun, '0.00', '20000.00'],
        ])
            ->and(AmbilBarisJurnalKasUji(MutasiKas::query()->where('Uuid', $setoran['Uuid'])->sole()))->toBe([
                [BantuanJurnal::IdAkunPeran(PeranAkun::KasBrankas), '1500000.00', '0.00'],
                [$kas, '0.00', '1500000.00'],
            ])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('BR-06.4 kas keluar di atas batas Rp 200.000 wajib penyetuju berizin kas.keluar.setujui', function (): void {
        $k = SiapkanShiftKasUji($this);
        $tanpa = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '200000.01', $k['KategoriKeluar']);
        $kasirMenyetujui = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '350000.00', $k['KategoriKeluar'], ['UuidPenyetuju' => $k['Kasir']->Uuid]);
        $pasBatas = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '200000.00', $k['KategoriKeluar']);
        $disetujui = BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '350000.00', $k['KategoriKeluar'], ['UuidPenyetuju' => $k['Supervisor']->Uuid]);

        $hasil = BantuanKasir::Kirim($this, $k['Token'], [$tanpa, $kasirMenyetujui, $pasBatas, $disetujui])->assertOk()->json('Hasil');

        expect(array_column($hasil, 'Status'))->toBe(['Ditolak', 'Ditolak', 'Diterima', 'Diterima'])
            ->and($hasil[0]['Galat']['Kode'])->toBe('PersetujuanDiperlukan')
            ->and($hasil[0]['Galat']['Detail'])->toBe(['BatasKasKeluar' => '200000.00'])
            ->and($hasil[1]['Galat']['Kode'])->toBe('PenyetujuTidakBerwenang');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(MutasiKas::query()->where('Uuid', $disetujui['Uuid'])->sole()->DisetujuiOleh)->toBe($k['Supervisor']->Id);
    });

    it('BR-06.4 batas mengikuti pengaturan tenant (0 = setiap kas keluar butuh persetujuan). Kas masuk tidak butuh persetujuan', function (): void {
        $k = SiapkanShiftKasUji($this);
        $tenant = Tenant::query()->findOrFail($k['Tenant']->Id);
        $tenant->Pengaturan = array_merge($tenant->Pengaturan ?? [], ['BatasKasKeluar' => '0.00']);
        $tenant->save();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '1000.00', $k['KategoriKeluar']),
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Masuk', '5000000.00', $k['KategoriMasuk']),
        ]))->toBe([['Ditolak', 'PersetujuanDiperlukan'], ['Diterima', null]]);
    });

    it('idempoten & urutan batch: shift dan mutasinya dalam satu batch diproses berurutan. Kirim ulang = Duplikat tanpa jurnal ganda', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir']);
        $mutasi = BantuanKasir::ItemMutasiKas($shift['Uuid'], $k['Kasir'], 'Keluar', '15000.00', $k['KategoriKeluar']);
        $mutasiSebelumShift = BantuanKasir::ItemMutasiKas(BantuanKasir::Uuid(), $k['Kasir'], 'Keluar', '15000.00', $k['KategoriKeluar']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$mutasiSebelumShift, $shift, $mutasi]))
            ->toBe([['Ditolak', 'ShiftTidakDikenal'], ['Diterima', null], ['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$shift, $mutasi]))->toBe([['Duplikat', null], ['Duplikat', null]]);

        $ubahJumlah = $mutasi;
        $ubahJumlah['Data']['Jumlah'] = '99000.00';
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$ubahJumlah]))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(MutasiKas::query()->count())->toBe(1)
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::MutasiKas->value)->count())->toBe(1);
    });

    it('BR-06.2 shift bukan bersama: kasir lain ditolak BukanShiftSendiri, supervisor boleh. Shift bersama: kasir lain boleh', function (): void {
        $k = SiapkanShiftKasUji($this);
        $kasirLain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $kasirLain, 'Keluar', '5000.00', $k['KategoriKeluar']),
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Supervisor'], 'Keluar', '5000.00', $k['KategoriKeluar']),
        ]))->toBe([['Ditolak', 'BukanShiftSendiri'], ['Diterima', null]]);

        $perangkat2 = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Bersama');
        $bersama = BantuanKasir::ItemBukaShift($k['Supervisor'], timpa: ['Bersama' => true]);
        expect(BantuanKasir::KirimRingkas($this, $perangkat2['Token'], [
            $bersama,
            BantuanKasir::ItemMutasiKas($bersama['Uuid'], $kasirLain, 'Keluar', '5000.00', $k['KategoriKeluar']),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
    });

    it('K33: pencatat yang izin/outlet-nya berubah saat offline tetap diterima + PerluTinjauan IzinBerubah (uang sudah bergerak), bukan anggota usaha ditolak', function (): void {
        $k = SiapkanShiftKasUji($this, bersama: true);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $luarOutlet = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        $asing = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        $tanpaIzin = BantuanKasir::ItemMutasiKas($k['UuidShift'], $gudang, 'Keluar', '5000.00', $k['KategoriKeluar']);
        $diLuarOutlet = BantuanKasir::ItemMutasiKas($k['UuidShift'], $luarOutlet, 'Masuk', '7000.00', $k['KategoriMasuk']);
        $bukanAnggota = BantuanKasir::ItemMutasiKas($k['UuidShift'], $asing['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaIzin, $diLuarOutlet, $bukanAnggota]))
            ->toBe([['Diterima', null], ['Diterima', null], ['Ditolak', 'KasirTidakDitemukan']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $a = MutasiKas::query()->where('Uuid', $tanpaIzin['Uuid'])->sole();
        $b = MutasiKas::query()->where('Uuid', $diLuarOutlet['Uuid'])->sole();
        expect($a->PerluTinjauan)->toBeTrue()
            ->and($a->AlasanTinjauan)->toContain('IzinBerubah')->toContain('izin mencatat kas')
            ->and($b->PerluTinjauan)->toBeTrue()
            ->and($b->AlasanTinjauan)->toContain('IzinBerubah')->toContain('tidak lagi terdaftar di outlet')
            ->and($a->IdJurnal)->not->toBeNull()
            ->and(MutasiKas::query()->where('Uuid', $bukanAnggota['Uuid'])->exists())->toBeFalse();
    });

    it('penolakan: shift perangkat lain, kategori salah jenis/nonaktif, jumlah 0, waktu sebelum buka shift. Periode terkunci (§18/F-15) diterima & dibukukan di periode berikutnya', function (): void {
        $k = SiapkanShiftKasUji($this);
        $perangkat2 = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Belakang');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $nonaktif = BantuanKasir::BuatKategori('Parkir motor lama', JenisKategoriKas::Keluar, '6-9000', aktif: false);

        expect(BantuanKasir::KirimRingkas($this, $perangkat2['Token'], [
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar']),
        ]))->toBe([['Ditolak', 'ShiftTidakDikenal']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [
                BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriMasuk']),
                BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $nonaktif),
                BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '0.00', $k['KategoriKeluar']),
                BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar'], ['DicatatPada' => now()->subDays(2)->utc()->toIso8601ZuluString()]),
            ]))->toBe([['Ditolak', 'KategoriTidakValid'], ['Ditolak', 'KategoriNonaktif'], ['Ditolak', 'JumlahTidakValid'], ['Ditolak', 'WaktuTidakValid']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(MutasiKas::query()->count())->toBe(0);

        // §18/F-15: kas keluar offline di periode terkunci tetap diterima; jurnalnya dibukukan di periode terbuka berikutnya.
        BantuanPersediaan::KunciPeriode(now()->format('Y-m'));
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar']),
        ]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'MutasiKas')->sole();
        expect(MutasiKas::query()->count())->toBe(1)
            ->and($jurnal->Tanggal->toDateString())->toBe(now()->startOfMonth()->addMonthNoOverflow()->toDateString())
            ->and($jurnal->TotalDebit)->toBe($jurnal->TotalKredit);
    });

    it('isolasi tenant: kategori dan shift tenant lain tidak bisa dipakai', function (): void {
        $a = SiapkanShiftKasUji($this);
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');
        $shiftB = BantuanKasir::ItemBukaShift($b['Kasir']);

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [
            $shiftB,
            BantuanKasir::ItemMutasiKas($shiftB['Uuid'], $b['Kasir'], 'Keluar', '5000.00', $a['KategoriKeluar']),
            BantuanKasir::ItemMutasiKas($a['UuidShift'], $b['Kasir'], 'Keluar', '5000.00', $b['KategoriKeluar']),
        ]))->toBe([['Diterima', null], ['Ditolak', 'KategoriTidakValid'], ['Ditolak', 'ShiftTidakDikenal']]);
    });

    it('mutasi kas append-only: tidak bisa diubah atau dihapus lewat model', function (): void {
        $k = SiapkanShiftKasUji($this);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemMutasiKas($k['UuidShift'], $k['Kasir'], 'Keluar', '5000.00', $k['KategoriKeluar'])]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $mutasi = MutasiKas::query()->sole();

        expect(fn () => $mutasi->update(['Jumlah' => '1.00']))->toThrow(LogicException::class)
            ->and(fn () => $mutasi->fresh()?->delete())->toThrow(LogicException::class);
    });
});
