<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Layanan\PenutupAbsensiOtomatis;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\AturanKehadiran;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 (D-250): absensi yang lupa absen keluar ditutup otomatis sesuai jam selesai jadwal, supaya jam kerjanya tidak
 * menyambung ke hari berikutnya. Jam keluar yang dicatat adalah batas shift, bukan jam sistem menutupnya.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // Senin 5 Oktober 2026 pukul 06.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 06:00:00', 'Asia/Jakarta'));
});

function WibUtcTutup(string $waktu): CarbonImmutable
{
    return CarbonImmutable::parse($waktu, 'Asia/Jakarta')->utc();
}

/** Karyawan + absensi masuk tanggal [tanggal] pukul [masuk] WIB yang belum keluar, dengan jadwal opsional. */
function SiapkanAbsensiTerbuka(object $tes, string $masuk = '2026-10-05 08:00', ?array $jadwal = ['08:00', '17:00']): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Tutup Otomatis');
    $karyawan = Karyawan::query()->create(['Nama' => 'Rina Lupa Pulang', 'IdOutlet' => $k['Outlet']->Id]);
    $tanggal = substr($masuk, 0, 10);

    if ($jadwal !== null) {
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $tanggal, 'JamMulai' => $jadwal[0], 'JamSelesai' => $jadwal[1]]);
    }

    $absensi = Absensi::query()->create([
        'IdKaryawan' => $karyawan->Id,
        'IdOutlet' => $k['Outlet']->Id,
        'TanggalBisnis' => $tanggal,
        'MasukPada' => WibUtcTutup("{$masuk}:00"),
    ]);

    return [$k, $karyawan, $absensi];
}

describe('batas penutupan (murni)', function (): void {
    $jadwal = fn (string $mulai, string $selesai): Collection => collect([new JadwalKerja(['JamMulai' => $mulai, 'JamSelesai' => $selesai])]);

    it('jam selesai jadwal menjadi batas, dihitung di zona outlet', function () use ($jadwal): void {
        $batas = PenutupAbsensiOtomatis::HitungBatasUtc(WibUtcTutup('2026-10-05 08:00:00'), 25200, $jadwal('08:00', '17:00'));

        expect($batas->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 17:00:00')->toIso8601ZuluString());
    });

    it('shift malam yang melewati tengah malam berakhir keesokan harinya', function () use ($jadwal): void {
        $batas = PenutupAbsensiOtomatis::HitungBatasUtc(WibUtcTutup('2026-10-05 20:00:00'), 25200, $jadwal('20:00', '02:00'));

        expect($batas->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-06 02:00:00')->toIso8601ZuluString());
    });

    it('tanpa jadwal, atau jadwal yang sudah berakhir sebelum masuk, batasnya 23.59 hari masuk', function () use ($jadwal): void {
        $tanpa = PenutupAbsensiOtomatis::HitungBatasUtc(WibUtcTutup('2026-10-05 10:00:00'), 25200, collect());
        $berakhir = PenutupAbsensiOtomatis::HitungBatasUtc(WibUtcTutup('2026-10-05 18:00:00'), 25200, $jadwal('08:00', '17:00'));

        expect($tanpa->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 23:59:00')->toIso8601ZuluString())
            ->and($berakhir->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 23:59:00')->toIso8601ZuluString());
    });

    it('beberapa jadwal pada hari yang sama: yang paling akhir berakhir menjadi batas', function (): void {
        $dua = collect([new JadwalKerja(['JamMulai' => '08:00', 'JamSelesai' => '12:00']), new JadwalKerja(['JamMulai' => '13:00', 'JamSelesai' => '18:00'])]);
        $batas = PenutupAbsensiOtomatis::HitungBatasUtc(WibUtcTutup('2026-10-05 08:00:00'), 25200, $dua);

        expect($batas->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 18:00:00')->toIso8601ZuluString());
    });
});

describe('penutupan absensi yang lupa keluar', function (): void {
    it('ditutup setelah jam selesai + masa tunggu, dengan jam keluar = jam selesai jadwal (bukan jam sistem)', function (): void {
        [$k, , $absensi] = SiapkanAbsensiTerbuka($this);
        $penutup = app(PenutupAbsensiOtomatis::class);

        // 17.00 + 299 menit: masih dalam masa tunggu lembur (bawaan 5 jam).
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 21:59:00')))->toBe(0);
        expect($absensi->refresh()->KeluarPada)->toBeNull();

        // 17.00 + 300 menit: ditutup, jam keluar tetap 17.00.
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 22:00:00')))->toBe(1);
        $absensi->refresh();

        expect($absensi->KeluarPada?->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 17:00:00')->toIso8601ZuluString())
            ->and($absensi->KeluarOtomatis)->toBeTrue()
            ->and($absensi->PerluDicek)->toBeTrue()
            ->and($absensi->CatatanPeriksa)->toContain('ditutup otomatis pukul 17:00');

        $log = LogAudit::query()->where('Peristiwa', 'absensi.keluar-otomatis')->sole();
        expect($log->IdTenant)->toBe($k['Tenant']->Id)
            ->and($log->NilaiLama['KeluarPada'])->toBeNull()
            ->and($log->NilaiBaru['KeluarOtomatis'])->toBeTrue();
    });

    it('aman diulang dan tidak menyentuh absensi yang sudah keluar atau yang masih dalam shift', function (): void {
        [$k, $karyawan, $absensi] = SiapkanAbsensiTerbuka($this);
        $sudah = Absensi::query()->create([
            'IdKaryawan' => $karyawan->Id,
            'IdOutlet' => $k['Outlet']->Id,
            'TanggalBisnis' => '2026-10-04',
            'MasukPada' => WibUtcTutup('2026-10-04 08:00:00'),
            'KeluarPada' => WibUtcTutup('2026-10-04 16:45:00'),
        ]);
        $penutup = app(PenutupAbsensiOtomatis::class);

        // Siang hari, shift masih berjalan.
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 13:00:00')))->toBe(0);
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-06 09:00:00')))->toBe(1);
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-06 09:05:00')))->toBe(0);

        $sudah->refresh();
        expect($sudah->KeluarOtomatis)->toBeFalse()
            ->and($sudah->KeluarPada?->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-04 16:45:00')->toIso8601ZuluString())
            ->and(LogAudit::query()->where('Peristiwa', 'absensi.keluar-otomatis')->count())->toBe(1)
            ->and($absensi->refresh()->KeluarOtomatis)->toBeTrue();
    });

    it('tanpa jadwal: ditutup pukul 23.59 hari masuk, jadi tidak menyambung ke hari berikutnya', function (): void {
        [, , $absensi] = SiapkanAbsensiTerbuka($this, '2026-10-05 08:00', null);

        expect(app(PenutupAbsensiOtomatis::class)->TutupYangLewat(WibUtcTutup('2026-10-06 06:00:00')))->toBe(1);
        $absensi->refresh();

        expect($absensi->KeluarPada?->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 23:59:00')->toIso8601ZuluString())
            ->and($absensi->KeluarOtomatis)->toBeTrue();
    });

    it('shift malam: 20.00-02.00 tidak ditutup sebelum 02.00 + masa tunggu 5 jam, lalu keluarnya 02.00', function (): void {
        [, , $absensi] = SiapkanAbsensiTerbuka($this, '2026-10-05 20:00', ['20:00', '02:00']);
        $penutup = app(PenutupAbsensiOtomatis::class);

        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 23:59:00')))->toBe(0)
            ->and($penutup->TutupYangLewat(WibUtcTutup('2026-10-06 06:59:00')))->toBe(0)
            ->and($penutup->TutupYangLewat(WibUtcTutup('2026-10-06 07:00:00')))->toBe(1);
        expect($absensi->refresh()->KeluarPada?->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-06 02:00:00')->toIso8601ZuluString());
    });

    it('aturan tenant: dimatikan tidak menutup apa pun, masa tunggu mengikuti pengaturan', function (): void {
        [$k, , $absensi] = SiapkanAbsensiTerbuka($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $aturan = AturanKehadiran::query()->create(['TutupOtomatisAktif' => false]);
        $penutup = app(PenutupAbsensiOtomatis::class);

        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-07 10:00:00')))->toBe(0);
        expect($absensi->refresh()->KeluarPada)->toBeNull();

        $aturan->forceFill(['TutupOtomatisAktif' => true, 'TutupOtomatisSetelahMenit' => 0])->save();
        expect($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 16:59:00')))->toBe(0)
            ->and($penutup->TutupYangLewat(WibUtcTutup('2026-10-05 17:00:00')))->toBe(1);
    });

    it('hanya karyawan yang diminta ditutup bila idKaryawan diberikan', function (): void {
        [$k, $karyawan, $absensi] = SiapkanAbsensiTerbuka($this);
        $lain = Karyawan::query()->create(['Nama' => 'Budi Juga Lupa', 'IdOutlet' => $k['Outlet']->Id]);
        $absenLain = Absensi::query()->create(['IdKaryawan' => $lain->Id, 'IdOutlet' => $k['Outlet']->Id, 'TanggalBisnis' => '2026-10-05', 'MasukPada' => WibUtcTutup('2026-10-05 08:00:00')]);

        expect(app(PenutupAbsensiOtomatis::class)->TutupYangLewat(WibUtcTutup('2026-10-06 09:00:00'), $karyawan->Id))->toBe(1);
        expect($absensi->refresh()->KeluarOtomatis)->toBeTrue()
            ->and($absenLain->refresh()->KeluarPada)->toBeNull();
    });
});

describe('dipicu dari jadwal dan halaman absen', function (): void {
    it('perintah karyawan:tutup-absensi-terbuka menutup absensi lupa keluar di semua tenant yang punya', function (): void {
        [, , $absensi] = SiapkanAbsensiTerbuka($this);
        $this->travelTo(WibUtcTutup('2026-10-06 09:00:00'));

        expect(Artisan::call('karyawan:tutup-absensi-terbuka'))->toBe(0);
        expect(Artisan::output())->toContain('1 absensi ditutup otomatis');

        $absensi = Absensi::query()->withoutGlobalScopes()->whereKey($absensi->Id)->sole();
        expect($absensi->KeluarOtomatis)->toBeTrue()
            ->and($absensi->KeluarPada?->toIso8601ZuluString())->toBe(WibUtcTutup('2026-10-05 17:00:00')->toIso8601ZuluString());
    });

    it('membuka halaman Absen menutup catatan kemarin, jadi tidak lagi "sedang bekerja" dan riwayat menandainya', function (): void {
        [$k, , $absensi] = SiapkanAbsensiTerbuka($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $akun = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Karyawan);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Karyawan::query()->whereKey($absensi->IdKaryawan)->update(['IdPengguna' => $akun->Id]);
        $this->travelTo(WibUtcTutup('2026-10-06 08:00:00'));
        BantuanOrganisasi::Masuk($this, $akun, $k['Tenant']->Id);

        $this->get('/kelola/absen')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Absen/Saya')
            ->where('AbsensiTerbuka', null)
            ->where('Riwayat.0.KeluarOtomatis', true)
            ->where('Riwayat.0.PerluDicek', true));
    });

    it('aturan kehadiran menyimpan saklar dan masa tunggu, menolak nilai di luar 0-720 menit, dan nilai yang tidak dikirim tidak berubah', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Aturan Tutup');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $dasar = ['WajibJadwal' => false, 'MasukPalingAwalMenit' => 60, 'ToleransiTerlambatMenit' => 5, 'ToleransiPulangCepatMenit' => 5, 'LemburSetelahMenit' => 30,
            'PengingatShiftAktif' => false, 'PengingatShiftMenitSebelum' => 30, 'PeringatanPengelolaAktif' => false, 'PeringatanPengelolaSetelahMenit' => 15];

        $this->get('/kelola/karyawan/aturan-kehadiran')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Aturan.TutupOtomatisAktif', true)->where('Aturan.TutupOtomatisSetelahMenit', 300));
        $this->put('/kelola/karyawan/aturan-kehadiran', [...$dasar, 'TutupOtomatisSetelahMenit' => 800])->assertSessionHasErrors('TutupOtomatisSetelahMenit');
        $this->put('/kelola/karyawan/aturan-kehadiran', [...$dasar, 'TutupOtomatisAktif' => false, 'TutupOtomatisSetelahMenit' => 45])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $aturan = AturanKehadiran::query()->sole();
        expect($aturan->TutupOtomatisAktif)->toBeFalse()->and($aturan->TutupOtomatisSetelahMenit)->toBe(45);

        $this->put('/kelola/karyawan/aturan-kehadiran', [...$dasar, 'ToleransiTerlambatMenit' => 10])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($aturan->refresh()->TutupOtomatisAktif)->toBeFalse()->and($aturan->TutupOtomatisSetelahMenit)->toBe(45);
    });

    it('rekap absensi dan Kotak Tindakan memuat absensi keluar otomatis untuk diperiksa', function (): void {
        [$k] = SiapkanAbsensiTerbuka($this);
        app(PenutupAbsensiOtomatis::class)->TutupYangLewat(WibUtcTutup('2026-10-06 09:00:00'));
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $json = $this->getJson('/kelola/karyawan/absensi?saring[KeluarOtomatis]=1')->assertOk();
        expect($json->json('Meta.Total'))->toBe(1)
            ->and($json->json('Data.0.KeluarOtomatis'))->toBeTrue()
            ->and($json->json('Data.0.JamKeluar'))->toBe('17:00');

        $this->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Butir', fn ($butir) => collect($butir)->contains(fn ($b) => $b['Kunci'] === 'karyawan.absen-keluar-otomatis' && $b['Jumlah'] === 1)
                && ! collect($butir)->contains(fn ($b) => $b['Kunci'] === 'karyawan.absen-perlu-dicek')));
    });
});
