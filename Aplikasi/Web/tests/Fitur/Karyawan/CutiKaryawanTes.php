<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Enum\StatusCuti;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\AturanKehadiran;
use App\Domain\Karyawan\Model\CutiKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\RekapGajiBaris;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 cuti/izin karyawan (EMP-03): pengelola mencatat hari karyawan tidak bekerja. Cuti berbayar tidak dihitung "tidak
 * masuk" di rekap gaji; cuti apa pun menahan pengingat shift dan peringatan belum masuk; catatan tidak bisa bertabrakan
 * dan tidak diubah, hanya dibatalkan dengan alasan.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8101']])]);
});

function KirimanCuti(Karyawan $karyawan, array $ubah = []): array
{
    return [
        'Karyawan' => $karyawan->Uuid,
        'Jenis' => 'Cuti',
        'TanggalMulai' => '2026-10-12',
        'TanggalSelesai' => '2026-10-14',
        'Berbayar' => true,
        'Alasan' => 'Menikah',
        ...$ubah,
    ];
}

describe('mencatat dan membatalkan cuti', function (): void {
    it('Owner mencatat cuti: tersimpan berstatus Aktif, diaudit, dan tampil di daftar', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Solo');
        $rina = Karyawan::query()->create(['Nama' => 'Rina Cuti', 'IdOutlet' => $k['Outlet']->Id]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->get('/kelola/karyawan/cuti')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Karyawan/Cuti')->where('Izin.Kelola', true)->has('OpsiKaryawan', 1));
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina))->assertRedirect('/kelola/karyawan/cuti')->assertSessionHas('Kilat', 'Cuti dicatat.');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $cuti = CutiKaryawan::query()->sole();
        expect($cuti->Status)->toBe(StatusCuti::Aktif)
            ->and($cuti->Berbayar)->toBeTrue()
            ->and($cuti->TanggalMulai->toDateString())->toBe('2026-10-12')
            ->and($cuti->TanggalSelesai->toDateString())->toBe('2026-10-14')
            ->and(LogAudit::query()->where('Peristiwa', 'cuti-karyawan.catat')->count())->toBe(1);

        $baris = $this->getJson('/kelola/karyawan/cuti')->assertOk()->json('Data');
        expect($baris)->toHaveCount(1)
            ->and($baris[0]['Karyawan'])->toBe('Rina Cuti')
            ->and($baris[0]['Jenis'])->toBe('Cuti')
            ->and($baris[0]['LabelStatus'])->toBe('Berlaku');
    });

    it('validasi: rentang terbalik, lebih dari 90 hari, karyawan nonaktif, dan jenis tak dikenal ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Validasi');
        $rina = Karyawan::query()->create(['Nama' => 'Rina Valid', 'IdOutlet' => $k['Outlet']->Id]);
        $toni = Karyawan::query()->create(['Nama' => 'Toni Nonaktif', 'IdOutlet' => $k['Outlet']->Id, 'Status' => StatusKaryawan::Nonaktif]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['TanggalSelesai' => '2026-10-11']))->assertSessionHasErrors(['TanggalSelesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['TanggalMulai' => '2026-01-01', 'TanggalSelesai' => '2026-06-30']))->assertSessionHasErrors('TanggalSelesai');
        $this->post('/kelola/karyawan/cuti', KirimanCuti($toni))->assertSessionHasErrors(['Karyawan' => 'Pilih karyawan yang aktif.']);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['Jenis' => 'Libur']))->assertSessionHasErrors('Jenis');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(CutiKaryawan::query()->count())->toBe(0);
    });

    it('catatan yang bertabrakan ditolak. Setelah dibatalkan rentang itu bisa dicatat ulang. Pembatalan butuh alasan dan hanya sekali', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Tabrakan');
        $rina = Karyawan::query()->create(['Nama' => 'Rina Tabrakan', 'IdOutlet' => $k['Outlet']->Id]);
        $sari = Karyawan::query()->create(['Nama' => 'Sari Lain', 'IdOutlet' => $k['Outlet']->Id]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina))->assertRedirect();
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['TanggalMulai' => '2026-10-14', 'TanggalSelesai' => '2026-10-16', 'Jenis' => 'Sakit']))->assertSessionHasErrors('TanggalMulai');
        // Rentang bersebelahan dan karyawan lain tidak bentrok.
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['TanggalMulai' => '2026-10-15', 'TanggalSelesai' => '2026-10-15']))->assertRedirect();
        $this->post('/kelola/karyawan/cuti', KirimanCuti($sari))->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pertama = CutiKaryawan::query()->where('IdKaryawan', $rina->Id)->orderBy('TanggalMulai')->firstOrFail();

        $this->post("/kelola/karyawan/cuti/{$pertama->Uuid}/batal", ['Alasan' => ''])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/karyawan/cuti/{$pertama->Uuid}/batal", ['Alasan' => 'abc'])->assertSessionHasErrors(['Alasan' => 'Tulis alasan pembatalan, minimal 5 karakter.']);
        $this->post("/kelola/karyawan/cuti/{$pertama->Uuid}/batal", ['Alasan' => 'Salah tanggal'])->assertRedirect();
        $this->post("/kelola/karyawan/cuti/{$pertama->Uuid}/batal", ['Alasan' => 'Salah tanggal'])->assertSessionHasErrors(['Umum' => 'Catatan ini sudah dibatalkan.']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pertama->refresh();
        expect($pertama->Status)->toBe(StatusCuti::Dibatalkan)
            ->and($pertama->AlasanBatal)->toBe('Salah tanggal')
            ->and(LogAudit::query()->where('Peristiwa', 'cuti-karyawan.batal')->count())->toBe(1);

        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['TanggalMulai' => '2026-10-12', 'TanggalSelesai' => '2026-10-14']))->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(CutiKaryawan::query()->where('IdKaryawan', $rina->Id)->where('Status', StatusCuti::Aktif->value)->count())->toBe(2);
    });

    it('Kasir hanya boleh melihat daftar tetapi tidak mencatat. Tenant lain tidak melihat catatan ini', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Izin');
        $rina = Karyawan::query()->create(['Nama' => 'Rina Izin', 'IdOutlet' => $k['Outlet']->Id]);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $k['Tenant']->Id)->post('/kelola/karyawan/cuti', KirimanCuti($rina))->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina))->assertRedirect();

        $lain = BantuanPenjualan::Siapkan($this, 'Salon Cuti Lain');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id, PeranTenantBawaan::Pemilik);
        expect($this->getJson('/kelola/karyawan/cuti')->assertOk()->json('Data'))->toBe([]);
    });
});

describe('efek cuti pada rekap gaji', function (): void {
    it('cuti berbayar bukan tidak masuk. Cuti tidak berbayar dan hari tanpa keterangan tetap dihitung. Absensi pada hari cuti dinilai biasa', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Gaji');
        $dewi = Karyawan::query()->create([
            'Nama' => 'Dewi Cuti', 'GajiPokok' => '3000000', 'IdOutlet' => $k['Outlet']->Id,
            'TarifLemburPerJam' => '20000', 'PotonganTerlambatPerMenit' => '1000', 'PotonganTidakMasukPerHari' => '100000',
        ]);
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-25'] as $tanggal) {
            JadwalKerja::query()->create(['IdKaryawan' => $dewi->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => $tanggal, 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        // 1–2 Okt cuti berbayar (tanpa absen). 5 Okt izin tidak digaji. 6 Okt tanpa keterangan. 7 Okt cuti berbayar tetapi tetap hadir terlambat.
        $this->post('/kelola/karyawan/cuti', KirimanCuti($dewi, ['TanggalMulai' => '2026-10-01', 'TanggalSelesai' => '2026-10-02']))->assertRedirect();
        $this->post('/kelola/karyawan/cuti', KirimanCuti($dewi, ['Jenis' => 'Izin', 'Berbayar' => false, 'TanggalMulai' => '2026-10-05', 'TanggalSelesai' => '2026-10-05']))->assertRedirect();
        $this->post('/kelola/karyawan/cuti', KirimanCuti($dewi, ['TanggalMulai' => '2026-10-07', 'TanggalSelesai' => '2026-10-07']))->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $masuk = CarbonImmutable::parse('2026-10-07 08:30:00', 'Asia/Jakarta')->utc();
        Absensi::query()->create([
            'IdKaryawan' => $dewi->Id, 'IdOutlet' => $k['Outlet']->Id, 'TanggalBisnis' => '2026-10-07', 'MasukPada' => $masuk,
            'KeluarPada' => CarbonImmutable::parse('2026-10-07 16:00:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = RekapGajiBaris::query()->sole();
        // Tidak masuk: 5 Okt (izin tidak digaji) + 6 Okt = 2 hari; 1–2 Okt cuti berbayar tidak dihitung; 7 Okt hadir terlambat 30 menit.
        expect($baris->HariTidakMasuk)->toBe(2)
            ->and($baris->PotonganTidakMasuk)->toBe('200000.00')
            ->and($baris->TerlambatMenit)->toBe(30)
            ->and($baris->PotonganTerlambat)->toBe('30000.00');
    });

    it('cuti yang dibatalkan kembali dihitung sebagai tidak masuk', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Batal Gaji');
        $dewi = Karyawan::query()->create(['Nama' => 'Dewi Batal', 'GajiPokok' => '3000000', 'IdOutlet' => $k['Outlet']->Id, 'PotonganTidakMasukPerHari' => '100000']);
        JadwalKerja::query()->create(['IdKaryawan' => $dewi->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-01', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($dewi, ['TanggalMulai' => '2026-10-01', 'TanggalSelesai' => '2026-10-01']))->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $this->post('/kelola/karyawan/cuti/'.CutiKaryawan::query()->sole()->Uuid.'/batal', ['Alasan' => 'Tidak jadi cuti'])->assertRedirect();

        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(RekapGajiBaris::query()->sole()->HariTidakMasuk)->toBe(1);
    });
});

describe('efek cuti pada notifikasi kehadiran', function (): void {
    function PesanKehadiranCuti(?string $nomor = null)
    {
        return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
            ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send') && ($nomor === null || $r['target'] === $nomor))
            ->values();
    }

    it('karyawan yang sedang cuti tidak diingatkan dan tidak diperingatkan belum masuk. Setelah cuti dibatalkan pesan berjalan lagi', function (): void {
        // Senin 5 Okt 2026, 07.35 WIB: shift 08.00–16.00, pengingat 30 menit sebelumnya.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:35:00', 'Asia/Jakarta'));
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Cuti Notifikasi');
        Pengguna::query()->whereKey($k['Pemilik']->Id)->update(['NoHp' => '081288880001']);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        Pengguna::query()->whereKey($kasir->Id)->update(['NoHp' => '081288880002']);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $intan = Karyawan::query()->create(['Nama' => 'Intan Cuti', 'IdPengguna' => $kasir->Id, 'IdOutlet' => $k['Outlet']->Id]);
        JadwalKerja::query()->create(['IdKaryawan' => $intan->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-05', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        AturanKehadiran::query()->create(['PengingatShiftAktif' => true, 'PengingatShiftMenitSebelum' => 30, 'PeringatanPengelolaAktif' => true, 'PeringatanPengelolaSetelahMenit' => 15]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($intan, ['TanggalMulai' => '2026-10-05', 'TanggalSelesai' => '2026-10-05', 'Jenis' => 'Sakit']))->assertRedirect();
        $jalankan = fn () => Artisan::call('karyawan:kirim-notifikasi-kehadiran', ['--tenant' => [$k['Tenant']->Id]]);

        $jalankan();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:20:00', 'Asia/Jakarta'));
        $jalankan();
        expect(PesanKehadiranCuti())->toHaveCount(0);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(JadwalKerja::query()->sole()->PeringatanKehadiranPada)->toBeNull();

        // Cuti dibatalkan: karyawan belum masuk, pengelola diperingatkan.
        $this->post('/kelola/karyawan/cuti/'.CutiKaryawan::query()->sole()->Uuid.'/batal', ['Alasan' => 'Sudah sembuh, masuk kerja'])->assertRedirect();
        $jalankan();
        $pesan = PesanKehadiranCuti('6281288880001');
        expect($pesan)->toHaveCount(1)->and((string) $pesan[0]['message'])->toContain('Intan Cuti belum masuk');
    });
});

describe('jadwal kerja menandai hari cuti', function (): void {
    it('prop jadwal memuat jenis dan status gaji hari cuti pada minggu yang dibuka', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cuti Jadwal');
        $rina = Karyawan::query()->create(['Nama' => 'Rina Jadwal', 'IdOutlet' => $k['Outlet']->Id]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/cuti', KirimanCuti($rina, ['Jenis' => 'Izin', 'Berbayar' => false, 'TanggalMulai' => '2026-10-13', 'TanggalSelesai' => '2026-10-14']))->assertRedirect();

        $this->get('/kelola/karyawan/jadwal?minggu=2026-10-12')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jadwal.Baris.0.Cuti.2026-10-13', ['Jenis' => 'Izin', 'Berbayar' => false])
            ->where('Jadwal.Baris.0.Cuti.2026-10-14', ['Jenis' => 'Izin', 'Berbayar' => false])
            ->missing('Jadwal.Baris.0.Cuti.2026-10-12'));
        $this->get('/kelola/karyawan/jadwal?minggu=2026-10-19')->assertInertia(fn (AssertableInertia $h) => $h->where('Jadwal.Baris.0.Cuti', []));
    });
});
