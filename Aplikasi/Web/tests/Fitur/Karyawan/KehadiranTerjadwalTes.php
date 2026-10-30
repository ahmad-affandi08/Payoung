<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\AturanKehadiran;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\RekapGaji;
use App\Domain\Karyawan\Model\RekapGajiBaris;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 bagian 5 (D-44): jadwal kerja terintegrasi penuh dengan absensi. Rekap absensi menilai terlambat, pulang cepat,
 * dan lembur; rekap gaji menghitung lembur dan potongan terlambat/tidak masuk dari absensi vs jadwal; aturan kehadiran
 * tenant; pengingat shift dan peringatan terlambat/belum masuk lewat WhatsApp.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8101']])]);
});

/** Waktu dinding WIB (outlet Asia/Jakarta) ke UTC untuk kolom MasukPada/KeluarPada. */
function WibKeUtc(string $waktu): CarbonImmutable
{
    return CarbonImmutable::parse($waktu, 'Asia/Jakarta')->utc();
}

function CatatAbsenUji(Karyawan $karyawan, int $idOutlet, string $tanggal, string $masuk, ?string $keluar): Absensi
{
    return Absensi::query()->create([
        'IdKaryawan' => $karyawan->Id,
        'IdOutlet' => $idOutlet,
        'TanggalBisnis' => $tanggal,
        'MasukPada' => WibKeUtc("{$tanggal} {$masuk}:00"),
        'KeluarPada' => $keluar === null ? null : WibKeUtc("{$tanggal} {$keluar}:00"),
    ]);
}

function PesanKehadiranTerkirim(?string $nomor = null): Collection
{
    return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
        ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send') && ($nomor === null || $r['target'] === $nomor))
        ->values();
}

describe('rekap absensi menilai jadwal', function (): void {
    it('terlambat, pulang cepat, dan lembur dihitung dari jadwal dengan aturan tenant', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Kehadiran Solo');
        $a = Karyawan::query()->create(['Nama' => 'Ani Terlambat', 'IdOutlet' => $k['Outlet']->Id]);
        $b = Karyawan::query()->create(['Nama' => 'Budi Lembur', 'IdOutlet' => $k['Outlet']->Id]);
        $c = Karyawan::query()->create(['Nama' => 'Citra Tepat', 'IdOutlet' => $k['Outlet']->Id]);
        foreach ([$a, $b, $c] as $orang) {
            JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }
        CatatAbsenUji($a, $k['Outlet']->Id, '2026-10-05', '08:20', '15:30');
        CatatAbsenUji($b, $k['Outlet']->Id, '2026-10-05', '07:58', '17:10');
        CatatAbsenUji($c, $k['Outlet']->Id, '2026-10-05', '08:00', '16:20');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $baris = collect($this->getJson('/kelola/karyawan/absensi?saring[TanggalBisnis]=2026-10-05..2026-10-05')->assertOk()->json('Data'))->keyBy('NamaKaryawan');
        expect($baris['Ani Terlambat']['TerlambatMenit'])->toBe(20)
            ->and($baris['Ani Terlambat']['PulangCepatMenit'])->toBe(30)
            ->and($baris['Ani Terlambat']['Status'])->toBe('Terlambat')
            ->and($baris['Budi Lembur']['LemburMenit'])->toBe(70)
            ->and($baris['Budi Lembur']['Status'])->toBe('Lembur')
            // 20 menit setelah jam selesai < ambang lembur 30 menit.
            ->and($baris['Citra Tepat']['LemburMenit'])->toBe(0)
            ->and($baris['Citra Tepat']['Status'])->toBe('TepatWaktu');

        // Aturan tenant mengubah penilaian: toleransi terlambat 30 menit membebaskan Ani; ambang lembur 15 menit menghitung Citra.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        AturanKehadiran::query()->create(['ToleransiTerlambatMenit' => 30, 'LemburSetelahMenit' => 15]);
        $baris = collect($this->getJson('/kelola/karyawan/absensi?saring[TanggalBisnis]=2026-10-05..2026-10-05')->json('Data'))->keyBy('NamaKaryawan');
        expect($baris['Ani Terlambat']['TerlambatMenit'])->toBe(0)
            ->and($baris['Ani Terlambat']['Status'])->toBe('PulangCepat')
            ->and($baris['Citra Tepat']['LemburMenit'])->toBe(20);
    });
});

describe('aturan kehadiran', function (): void {
    it('Owner menyimpan aturan: nilai divalidasi, perubahan diaudit, Kasir tidak boleh mengubah', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Aturan Kehadiran');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $dasar = ['WajibJadwal' => true, 'MasukPalingAwalMenit' => 45, 'ToleransiTerlambatMenit' => 10, 'ToleransiPulangCepatMenit' => 10, 'LemburSetelahMenit' => 60,
            'PengingatShiftAktif' => true, 'PengingatShiftMenitSebelum' => 30, 'PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 20];

        $this->get('/kelola/karyawan/aturan-kehadiran')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Karyawan/AturanKehadiran')
            ->where('Aturan.WajibJadwal', false)->where('Aturan.ToleransiTerlambatMenit', 5)->where('Izin.Kelola', true));
        $this->put('/kelola/karyawan/aturan-kehadiran', [...$dasar, 'MasukPalingAwalMenit' => 600])->assertSessionHasErrors(['MasukPalingAwalMenit']);
        $this->put('/kelola/karyawan/aturan-kehadiran', [...$dasar, 'PeringatanPengelolaSetelahMenit' => 5])->assertSessionHasErrors(['PeringatanPengelolaSetelahMenit' => 'Peringatan ke pengelola tidak boleh lebih awal dari toleransi terlambat.']);
        $this->put('/kelola/karyawan/aturan-kehadiran', $dasar)->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $aturan = AturanKehadiran::query()->sole();
        expect($aturan->WajibJadwal)->toBeTrue()
            ->and($aturan->MasukPalingAwalMenit)->toBe(45)
            ->and(LogAudit::query()->where('Peristiwa', 'aturan-kehadiran.simpan')->count())->toBe(1);

        $this->put('/kelola/karyawan/aturan-kehadiran', $dasar)->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'aturan-kehadiran.simpan')->count())->toBe(1);

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->put('/kelola/karyawan/aturan-kehadiran', $dasar)->assertForbidden();
    });

    it('tenant lain tidak melihat aturan kehadiran tenant ini', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Kedai Aturan A');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        AturanKehadiran::query()->create(['WajibJadwal' => true]);
        $b = BantuanPenjualan::Siapkan($this, 'Kedai Aturan B');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);

        $this->get('/kelola/karyawan/aturan-kehadiran')->assertInertia(fn (AssertableInertia $h) => $h->where('Aturan.WajibJadwal', false));
    });
});

describe('rekap gaji: lembur, potongan terlambat & tidak masuk', function (): void {
    it('draf menghitung dari absensi vs jadwal. Jurnal tetap seimbang. Potongan kehadiran masuk Pendapatan Lain', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Gaji Kehadiran');
        $dewi = Karyawan::query()->create([
            'Nama' => 'Dewi Kehadiran', 'GajiPokok' => '3000000', 'IdOutlet' => $k['Outlet']->Id,
            'TarifLemburPerJam' => '20000', 'PotonganTerlambatPerMenit' => '1000', 'PotonganTidakMasukPerHari' => '100000',
        ]);
        // Tanpa tarif: kehadiran dicatat tetapi tidak menghasilkan uang.
        $eko = Karyawan::query()->create(['Nama' => 'Eko Tanpa Tarif', 'GajiPokok' => '2000000', 'IdOutlet' => $k['Outlet']->Id]);
        foreach ([$dewi, $eko] as $orang) {
            foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-25'] as $tanggal) {
                JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $tanggal, 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
            }
        }
        // 1 Okt: terlambat 15 menit + lembur 60 menit. 2 Okt: tepat. 5 & 6 Okt: tidak masuk. 25 Okt: belum terjadi.
        CatatAbsenUji($dewi, $k['Outlet']->Id, '2026-10-01', '08:15', '17:00');
        CatatAbsenUji($dewi, $k['Outlet']->Id, '2026-10-02', '07:55', '16:00');
        CatatAbsenUji($eko, $k['Outlet']->Id, '2026-10-01', '08:15', '17:00');
        CatatAbsenUji($eko, $k['Outlet']->Id, '2026-10-02', '08:00', '16:00');
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $beban = Akun::query()->where('Kode', '6-1000')->firstOrFail();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        $baris = RekapGajiBaris::query()->get()->keyBy('IdKaryawan');
        expect($baris[$dewi->Id]->LemburMenit)->toBe(60)
            ->and($baris[$dewi->Id]->Lembur)->toBe('20000.00')
            ->and($baris[$dewi->Id]->TerlambatMenit)->toBe(15)
            ->and($baris[$dewi->Id]->PotonganTerlambat)->toBe('15000.00')
            ->and($baris[$dewi->Id]->HariTidakMasuk)->toBe(2)
            ->and($baris[$dewi->Id]->PotonganTidakMasuk)->toBe('200000.00')
            ->and($baris[$dewi->Id]->Bersih)->toBe('2805000.00')
            ->and($baris[$eko->Id]->LemburMenit)->toBe(60)
            ->and($baris[$eko->Id]->Lembur)->toBe('0.00')
            ->and($baris[$eko->Id]->HariTidakMasuk)->toBe(2)
            ->and($baris[$eko->Id]->PotonganTidakMasuk)->toBe('0.00')
            ->and($baris[$eko->Id]->Bersih)->toBe('2000000.00')
            ->and($rekap->TotalKotor)->toBe('5020000.00')
            ->and($rekap->TotalPotongan)->toBe('215000.00')
            ->and($rekap->TotalBersih)->toBe('4805000.00');

        $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Baris.0.Nama', 'Dewi Kehadiran')
            ->where('Baris.0.Kotor', '3020000.00')
            ->where('Baris.0.LemburMenit', 60)
            ->where('Baris.0.HariTidakMasuk', 2));

        // Pengelola menyesuaikan: lembur belum disetujui (0), potongan tidak masuk jadi 1 hari.
        $alamat = "/kelola/karyawan/gaji/{$rekap->Uuid}/baris/{$dewi->Uuid}";
        $this->put($alamat, ['Tambahan' => '0', 'PotonganKasbon' => '0', 'PotonganLain' => '0', 'Lembur' => '0', 'PotonganTidakMasuk' => '100000'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(RekapGajiBaris::query()->where('IdKaryawan', $dewi->Id)->value('Bersih'))->toBe('2885000.00');
        $this->put($alamat, ['Tambahan' => '0', 'PotonganKasbon' => '0', 'PotonganLain' => '0', 'PotonganTidakMasuk' => '9999999'])
            ->assertSessionHasErrors(['PotonganLain' => 'Potongan melebihi gaji kotor. Kurangi potongan.']);

        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Kotor: 3.000.000 + 2.000.000 (lembur Dewi disetel 0). Potongan kehadiran Dewi 15.000 + 100.000 = 115.000.
        expect((string) JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00')
            ->and((string) JurnalDetail::query()->where('IdAkun', $beban->Id)->selectRaw('CAST(SUM(`Debit` - `Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('5000000.00')
            ->and((string) JurnalDetail::query()->where('IdAkun', BantuanJurnal::IdAkunPeran(PeranAkun::PendapatanLain))->selectRaw('CAST(SUM(`Debit` - `Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('-115000.00');
    });

    it('potongan kehadiran tidak membuat gaji minus: dibatasi sampai gaji kotor, terlambat dulu', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Potongan Besar');
        $fajar = Karyawan::query()->create([
            'Nama' => 'Fajar Sering Alpa', 'GajiPokok' => '300000', 'IdOutlet' => $k['Outlet']->Id,
            'PotonganTerlambatPerMenit' => '10000', 'PotonganTidakMasukPerHari' => '200000',
        ]);
        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $tanggal) {
            JadwalKerja::query()->create(['IdKaryawan' => $fajar->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $tanggal, 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }
        CatatAbsenUji($fajar, $k['Outlet']->Id, '2026-10-01', '08:20', '16:00');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = RekapGajiBaris::query()->sole();
        // Terlambat 20 menit × 10.000 = 200.000; tidak masuk 2 hari × 200.000 = 400.000, tersisa 100.000 dari gaji 300.000.
        expect($baris->PotonganTerlambat)->toBe('200000.00')
            ->and($baris->PotonganTidakMasuk)->toBe('100000.00')
            ->and($baris->Bersih)->toBe('0.00');
    });

    it('formulir karyawan menyimpan tarif. Tarif tidak ditulis ke log audit dan hanya terlihat pemegang karyawan.kelola', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Tarif Karyawan');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Gita Lembur', 'GajiPokok' => '2500000', 'TarifLemburPerJam' => '15000', 'PotonganTerlambatPerMenit' => '500', 'PotonganTidakMasukPerHari' => '85000'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $gita = Karyawan::query()->where('Nama', 'Gita Lembur')->sole();
        $audit = (string) json_encode(LogAudit::query()->where('Peristiwa', 'karyawan.tambah')->value('NilaiBaru'));
        expect($gita->TarifLemburPerJam)->toBe('15000.00')
            ->and($gita->PotonganTidakMasukPerHari)->toBe('85000.00')
            ->and($audit)->not->toContain('15000')->not->toContain('85000');
        $this->post('/kelola/tim', ['Akses' => 'Tanpa', 'Nama' => 'Hana', 'TarifLemburPerJam' => 'abc'])->assertSessionHasErrors('TarifLemburPerJam');
    });
});

describe('notifikasi kehadiran lewat WhatsApp', function (): void {
    /** @return array{0: array<string, mixed>, 1: Karyawan, 2: Pengguna} [konteks, karyawan terjadwal, akun karyawan] */
    function SiapkanNotifikasiKehadiran($tes, array $aturan): array
    {
        // Senin 5 Okt 2026, 07.20 WIB: shift 08.00–16.00.
        $tes->travelTo(CarbonImmutable::parse('2026-10-05 07:20:00', 'Asia/Jakarta'));
        $k = BantuanPenjualan::Siapkan($tes, 'Kedai Notifikasi Kehadiran');
        Pengguna::query()->whereKey($k['Pemilik']->Id)->update(['NoHp' => '081288880001']);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        Pengguna::query()->whereKey($kasir->Id)->update(['NoHp' => '081288880002']);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $karyawan = Karyawan::query()->create(['Nama' => 'Intan Kasir', 'IdPengguna' => $kasir->Id, 'IdOutlet' => $k['Outlet']->Id]);
        JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        AturanKehadiran::query()->create($aturan);

        return [$k, $karyawan, $kasir];
    }

    $jalankan = fn (array $k) => Artisan::call('karyawan:kirim-notifikasi-kehadiran', ['--tenant' => [$k['Tenant']->Id]]);

    it('pengingat shift ke karyawan sebelum mulai, sekali saja, dan tidak bila sudah absen', function () use ($jalankan): void {
        [$k, $karyawan] = SiapkanNotifikasiKehadiran($this, ['PengingatShiftAktif' => true, 'PengingatShiftMenitSebelum' => 30]);

        // 07.20 = 40 menit sebelum mulai: belum waktunya.
        expect($jalankan($k))->toBe(0)->and(PesanKehadiranTerkirim())->toHaveCount(0);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:35:00', 'Asia/Jakarta'));
        $jalankan($k);
        expect(PesanKehadiranTerkirim())->toHaveCount(1)
            ->and((string) PesanKehadiranTerkirim('6281288880002')[0]['message'])->toContain('Intan Kasir')->toContain('08:00')->toContain('absen masuk');

        $jalankan($k);
        expect(PesanKehadiranTerkirim())->toHaveCount(1);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(JadwalKerja::query()->sole()->PengingatShiftPada)->not->toBeNull();

        // Karyawan yang sudah absen tidak diingatkan: jadwal baru, absen sudah ada.
        JadwalKerja::query()->delete();
        $lain = JadwalKerja::query()->create(['IdKaryawan' => $karyawan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        CatatAbsenUji($karyawan, $k['Outlet']->Id, '2026-10-05', '07:30', null);
        $jalankan($k);
        expect(PesanKehadiranTerkirim())->toHaveCount(1)->and($lain->refresh()->PengingatShiftPada)->toBeNull();
    });

    it('tanpa --tenant hanya tenant yang punya jadwal dengan penanda belum terisi yang dikunjungi', function (): void {
        [$k] = SiapkanNotifikasiKehadiran($this, ['PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 15]);
        // Tenant lain tanpa jadwal sama sekali: tidak boleh dikunjungi (dulu semua tenant dikunjungi tiap 5 menit).
        BantuanOrganisasi::BuatTenant('Toko Tanpa Jadwal');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:16:00', 'Asia/Jakarta'));

        expect(Artisan::call('karyawan:kirim-notifikasi-kehadiran'))->toBe(0)
            ->and(Artisan::output())->toContain('1 tenant diperiksa');

        // Setelah semua penanda terisi, tidak ada lagi yang perlu dikunjungi.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        JadwalKerja::query()->update(['PengingatShiftPada' => now(), 'PeringatanKehadiranPada' => now()]);
        Artisan::call('karyawan:kirim-notifikasi-kehadiran');
        expect(Artisan::output())->toContain('0 tenant diperiksa');
    });

    it('aturan mati (bawaan): tidak ada pesan sama sekali', function () use ($jalankan): void {
        [$k] = SiapkanNotifikasiKehadiran($this, ['PengingatShiftAktif' => false, 'PeringatanPengelolaAktif' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:30:00', 'Asia/Jakarta'));

        expect($jalankan($k))->toBe(0)->and(PesanKehadiranTerkirim())->toHaveCount(0);
    });

    it('peringatan ke pengelola: belum masuk setelah jeda, sekali saja. Karyawan tidak menerimanya', function () use ($jalankan): void {
        [$k] = SiapkanNotifikasiKehadiran($this, ['PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 15]);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:10:00', 'Asia/Jakarta'));
        $jalankan($k);
        expect(PesanKehadiranTerkirim())->toHaveCount(0);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:16:00', 'Asia/Jakarta'));
        $jalankan($k);
        $pesan = PesanKehadiranTerkirim('6281288880001');
        expect($pesan)->toHaveCount(1)
            ->and((string) $pesan[0]['message'])->toContain('Intan Kasir belum masuk')->toContain('08:00–16:00')
            ->and(PesanKehadiranTerkirim('6281288880002'))->toHaveCount(0);

        $jalankan($k);
        expect(PesanKehadiranTerkirim('6281288880001'))->toHaveCount(1);
    });

    it('peringatan terlambat: karyawan sudah masuk tetapi lewat toleransi, yang tepat waktu tidak memicu pesan', function () use ($jalankan): void {
        [$k, $karyawan] = SiapkanNotifikasiKehadiran($this, ['PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 15]);
        CatatAbsenUji($karyawan, $k['Outlet']->Id, '2026-10-05', '08:12', null);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:20:00', 'Asia/Jakarta'));
        $jalankan($k);
        $pesan = PesanKehadiranTerkirim('6281288880001');
        expect($pesan)->toHaveCount(1)->and((string) $pesan[0]['message'])->toContain('Intan Kasir terlambat 12 menit');

        // Tepat waktu: tidak ada pesan, jadwal tetap ditandai supaya tidak dipindai ulang.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Absensi::query()->delete();
        JadwalKerja::query()->update(['PeringatanKehadiranPada' => null]);
        CatatAbsenUji($karyawan, $k['Outlet']->Id, '2026-10-05', '08:02', null);
        $jalankan($k);
        expect(PesanKehadiranTerkirim('6281288880001'))->toHaveCount(1);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(JadwalKerja::query()->sole()->PeringatanKehadiranPada)->not->toBeNull();
    });

    it('peringatan hanya ke anggota yang boleh melihat karyawan di outlet jadwal', function () use ($jalankan): void {
        [$k] = SiapkanNotifikasiKehadiran($this, ['PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 15]);
        // Kasir (0002) tidak punya karyawan.lihat; hanya Owner (0001) yang menerima.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:30:00', 'Asia/Jakarta'));
        $jalankan($k);

        expect(PesanKehadiranTerkirim('6281288880001'))->toHaveCount(1)->and(PesanKehadiranTerkirim('6281288880002'))->toHaveCount(0);
    });
});
