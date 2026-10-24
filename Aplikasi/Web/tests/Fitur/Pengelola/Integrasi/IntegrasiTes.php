<?php

declare(strict_types=1);

use App\Domain\Pengelola\Integrasi\Aksi\UjiKoneksiIntegrasi;
use App\Domain\Pengelola\Integrasi\Data\HasilUjiKoneksi;
use App\Domain\Pengelola\Integrasi\Enum\StatusIntegrasi;
use App\Domain\Pengelola\Integrasi\Layanan\PenerapKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiKoneksi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiSmtp;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiTurnstile;
use App\Domain\Pengelola\Integrasi\Penguji\PenyaringPesan;
use App\Domain\Pengelola\Integrasi\Surel\IntegrasiGagal;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

const RAHASIA_SMTP_UJI = 'sandi-smtp-sangat-rahasia-9876';

function MasukSebagaiIntegrasi(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function DataEmailUji(array $ubah = []): array
{
    return array_replace_recursive([
        'Jenis' => 'Email',
        'Lingkungan' => 'Staging',
        'Pengaturan' => [
            'Host' => 'smtp.hostinger.com', 'Port' => 465, 'Enkripsi' => 'Ssl',
            'NamaPengguna' => 'noreply@contoh.id', 'AlamatPengirim' => 'noreply@contoh.id', 'NamaPengirim' => 'Kasir Nusantara',
        ],
        'Kredensial' => ['KataSandi' => RAHASIA_SMTP_UJI],
        'RotasiSetiapHari' => 90,
        'Alasan' => null,
    ], $ubah);
}

/**
 * Berkas akun layanan Firebase yang utuh ±2.400 karakter, di atas batas bawaan 2.000.
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function DataPushUji(array $ubah = []): array
{
    $akun = (string) json_encode([
        'type' => 'service_account',
        'project_id' => 'payoung-id',
        'private_key_id' => str_repeat('a1b2c3d4e5f6', 8),
        'private_key' => "-----BEGIN PRIVATE KEY-----\n".str_repeat('MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQ', 30)."\n-----END PRIVATE KEY-----\n",
        'client_email' => 'firebase-adminsdk-fbsvc@payoung-id.iam.gserviceaccount.com',
        'client_id' => '123456789012345678901',
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-fbsvc%40payoung-id.iam.gserviceaccount.com',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return array_replace_recursive([
        'Jenis' => 'Push',
        'Lingkungan' => 'Staging',
        'Penyedia' => 'Fcm',
        'Pengaturan' => [],
        'Kredensial' => ['AkunLayanan' => $akun],
        'RotasiSetiapHari' => 90,
        'Alasan' => null,
    ], $ubah);
}

function PalsukanPengujiSmtp(bool $berhasil): void
{
    app()->instance(PengujiSmtp::class, new class($berhasil) implements PengujiKoneksi
    {
        public function __construct(private readonly bool $berhasil) {}

        public function Uji(array $pengaturan, array $kredensial): HasilUjiKoneksi
        {
            return $this->berhasil ? HasilUjiKoneksi::Berhasil('Login SMTP berhasil.') : HasilUjiKoneksi::Gagal('Login SMTP ditolak.');
        }
    });
}

function AmbilEmailStaging(): KonfigurasiIntegrasi
{
    return KonfigurasiIntegrasi::query()->where('Jenis', 'Email')->where('Lingkungan', 'Staging')->sole();
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
});

describe('Izin & alasan (BR-P05.2)', function (): void {
    it('hanya Teknis dan Super Admin yang bisa membuka dan mengubah integrasi', function (PeranPengelolaBawaan $peran): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota($peran));

        $konfigurasi = KonfigurasiIntegrasi::query()->create([
            'Jenis' => 'Email', 'Lingkungan' => 'Staging', 'Penyedia' => 'Smtp', 'Pengaturan' => [],
            'Kredensial' => ['KataSandi' => RAHASIA_SMTP_UJI], 'PetunjukKredensial' => [], 'KredensialDiubahPada' => now(),
        ]);

        $this->get(BantuanPengelola::Url('/integrasi'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertForbidden();
        foreach (['uji', 'aktifkan', 'nonaktifkan'] as $aksi) {
            $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/{$aksi}"))->assertForbidden();
        }
    })->with([
        PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Analis,
        PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::MitraPenjualan,
    ]);

    it('perubahan konfigurasi produksi wajib alasan dan tercatat di log audit', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));

        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Lingkungan' => 'Produksi']))->assertSessionHasErrors('Alasan');
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Lingkungan' => 'Produksi', 'Alasan' => 'Pindah ke SMTP Hostinger']))
            ->assertSessionHasNoErrors();

        expect(LogAuditPengelola::query()->where('Aksi', 'integrasi.buat')->sole()->Alasan)->toBe('Pindah ke SMTP Hostinger');
    });
});

describe('Kerahasiaan kredensial (BR-P05.1, BR-P05.6)', function (): void {
    it('kredensial terenkripsi di database, tidak tampil di halaman, dan tidak masuk log audit', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();

        $mentah = (string) DB::table('KonfigurasiIntegrasi')->value('Kredensial');
        expect($mentah)->not->toContain(RAHASIA_SMTP_UJI)
            ->and(AmbilEmailStaging()->Kredensial['KataSandi'])->toBe(RAHASIA_SMTP_UJI)
            // T2: kata sandi/rahasia SMTP disembunyikan penuh, tanpa 4 karakter terakhir.
            ->and(AmbilEmailStaging()->PetunjukKredensial)->toBe(['KataSandi' => '••••']);

        $this->get(BantuanPengelola::Url('/integrasi'))
            ->assertDontSee(RAHASIA_SMTP_UJI)
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Integrasi/Daftar')
                // v2.69/v2.70, D-57: 8 jenis platform (termasuk LoginSosial/Google dan PendaftaranMerchant/DOKU Partner) × 2 lingkungan.
                // Gerbang pembayaran QRIS tidak termasuk (diatur tenant sejak v2.06); Push (FCM) & GerbangBilling (DOKU penagih langganan) termasuk.
                ->has('Integrasi', 16)
                ->where('Integrasi.0.Konfigurasi.PetunjukKredensial.KataSandi', '••••'));
        expect($this->get(BantuanPengelola::Url('/integrasi'))->getContent())->not->toContain('9876');

        $log = LogAuditPengelola::query()->where('Aksi', 'integrasi.buat')->sole();
        expect(json_encode([$log->NilaiLama, $log->NilaiBaru]))->not->toContain(RAHASIA_SMTP_UJI)
            ->and($log->NilaiBaru['KredensialDiganti'] ?? null)->toBe(['KataSandi']);
    });

    it('kolom kredensial kosong saat menyunting mempertahankan nilai lama; kredensial pendek tidak diberi petunjuk', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $diubahPertama = AmbilEmailStaging()->KredensialDiubahPada;

        $this->travel(2)->days();
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Kredensial' => ['KataSandi' => ''], 'Pengaturan' => ['Port' => 587, 'Enkripsi' => 'Tls']]))
            ->assertSessionHasNoErrors();
        expect(AmbilEmailStaging()->Kredensial['KataSandi'])->toBe(RAHASIA_SMTP_UJI)
            ->and(AmbilEmailStaging()->KredensialDiubahPada->equalTo($diubahPertama))->toBeTrue();

        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Kredensial' => ['KataSandi' => 'pendek']]))->assertSessionHasNoErrors();
        expect(AmbilEmailStaging()->PetunjukKredensial)->toBe(['KataSandi' => '••••'])
            ->and(AmbilEmailStaging()->KredensialDiubahPada->greaterThan($diubahPertama))->toBeTrue();
    });

    it('T2: petunjuk kata sandi lama (tersimpan dengan 4 karakter terakhir) tetap ditampilkan tersembunyi penuh', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        DB::table('KonfigurasiIntegrasi')->update(['PetunjukKredensial' => json_encode(['KataSandi' => '••••9876'])]);

        $this->get(BantuanPengelola::Url('/integrasi'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Integrasi.0.Konfigurasi.PetunjukKredensial.KataSandi', '••••'));
    });

    it('konfigurasi baru wajib mengisi semua kredensial', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));

        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Kredensial' => ['KataSandi' => '']]))
            ->assertSessionHasErrors('Kredensial.KataSandi');
    });

    it('menerima berkas akun layanan Firebase yang lebih dari 2.000 karakter', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $data = DataPushUji();

        expect(mb_strlen($data['Kredensial']['AkunLayanan']))->toBeGreaterThan(2000);

        $this->post(BantuanPengelola::Url('/integrasi'), $data)->assertSessionHasNoErrors();

        $konfigurasi = KonfigurasiIntegrasi::query()->where('Jenis', 'Push')->where('Lingkungan', 'Staging')->sole();
        expect($konfigurasi->Kredensial['AkunLayanan'])->toBe($data['Kredensial']['AkunLayanan'])
            // Kunci privat tidak pernah diberi petunjuk 4 karakter terakhir.
            ->and($konfigurasi->PetunjukKredensial)->toBe(['AkunLayanan' => '••••']);
    });

    it('serialisasi model tidak memuat kredensial', function (): void {
        $konfigurasi = KonfigurasiIntegrasi::query()->create([
            'Jenis' => 'Email', 'Lingkungan' => 'Staging', 'Penyedia' => 'Smtp', 'Pengaturan' => [],
            'Kredensial' => ['KataSandi' => RAHASIA_SMTP_UJI], 'PetunjukKredensial' => [], 'KredensialDiubahPada' => now(),
        ]);

        expect($konfigurasi->toJson())->not->toContain(RAHASIA_SMTP_UJI)
            ->and(array_key_exists('Kredensial', $konfigurasi->toArray()))->toBeFalse();
    });

    it('pesan masalah penyedia tidak memuat kredensial', function (): void {
        expect(PenyaringPesan::Saring('auth failed for '.RAHASIA_SMTP_UJI, ['KataSandi' => RAHASIA_SMTP_UJI]))
            ->toBe('auth failed for [disembunyikan]');
    });
});

describe('Tes koneksi & aktivasi (BR-P05.4)', function (): void {
    it('tidak bisa aktif sebelum tes koneksi berhasil; perubahan isi menonaktifkan sampai diuji ulang', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $uuid = AmbilEmailStaging()->Uuid;

        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasErrors('Umum');

        PalsukanPengujiSmtp(false);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"))->assertSessionHasErrors('Umum');
        expect(AmbilEmailStaging()->Status)->toBe(StatusIntegrasi::Gagal);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasErrors('Umum');

        PalsukanPengujiSmtp(true);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"))->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasNoErrors();
        expect(AmbilEmailStaging()->Aktif)->toBeTrue()
            ->and(AmbilEmailStaging()->HasilUji['Berhasil'] ?? null)->toBeTrue();

        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Pengaturan' => ['Host' => 'smtp.lain.id']]))->assertSessionHasNoErrors();
        expect(AmbilEmailStaging()->Aktif)->toBeFalse()
            ->and(AmbilEmailStaging()->Status)->toBe(StatusIntegrasi::BelumDiuji)
            ->and(LogAuditPengelola::query()->whereIn('Aksi', ['integrasi.uji', 'integrasi.aktifkan'])->count())->toBe(3);
    });

    it('Turnstile: secret benar diterima, secret salah ditolak', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin));
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Captcha', 'Lingkungan' => 'Staging', 'Pengaturan' => ['KunciSitus' => '0x4AAAAAAAuji'],
            'Kredensial' => ['KunciRahasia' => '0x4AAAAAAArahasia-turnstile'], 'RotasiSetiapHari' => 180,
        ])->assertSessionHasNoErrors();
        $uuid = KonfigurasiIntegrasi::query()->where('Jenis', 'Captcha')->sole()->Uuid;

        Http::fake([PengujiTurnstile::URL_VERIFIKASI => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-secret']])
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"))->assertSessionHasErrors('Umum');
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"))->assertSessionHasNoErrors();

        expect(KonfigurasiIntegrasi::query()->where('Jenis', 'Captcha')->sole()->Status)->toBe(StatusIntegrasi::Terhubung);
    });

    it('hasil uji dibuang bila isian berubah selama pengujian', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $lama = AmbilEmailStaging();
        // Penguji palsu yang mengganti isian di tengah pengujian, meniru simpanan anggota lain.
        app()->instance(PengujiSmtp::class, new class implements PengujiKoneksi
        {
            public function Uji(array $pengaturan, array $kredensial): HasilUjiKoneksi
            {
                $konfigurasi = KonfigurasiIntegrasi::query()->sole();
                $konfigurasi->update(['Kredensial' => ['KataSandi' => 'sandi-baru-yang-belum-diuji-1234']]);

                return HasilUjiKoneksi::Berhasil('Login SMTP berhasil.');
            }
        });

        $hasil = app(UjiKoneksiIntegrasi::class)->Jalankan($lama);

        expect($hasil['Hasil']->berhasil)->toBeFalse()
            ->and(AmbilEmailStaging()->Status)->toBe(StatusIntegrasi::BelumDiuji);
    });

    it('menonaktifkan integrasi produksi wajib alasan', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['Lingkungan' => 'Produksi', 'Alasan' => 'Awal']))->assertSessionHasNoErrors();
        $konfigurasi = KonfigurasiIntegrasi::query()->where('Lingkungan', 'Produksi')->sole();
        PalsukanPengujiSmtp(true);
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/uji"))->assertSessionHasNoErrors();

        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/aktifkan"))->assertSessionHasErrors('Alasan');
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/aktifkan"), ['Alasan' => 'Rilis'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/integrasi/{$konfigurasi->Uuid}/nonaktifkan"))->assertSessionHasErrors('Alasan');
    });
});

describe('Uji berkala, alert, banner, rotasi (BR-P05.3, BR-P05.5)', function (): void {
    it('alert email ke Teknis dikirim sekali saat status berubah menjadi gagal, dan banner tampil', function (): void {
        Mail::fake();
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiIntegrasi($this, $teknis);
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $uuid = AmbilEmailStaging()->Uuid;
        PalsukanPengujiSmtp(true);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"));
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasNoErrors();

        PalsukanPengujiSmtp(false);
        $this->artisan('pengelola:uji-integrasi')->assertSuccessful();
        $this->artisan('pengelola:uji-integrasi')->assertSuccessful();

        Mail::assertSent(IntegrasiGagal::class, 1);
        Mail::assertSent(IntegrasiGagal::class, fn (IntegrasiGagal $surel) => $surel->hasTo($teknis->Email));
        expect(AmbilEmailStaging()->GagalBeruntun)->toBe(2);

        $this->get(BantuanPengelola::Url('/'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('PeringatanIntegrasi', ['Email transaksional gagal saat diuji. Fitur yang memakainya bisa terganggu.']));
    });

    it('tanpa anggota Teknis, alert dikirim ke Super Admin', function (): void {
        Mail::fake();
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        MasukSebagaiIntegrasi($this, $superAdmin);
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $uuid = AmbilEmailStaging()->Uuid;
        PalsukanPengujiSmtp(true);
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"));
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasNoErrors();

        PalsukanPengujiSmtp(false);
        $this->artisan('pengelola:uji-integrasi')->assertSuccessful();

        Mail::assertSent(IntegrasiGagal::class, fn (IntegrasiGagal $surel) => $surel->hasTo($superAdmin->Email));
    });

    it('banner mengingatkan rotasi kunci setelah masa rotasi lewat', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji(['RotasiSetiapHari' => 30]))->assertSessionHasNoErrors();
        PalsukanPengujiSmtp(true);
        $uuid = AmbilEmailStaging()->Uuid;
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"));
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasNoErrors();

        $this->travel(31)->days();
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Analis));
        $this->get(BantuanPengelola::Url('/'))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('PeringatanIntegrasi', ['Kredensial Email transaksional sudah lewat masa rotasi 30 hari. Ganti kuncinya.']));
    });
});

describe('Penerapan konfigurasi aktif (P-05)', function (): void {
    it('konfigurasi aktif lingkungan server diterapkan ke mailer dan CAPTCHA; yang nonaktif diabaikan', function (): void {
        MasukSebagaiIntegrasi($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        $this->post(BantuanPengelola::Url('/integrasi'), DataEmailUji())->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/integrasi'), [
            'Jenis' => 'Captcha', 'Lingkungan' => 'Staging', 'Pengaturan' => ['KunciSitus' => '0x4AAAAAAAuji'],
            'Kredensial' => ['KunciRahasia' => '0x4AAAAAAArahasia-turnstile'], 'RotasiSetiapHari' => 180,
        ])->assertSessionHasNoErrors();
        PalsukanPengujiSmtp(true);
        $uuid = AmbilEmailStaging()->Uuid;
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/uji"));
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/aktifkan"))->assertSessionHasNoErrors();

        app(PenerapKonfigurasiIntegrasi::class)->Terapkan();

        expect(config('mail.mailers.smtp.host'))->toBe('smtp.hostinger.com')
            ->and(config('mail.mailers.smtp.password'))->toBe(RAHASIA_SMTP_UJI)
            ->and(config('mail.from.name'))->toBe('Kasir Nusantara')
            ->and(config('integrasi.Turnstile.KunciRahasia'))->toBeNull()
            ->and(Cache::get(PenerapKonfigurasiIntegrasi::KUNCI_CACHE)[0]['Kredensial'] ?? '')->not->toContain(RAHASIA_SMTP_UJI);

        // Setelah dinonaktifkan, cache dihapus sehingga konfigurasi tidak lagi diterapkan.
        $this->post(BantuanPengelola::Url("/integrasi/{$uuid}/nonaktifkan"))->assertSessionHasNoErrors();
        config(['mail.mailers.smtp.host' => 'dari-env']);
        app(PenerapKonfigurasiIntegrasi::class)->Terapkan();
        expect(config('mail.mailers.smtp.host'))->toBe('dari-env');
    });
});
