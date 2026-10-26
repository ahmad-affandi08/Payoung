<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Operasional\Aksi\KelolaInsiden;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Kueri\StatusPlatformPublik;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Domain\Tenant\Model\PembaruanInsiden;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * P-11 PGL-21 (v5.95): manajemen insiden di konsol (Teknis/Super Admin) dan halaman status publik. BR-P11.2: insiden
 * berdampak > 10% tenant aktif langsung diumumkan. Kronologi append-only, status maju saja, post-mortem setelah selesai,
 * halaman publik hanya memuat yang diumumkan dan tidak membocorkan data internal; ekspor statis untuk hosting terpisah.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    Cache::flush();
});

function MasukPengelolaInsiden(TestCase $tes, PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Teknis): void
{
    $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @return array<string, mixed> */
function IsianInsiden(array $timpa = []): array
{
    return [
        'Judul' => 'Sinkron kasir tertunda',
        'Tingkat' => 'Besar',
        'Dampak' => 'Transaksi dari aplikasi kasir terkirim lebih lambat dari biasanya. Data tetap aman di perangkat.',
        'Komponen' => ['Kasir'],
        'PersenTenantTerdampak' => 4,
        'Diumumkan' => true,
        'KronologiAwal' => 'Antrean sinkron menumpuk sejak pukul 10.00, tim sedang memeriksa.',
        ...$timpa,
    ];
}

function StatusPublik(TestCase $tes): array
{
    return $tes->getJson('https://payoung.test/status/data')->assertOk()->json();
}

describe('mencatat dan memperbarui insiden', function (): void {
    it('Teknis membuka insiden: kronologi awal, audit. Validasi judul, komponen, persen, dan waktu mulai', function (): void {
        MasukPengelolaInsiden($this);
        $url = BantuanPengelola::Url('/operasional/insiden');

        $this->post($url, IsianInsiden(['Judul' => 'x']))->assertSessionHasErrors('Judul');
        $this->post($url, IsianInsiden(['Komponen' => []]))->assertSessionHasErrors('Komponen');
        $this->post($url, IsianInsiden(['Komponen' => ['Radio']]))->assertSessionHasErrors('Komponen.0');
        $this->post($url, IsianInsiden(['PersenTenantTerdampak' => 150]))->assertSessionHasErrors('PersenTenantTerdampak');
        $this->post($url, IsianInsiden(['Tingkat' => 'Sepele']))->assertSessionHasErrors('Tingkat');
        $this->post($url, IsianInsiden(['KronologiAwal' => 'pendek']))->assertSessionHasErrors('KronologiAwal');
        $this->post($url, IsianInsiden(['MulaiPada' => '2026-10-20T00:00:00Z']))->assertSessionHasErrors('MulaiPada');
        expect(InsidenPlatform::query()->count())->toBe(0);

        $this->post($url, IsianInsiden())->assertSessionHasNoErrors()->assertSessionHas('Kilat');
        $insiden = InsidenPlatform::query()->sole();
        expect($insiden->Status)->toBe(StatusInsiden::Diselidiki)
            ->and($insiden->DiumumkanPada)->not->toBeNull()
            ->and($insiden->Komponen)->toBe(['Kasir'])
            ->and(PembaruanInsiden::query()->where('IdInsiden', $insiden->Id)->sole()->Isi)->toContain('Antrean sinkron menumpuk')
            ->and(LogAuditPengelola::query()->where('Aksi', 'insiden.buka')->count())->toBe(1);
    });

    it('BR-P11.2: dampak di atas 10% tenant aktif selalu langsung diumumkan walau dicatat sebagai internal; 10% persis tidak dipaksa', function (): void {
        MasukPengelolaInsiden($this);
        $url = BantuanPengelola::Url('/operasional/insiden');

        $this->post($url, IsianInsiden(['Judul' => 'Gangguan luas pembayaran', 'PersenTenantTerdampak' => 35, 'Diumumkan' => false]))->assertSessionHasNoErrors();
        $this->post($url, IsianInsiden(['Judul' => 'Tepat sepuluh persen', 'PersenTenantTerdampak' => 10, 'Diumumkan' => false]))->assertSessionHasNoErrors();

        expect(InsidenPlatform::query()->where('Judul', 'Gangguan luas pembayaran')->sole()->DiumumkanPada)->not->toBeNull()
            ->and(InsidenPlatform::query()->where('Judul', 'Tepat sepuluh persen')->sole()->DiumumkanPada)->toBeNull();
        expect(LogAuditPengelola::query()->where('Aksi', 'insiden.buka')->get()->map(fn ($l) => $l->NilaiBaru['DiumumkanOtomatis'] ?? null)->all())->toContain(true);
    });

    it('pembaruan: status hanya maju, kronologi bertambah, selesai itu final dan mengisi waktu selesai', function (): void {
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertSessionHasNoErrors();
        $insiden = InsidenPlatform::query()->sole();
        $url = BantuanPengelola::Url("/operasional/insiden/{$insiden->Uuid}/pembaruan");

        $this->post($url, ['Status' => 'Dipantau', 'Isi' => 'x'])->assertSessionHasErrors('Isi');
        $this->post($url, ['Status' => 'Teridentifikasi', 'Isi' => 'Penyebab ditemukan: antrean tersumbat satu pekerja.'])->assertSessionHasNoErrors();
        // Maju ke Dipantau, lalu boleh mundur ke Teridentifikasi bila berulang.
        $this->post($url, ['Status' => 'Dipantau', 'Isi' => 'Perbaikan terpasang, kami pantau.'])->assertSessionHasNoErrors();
        $this->post($url, ['Status' => 'Teridentifikasi', 'Isi' => 'Gangguan muncul lagi, memeriksa ulang.'])->assertSessionHasNoErrors();
        $this->post($url, ['Status' => 'Diselidiki', 'Isi' => 'Kembali ke awal tidak boleh.'])->assertSessionHasErrors('Status');

        // Sesi konsol berakhir setelah 30 menit tidak aktif, jadi lompatan waktu dibuat lebih pendek dari itu.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 03:20:00', 'UTC'));
        $this->post($url, ['Status' => 'Selesai', 'Isi' => 'Layanan pulih sepenuhnya, antrean kosong.'])->assertSessionHasNoErrors();
        $insiden->refresh();
        expect($insiden->Status)->toBe(StatusInsiden::Selesai)
            ->and($insiden->SelesaiPada?->toIso8601ZuluString())->toBe('2026-10-14T03:20:00Z')
            ->and(PembaruanInsiden::query()->where('IdInsiden', $insiden->Id)->count())->toBe(5);

        $this->post($url, ['Status' => 'Selesai', 'Isi' => 'Pembaruan setelah selesai ditolak.'])->assertSessionHasErrors(['Umum' => 'Insiden ini sudah selesai dan tidak bisa diperbarui.']);
        expect(PembaruanInsiden::query()->where('IdInsiden', $insiden->Id)->count())->toBe(5);
    });

    it('insiden internal bisa diumumkan belakangan, sekali saja', function (): void {
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['Diumumkan' => false]))->assertSessionHasNoErrors();
        $insiden = InsidenPlatform::query()->sole();
        expect($insiden->DiumumkanPada)->toBeNull()
            ->and(StatusPublik($this)['Aktif'])->toBe([]);

        $this->post(BantuanPengelola::Url("/operasional/insiden/{$insiden->Uuid}/umumkan"))->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$insiden->Uuid}/umumkan"))->assertSessionHasErrors(['Umum' => 'Insiden ini sudah tampil di halaman status.']);
        expect(StatusPublik($this)['Aktif'])->toHaveCount(1);
    });

    it('post-mortem: hanya setelah selesai, terbit hanya bila diumumkan, dan yang terbit tidak diubah', function (): void {
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertSessionHasNoErrors();
        $insiden = InsidenPlatform::query()->sole();
        $teks = 'Antrean sinkron tersumbat karena satu pekerja berhenti. Penyebab: memori habis. Pencegahan: batas memori dan alert antrean.';
        $url = BantuanPengelola::Url("/operasional/insiden/{$insiden->Uuid}/post-mortem");

        $this->post($url, ['PostMortem' => $teks, 'Terbitkan' => false])->assertSessionHasErrors(['Umum' => 'Post-mortem ditulis setelah insiden selesai.']);
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$insiden->Uuid}/pembaruan"), ['Status' => 'Selesai', 'Isi' => 'Layanan pulih sepenuhnya.'])->assertSessionHasNoErrors();
        $this->post($url, ['PostMortem' => 'terlalu pendek', 'Terbitkan' => false])->assertSessionHasErrors('PostMortem');

        $this->post($url, ['PostMortem' => $teks, 'Terbitkan' => false])->assertSessionHasNoErrors();
        expect(StatusPublik($this)['Riwayat'][0]['PostMortem'])->toBeNull();

        $this->post($url, ['PostMortem' => $teks, 'Terbitkan' => true])->assertSessionHasNoErrors();
        expect(StatusPublik($this)['Riwayat'][0]['PostMortem'])->toBe($teks);
        $this->post($url, ['PostMortem' => $teks.' Tambahan.', 'Terbitkan' => true])->assertSessionHasErrors(['Umum' => 'Post-mortem yang sudah terbit tidak diubah lagi.']);

        // Insiden internal tidak boleh menerbitkan post-mortem publik.
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['Judul' => 'Gangguan internal saja', 'Diumumkan' => false]))->assertSessionHasNoErrors();
        $dalam = InsidenPlatform::query()->where('Judul', 'Gangguan internal saja')->sole();
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$dalam->Uuid}/pembaruan"), ['Status' => 'Selesai', 'Isi' => 'Sudah beres sendiri.'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$dalam->Uuid}/post-mortem"), ['PostMortem' => $teks, 'Terbitkan' => true])
            ->assertSessionHasErrors(['Umum' => 'Umumkan insiden ini dulu sebelum menerbitkan post-mortem.']);
    });

    it('izin: Keuangan tidak boleh melihat. Peran yang boleh melihat tetapi tidak mengelola ditolak menulis', function (): void {
        MasukPengelolaInsiden($this, PeranPengelolaBawaan::Keuangan);
        $this->get(BantuanPengelola::Url('/operasional/insiden'))->assertForbidden();
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertForbidden();

        MasukPengelolaInsiden($this);
        $this->get(BantuanPengelola::Url('/operasional/insiden'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Operasional/Insiden')
            ->where('Insiden', [])
            ->where('AmbangWajibUmumPersen', 10)
            ->has('OpsiKomponen', 7));
    });
});

describe('halaman status publik', function (): void {
    it('normal bila tidak ada insiden. Komponen terdampak mengikuti tingkat insiden aktif yang diumumkan. Insiden selesai pindah ke riwayat', function (): void {
        $normal = StatusPublik($this);
        expect($normal['Keseluruhan'])->toBe('Normal')
            ->and($normal['LabelKeseluruhan'])->toBe('Semua layanan berjalan normal')
            ->and(collect($normal['Komponen'])->pluck('Kondisi')->unique()->all())->toBe(['Normal'])
            ->and($normal['Aktif'])->toBe([])
            ->and($normal['Riwayat'])->toBe([]);

        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['Tingkat' => 'Kecil', 'Komponen' => ['Kasir', 'Notifikasi']]))->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['Judul' => 'Pembayaran QRIS gagal', 'Tingkat' => 'Kritis', 'Komponen' => ['Pembayaran', 'Kasir'], 'PersenTenantTerdampak' => 30]))->assertSessionHasNoErrors();
        // Insiden internal tidak ikut menentukan status publik.
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['Judul' => 'Investigasi internal', 'Tingkat' => 'Kritis', 'Komponen' => ['Situs'], 'Diumumkan' => false]))->assertSessionHasNoErrors();

        $status = StatusPublik($this);
        $kondisi = collect($status['Komponen'])->pluck('Kondisi', 'Kode');
        expect($status['Keseluruhan'])->toBe('Besar')
            ->and($kondisi['Kasir'])->toBe('Besar')
            ->and($kondisi['Pembayaran'])->toBe('Besar')
            ->and($kondisi['Notifikasi'])->toBe('Sebagian')
            ->and($kondisi['Situs'])->toBe('Normal')
            ->and($status['Aktif'])->toHaveCount(2);

        $aktif = collect($status['Aktif'])->firstWhere('Judul', 'Pembayaran QRIS gagal');
        $uuid = $aktif['Uuid'];
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$uuid}/pembaruan"), ['Status' => 'Selesai', 'Isi' => 'Pembayaran QRIS pulih.'])->assertSessionHasNoErrors();
        $status = StatusPublik($this);
        expect($status['Aktif'])->toHaveCount(1)
            ->and($status['Riwayat'])->toHaveCount(1)
            ->and($status['Riwayat'][0]['Judul'])->toBe('Pembayaran QRIS gagal')
            ->and($status['Riwayat'][0]['Pembaruan'][0]['Isi'])->toBe('Pembayaran QRIS pulih.')
            ->and(collect($status['Komponen'])->pluck('Kondisi', 'Kode')['Pembayaran'])->toBe('Normal');
    });

    it('tidak membocorkan data internal. Riwayat hanya 30 hari terakhir. Tembolok dilupakan saat insiden berubah', function (): void {
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden(['PersenTenantTerdampak' => 55]))->assertSessionHasNoErrors();
        $mentah = $this->get('https://payoung.test/status/data')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->getContent();
        expect($mentah)->not->toContain('PersenTenantTerdampak')->not->toContain('DibuatOleh')->not->toContain('55');

        // Insiden lama (selesai 40 hari lalu) tidak muncul; yang baru selesai muncul.
        $lama = InsidenPlatform::query()->create([
            'Judul' => 'Gangguan bulan lalu', 'Tingkat' => 'Kecil', 'Status' => StatusInsiden::Selesai, 'Dampak' => 'Dampak lama yang sudah lewat.',
            'Komponen' => ['Situs'], 'MulaiPada' => now()->subDays(41), 'DiumumkanPada' => now()->subDays(41), 'SelesaiPada' => now()->subDays(40),
        ]);
        StatusPlatformPublik::LupakanTembolok();
        expect(collect(StatusPublik($this)['Riwayat'])->pluck('Judul')->all())->not->toContain($lama->Judul);

        // Tembolok 30 detik: perubahan lewat aksi langsung terlihat, tanpa menunggu.
        $baru = InsidenPlatform::query()->where('Judul', 'Sinkron kasir tertunda')->sole();
        $this->post(BantuanPengelola::Url("/operasional/insiden/{$baru->Uuid}/pembaruan"), ['Status' => 'Dipantau', 'Isi' => 'Perbaikan terpasang, dipantau.'])->assertSessionHasNoErrors();
        expect(StatusPublik($this)['Aktif'][0]['LabelStatus'])->toBe('Dipantau');
    });

    it('halaman /status memakai shell situs dan memuat data awal. Hanya domain pemasaran', function (): void {
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertSessionHasNoErrors();

        $this->get('https://payoung.test/status')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/StatusPlatform')
            ->where('Status.Keseluruhan', 'Besar')
            ->where('Status.Aktif.0.Judul', 'Sinkron kasir tertunda'));
    });
});

describe('ekspor statis untuk hosting terpisah', function (): void {
    it('status:ekspor menulis status.json dan index.html, tanpa tujuan gagal; JSON sama dengan yang disajikan', function (): void {
        $folder = sys_get_temp_dir().'/status-ekspor-'.bin2hex(random_bytes(4));
        MasukPengelolaInsiden($this);
        $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertSessionHasNoErrors();

        expect(Artisan::call('status:ekspor'))->toBe(1);
        expect(Artisan::call('status:ekspor', ['tujuan' => $folder]))->toBe(0);

        $json = json_decode((string) File::get("{$folder}/status.json"), true);
        expect($json['Aktif'][0]['Judul'])->toBe('Sinkron kasir tertunda')
            ->and($json)->toBe(StatusPublik($this))
            ->and(File::exists("{$folder}/status.json.tmp"))->toBeFalse()
            ->and((string) File::get("{$folder}/index.html"))->toContain('status.json')->toContain('Status layanan Payoung');

        File::deleteDirectory($folder);
    });

    it('warna di halaman statis sama dengan token Gaya/Aplikasi.css (tidak melenceng dari desain)', function (): void {
        $css = (string) File::get(base_path('resources/js/Gaya/Aplikasi.css'));
        $html = (string) File::get(resource_path('status-statis/index.html'));
        $peta = ['--latar' => '--color-latar', '--permukaan' => '--color-permukaan', '--garis' => '--color-garis', '--teks-utama' => '--color-teks-utama',
            '--teks-sekunder' => '--color-teks-sekunder', '--brand' => '--color-brand', '--brand-gelap' => '--color-brand-gelap',
            '--sukses' => '--color-sukses', '--peringatan' => '--color-peringatan', '--bahaya' => '--color-bahaya'];

        foreach ($peta as $lokal => $token) {
            preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-f]{6})/i', $css, $nilaiToken);
            preg_match('/^\s*'.preg_quote($lokal, '/').':\s*(#[0-9a-f]{6})/mi', $html, $nilaiLokal);
            expect($nilaiLokal[1] ?? null)->toBe($nilaiToken[1] ?? 'tidak-ada', "Warna {$lokal} berbeda dari {$token}");
        }
    });
});

it('aksi memakai transaksi: kegagalan pembaruan tidak meninggalkan kronologi setengah jadi', function (): void {
    MasukPengelolaInsiden($this);
    $this->post(BantuanPengelola::Url('/operasional/insiden'), IsianInsiden())->assertSessionHasNoErrors();
    $insiden = InsidenPlatform::query()->sole();
    $pelaku = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);

    expect(fn () => app(KelolaInsiden::class)->Perbarui($pelaku, $insiden, StatusInsiden::Selesai, 'pdk'))->toThrow(PelanggaranAturanBisnis::class);
    expect(PembaruanInsiden::query()->where('IdInsiden', $insiden->Id)->count())->toBe(1)
        ->and($insiden->refresh()->Status)->toBe(StatusInsiden::Diselidiki);
});
