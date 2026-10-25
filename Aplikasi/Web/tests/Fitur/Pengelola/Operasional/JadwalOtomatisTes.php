<?php

declare(strict_types=1);

use App\Domain\Pengelola\Operasional\Layanan\PencatatRiwayatJadwal;
use App\Domain\Pengelola\Operasional\Model\RiwayatJadwal;
use App\Domain\Pengelola\Operasional\Tugas\JalankanPerintahJadwalTugas;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

const PERINTAH_JADWAL_UJI = 'tenant:periksa-silang';

function AcaraUji(string $perintah): Event
{
    return new Event(app(EventMutex::class), "'php' 'artisan' {$perintah}");
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-25 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('D-209 riwayat jadwal otomatis', function (): void {
    it('mencatat mulai dan selesai perintah terjadwal, dan mengabaikan queue:work', function (): void {
        $pencatat = app(PencatatRiwayatJadwal::class);
        $acara = AcaraUji(PERINTAH_JADWAL_UJI);

        $pencatat->SaatMulai(new ScheduledTaskStarting($acara));
        $this->travel(3)->seconds();
        $acara->exitCode = 0;
        $pencatat->SaatSelesai(new ScheduledTaskFinished($acara, 3.0));
        $pencatat->SaatMulai(new ScheduledTaskStarting(AcaraUji('queue:work --stop-when-empty')));

        $baris = RiwayatJadwal::query()->sole();
        expect($baris->Perintah)->toBe(PERINTAH_JADWAL_UJI)
            ->and($baris->Berhasil)->toBeTrue()
            ->and($baris->DurasiMs)->toBe(3000)
            ->and($baris->Sumber)->toBe(RiwayatJadwal::SUMBER_JADWAL);
    });

    it('perintah yang gagal tercatat gagal beserta alasannya', function (): void {
        $pencatat = app(PencatatRiwayatJadwal::class);
        $acara = AcaraUji(PERINTAH_JADWAL_UJI);

        $pencatat->SaatMulai(new ScheduledTaskStarting($acara));
        $pencatat->SaatGagal(new ScheduledTaskFailed($acara, new RuntimeException('Koneksi basis data terputus.')));

        $baris = RiwayatJadwal::query()->sole();
        expect($baris->Berhasil)->toBeFalse()->and($baris->Keterangan)->toBe('Koneksi basis data terputus.');
    });

    it('menyimpan paling banyak 30 jalan terakhir per perintah', function (): void {
        $pencatat = app(PencatatRiwayatJadwal::class);
        $acara = AcaraUji(PERINTAH_JADWAL_UJI);
        $acara->exitCode = 0;

        for ($i = 0; $i < PencatatRiwayatJadwal::BATAS_PER_PERINTAH + 5; $i++) {
            $pencatat->SaatMulai(new ScheduledTaskStarting($acara));
            $pencatat->SaatSelesai(new ScheduledTaskFinished($acara, 0.1));
        }

        expect(RiwayatJadwal::query()->where('Perintah', PERINTAH_JADWAL_UJI)->count())->toBe(PencatatRiwayatJadwal::BATAS_PER_PERINTAH);
    });
});

describe('D-209 halaman Jadwal & penyimpanan', function (): void {
    it('Teknis melihat semua perintah terjadwal dengan status jalan terakhir dan tenant penyimpanan terbesar', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Toko Foto Besar', 'foto@contoh.id');
        PemakaianPenyimpanan::query()->withoutGlobalScopes()->updateOrCreate(['IdTenant' => $tenant->Id], ['Byte' => 5_242_880]);
        RiwayatJadwal::query()->create(['Perintah' => PERINTAH_JADWAL_UJI, 'Sumber' => 'Jadwal', 'MulaiPada' => now()->subHour(), 'SelesaiPada' => now()->subHour()->addSecond(), 'Berhasil' => false, 'DurasiMs' => 1000, 'Keterangan' => 'Galat uji.']);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->get(BantuanPengelola::Url('/operasional/jadwal'))->assertOk()->assertInertia(function (AssertableInertia $halaman): void {
            $halaman->component('Pengelola/Operasional/Jadwal')->where('BolehKelola', true);
            $data = collect($halaman->toArray()['props']['Jadwal']['Data']);
            $baris = $data->firstWhere('Perintah', PERINTAH_JADWAL_UJI);
            expect($baris['Status'])->toBe('Gagal')
                ->and($data->pluck('Perintah')->contains(fn (string $p): bool => str_starts_with($p, 'queue:work')))->toBeFalse()
                ->and($halaman->toArray()['props']['Penyimpanan']['Terbesar'][0]['Nama'])->toBe('Toko Foto Besar');
        });
    });

    it('Jalankan sekarang mengantrekan perintah, mencatat baris manual dan audit', function (): void {
        Queue::fake();
        $teknis = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->post(BantuanPengelola::Url('/operasional/jadwal/jalankan'), ['Perintah' => PERINTAH_JADWAL_UJI])->assertSessionHasNoErrors()->assertSessionHas('Kilat');

        $baris = RiwayatJadwal::query()->sole();
        expect($baris->Sumber)->toBe(RiwayatJadwal::SUMBER_MANUAL)->and($baris->IdPenggunaPengelola)->toBe($teknis->Id)->and($baris->SelesaiPada)->toBeNull();
        Queue::assertPushed(JalankanPerintahJadwalTugas::class, fn (JalankanPerintahJadwalTugas $tugas): bool => $tugas->idRiwayat === $baris->Id);
        expect(LogAuditPengelola::query()->where('Aksi', 'operasional.jadwal.jalankan')->sole()->NilaiBaru)->toMatchArray(['Perintah' => PERINTAH_JADWAL_UJI]);
    });

    it('menolak perintah di luar jadwal dan perintah yang masih berjalan', function (): void {
        Queue::fake();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->post(BantuanPengelola::Url('/operasional/jadwal/jalankan'), ['Perintah' => 'migrate:fresh'])->assertSessionHasErrors('Perintah');
        $this->post(BantuanPengelola::Url('/operasional/jadwal/jalankan'), ['Perintah' => PERINTAH_JADWAL_UJI])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/operasional/jadwal/jalankan'), ['Perintah' => PERINTAH_JADWAL_UJI])->assertSessionHasErrors('Perintah');

        Queue::assertPushed(JalankanPerintahJadwalTugas::class, 1);
        expect(RiwayatJadwal::query()->count())->toBe(1);
    });

    it('tugas menjalankan perintah dan menutup riwayat dengan hasilnya', function (): void {
        $baris = RiwayatJadwal::query()->create(['Perintah' => 'inspire', 'Sumber' => 'Manual', 'MulaiPada' => now()]);

        app()->call([new JalankanPerintahJadwalTugas($baris->Id), 'handle']);

        $baris->refresh();
        expect($baris->Berhasil)->toBeTrue()->and($baris->SelesaiPada)->not->toBeNull();
    });

    it('§19.3: peran tanpa izin operasional ditolak, Analis tidak bisa menjalankan', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        $this->get(BantuanPengelola::Url('/operasional/jadwal'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/operasional/jadwal/jalankan'), ['Perintah' => PERINTAH_JADWAL_UJI])->assertForbidden();
    });
});
