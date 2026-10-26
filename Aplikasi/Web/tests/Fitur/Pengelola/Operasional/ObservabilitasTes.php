<?php

declare(strict_types=1);

use App\Domain\Organisasi\Layanan\PencatatAktivitasPerangkat;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Operasional\Aksi\PeriksaKondisiOperasional;
use App\Domain\Pengelola\Operasional\Kueri\PerangkatOutboxMacet;
use App\Domain\Pengelola\Operasional\Kueri\RingkasanMetrikApi;
use App\Domain\Pengelola\Operasional\Layanan\PencatatMetrikApi;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Operasional\Model\CatatanBackup;
use App\Domain\Pengelola\Operasional\Model\MetrikApi;
use App\Domain\Pengelola\Operasional\Surel\AlertOperasionalTerbuka;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * P-11 PGL-20: error rate & p95 API dari metrik per menit, outbox perangkat macet > 2 jam, dan alert kritis juga lewat
 * WhatsApp ke Teknis yang bertugas.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-13 10:00:30', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    Cache::flush();
    // Aturan dasar alert (ambang 5%, minimum permintaan, pembukaan/penutupan insiden) diuji dengan penundaan v6.60
    // dimatikan dan minimum 30; perilaku penundaan & minimum baru diuji di blok "Penundaan alert lalu lintas API".
    config(['operasional.MinimumPermintaanMetrik' => 30, 'operasional.KonfirmasiMetrikMenit' => 0, 'operasional.PulihMetrikMenit' => 0]);
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['6001']])]);
    // Backup sehat supaya alert backup tidak ikut membuka insiden.
    CatatanBackup::query()->create(['Jenis' => 'Backup', 'Hasil' => 'Berhasil', 'SelesaiPada' => now()->subHour(), 'Sumber' => 'Skrip']);
    $this->artisan('pengelola:detak')->assertSuccessful();
});

function MasukSebagaiTeknisObservabilitas(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @param array<string, int> $nilai */
function TambahMetrikMenit(string $kelompok, int $menitLalu, array $nilai): void
{
    MetrikApi::query()->create(['Kelompok' => $kelompok, 'Menit' => now()->subMinutes($menitLalu)->startOfMinute()] + $nilai);
}

/** 100 permintaan POS: 90 ≤ 100 ms, 8 ≤ 250 ms, 2 ≤ 400 ms (p95 = 250 ms), total 12.000 ms, 3 galat server. */
function MetrikPosSehat(int $menitLalu = 2): void
{
    TambahMetrikMenit('Pos', $menitLalu, [
        'JumlahPermintaan' => 100, 'JumlahGalatServer' => 3, 'TotalDurasiMs' => 12000,
        'Hingga100Ms' => 90, 'Hingga250Ms' => 8, 'Hingga400Ms' => 2,
    ]);
}

function PesanWaTerkirim(?string $nomor = null): array
{
    return collect(Http::recorded())
        ->map(fn (array $pasangan) => $pasangan[0])
        ->filter(fn ($r) => str_contains($r->url(), 'api.fonnte.com/send') && ($nomor === null || $r['target'] === $nomor))
        ->map(fn ($r) => (string) $r['message'])
        ->values()
        ->all();
}

describe('Pencatat metrik API', function (): void {
    it('kelompok dari jalur, bucket dari durasi', function (): void {
        expect(PencatatMetrikApi::TentukanKelompok('api/pos/v1/sinkron/kirim'))->toBe('Pos')
            ->and(PencatatMetrikApi::TentukanKelompok('api/pemilik/v1/dasbor'))->toBe('Pemilik')
            ->and(PencatatMetrikApi::TentukanKelompok('api/v1/produk'))->toBe('ApiPublik')
            ->and(PencatatMetrikApi::TentukanKelompok('kelola/produk'))->toBeNull()
            ->and(PencatatMetrikApi::TentukanKelompok('sehat'))->toBeNull()
            ->and(array_map(PencatatMetrikApi::TentukanBucket(...), [0, 100, 101, 250, 400, 401, 800, 801, 1600, 1601, 9000]))->toBe([
                'Hingga100Ms', 'Hingga100Ms', 'Hingga250Ms', 'Hingga250Ms', 'Hingga400Ms', 'Hingga800Ms', 'Hingga800Ms', 'Hingga1600Ms', 'Hingga1600Ms', 'Lebih1600Ms', 'Lebih1600Ms',
            ]);
    });

    it('permintaan dihitung di cache, disiram ke MetrikApi setelah menitnya lewat, dan menit berjalan belum disiram', function (): void {
        $pencatat = app(PencatatMetrikApi::class);
        $pencatat->Catat('Pos', 200, 80);
        $pencatat->Catat('Pos', 500, 900);
        $pencatat->Catat('Pos', 401, 30);
        $pencatat->Catat('Pemilik', 200, 300);

        expect($pencatat->Vaskan())->toBe(0)->and(MetrikApi::query()->count())->toBe(0);

        $this->travel(1)->minutes();
        expect($pencatat->Vaskan())->toBe(2);
        $pos = MetrikApi::query()->where('Kelompok', 'Pos')->sole();
        expect($pos->JumlahPermintaan)->toBe(3)
            ->and($pos->JumlahGalatServer)->toBe(1, '401 bukan masalah server')
            ->and($pos->TotalDurasiMs)->toBe(1010)
            ->and([$pos->Hingga100Ms, $pos->Hingga250Ms, $pos->Hingga800Ms, $pos->Hingga1600Ms])->toBe([2, 0, 0, 1])
            ->and(MetrikApi::query()->where('Kelompok', 'Pemilik')->sole()->Hingga400Ms)->toBe(1);

        // Disiram dua kali tidak menggandakan: penghitung diambil lalu dihapus.
        expect($pencatat->Vaskan())->toBe(0)->and(MetrikApi::query()->where('Kelompok', 'Pos')->sole()->JumlahPermintaan)->toBe(3);
    });

    it('penyiraman yang terlambat menjumlahkan ke baris menit yang sama dan metrik lewat retensi dipangkas', function (): void {
        $pencatat = app(PencatatMetrikApi::class);
        $pencatat->Catat('Pos', 200, 50);
        $this->travel(1)->minutes();
        $pencatat->Vaskan();
        // Permintaan yang selesai terlambat dicatat memakai waktu aslinya (menit lalu).
        $pencatat->Catat('Pos', 200, 60, now()->subMinute());
        $pencatat->Vaskan();
        expect(MetrikApi::query()->where('Kelompok', 'Pos')->sole()->JumlahPermintaan)->toBe(2);

        TambahMetrikMenit('Pos', 8 * 24 * 60, ['JumlahPermintaan' => 5]);
        $pencatat->Vaskan();
        expect(MetrikApi::query()->count())->toBe(1);
    });

    it('API nyata dicatat per kelompok. Halaman web dan /sehat tidak', function (): void {
        $this->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertUnauthorized();
        $this->getJson('/api/pemilik/v1/profil')->assertUnauthorized();
        $this->get('/sehat');
        $this->get('/masuk');

        $this->travel(1)->minutes();
        app(PencatatMetrikApi::class)->Vaskan();

        expect(MetrikApi::query()->orderBy('Kelompok')->get(['Kelompok', 'JumlahPermintaan', 'JumlahGalatServer'])->map->only(['Kelompok', 'JumlahPermintaan', 'JumlahGalatServer'])->all())->toBe([
            ['Kelompok' => 'Pemilik', 'JumlahPermintaan' => 1, 'JumlahGalatServer' => 0],
            ['Kelompok' => 'Pos', 'JumlahPermintaan' => 1, 'JumlahGalatServer' => 0],
        ]);
    });
});

describe('Ringkasan error rate & p95', function (): void {
    it('p95 dari histogram, error rate dari masalah server, dan jendela menit', function (): void {
        MetrikPosSehat(2);
        TambahMetrikMenit('Pos', 30, ['JumlahPermintaan' => 100, 'JumlahGalatServer' => 50, 'TotalDurasiMs' => 100000, 'Hingga1600Ms' => 100]);
        TambahMetrikMenit('Pemilik', 1, ['JumlahPermintaan' => 40, 'JumlahGalatServer' => 0, 'TotalDurasiMs' => 4000, 'Hingga100Ms' => 40]);

        $ringkasan = collect(app(RingkasanMetrikApi::class)->Ambil(5))->keyBy('Kelompok');

        expect($ringkasan['Pos'])->toMatchArray(['Jumlah' => 100, 'GalatServer' => 3, 'ErrorRatePersen' => '3.00', 'RataRataMs' => 120, 'P95Ms' => 250, 'P95Lebih' => false])
            ->and($ringkasan['Pemilik'])->toMatchArray(['Jumlah' => 40, 'P95Ms' => 100])
            ->and($ringkasan['ApiPublik'])->toMatchArray(['Jumlah' => 0, 'P95Ms' => null, 'RataRataMs' => null]);

        $sejam = collect(app(RingkasanMetrikApi::class)->Ambil(60))->keyBy('Kelompok');
        expect($sejam['Pos'])->toMatchArray(['Jumlah' => 200, 'GalatServer' => 53, 'P95Ms' => 1600, 'P95Lebih' => false]);
    });
});

describe('Alert error rate dan latensi (BR-P11.1 lanjutan)', function (): void {
    it('error rate ≥ 5% dengan permintaan cukup membuka alert kritis, email sekali, lalu tertutup saat pulih', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 60, 'JumlahGalatServer' => 6, 'TotalDurasiMs' => 3000, 'Hingga100Ms' => 60]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['ErrorRateTinggi']);
        app(PeriksaKondisiOperasional::class)->Jalankan();

        $alert = AlertOperasional::query()->sole();
        expect($alert->Tingkat->value)->toBe('Kritis')
            ->and($alert->Pesan)->toContain('Pos 10.00% (6 dari 60)')
            ->and($alert->EmailTerkirimPada)->not->toBeNull();
        Mail::assertSent(AlertOperasionalTerbuka::class, 1);
        Mail::assertSent(AlertOperasionalTerbuka::class, fn (AlertOperasionalTerbuka $surel) => $surel->hasTo($teknis->Email));

        // Setelah jendela lewat dan scheduler berdetak lagi, insiden tertutup.
        $this->travel(6)->minutes();
        $this->artisan('pengelola:detak')->assertSuccessful();
        expect(AlertOperasional::query()->sole()->SelesaiPada)->not->toBeNull();
    });

    it('permintaan terlalu sedikit tidak membuka alert walau semuanya masalah', function (): void {
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 10, 'JumlahGalatServer' => 10, 'TotalDurasiMs' => 90000, 'Lebih1600Ms' => 10]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);
    });

    it('p95 API POS di atas 400 ms membuka alert peringatan, email tetapi tanpa WhatsApp. P95 Owner yang lambat tidak dinilai', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 80, 'JumlahGalatServer' => 0, 'TotalDurasiMs' => 40000, 'Hingga250Ms' => 40, 'Hingga800Ms' => 40]);
        TambahMetrikMenit('Pemilik', 1, ['JumlahPermintaan' => 80, 'JumlahGalatServer' => 0, 'TotalDurasiMs' => 90000, 'Hingga1600Ms' => 80]);
        PengaturanPlatform::query()->create(['Kunci' => 'operasional.alert-nomor-wa', 'Nilai' => json_encode(['6281234567890'])]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['LatensiPosTinggi']);

        $alert = AlertOperasional::query()->sole();
        expect($alert->Tingkat->value)->toBe('Peringatan')
            ->and($alert->Pesan)->toContain('p95 API POS sampai 800 ms')
            ->and($alert->EmailTerkirimPada)->not->toBeNull()
            ->and($alert->WaTerkirimPada)->toBeNull();
        expect(PesanWaTerkirim())->toBe([]);
    });

    it('p95 tepat di bucket 400 ms masih memenuhi target', function (): void {
        MetrikPosSehat(1);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);
    });
});

describe('Outbox perangkat macet', function (): void {
    it('awal outbox dicatat saat tidak kosong, dipertahankan selama masih ada, dan dikosongkan saat habis', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Toko Outbox Macet');
        ['Perangkat' => $perangkat] = BantuanPerangkat::BuatPerangkat($tenant->Id);
        $perangkat->forceFill(['DiaktifkanPada' => now(), 'TerakhirAktifPada' => now()])->save();
        $pencatat = app(PencatatAktivitasPerangkat::class);

        $pencatat->Catat($perangkat, '1.0.0', 4);
        $sejak = $perangkat->refresh()->OutboxTertundaSejak;
        expect($sejak)->not->toBeNull()->and($perangkat->JumlahOutboxTertunda)->toBe(4);

        $this->travel(30)->minutes();
        $pencatat->Catat($perangkat, '1.0.0', 2);
        expect($perangkat->refresh()->OutboxTertundaSejak?->equalTo($sejak))->toBeTrue()->and($perangkat->JumlahOutboxTertunda)->toBe(2);

        $pencatat->Catat($perangkat, '1.0.0', 0);
        expect($perangkat->refresh()->OutboxTertundaSejak)->toBeNull();

        // Perangkat lama yang sudah punya outbox tetapi belum punya awal diberi awal saat kabar berikutnya masuk.
        $perangkat->forceFill(['JumlahOutboxTertunda' => 7, 'OutboxTertundaSejak' => null])->save();
        $this->travel(2)->minutes();
        $pencatat->Catat($perangkat, '1.0.0', null);
        expect($perangkat->refresh()->OutboxTertundaSejak)->not->toBeNull();
    });

    it('outbox tidak kosong > 2 jam membuka alert peringatan. Daftar memuat tenant, outlet, dan perangkat. Pulih menutup alert', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Toko Outbox Macet Sragen');
        ['Tenant' => $tenantSehat] = BantuanOrganisasi::BuatTenant('Toko Lancar Jaya');
        ['Perangkat' => $macet] = BantuanPerangkat::BuatPerangkat($tenant->Id, nama: 'Kasir Depan Sragen');
        ['Perangkat' => $baru] = BantuanPerangkat::BuatPerangkat($tenant->Id, nama: 'Kasir Belakang');
        ['Perangkat' => $sehat] = BantuanPerangkat::BuatPerangkat($tenantSehat->Id, nama: 'Kasir Sehat');
        $macet->forceFill(['DiaktifkanPada' => now()->subDay(), 'TerakhirAktifPada' => now(), 'VersiAplikasi' => '1.0.0+7', 'JumlahOutboxTertunda' => 12, 'OutboxTertundaSejak' => now()->subHours(3)])->save();
        $baru->forceFill(['DiaktifkanPada' => now()->subDay(), 'JumlahOutboxTertunda' => 2, 'OutboxTertundaSejak' => now()->subMinutes(30)])->save();
        $sehat->forceFill(['DiaktifkanPada' => now()->subDay(), 'JumlahOutboxTertunda' => 0, 'OutboxTertundaSejak' => null])->save();

        $daftar = app(PerangkatOutboxMacet::class)->AmbilDaftar();
        expect(app(PerangkatOutboxMacet::class)->HitungTenant())->toBe(1)
            ->and($daftar)->toHaveCount(1)
            ->and($daftar[0])->toMatchArray(['NamaTenant' => 'Toko Outbox Macet Sragen', 'NamaPerangkat' => 'Kasir Depan Sragen', 'JumlahOutbox' => 12, 'VersiAplikasi' => '1.0.0+7'])
            ->and($daftar[0]['NamaOutlet'])->toBeString()->not->toBe('-');
        // Membaca daftar rinci lintas tenant diaudit; penghitung alert tiap menit tidak.
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->count())->toBe(1);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['OutboxMacet']);
        $alert = AlertOperasional::query()->sole();
        expect($alert->Tingkat->value)->toBe('Peringatan')->and($alert->Pesan)->toContain('1 tenant');

        $macet->forceFill(['JumlahOutboxTertunda' => 0, 'OutboxTertundaSejak' => null])->save();
        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);
        expect(AlertOperasional::query()->sole()->SelesaiPada)->not->toBeNull();
    });

    it('perangkat dicabut atau belum diaktifkan tidak dihitung macet', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Toko Perangkat Dicabut');
        ['Perangkat' => $dicabut] = BantuanPerangkat::BuatPerangkat($tenant->Id, nama: 'Kasir Lama');
        $dicabut->forceFill(['DiaktifkanPada' => now()->subDays(3), 'DicabutPada' => now()->subDay(), 'JumlahOutboxTertunda' => 9, 'OutboxTertundaSejak' => now()->subDays(2)])->save();
        ['Perangkat' => $belum] = BantuanPerangkat::BuatPerangkat($tenant->Id, nama: 'Kasir Baru');
        $belum->forceFill(['DiaktifkanPada' => null, 'JumlahOutboxTertunda' => 3, 'OutboxTertundaSejak' => now()->subDays(1)])->save();

        expect(app(PerangkatOutboxMacet::class)->HitungTenant())->toBe(0);
    });
});

describe('Alert kritis lewat WhatsApp', function (): void {
    it('alert kritis dikirim ke semua nomor sekali per insiden, tanpa nomor tidak dikirim lalu dicoba lagi setelah nomor diatur', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 60, 'JumlahGalatServer' => 6, 'TotalDurasiMs' => 3000, 'Hingga100Ms' => 60]);

        app(PeriksaKondisiOperasional::class)->Jalankan();
        expect(AlertOperasional::query()->sole()->WaTerkirimPada)->toBeNull()->and(PesanWaTerkirim())->toBe([]);

        PengaturanPlatform::query()->create(['Kunci' => 'operasional.alert-nomor-wa', 'Nilai' => json_encode(['6281234567890', '6285711112222'])]);
        app(PeriksaKondisiOperasional::class)->Jalankan();
        app(PeriksaKondisiOperasional::class)->Jalankan();

        $pesan = PesanWaTerkirim('6281234567890');
        expect(AlertOperasional::query()->sole()->WaTerkirimPada)->not->toBeNull()
            ->and($pesan)->toHaveCount(1)
            ->and($pesan[0])->toContain('Alert Kritis: Error rate API tinggi')->toContain('Pos 10.00% (6 dari 60)')->toContain('/operasional')
            ->and(PesanWaTerkirim('6285711112222'))->toHaveCount(1);
    });

    it('penyedia WhatsApp gagal: tidak mengganggu pemeriksaan dan dicoba lagi pada pemeriksaan berikutnya', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        PengaturanPlatform::query()->create(['Kunci' => 'operasional.alert-nomor-wa', 'Nilai' => json_encode(['6281234567890'])]);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 60, 'JumlahGalatServer' => 6, 'TotalDurasiMs' => 3000, 'Hingga100Ms' => 60]);
        // Stub awal di beforeEach (berhasil) menang bila ditimpa; ganti seluruh klien HTTP uji.
        Http::swap(new HttpFactory);
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'device disconnect'], 200)]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe(['ErrorRateTinggi']);
        expect(AlertOperasional::query()->sole()->WaTerkirimPada)->toBeNull();

        Http::swap(new HttpFactory);
        Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['6002']])]);
        app(PeriksaKondisiOperasional::class)->Jalankan();
        expect(AlertOperasional::query()->sole()->WaTerkirimPada)->not->toBeNull();
    });

    it('Teknis mengatur nomor penerima: dirapikan, tanpa duplikat, diaudit tersamar. Nomor tidak sah dan terlalu banyak ditolak. Peran lain ditolak', function (): void {
        $teknis = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MasukSebagaiTeknisObservabilitas($this, $teknis);

        $this->post(BantuanPengelola::Url('/operasional/nomor-wa-alert'), ['NomorWa' => "0812-3456-7890\n+62 812 3456 7890, 085711112222"])->assertSessionHasNoErrors();
        expect(json_decode((string) PengaturanPlatform::query()->where('Kunci', 'operasional.alert-nomor-wa')->value('Nilai'), true))->toBe(['6281234567890', '6285711112222']);
        $audit = LogAuditPengelola::query()->where('Aksi', 'operasional.alert.nomor-wa.atur')->sole();
        expect(json_encode($audit->NilaiBaru, JSON_UNESCAPED_UNICODE))->not->toContain('6281234567890')->toContain('62812••••7890');

        $this->post(BantuanPengelola::Url('/operasional/nomor-wa-alert'), ['NomorWa' => '12345'])->assertSessionHasErrors();
        $this->post(BantuanPengelola::Url('/operasional/nomor-wa-alert'), ['NomorWa' => '0811111111, 0811111112, 0811111113, 0811111114, 0811111115, 0811111116'])->assertSessionHasErrors();
        expect(json_decode((string) PengaturanPlatform::query()->where('Kunci', 'operasional.alert-nomor-wa')->value('Nilai'), true))->toHaveCount(2);

        $this->post(BantuanPengelola::Url('/operasional/nomor-wa-alert'), ['NomorWa' => ''])->assertSessionHasNoErrors();
        expect(json_decode((string) PengaturanPlatform::query()->where('Kunci', 'operasional.alert-nomor-wa')->value('Nilai'), true))->toBe([]);

        MasukSebagaiTeknisObservabilitas($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan));
        $this->post(BantuanPengelola::Url('/operasional/nomor-wa-alert'), ['NomorWa' => '081234567890'])->assertForbidden();
    });
});

describe('Dasbor operasional memuat metrik, outbox macet, dan nomor alert', function (): void {
    it('Teknis melihat error rate & p95 per kelompok, daftar outbox macet, dan nomor penerima', function (): void {
        MasukSebagaiTeknisObservabilitas($this, BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis));
        MetrikPosSehat(2);
        PengaturanPlatform::query()->create(['Kunci' => 'operasional.alert-nomor-wa', 'Nilai' => json_encode(['6281234567890'])]);

        $this->get(BantuanPengelola::Url('/operasional'))->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Pengelola/Operasional/Dasbor')
            ->where('Dasbor.Metrik.Jendela', 5)
            ->where('Dasbor.Metrik.BatasP95PosMs', 400)
            ->where('Dasbor.Metrik.BatasErrorRatePersen', '5')
            ->where('Dasbor.Metrik.Terkini.0.Kelompok', 'Pos')
            ->where('Dasbor.Metrik.Terkini.0.Jumlah', 100)
            ->where('Dasbor.Metrik.Terkini.0.ErrorRatePersen', '3.00')
            ->where('Dasbor.Metrik.Terkini.0.P95Ms', 250)
            ->where('Dasbor.OutboxMacet.BatasJam', 2)
            ->where('Dasbor.OutboxMacet.Daftar', [])
            ->where('Dasbor.AlertWa.Nomor', ['6281234567890'])
            ->where('Dasbor.AlertWa.Maks', 5));
    });
});

describe('Penundaan alert lalu lintas API (P-11 PGL-20, v6.60)', function (): void {
    beforeEach(function (): void {
        // Nilai bawaan produksi dari berkas konfigurasi, bukan nilai uji.
        config(['operasional' => require config_path('operasional.php')]);
    });

    /** Satu menit berlalu lalu pemeriksa berjalan, seperti detak scheduler tiap menit. */
    function PeriksaSatuMenitLagi(): array
    {
        test()->travel(1)->minutes();
        // Detak scheduler tiap menit (seperti produksi), supaya alert "Scheduler berhenti" tidak ikut muncul.
        test()->artisan('pengelola:detak')->assertSuccessful();

        return app(PeriksaKondisiOperasional::class)->Jalankan();
    }

    function MetrikPosBuruk(int $menitLalu = 1): void
    {
        TambahMetrikMenit('Pos', $menitLalu, ['JumlahPermintaan' => 120, 'JumlahGalatServer' => 12, 'TotalDurasiMs' => 6000, 'Hingga100Ms' => 120]);
    }

    it('sampel kecil (2 masalah dari 33 permintaan) tidak lagi membuka alert kritis', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 33, 'JumlahGalatServer' => 2, 'TotalDurasiMs' => 1650, 'Hingga100Ms' => 33]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe([])
            ->and(AlertOperasional::query()->count())->toBe(0);
        Mail::assertNothingSent();
    });

    it('error rate tinggi baru membuka insiden setelah bertahan 2 menit. Email sekali', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MetrikPosBuruk();

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe([])
            ->and(AlertOperasional::query()->count())->toBe(0)
            ->and(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi'])
            ->and(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi']);

        expect(AlertOperasional::query()->count())->toBe(1);
        Mail::assertSent(AlertOperasionalTerbuka::class, 1);
    });

    it('gangguan sesaat (sehat sebelum 2 menit) tidak pernah membuka insiden', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MetrikPosBuruk();

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([]);
        MetrikApi::query()->delete();

        expect(PeriksaSatuMenitLagi())->toBe([]);
        MetrikPosBuruk();

        // Hitungan mulai ulang karena kondisi sempat sehat: satu menit buruk lagi belum cukup.
        expect(PeriksaSatuMenitLagi())->toBe([])
            ->and(AlertOperasional::query()->count())->toBe(0);
        Mail::assertNothingSent();
    });

    it('insiden yang sudah terbuka tidak membuka-menutup saat sempat sehat sebentar. Baru tertutup setelah sehat 5 menit', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MetrikPosBuruk();
        app(PeriksaKondisiOperasional::class)->Jalankan();
        PeriksaSatuMenitLagi();
        expect(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi']);

        // Sehat 3 menit lalu buruk lagi: tetap insiden yang sama, tanpa email kedua.
        MetrikApi::query()->delete();
        expect(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi'])
            ->and(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi'])
            ->and(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi']);
        MetrikPosBuruk();
        expect(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi'])
            ->and(AlertOperasional::query()->count())->toBe(1)
            ->and(AlertOperasional::query()->sole()->SelesaiPada)->toBeNull();
        Mail::assertSent(AlertOperasionalTerbuka::class, 1);

        // Sehat terus 5 menit: insiden tertutup.
        MetrikApi::query()->delete();
        $hasil = [];

        for ($menit = 0; $menit < 6; $menit++) {
            $hasil[] = PeriksaSatuMenitLagi();
        }

        expect($hasil[0])->toBe(['ErrorRateTinggi'])
            ->and($hasil[5])->toBe([])
            ->and(AlertOperasional::query()->sole()->SelesaiPada)->not->toBeNull();

        // Kondisi buruk berikutnya adalah insiden baru, juga setelah ditunda 2 menit.
        MetrikPosBuruk();
        expect(PeriksaSatuMenitLagi())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe(['ErrorRateTinggi'])
            ->and(AlertOperasional::query()->count())->toBe(2);
    });

    it('celah pemeriksaan (scheduler sempat mati) mengulang hitungan, bukan dianggap buruk sepanjang celah', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        MetrikPosBuruk();
        app(PeriksaKondisiOperasional::class)->Jalankan();
        PeriksaSatuMenitLagi();

        // Tidak ada pemeriksaan selama 4 menit; metrik buruk masih ada di jendela 5 menit.
        $this->travel(4)->minutes();
        MetrikPosBuruk(0);
        $this->artisan('pengelola:detak')->assertSuccessful();

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->not->toContain('ErrorRateTinggi')
            ->and(AlertOperasional::query()->where('Kunci', 'ErrorRateTinggi')->count())->toBe(0);
    });

    it('alert latensi POS ikut ditunda, sedangkan alert non-lalu-lintas (outbox macet) tidak', function (): void {
        BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        TambahMetrikMenit('Pos', 1, ['JumlahPermintaan' => 150, 'JumlahGalatServer' => 0, 'TotalDurasiMs' => 75000, 'Hingga250Ms' => 70, 'Hingga800Ms' => 80]);

        expect(app(PeriksaKondisiOperasional::class)->Jalankan())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe([])
            ->and(PeriksaSatuMenitLagi())->toBe(['LatensiPosTinggi']);
    });
});
