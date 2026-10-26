<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Tindakan\Model\LanggananRingkasanTindakan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-23 D bagian 4: ringkasan pagi Kotak Tindakan lewat WhatsApp (D-33: notifikasi tenant tidak memakai email). Owner bawaan berlangganan, anggota lain memilih sendiri
 * di halaman Kotak Tindakan. Isi mengikuti izin penerima (butir Penting & Perhatian saja); tanpa butir tidak dikirim;
 * paling banyak sekali per tanggal bisnis.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['6001']])]);
});

/** Pesan WhatsApp ringkasan yang dikirim ke penyedia (Fonnte), per nomor tujuan. */
function RingkasanTerkirim(?string $nomor = null): Collection
{
    return collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])
        ->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send') && ($nomor === null || $r['target'] === $nomor))
        ->values();
}

function AturNoHpUji(Pengguna $pengguna, string $noHp): void
{
    Pengguna::query()->whereKey($pengguna->Id)->update(['NoHp' => $noHp]);
}

it('Owner menerima ringkasan; Akuntan setelah berlangganan; Kasir tanpa butir tidak menerima. Sekali per hari. Bisa berhenti', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kelontong Makmur Sragen');
    $idTenant = $t['Tenant']->Id;
    $akuntan = BantuanOrganisasi::TambahAnggota($idTenant, PeranTenantBawaan::Akuntan);
    $kasir = BantuanOrganisasi::TambahAnggota($idTenant, PeranTenantBawaan::Kasir);
    AturNoHpUji($t['Pemilik'], '081211110001');
    AturNoHpUji($akuntan, '081211110002');
    AturNoHpUji($kasir, '081211110003');
    BantuanOrganisasi::AturKonteks($idTenant);
    $akun = fn (string $kode): string => (string) Akun::query()->where('Kode', $kode)->value('Uuid');

    // Januari punya jurnal dan belum ditutup buku → butir "Tutup buku Januari 2026" (Perhatian, izin akuntansi).
    $this->travelTo(CarbonImmutable::parse('2026-01-31 10:00', 'Asia/Jakarta'));
    BantuanOrganisasi::Masuk($this, $akuntan, $idTenant)->post('/kelola/akuntansi/kas-bank', [
        'Jenis' => 'Pengeluaran',
        'Tanggal' => '2026-01-31',
        'UuidAkunSumber' => $akun('1-1100'),
        'UuidAkunTujuan' => $akun('6-2000'),
        'Jumlah' => '4500000',
        'Keterangan' => 'Sewa ruko Jl. Raya Sukowati Sragen',
    ])->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-02-12 07:00', 'Asia/Jakarta'));
    $jalankan = fn () => Artisan::call('tindakan:kirim-ringkasan-harian', ['--tenant' => [$idTenant]]);

    expect($jalankan())->toBe(0);
    expect(RingkasanTerkirim())->toHaveCount(1);
    $pesan = (string) RingkasanTerkirim('6281211110001')[0]['message'];
    expect($pesan)->toContain('Selamat pagi')
        ->and($pesan)->toContain('ringkasan Toko Kelontong Makmur Sragen hari ini (12 Februari 2026)')
        ->and($pesan)->toContain('Tutup buku Januari 2026')
        ->and($pesan)->toContain('/kelola/tindakan');
    Mail::assertNothingSent();

    // Akuntan & Kasir berlangganan dari halaman Kotak Tindakan.
    BantuanOrganisasi::Masuk($this, $akuntan, $idTenant)->get('/kelola/tindakan')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('RingkasanWhatsapp', ['BisaWhatsapp' => true, 'Aktif' => false]));
    BantuanOrganisasi::Masuk($this, $akuntan, $idTenant)->put('/kelola/tindakan/ringkasan-whatsapp', ['Aktif' => true])
        ->assertSessionHas('Kilat', 'Ringkasan dikirim ke WhatsApp Anda setiap pagi.');
    BantuanOrganisasi::Masuk($this, $kasir, $idTenant)->put('/kelola/tindakan/ringkasan-whatsapp', ['Aktif' => true])->assertSessionHasNoErrors();

    // Jalan ulang di hari yang sama: Owner tidak dikirim lagi; Akuntan menerima; Kasir tidak punya butir.
    $jalankan();
    expect(RingkasanTerkirim())->toHaveCount(2)
        ->and(RingkasanTerkirim('6281211110002'))->toHaveCount(1)
        ->and(RingkasanTerkirim('6281211110003'))->toHaveCount(0);

    // Owner berhenti; esok hari hanya Akuntan.
    BantuanOrganisasi::Masuk($this, $t['Pemilik'], $idTenant)->get('/kelola/tindakan')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('RingkasanWhatsapp.Aktif', true));
    BantuanOrganisasi::Masuk($this, $t['Pemilik'], $idTenant)->put('/kelola/tindakan/ringkasan-whatsapp', ['Aktif' => false])->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-02-13 07:00', 'Asia/Jakarta'));
    $jalankan();
    expect(RingkasanTerkirim())->toHaveCount(3)
        ->and(RingkasanTerkirim('6281211110001'))->toHaveCount(1);

    BantuanOrganisasi::AturKonteks($idTenant);
    expect(LanggananRingkasanTindakan::query()->where('IdPengguna', $t['Pemilik']->Id)->value('Aktif'))->toBeFalse();
});

it('tanpa butir penting/perhatian tidak ada pesan. Langganan milik tenant sendiri, tanpa nomor HP tidak bisa berlangganan', function (): void {
    $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
    $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
    AturNoHpUji($a['Pemilik'], '081211110009');

    expect(Artisan::call('tindakan:kirim-ringkasan-harian'))->toBe(0);
    expect(RingkasanTerkirim())->toHaveCount(0);
    Mail::assertNothingSent();

    BantuanOrganisasi::Masuk($this, $a['Pemilik'], $a['Tenant']->Id)->put('/kelola/tindakan/ringkasan-whatsapp', ['Aktif' => false])->assertSessionHasNoErrors();
    AturNoHpUji($b['Pemilik'], '');
    BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id)->put('/kelola/tindakan/ringkasan-whatsapp', ['Aktif' => true])
        ->assertSessionHasErrors(['Aktif' => 'Akun Anda belum punya nomor HP. Tambahkan nomor HP dulu untuk menerima ringkasan.']);
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(LanggananRingkasanTindakan::query()->count())->toBe(0);
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect(LanggananRingkasanTindakan::query()->count())->toBe(1);
});
