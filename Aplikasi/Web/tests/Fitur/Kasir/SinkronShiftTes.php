<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-06 buka shift lewat sinkron (POST /api/pos/v1/sinkron/kirim)', function (): void {
    it('BR-06.3 shift yang dibuka offline diterima: kas awal, pecahan, tanggal bisnis, riwayat & audit tercatat', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $item = BantuanKasir::ItemBukaShift($k['Kasir'], '750000.00', [
            'DibukaPada' => '2026-09-24T01:15:00Z',
            'Pecahan' => [['Nominal' => '100000', 'Jumlah' => 5], ['Nominal' => '50000', 'Jumlah' => 4], ['Nominal' => '2000', 'Jumlah' => 25]],
        ]);

        $respons = BantuanKasir::Kirim($this, $k['Token'], [$item])->assertOk();

        expect($respons->json('Hasil'))->toBe([['Uuid' => $item['Uuid'], 'Jenis' => 'Shift.Buka', 'Status' => 'Diterima', 'Galat' => null]])
            ->and($respons->json('WaktuServer'))->toBeString();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->where('Uuid', $item['Uuid'])->sole();
        expect($shift->Status)->toBe(StatusShift::Terbuka)
            ->and($shift->KasAwal)->toBe('750000.00')
            ->and($shift->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($shift->IdOutlet)->toBe($k['Outlet']->Id)
            ->and($shift->DibukaOleh)->toBe($k['Kasir']->Id)
            ->and($shift->TanggalBisnis->toDateString())->toBe('2026-09-24')
            ->and($shift->PecahanKasAwal)->toHaveCount(3)
            ->and($shift->PerluTinjauan)->toBeFalse()
            ->and(LogAudit::query()->where('Peristiwa', 'shift.buka')->where('IdPengguna', $k['Kasir']->Id)->count())->toBe(1);
    });

    it('idempoten: Uuid yang sama dikirim ulang = Duplikat tanpa shift ganda; Uuid sama dengan data lain = UuidSudahDipakai', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $item = BantuanKasir::ItemBukaShift($k['Kasir']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

        $lain = BantuanKasir::ItemBukaShift($k['Kasir'], '1.00', uuid: $item['Uuid']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lain]))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Shift::query()->count())->toBe(1);
    });

    it('BR-06.1 satu perangkat satu shift terbuka: shift kedua di perangkat yang sama ditolak ShiftSudahTerbuka', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $pertama = BantuanKasir::ItemBukaShift($k['Kasir']);
        $kedua = BantuanKasir::ItemBukaShift($k['Supervisor']);

        $hasil = BantuanKasir::Kirim($this, $k['Token'], [$pertama, $kedua])->assertOk()->json('Hasil');

        expect($hasil[0]['Status'])->toBe('Diterima')
            ->and($hasil[1]['Status'])->toBe('Ditolak')
            ->and($hasil[1]['Galat']['Kode'])->toBe('ShiftSudahTerbuka')
            ->and($hasil[1]['Galat']['Detail'])->toBe(['UuidShift' => $pertama['Uuid']]);
    });

    it('BR-06.1 kasir yang sudah punya shift di perangkat lain (offline) tetap diterima tetapi ditandai PerluTinjauan. Shift bersama (BR-06.2) tidak ditandai', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $perangkat2 = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Belakang');
        $perangkat3 = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Teras');

        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'])]);
        $konflik = BantuanKasir::ItemBukaShift($k['Kasir']);
        $bersama = BantuanKasir::ItemBukaShift($k['Kasir'], timpa: ['Bersama' => true]);

        expect(BantuanKasir::KirimRingkas($this, $perangkat2['Token'], [$konflik]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $perangkat3['Token'], [$bersama]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shiftKonflik = Shift::query()->where('Uuid', $konflik['Uuid'])->sole();
        expect($shiftKonflik->PerluTinjauan)->toBeTrue()
            ->and($shiftKonflik->AlasanTinjauan)->toContain('BR-06.1')
            ->and(Shift::query()->where('Uuid', $bersama['Uuid'])->sole()->PerluTinjauan)->toBeFalse();
    });

    it('validasi: pecahan tidak sama dengan kas awal, kas awal minus/format salah, waktu di masa depan, jenis tidak dikenal', function (): void {
        $k = BantuanKasir::Siapkan($this);

        $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanKasir::ItemBukaShift($k['Kasir'], '100000.00', ['Pecahan' => [['Nominal' => '50000', 'Jumlah' => 1]]]),
            BantuanKasir::ItemBukaShift($k['Kasir'], '-5000.00'),
            BantuanKasir::ItemBukaShift($k['Kasir'], '1.5e5'),
            BantuanKasir::ItemBukaShift($k['Kasir'], '100000.00', ['DibukaPada' => now()->addHour()->utc()->toIso8601ZuluString()]),
            BantuanKasir::ItemBukaShift($k['Kasir'], '100000.00', ['DibukaPada' => '2026-09-24 08:00:00']),
            ['Jenis' => 'Penjualan.Simpan', 'Uuid' => BantuanKasir::Uuid(), 'Data' => []],
        ]);

        expect($hasil)->toBe([
            ['Ditolak', 'PecahanTidakSesuai'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'DataTidakValid'],
            ['Ditolak', 'JenisItemTidakDikenal'],
        ]);
    });

    it('izin & akses: pengguna tanpa penjualan.buat ditolak TanpaIzin. Pengguna di luar outlet perangkat ditolak KasirTidakDitemukan', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $luarOutlet = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanKasir::ItemBukaShift($gudang),
            BantuanKasir::ItemBukaShift($luarOutlet),
        ]))->toBe([['Ditolak', 'TanpaIzin'], ['Ditolak', 'KasirTidakDitemukan']]);
    });

    it('isolasi tenant: kasir tenant lain ditolak; Uuid shift tenant lain tidak bisa dipakai ulang dan tidak bocor', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $itemA = BantuanKasir::ItemBukaShift($a['Kasir']);
        BantuanKasir::KirimRingkas($this, $a['Token'], [$itemA]);

        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [BantuanKasir::ItemBukaShift($a['Kasir'])]))->toBe([['Ditolak', 'KasirTidakDitemukan']])
            ->and(BantuanKasir::KirimRingkas($this, $b['Token'], [BantuanKasir::ItemBukaShift($b['Kasir'], uuid: $itemA['Uuid'])]))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(Shift::query()->count())->toBe(0);
    });

    it('kontrak: tanpa token 401, batch kosong atau > 50 item atau Uuid ganda dalam batch 422', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $item = BantuanKasir::ItemBukaShift($k['Kasir']);

        $this->postJson('/api/pos/v1/sinkron/kirim', ['Item' => [$item]])->assertUnauthorized();
        BantuanKasir::Kirim($this, $k['Token'], [])->assertUnprocessable();
        BantuanKasir::Kirim($this, $k['Token'], array_fill(0, 51, $item))->assertUnprocessable();
        BantuanKasir::Kirim($this, $k['Token'], [$item, $item])->assertUnprocessable();
    });

    it('data pembukaan shift tidak bisa diubah dan shift tidak bisa dihapus (model)', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'])]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->sole();

        expect(fn () => $shift->update(['KasAwal' => '1.00']))->toThrow(LogicException::class)
            ->and(fn () => $shift->fresh()?->delete())->toThrow(LogicException::class);
    });
});
