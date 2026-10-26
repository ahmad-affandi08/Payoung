<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use App\Domain\Dukungan\Layanan\PenghitungSlaTiket;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Model\TiketDukunganPesan;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganDieskalasi;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Referensi\Enum\JenisHariLibur;
use App\Domain\Referensi\Model\HariLibur;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/*
 * P-09: SLA respons pertama dihitung dalam jam kerja (Senin–Jumat 08.00–17.00 WIB, hari libur dilewati; tiket Mendesak
 * paket berbayar jam kalender) dan eskalasi L1 → L2 → Teknis, otomatis saat SLA terlewati dan manual oleh petugas.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00', 'Asia/Jakarta'));
    Mail::fake();
    Storage::fake('local');
});

function WibSla(string $waktu): Carbon
{
    return Carbon::parse($waktu, 'Asia/Jakarta');
}

function MasukSebagaiPetugasSla(TestCase $tes, PeranPengelolaBawaan $peran): TestCase
{
    return $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

describe('SLA jam kerja', function (): void {
    it('menambah jam kerja: dalam hari yang sama, melewati malam, akhir pekan, dan sebelum jam buka', function (): void {
        $sla = app(PenghitungSlaTiket::class);
        $hitung = fn (string $mulai, int $jam): string => $sla->TambahJamKerja(WibSla($mulai)->utc(), $jam)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i');

        expect($hitung('2026-09-24 09:00', 8))->toBe('2026-09-24 17:00')
            ->and($hitung('2026-09-24 09:00', 9))->toBe('2026-09-25 09:00')
            ->and($hitung('2026-09-25 16:00', 4))->toBe('2026-09-28 11:00', 'Jumat 16.00 + 4 jam kerja: 1 jam Jumat, 3 jam Senin')
            ->and($hitung('2026-09-26 10:00', 2))->toBe('2026-09-28 10:00', 'Dibuat Sabtu dihitung sejak Senin 08.00')
            ->and($hitung('2026-09-24 06:00', 4))->toBe('2026-09-24 12:00', 'Sebelum jam buka dihitung sejak 08.00')
            ->and($hitung('2026-09-24 19:30', 4))->toBe('2026-09-25 12:00', 'Setelah jam tutup dihitung sejak hari kerja berikutnya')
            ->and($hitung('2026-09-24 09:00', 18))->toBe('2026-09-28 09:00', '2 hari kerja (Gratis): 8 jam Kamis + 9 jam Jumat + 1 jam Senin');
    });

    it('hari libur terbit dilewati. Libur yang dibatalkan tidak', function (): void {
        HariLibur::query()->create(['Tanggal' => '2026-09-28', 'Nama' => 'Cuti bersama uji', 'Jenis' => JenisHariLibur::CutiBersama, 'Status' => StatusDataMaster::Terbit]);
        HariLibur::query()->create(['Tanggal' => '2026-09-29', 'Nama' => 'Libur batal', 'Jenis' => JenisHariLibur::Nasional, 'Status' => StatusDataMaster::Dibatalkan, 'DibatalkanPada' => now()]);
        $hasil = app(PenghitungSlaTiket::class)->TambahJamKerja(WibSla('2026-09-25 16:00')->utc(), 4)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i');

        expect($hasil)->toBe('2026-09-29 11:00', 'Senin libur dilewati; Selasa yang liburnya dibatalkan tetap hari kerja');
    });

    it('hanya tiket Mendesak paket berbayar yang dihitung jam kalender', function (): void {
        $sla = app(PenghitungSlaTiket::class);

        expect($sla->CekKalender('PRO', PrioritasTiketDukungan::Mendesak))->toBeTrue()
            ->and($sla->CekKalender('BISNIS', PrioritasTiketDukungan::Mendesak))->toBeTrue()
            ->and($sla->CekKalender('GRATIS', PrioritasTiketDukungan::Mendesak))->toBeFalse()
            ->and($sla->CekKalender('PRO', PrioritasTiketDukungan::Tinggi))->toBeFalse()
            ->and($sla->CekKalender(null, PrioritasTiketDukungan::Mendesak))->toBeFalse()
            ->and($sla->HitungJam('GRATIS', PrioritasTiketDukungan::Normal))->toBe(18)
            ->and($sla->HitungJam('STARTER', PrioritasTiketDukungan::Normal))->toBe(9);
    });

    it('tiket baru menyimpan jam SLA, mode, dan batas sesuai waktu pembuatan', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko SLA Jam Kerja', 'pemilik@slajamkerja.id');

        // Kamis 09.00, paket PRO: Normal 8 jam kerja → 17.00; Mendesak 4 jam kalender → 13.00.
        $normal = BantuanDukungan::BuatTiket($tenant, $pemilik);
        $mendesak = BantuanDukungan::BuatTiket($tenant, $pemilik, prioritas: PrioritasTiketDukungan::Mendesak);
        expect($normal->SlaJamKalender)->toBeFalse()
            ->and($normal->BatasSlaPada->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-24 17:00')
            ->and($mendesak->SlaJamKalender)->toBeTrue()
            ->and($mendesak->BatasSlaPada->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-24 13:00');

        // Jumat 16.00: Normal 8 jam kerja → Senin 15.00 (1 jam Jumat + 7 jam Senin); Mendesak tetap 4 jam kalender → 20.00.
        $this->travelTo(WibSla('2026-09-25 16:00'));
        $jumat = BantuanDukungan::BuatTiket($tenant, $pemilik);
        $jumatMendesak = BantuanDukungan::BuatTiket($tenant, $pemilik, prioritas: PrioritasTiketDukungan::Mendesak);
        expect($jumat->BatasSlaPada->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-28 15:00')
            ->and($jumatMendesak->BatasSlaPada->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-25 20:00');
    });

    it('tiket Gratis Mendesak tetap jam kerja (18 jam = 2 hari kerja)', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Gratis SLA', 'pemilik@gratissla.id', 'GRATIS');
        $tiket = BantuanDukungan::BuatTiket($tenant, $pemilik, prioritas: PrioritasTiketDukungan::Mendesak);

        expect($tiket->JamSla)->toBe(18)
            ->and($tiket->SlaJamKalender)->toBeFalse()
            ->and($tiket->BatasSlaPada->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-28 09:00');
    });
});

describe('Eskalasi otomatis karena SLA', function (): void {
    it('SLA terlewati naik ke L2 (Dukungan + Super Admin diberi tahu), lalu ke Teknis setelah 4 jam kerja tanpa respons. Tidak naik dua kali', function (): void {
        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Eskalasi Otomatis', 'pemilik@eskalasi.id');
        $tiket = BantuanDukungan::BuatTiket($tenant, $pemilik);

        // Sebelum batas SLA (Kamis 17.00): belum ada yang naik dan tidak ada akses lintas tenant yang dicatat.
        $this->travelTo(WibSla('2026-09-24 16:59'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('0 tiket naik ke L2, 0 tiket naik ke Teknis')->assertSuccessful();
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->count())->toBe(0);

        // Lewat batas SLA: L1 → L2.
        $this->travelTo(WibSla('2026-09-24 17:30'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('1 tiket naik ke L2, 0 tiket naik ke Teknis')->assertSuccessful();
        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket = $tiket->refresh();
        expect($tiket->TingkatEskalasi)->toBe(TingkatEskalasiTiket::L2)->and($tiket->DieskalasiPada)->not->toBeNull();
        Mail::assertSent(TiketDukunganDieskalasi::class, 2);
        Mail::assertSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($dukungan->Email) && $surel->tingkat === 'L2 | Dukungan senior');
        Mail::assertSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($superAdmin->Email));
        Mail::assertNotSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($teknis->Email));

        // Belum 4 jam kerja sejak batas SLA (Jumat 08.00 + 4 jam = 12.00): tidak naik lagi.
        $this->travelTo(WibSla('2026-09-25 11:59'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('0 tiket naik ke L2, 0 tiket naik ke Teknis')->assertSuccessful();
        expect($tiket->refresh()->TingkatEskalasi)->toBe(TingkatEskalasiTiket::L2);

        $this->travelTo(WibSla('2026-09-25 12:01'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('0 tiket naik ke L2, 1 tiket naik ke Teknis')->assertSuccessful();
        expect($tiket->refresh()->TingkatEskalasi)->toBe(TingkatEskalasiTiket::Teknis);
        Mail::assertSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($teknis->Email) && $surel->tingkat === 'Teknis');
        Mail::assertNotSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($superAdmin->Email) && $surel->tingkat === 'Teknis');

        // Di Teknis tidak naik lagi; eskalasi tercatat sebagai catatan internal (tidak terlihat tenant) dan di audit.
        $this->travelTo(WibSla('2026-09-28 10:00'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('0 tiket naik ke L2, 0 tiket naik ke Teknis')->assertSuccessful();
        expect(TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->where('CatatanInternal', true)->count())->toBe(2)
            ->and(TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->where('CatatanInternal', false)->count())->toBe(1)
            ->and(LogAuditPengelola::query()->where('Aksi', 'dukungan.tiket.eskalasi-otomatis')->count())->toBe(2);
    });

    it('tiket yang sudah direspons atau sudah selesai tidak dieskalasi. Tiket mendesak memakai jam kalender', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Tanpa Eskalasi', 'pemilik@tanpaeskalasi.id');
        $direspons = BantuanDukungan::BuatTiket($tenant, $pemilik, judul: 'Sudah direspons tim');
        $selesai = BantuanDukungan::BuatTiket($tenant, $pemilik, judul: 'Sudah selesai');
        BantuanDukungan::BuatTiket($tenant, $pemilik, judul: 'Mendesak jam kalender', prioritas: PrioritasTiketDukungan::Mendesak);
        app(KonteksTenant::class)->Atur($tenant->Id);
        $direspons->forceFill(['ResponsPertamaPada' => now()->addMinutes(30)])->save();
        $selesai->forceFill(['Status' => 'Ditangani'])->save();
        $selesai->forceFill(['Status' => 'Selesai', 'DiselesaikanPada' => now()])->save();

        // Kamis 13.30: batas tiket mendesak (13.00, kalender) terlewati; tiket lain batasnya 17.00.
        $this->travelTo(WibSla('2026-09-24 13:30'));
        $this->artisan('dukungan:eskalasikan-tiket')->expectsOutputToContain('1 tiket naik ke L2')->assertSuccessful();

        expect(TiketDukungan::query()->orderBy('Id')->get()->map(fn (TiketDukungan $t): string => $t->TingkatEskalasi->value)->all())->toBe(['L1', 'L1', 'L2']);
    });
});

describe('Eskalasi manual', function (): void {
    it('petugas Dukungan menaikkan tiket ke L2 lalu Teknis dengan alasan. Tidak bisa turun atau mengulang. Tim tujuan diberi tahu', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiPetugasSla($this, PeranPengelolaBawaan::Dukungan);
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Eskalasi Manual', 'pemilik@eskalasimanual.id');
        $tiket = BantuanDukungan::BuatTiket($tenant, $pemilik);
        $alamat = BantuanPengelola::Url("/dukungan/tiket/{$tiket->Uuid}/eskalasi");

        $this->post($alamat, ['Tingkat' => 'L2', 'Alasan' => ''])->assertSessionHasErrors('Alasan');
        $this->post($alamat, ['Tingkat' => 'L1', 'Alasan' => 'Turun tingkat'])->assertSessionHasErrors('Tingkat');
        $this->post($alamat, ['Tingkat' => 'L2', 'Alasan' => 'Perlu keputusan senior soal refund'])->assertSessionHasNoErrors()->assertSessionHas('Kilat');
        app(KonteksTenant::class)->Atur($tenant->Id);
        expect($tiket->refresh()->TingkatEskalasi)->toBe(TingkatEskalasiTiket::L2);

        $this->post($alamat, ['Tingkat' => 'L2', 'Alasan' => 'Sama saja tingkatnya'])->assertSessionHasErrors();
        $this->post($alamat, ['Tingkat' => 'Teknis', 'Alasan' => 'Printer macet di semua outlet'])->assertSessionHasNoErrors();
        expect($tiket->refresh()->TingkatEskalasi)->toBe(TingkatEskalasiTiket::Teknis);
        Mail::assertSent(TiketDukunganDieskalasi::class, fn (TiketDukunganDieskalasi $surel) => $surel->hasTo($teknis->Email) && $surel->alasan === 'Printer macet di semua outlet');
        expect(LogAuditPengelola::query()->where('Aksi', 'dukungan.tiket.eskalasi')->count())->toBe(2);

        $this->post($alamat, ['Tingkat' => 'Teknis', 'Alasan' => 'Sudah di Teknis'])->assertSessionHasErrors();
    });

    it('tiket yang sudah ditutup tidak bisa dieskalasi dan peran tanpa izin menangani ditolak', function (): void {
        ['Tenant' => $tenant, 'Pengguna' => $pemilik] = BantuanDukungan::BuatTenant('Toko Eskalasi Ditolak', 'pemilik@eskalasiditolak.id');
        $tiket = BantuanDukungan::BuatTiket($tenant, $pemilik);
        $alamat = BantuanPengelola::Url("/dukungan/tiket/{$tiket->Uuid}/eskalasi");

        MasukSebagaiPetugasSla($this, PeranPengelolaBawaan::Analis);
        $this->post($alamat, ['Tingkat' => 'L2', 'Alasan' => 'Perlu keputusan senior'])->assertForbidden();

        MasukSebagaiPetugasSla($this, PeranPengelolaBawaan::Dukungan);
        app(KonteksTenant::class)->Atur($tenant->Id);
        $tiket->forceFill(['Status' => 'Ditutup', 'DitutupPada' => now()])->save();
        $this->post($alamat, ['Tingkat' => 'L2', 'Alasan' => 'Perlu keputusan senior'])->assertSessionHasErrors();
    });
});
