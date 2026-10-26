<?php

declare(strict_types=1);

use App\Domain\Pengelola\Operasional\Aksi\PeriksaKondisiOperasional;
use App\Domain\Pengelola\Operasional\Kueri\TugasGagal;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Operasional\Model\CatatanBackup;
use App\Domain\Pengelola\Operasional\Model\DetakPenjadwal;
use App\Domain\Pengelola\Operasional\Surel\AlertOperasionalTerbuka;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

const RAHASIA_JOB_UJI = 'token-reset-sangat-rahasia-4321';

function MasukSebagaiOperasional(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

function CatatBackupBerhasil(Carbon $selesai): void
{
    CatatanBackup::query()->create([
        'Jenis' => 'Backup', 'Hasil' => 'Berhasil', 'SelesaiPada' => $selesai, 'Sumber' => 'Skrip', 'UkuranByte' => 52_428_800,
    ]);
}

function TambahJobAntrean(int $tersediaSejakDetik, string $antrean = 'default'): void
{
    DB::table('jobs')->insert([
        'queue' => $antrean,
        'payload' => json_encode(['displayName' => 'App\\Tugas\\KirimStrukWaTugas']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp() - $tersediaSejakDetik,
        'created_at' => now()->getTimestamp() - $tersediaSejakDetik,
    ]);
}

/** Job gagal dengan payload asli Laravel (closure terserialisasi yang membawa rahasia), dipindah dari `jobs`. */
function TambahJobGagal(): string
{
    $rahasia = RAHASIA_JOB_UJI;
    Queue::connection('database')->push(function () use ($rahasia): void {
        Log::info('Kirim ulang tautan reset', ['Token' => $rahasia]);
    });
    $job = DB::table('jobs')->orderByDesc('id')->first();
    DB::table('jobs')->where('id', $job->id)->delete();
    $payload = json_decode((string) $job->payload, true);
    $uuid = (string) $payload['uuid'];

    expect((string) $job->payload)->toContain(RAHASIA_JOB_UJI);

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $job->payload,
        'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: Gagal login SMTP password='.RAHASIA_JOB_UJI.' di smtp://noreply:'.RAHASIA_JOB_UJI."@smtp.hostinger.com\n#0 /app/vendor/symfony/mailer/Transport.php(12)\n#1 {main}",
        'failed_at' => now(),
    ]);

    return $uuid;
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    Mail::fake();
});

describe('Izin dasbor operasional (§19.3)', function (): void {
    it('hanya Teknis dan Super Admin yang membuka dasbor dan mengelola job gagal & backup', function (PeranPengelolaBawaan $peran): void {
        MasukSebagaiOperasional($this, BantuanPengelola::BuatAnggota($peran));
        $uuid = TambahJobGagal();

        $this->get(BantuanPengelola::Url('/operasional'))->assertForbidden();
        $this->get(BantuanPengelola::Url("/operasional/tugas-gagal/{$uuid}"))->assertForbidden();
        $this->post(BantuanPengelola::Url("/operasional/tugas-gagal/{$uuid}/coba-ulang"))->assertForbidden();
        $this->delete(BantuanPengelola::Url("/operasional/tugas-gagal/{$uuid}"), ['Alasan' => 'x'])->assertForbidden();
        $this->post(BantuanPengelola::Url('/operasional/backup'), [])->assertForbidden();
    })->with([
        PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Analis,
        PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::MitraPenjualan,
    ]);

    it('Teknis melihat detak, antrean, job gagal, backup, dan kesehatan server', function (): void {
        MasukSebagaiOperasional($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->artisan('pengelola:detak')->assertSuccessful();
        CatatBackupBerhasil(now()->subHours(7));
        TambahJobAntrean(30, 'default');
        TambahJobAntrean(10, 'notifikasi');
        TambahJobGagal();

        $this->get(BantuanPengelola::Url('/operasional'))->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Pengelola/Operasional/Dasbor')
            ->where('Dasbor.Penjadwal.Sehat', true)
            ->where('Dasbor.Antrean.Sehat', true)
            ->has('Dasbor.Antrean.PerAntrean', 2)
            ->where('Dasbor.Antrean.UmurTertuaDetik', 30)
            ->where('Dasbor.TugasGagal.Total', 1)
            ->where('Dasbor.Backup.Sehat', true)
            ->where('Dasbor.Backup.BackupTerakhir.UkuranByte', 52_428_800)
            ->where('Dasbor.Backup.UjiRestoreTerakhir', null)
            ->where('PeringatanOperasional', [])
            ->has('Dasbor.Kesehatan.UkuranDatabaseByte'));
    });
});

describe('BR-P11.1: alert kritis scheduler & antrean, sekali per insiden', function (): void {
    it('scheduler tidak berdetak > 3 menit membuka alert kritis, email ke Teknis sekali, lalu tertutup saat pulih', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        CatatBackupBerhasil(now()->subHour());
        $this->artisan('pengelola:detak')->assertSuccessful();

        $this->travel(3)->minutes();
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);

        $this->travel(1)->minutes();
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['PenjadwalBerhenti']);
        $this->travel(5)->minutes();
        app(PeriksaKondisiOperasional::class)->Jalankan();

        $alert = AlertOperasional::query()->sole();
        expect($alert->Tingkat->value)->toBe('Kritis')->and($alert->EmailTerkirimPada)->not->toBeNull();
        Mail::assertSent(AlertOperasionalTerbuka::class, 1);
        Mail::assertSent(AlertOperasionalTerbuka::class, fn (AlertOperasionalTerbuka $surel) => $surel->hasTo($teknis->Email));

        // Banner tampil untuk semua anggota yang masuk.
        MasukSebagaiOperasional($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan))
            ->get(BantuanPengelola::Url('/'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('PeringatanOperasional', 1));

        $this->artisan('pengelola:detak')->assertSuccessful();
        expect(AlertOperasional::query()->sole()->SelesaiPada)->not->toBeNull();

        // Insiden baru = email baru.
        $this->travel(10)->minutes();
        app(PeriksaKondisiOperasional::class)->Jalankan();
        expect(AlertOperasional::query()->count())->toBe(2);
        Mail::assertSent(AlertOperasionalTerbuka::class, 2);
    });

    it('/sehat ikut memeriksa alert tanpa menggagalkan cek kesehatan', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        CatatBackupBerhasil(now()->subHour());

        $this->get('/sehat')->assertOk();

        expect(AlertOperasional::query()->sole()->Kunci->value)->toBe('PenjadwalBerhenti');
        Mail::assertSent(AlertOperasionalTerbuka::class, 1);
    });

    it('job antrean tertua > 5 menit membuka alert. Job tertunda (delay) tidak dihitung', function (): void {
        $this->artisan('pengelola:detak');
        CatatBackupBerhasil(now()->subHour());
        TambahJobAntrean(-600);
        TambahJobAntrean(299);
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);

        TambahJobAntrean(301, 'notifikasi');
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['AntreanTertunda']);
    });

    it('backup berhasil terakhir > 26 jam atau belum pernah ada memicu alert peringatan', function (): void {
        $this->artisan('pengelola:detak');
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['BackupTerlambat']);

        $this->artisan('pengelola:catat-backup', ['--hasil' => 'Berhasil', '--ukuran' => '1048576', '--lokasi' => 'backup/2026-09-24.sql.gz'])
            ->assertSuccessful();
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);

        $this->travel(27)->hours();
        $this->artisan('pengelola:detak');
        $this->artisan('pengelola:catat-backup', ['--hasil' => 'Gagal', '--keterangan' => 'Kuota penyimpanan penuh'])->assertSuccessful();
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['BackupTerlambat'])
            ->and(AlertOperasional::query()->where('Kunci', 'BackupTerlambat')->whereNull('SelesaiPada')->sole()->Tingkat->value)->toBe('Peringatan');
    });
});

describe('Job gagal & backup', function (): void {
    it('detail job gagal menyembunyikan payload & rahasia di pesan masalah', function (): void {
        MasukSebagaiOperasional($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $uuid = TambahJobGagal();

        $this->get(BantuanPengelola::Url('/operasional'))->assertDontSee(RAHASIA_JOB_UJI);
        $this->get(BantuanPengelola::Url("/operasional/tugas-gagal/{$uuid}"))
            ->assertDontSee(RAHASIA_JOB_UJI)
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Operasional/TugasGagal')
                ->where('Tugas.NamaTugas', fn (string $nama) => str_starts_with($nama, 'Closure'))
                ->where('Tugas.Payload.commandName', 'Illuminate\\Queue\\CallQueuedClosure')
                ->missing('Tugas.Payload.data'));

        expect(TugasGagal::SaringRahasia('Authorization: Bearer abc.def.ghi kata_sandi="rahasia123"'))
            ->toBe('Authorization: Bearer [disembunyikan] kata_sandi="[disembunyikan]"');
    });

    it('coba ulang mengembalikan job ke antrean, buang wajib alasan. Keduanya tercatat di audit', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiOperasional($this, $teknis);
        $ulang = TambahJobGagal();
        $buang = TambahJobGagal();

        $this->post(BantuanPengelola::Url("/operasional/tugas-gagal/{$ulang}/coba-ulang"))->assertRedirect(BantuanPengelola::Url('/operasional'));
        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->where('uuid', $ulang)->exists())->toBeFalse();

        $this->delete(BantuanPengelola::Url("/operasional/tugas-gagal/{$buang}"), ['Alasan' => ''])->assertSessionHasErrors('Alasan');
        $this->delete(BantuanPengelola::Url("/operasional/tugas-gagal/{$buang}"), ['Alasan' => 'Email ke alamat yang sudah tidak aktif'])
            ->assertRedirect(BantuanPengelola::Url('/operasional'));
        expect(DB::table('failed_jobs')->count())->toBe(0);

        $this->post(BantuanPengelola::Url("/operasional/tugas-gagal/{$buang}/coba-ulang"))->assertSessionHasErrors('Umum');

        expect(LogAuditPengelola::query()->where('Aksi', 'operasional.job-gagal.coba-ulang')->sole()->IdPenggunaPengelola)->toBe($teknis->Id)
            ->and(LogAuditPengelola::query()->where('Aksi', 'operasional.job-gagal.buang')->sole()->Alasan)->toBe('Email ke alamat yang sudah tidak aktif');
    });

    it('job yang payload-nya tidak bisa dibaca ulang ditolak dengan pesan jelas, bukan masalah server', function (): void {
        MasukSebagaiOperasional($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $uuid = TambahJobGagal();
        DB::table('failed_jobs')->where('uuid', $uuid)->update(['payload' => json_encode([
            'uuid' => $uuid, 'displayName' => 'App\\Tugas\\TugasLamaDihapus', 'retryUntil' => null,
            'data' => ['commandName' => 'App\\Tugas\\TugasLamaDihapus', 'command' => 'O:99:"rusak'],
        ])]);

        $this->post(BantuanPengelola::Url("/operasional/tugas-gagal/{$uuid}/coba-ulang"))->assertSessionHasErrors('Umum');
        expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
    });

    it('Teknis mencatat uji restore manual. Lokasi berkredensial dan waktu masa depan ditolak', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiOperasional($this, $teknis);
        $isian = ['Jenis' => 'UjiRestore', 'Hasil' => 'Berhasil', 'SelesaiPada' => '2026-09-24T09:30', 'UkuranMb' => 512, 'Keterangan' => 'Restore ke VPS uji 42 menit'];

        $this->post(BantuanPengelola::Url('/operasional/backup'), [...$isian, 'Lokasi' => 's3://kunci:rahasia@bucket/backup.sql.gz'])
            ->assertSessionHasErrors('Lokasi');
        $this->post(BantuanPengelola::Url('/operasional/backup'), [...$isian, 'SelesaiPada' => '2026-09-24T11:00'])->assertSessionHasErrors('SelesaiPada');
        $this->post(BantuanPengelola::Url('/operasional/backup'), $isian)->assertSessionHasNoErrors();

        $catatan = CatatanBackup::query()->sole();
        expect($catatan->Sumber->value)->toBe('Manual')
            ->and($catatan->IdPenggunaPengelola)->toBe($teknis->Id)
            ->and($catatan->UkuranByte)->toBe(512 * 1024 * 1024)
            ->and($catatan->SelesaiPada->toIso8601String())->toBe(Carbon::parse('2026-09-24 09:30', 'Asia/Jakarta')->utc()->toIso8601String())
            ->and(LogAuditPengelola::query()->where('Aksi', 'operasional.backup.catat')->sole()->IdPenggunaPengelola)->toBe($teknis->Id);

        $this->get(BantuanPengelola::Url('/operasional'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Dasbor.Backup.UjiRestoreTerakhir.Hasil', 'Berhasil'));
    });

    it('catatan backup append-only', function (): void {
        CatatBackupBerhasil(now()->subHour());
        $catatan = CatatanBackup::query()->sole();

        expect($catatan->update(['Hasil' => 'Gagal']))->toBeFalse()
            ->and($catatan->delete())->toBeFalse()
            ->and(CatatanBackup::query()->sole()->Hasil->value)->toBe('Berhasil')
            ->and(DetakPenjadwal::query()->count())->toBe(0);
    });
});
