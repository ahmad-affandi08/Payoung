<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusPengumuman;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PengumumanPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * P-10 PGL-19 (v3.45): pengumuman platform dari konsol (draf → terbit → cabut) tampil sebagai banner di back-office
 * (prop `PengumumanPlatform`) dan aplikasi kasir (`konfigurasi-aplikasi.Pengumuman`) sesuai sasaran paket, sektor,
 * platform, dan versi; pemeliharaan wajib berjadwal; izin `rilis.kelola`; audit.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    Cache::flush();
});

/** Beranda back-office di domain tenant (bukan domain konsol yang dipakai permintaan sebelumnya). */
function BerandaTenantPengumuman(): string
{
    return rtrim((string) config('app.url'), '/').'/kelola';
}

function MasukPengelolaPengumuman(TestCase $tes, PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Teknis): void
{
    $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** @return array<string, mixed> */
function IsianPengumuman(array $timpa = []): array
{
    return [
        'Judul' => 'Pemeliharaan server Sabtu malam',
        'Isi' => 'Sinkron berhenti sebentar. Transaksi tetap tersimpan di perangkat dan terkirim setelahnya.',
        'Jenis' => 'Pemeliharaan',
        'TampilMulai' => '2026-10-07T00:00:00Z',
        'TampilSampai' => '2026-10-10T18:00:00Z',
        'PemeliharaanMulai' => '2026-10-10T16:00:00Z',
        'PemeliharaanSelesai' => '2026-10-10T18:00:00Z',
        'Sasaran' => ['Platform' => [], 'KodePaket' => [], 'Sektor' => []],
        ...$timpa,
    ];
}

it('draf → terbit tampil di back-office & kasir sesuai sasaran; cabut menghilangkannya; terbit tidak bisa diubah; audit', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Nusantara Pengumuman');
    ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
    $kodePaket = (string) Paket::query()->whereKey(Langganan::query()->where('IdTenant', $tenant->Id)->value('IdPaket'))->value('Kode');
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
    $sektor = 'FNB-CAF';
    $sektorLain = 'RTL-GEN';
    expect(TemplateSektor::query()->whereIn('Kode', [$sektor, $sektorLain])->count())->toBe(2);
    BantuanOrganisasi::AturKonteks($tenant->Id);
    Outlet::query()->update(['TemplateSektor' => $sektor]);

    MasukPengelolaPengumuman($this);
    // Pemeliharaan tanpa jadwal ditolak; masa tampil harus mencakup pemeliharaan.
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman(['PemeliharaanMulai' => null]))->assertSessionHasErrors('PemeliharaanMulai');
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman(['TampilSampai' => '2026-10-10T17:00:00Z']))->assertSessionHasErrors('TampilMulai');
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman(['Sasaran' => ['KodePaket' => ['TIDAK-ADA']]]))->assertSessionHasErrors('Sasaran');

    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman(['Sasaran' => ['KodePaket' => [$kodePaket], 'Sektor' => [$sektor], 'Platform' => ['Web', 'Android']]]))->assertSessionHasNoErrors();
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman([
        'Judul' => 'Untuk sektor lain saja', 'Jenis' => 'Info', 'PemeliharaanMulai' => null, 'PemeliharaanSelesai' => null,
        'Sasaran' => ['Sektor' => [$sektorLain]],
    ]))->assertSessionHasNoErrors();
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman([
        'Judul' => 'Kasir versi lama perlu diperbarui', 'Jenis' => 'Penting', 'PemeliharaanMulai' => null, 'PemeliharaanSelesai' => null,
        'Sasaran' => ['Platform' => ['Android'], 'VersiMaksimal' => '0.9.0'],
    ]))->assertSessionHasNoErrors();
    $pemeliharaan = PengumumanPlatform::query()->where('Jenis', 'Pemeliharaan')->sole();

    // Draf belum tampil di mana pun.
    BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get(BerandaTenantPengumuman())->assertInertia(fn (AssertableInertia $h) => $h->where('PengumumanPlatform', []));
    expect($this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->json('Pengumuman'))->toBe([]);

    MasukPengelolaPengumuman($this);
    foreach (PengumumanPlatform::query()->get() as $p) {
        $this->post(BantuanPengelola::Url("/pengumuman/{$p->Uuid}/terbitkan"))->assertSessionHasNoErrors();
    }
    $this->put(BantuanPengelola::Url("/pengumuman/{$pemeliharaan->Uuid}"), IsianPengumuman())->assertSessionHasErrors('Umum');

    // Back-office: hanya yang cocok paket & sektor & platform Web (bukan sektor lain, bukan khusus Android).
    BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get(BerandaTenantPengumuman())->assertInertia(fn (AssertableInertia $h) => $h
        ->has('PengumumanPlatform', 1)
        ->where('PengumumanPlatform.0.Judul', 'Pemeliharaan server Sabtu malam')
        ->where('PengumumanPlatform.0.BolehDitutup', false)
        ->where('PengumumanPlatform.0.PemeliharaanMulai', '2026-10-10T16:00:00Z'));

    // Kasir Android versi 1.0.0: pemeliharaan tampil, "Penting" untuk versi ≤ 0.9.0 tidak.
    $pos = $this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->json('Pengumuman');
    expect(array_column($pos, 'Judul'))->toBe(['Pemeliharaan server Sabtu malam']);
    $penting = $this->withToken($token)->withHeader('X-Versi-Aplikasi', '0.8.0')->getJson('/api/pos/v1/konfigurasi-aplikasi')->json('Pengumuman');
    expect(array_column($penting, 'Jenis'))->toBe(['Penting', 'Pemeliharaan']);

    // Setelah masa tampil habis, hilang tanpa dicabut.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 18:01:00', 'UTC'));
    Cache::flush();
    expect($this->withToken($token)->getJson('/api/pos/v1/konfigurasi-aplikasi')->json('Pengumuman'))->toBe([]);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));

    MasukPengelolaPengumuman($this);
    $this->post(BantuanPengelola::Url("/pengumuman/{$pemeliharaan->Uuid}/cabut"), ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
    $this->post(BantuanPengelola::Url("/pengumuman/{$pemeliharaan->Uuid}/cabut"), ['Alasan' => 'Jadwal pemeliharaan diundur'])->assertSessionHasNoErrors();
    expect($pemeliharaan->refresh()->Status)->toBe(StatusPengumuman::Dicabut);
    BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get(BerandaTenantPengumuman())->assertInertia(fn (AssertableInertia $h) => $h->where('PengumumanPlatform', []));

    MasukPengelolaPengumuman($this);
    $this->get(BantuanPengelola::Url('/pengumuman'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Pengelola/Rilis/Pengumuman')
        ->has('Pengumuman', 3)
        ->has('OpsiPlatform', 5));
    expect(LogAuditPengelola::query()->where('Aksi', 'pengumuman.simpan')->count())->toBe(3)
        ->and(LogAuditPengelola::query()->where('Aksi', 'pengumuman.terbit')->count())->toBe(3)
        ->and(LogAuditPengelola::query()->where('Aksi', 'pengumuman.cabut')->sole()->Alasan)->toBe('Jadwal pemeliharaan diundur');
});

it('izin: tanpa rilis.lihat ditolak melihat maupun membuat pengumuman', function (): void {
    MasukPengelolaPengumuman($this, PeranPengelolaBawaan::Keuangan);
    $this->get(BantuanPengelola::Url('/pengumuman'))->assertForbidden();
    $this->post(BantuanPengelola::Url('/pengumuman'), IsianPengumuman())->assertForbidden();
    expect(PengumumanPlatform::query()->count())->toBe(0);
});
