<?php

declare(strict_types=1);

use App\Domain\Bersama\Tindakan\Kueri\KotakTindakan;
use App\Domain\Organisasi\Aksi\BuatNotifikasiOperasional;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Kueri\PerangkatBermasalah;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-269 perangkat kasir yang perlu perhatian: tidak terhubung saat outlet buka (Kotak Tindakan, notifikasi, Beranda Owner
 * memakai satu sumber) dan antrean kirim yang macet. Perangkat yang sengaja dimatikan saat toko tutup tidak dihitung.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Queue::fake();
});

/** Semua hari buka 08.00 sampai 17.00 (jam lokal outlet). */
function JamTokoSiang(): array
{
    return array_fill_keys(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'], ['Buka' => '08:00', 'Tutup' => '17:00']);
}

/** @return array<string, mixed> tenant dengan satu perangkat aktif yang terakhir terhubung `$menitLalu` menit sebelum 11/11/2026 08.40 WIB. */
function SiapkanPerangkat(object $tes, int $menitLalu = 90): array
{
    $tes->travelTo(CarbonImmutable::parse('2026-11-11 08:40:00', 'Asia/Jakarta'));
    $k = BantuanPenjualan::Siapkan($tes);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $tes->travelTo(CarbonImmutable::parse('2026-11-11 08:40:00', 'Asia/Jakarta'));
    Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['TerakhirAktifPada' => now()->subMinutes($menitLalu), 'JumlahOutboxTertunda' => 0, 'OutboxTertundaSejak' => null]);

    return $k;
}

function AturJamOutlet(Outlet $outlet, ?array $jam): void
{
    $outlet->JamOperasional = $jam;
    $outlet->save();
}

function ButirPerangkat(array $k, ?object $pengguna = null): array
{
    $konteks = app(KonteksTindakanPengguna::class)->Buat($k['Tenant']->Id, ($pengguna ?? $k['Pemilik'])->Id, CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));

    return collect(app(KotakTindakan::class)->Ambil($konteks))->filter(fn ($b): bool => str_starts_with($b->kunci, 'perangkat.'))->keyBy('kunci')->all();
}

describe('perangkat tidak terhubung', function (): void {
    it('tanpa jam operasional outlet dianggap selalu buka: perangkat lebih dari 30 menit tidak terhubung dihitung, yang baru saja terhubung tidak', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 45);
        $kueri = app(PerangkatBermasalah::class);

        expect($kueri->AmbilTidakTerhubung(null))->toHaveCount(1)
            ->and($kueri->AmbilTidakTerhubung(null)[0]['Kode'])->toBe($k['Perangkat']->Kode);

        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['TerakhirAktifPada' => now()->subMinutes(10)]);
        expect($kueri->AmbilTidakTerhubung(null))->toBe([]);
    });

    it('dengan jam operasional: di luar jam buka, atau baru buka kurang dari 30 menit, tidak dihitung', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 600);
        AturJamOutlet($k['Outlet'], JamTokoSiang());
        $kueri = app(PerangkatBermasalah::class);
        $wib = fn (string $jam): CarbonImmutable => CarbonImmutable::parse("2026-11-11 {$jam}", 'Asia/Jakarta');

        // Toko tutup: perangkat sengaja dimatikan, tidak dikabarkan.
        expect($kueri->AmbilTidakTerhubung(null, $wib('22:00')))->toBe([])
            ->and($kueri->AmbilTidakTerhubung(null, $wib('07:30')))->toBe([])
            // Baru buka 10 menit: perangkat belum sempat dinyalakan.
            ->and($kueri->AmbilTidakTerhubung(null, $wib('08:10')))->toBe([])
            // Sudah buka lebih dari 30 menit dan perangkat masih belum terhubung.
            ->and($kueri->AmbilTidakTerhubung(null, $wib('08:40')))->toHaveCount(1)
            ->and($kueri->AmbilTidakTerhubung(null, $wib('16:59')))->toHaveCount(1);
    });

    it('jam zona waktu outlet dipakai, bukan zona server', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 600);
        $k['Outlet']->ZonaWaktu = 'Asia/Jayapura';
        AturJamOutlet($k['Outlet'], JamTokoSiang());
        $kueri = app(PerangkatBermasalah::class);

        // 08.40 WIB = 10.40 WIT: toko sudah buka.
        expect($kueri->AmbilTidakTerhubung(null, CarbonImmutable::parse('2026-11-11 08:40', 'Asia/Jakarta')))->toHaveCount(1)
            // 15.30 WIB = 17.30 WIT: sudah tutup di Papua.
            ->and($kueri->AmbilTidakTerhubung(null, CarbonImmutable::parse('2026-11-11 15:30', 'Asia/Jakarta')))->toBe([]);
    });

    it('perangkat yang dicabut tidak dihitung', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['DicabutPada' => now()]);

        expect(app(PerangkatBermasalah::class)->AmbilTidakTerhubung(null))->toBe([]);
    });
});

describe('antrean kirim macet', function (): void {
    it('antrean tidak kosong lebih lama dari batas jam dihitung, walau outlet sedang tutup', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 5);
        AturJamOutlet($k['Outlet'], JamTokoSiang());
        $kueri = app(PerangkatBermasalah::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 22:00', 'Asia/Jakarta'));

        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 4, 'OutboxTertundaSejak' => now()->subHours(3)]);
        $macet = $kueri->AmbilAntreanMacet(null);
        expect($macet)->toHaveCount(1)
            ->and($macet[0]['JumlahOutbox'])->toBe(4);

        // Belum melewati batas (config operasional.BatasOutboxMacetJam = 2).
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['OutboxTertundaSejak' => now()->subHour()]);
        expect($kueri->AmbilAntreanMacet(null))->toBe([]);

        // Antrean sudah kosong.
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 0, 'OutboxTertundaSejak' => null]);
        expect($kueri->AmbilAntreanMacet(null))->toBe([]);
    });

    it('dibatasi outlet yang boleh diakses', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 5);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 2, 'OutboxTertundaSejak' => now()->subHours(5)]);
        $kueri = app(PerangkatBermasalah::class);

        expect($kueri->AmbilAntreanMacet([$k['Outlet']->Id]))->toHaveCount(1)
            ->and($kueri->AmbilAntreanMacet([]))->toBe([]);
    });
});

describe('Kotak Tindakan', function (): void {
    it('memuat butir tidak terhubung dan antrean macet dengan rincian, tingkat, dan tautan', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 3, 'OutboxTertundaSejak' => now()->subHours(4)]);

        $butir = ButirPerangkat($k);

        expect($butir)->toHaveKeys(['perangkat.tidak-terhubung', 'perangkat.antrean-macet']);
        $tidak = $butir['perangkat.tidak-terhubung'];
        $macet = $butir['perangkat.antrean-macet'];
        expect($tidak->jumlah)->toBe(1)
            ->and($tidak->tingkat->value)->toBe('Perhatian')
            ->and($tidak->tautan)->toBe('/kelola/perangkat')
            ->and($tidak->rincian[0]->keterangan)->toContain('Terakhir terhubung 90 menit lalu')
            ->and($macet->jumlah)->toBe(1)
            ->and($macet->tingkat->value)->toBe('Penting')
            ->and($macet->rincian[0]->keterangan)->toContain('3 data tertunda')
            ->and($macet->keterangan)->toContain('Jangan hapus data atau pasang ulang aplikasi');
    });

    it('selesai sendiri saat perangkat terhubung lagi dan antreannya terkirim', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 3, 'OutboxTertundaSejak' => now()->subHours(4)]);
        expect(ButirPerangkat($k))->toHaveCount(2);

        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['TerakhirAktifPada' => now(), 'JumlahOutboxTertunda' => 0, 'OutboxTertundaSejak' => null]);

        expect(ButirPerangkat($k))->toBe([]);
    });

    it('butir tidak terhubung hilang di luar jam buka outlet, tetapi antrean macet tetap', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 600);
        AturJamOutlet($k['Outlet'], JamTokoSiang());
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 2, 'OutboxTertundaSejak' => now()->subHours(3)]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 21:00', 'Asia/Jakarta'));

        expect(array_keys(ButirPerangkat($k)))->toBe(['perangkat.antrean-macet']);
    });

    it('kasir yang tidak berizin melihat perangkat tidak melihat butirnya', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

        expect(ButirPerangkat($k, $kasir))->toBe([]);
    });
});

describe('notifikasi operasional', function (): void {
    it('perangkat tidak terhubung dan antrean macet dikabarkan sekali per hari, antrean macet dengan jenis sendiri', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 5, 'OutboxTertundaSejak' => now()->subHours(3)]);
        $buat = app(BuatNotifikasiOperasional::class);
        $hari = CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta');

        $buat->Jalankan($k['Tenant']->Id, $hari);
        $buat->Jalankan($k['Tenant']->Id, $hari);

        $offline = NotifikasiPengguna::query()->where('IdPengguna', $k['Pemilik']->Id)->where('Jenis', 'PerangkatOffline')->sole();
        $macet = NotifikasiPengguna::query()->where('IdPengguna', $k['Pemilik']->Id)->where('Jenis', 'AntreanKasirMacet')->sole();
        expect($offline->Judul)->toBe('Perangkat kasir tidak terhubung')
            ->and($offline->Isi)->toContain('1 perangkat tidak terhubung ke server lebih dari 30 menit saat outlet buka')
            ->and($macet->Judul)->toBe('Data kasir belum terkirim')
            ->and($macet->Isi)->toContain('1 perangkat menyimpan transaksi')
            ->and($macet->Data)->toMatchArray(['Href' => '/kelola/perangkat']);
    });

    it('perangkat yang dimatikan saat toko tutup tidak dikabarkan, antrean macet tetap', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 600);
        AturJamOutlet($k['Outlet'], JamTokoSiang());
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 1, 'OutboxTertundaSejak' => now()->subHours(3)]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 22:10', 'Asia/Jakarta'));

        app(BuatNotifikasiOperasional::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));

        expect(NotifikasiPengguna::query()->where('IdPengguna', $k['Pemilik']->Id)->where('Jenis', 'PerangkatOffline')->exists())->toBeFalse()
            ->and(NotifikasiPengguna::query()->where('IdPengguna', $k['Pemilik']->Id)->where('Jenis', 'AntreanKasirMacet')->exists())->toBeTrue();
    });

    it('tanpa izin melihat perangkat tidak ada kabar perangkat', function (): void {
        $k = SiapkanPerangkat($this, menitLalu: 90);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 1, 'OutboxTertundaSejak' => now()->subHours(3)]);

        app(BuatNotifikasiOperasional::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta'));

        expect(NotifikasiPengguna::query()->where('IdPengguna', $kasir->Id)->whereIn('Jenis', ['PerangkatOffline', 'AntreanKasirMacet'])->exists())->toBeFalse();
    });
});
