<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-e jam operasional outlet: pengelola mengatur jam per hari (jam lokal outlet), toko online tidak menerima pesanan baru
 * di luar jam itu (hitung & pesan ditolak `OutletTutup`) dan halaman toko menunjukkan kapan buka lagi; tanpa pengaturan
 * outlet selalu buka. Izin `outlet.kelola`, audit hanya bila berubah, isolasi tenant.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 05:00:00', 'UTC')); // Rabu 14 Oktober 2026 12.00 WIB
});

/** Senin–Sabtu 09:00–21:00, Minggu libur. */
function JamKedaiKopi(): array
{
    $hari = ['Buka' => '09:00', 'Tutup' => '21:00'];

    return ['Senin' => $hari, 'Selasa' => $hari, 'Rabu' => $hari, 'Kamis' => $hari, 'Jumat' => $hari, 'Sabtu' => $hari, 'Minggu' => null];
}

it('mengatur jam operasional: tersimpan, diaudit sekali, tampil di detail outlet; menghapusnya mengembalikan selalu buka', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);
    $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";

    $this->post("{$alamat}/jam-operasional", ['Jam' => JamKedaiKopi()])->assertRedirect()->assertSessionHas('Kilat');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Outlet::query()->findOrFail($k['Outlet']->Id)->JamOperasional)->toMatchArray(['Rabu' => ['Buka' => '09:00', 'Tutup' => '21:00'], 'Minggu' => null])
        ->and(LogAudit::query()->where('Peristiwa', 'outlet.jam-operasional.ubah')->count())->toBe(1);

    $this->post("{$alamat}/jam-operasional", ['Jam' => JamKedaiKopi()])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(LogAudit::query()->where('Peristiwa', 'outlet.jam-operasional.ubah')->count())->toBe(1);

    $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h
        ->where('JamOperasional.Diatur', true)
        ->where('JamOperasional.Hari.2', ['Nama' => 'Rabu', 'Buka' => '09:00', 'Tutup' => '21:00'])
        ->where('JamOperasional.Hari.6', ['Nama' => 'Minggu', 'Buka' => null, 'Tutup' => null]));

    $this->post("{$alamat}/jam-operasional", ['Jam' => null])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Outlet::query()->findOrFail($k['Outlet']->Id)->JamOperasional)->toBeNull();
    $this->get($alamat)->assertInertia(fn (AssertableInertia $h) => $h->where('JamOperasional.Diatur', false));
});

it('menolak jam tidak sah, buka sama dengan tutup, dan semua hari libur; tidak ada yang tersimpan', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);
    $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}";

    $this->post("{$alamat}/jam-operasional", ['Jam' => ['Senin' => ['Buka' => '25:00', 'Tutup' => '17:00']]])->assertSessionHasErrors('JamOperasional');
    $this->post("{$alamat}/jam-operasional", ['Jam' => ['Senin' => ['Buka' => '09:00', 'Tutup' => '09:00']]])->assertSessionHasErrors('JamOperasional');
    $this->post("{$alamat}/jam-operasional", ['Jam' => ['Senin' => null]])->assertSessionHasErrors('JamOperasional');
    $this->post("{$alamat}/jam-operasional", [])->assertSessionHasErrors('Jam');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Outlet::query()->findOrFail($k['Outlet']->Id)->JamOperasional)->toBeNull();
});

it('toko online: di jam buka menerima pesanan; di luar jam hitung & pesan ditolak OutletTutup dan halaman menunjukkan kapan buka lagi', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $k['Outlet']->forceFill(['JamOperasional' => JamKedaiKopi()])->save();
    $kirim = BantuanTokoOnline::Kiriman($k);

    // Rabu 12.00 WIB: buka.
    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', true));
    $this->postJson($k['AlamatToko'].'/keranjang/hitung', $kirim)->assertOk();

    // Rabu 22.00 WIB: tutup, buka lagi besok 09.00.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC'));
    $this->get($k['AlamatToko'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Aktif', false)
        ->where('PesanTutup', 'Buka lagi besok pukul 09.00.')
        ->where('Menu.Produk', []));
    $this->postJson($k['AlamatToko'].'/keranjang/hitung', $kirim)->assertStatus(409)->assertJsonPath('Galat.Kode', 'OutletTutup');
    $tolak = $this->postJson($k['AlamatToko'].'/pesan', $kirim)->assertStatus(409)->assertJsonPath('Galat.Kode', 'OutletTutup');
    expect((string) $tolak->json('Galat.Pesan'))->toContain('Buka lagi besok pukul 09.00.');

    // Minggu 12.00 WIB: libur, buka lagi Senin.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 05:00:00', 'UTC'));
    $this->get($k['AlamatToko'])->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', false)->where('PesanTutup', 'Buka lagi besok pukul 09.00.'));

    // Pagi Senin 08.00 WIB: belum buka, buka lagi hari ini; 09.00 pas: buka dan pesanan masuk.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC')); // Senin 19 Okt 03.00 WIB
    $this->get($k['AlamatToko'])->assertInertia(fn (AssertableInertia $h) => $h->where('PesanTutup', 'Buka lagi hari ini pukul 09.00.'));
    $this->travelTo(CarbonImmutable::parse('2026-10-19 02:00:00', 'UTC')); // 09.00 WIB
    $this->postJson($k['AlamatToko'].'/pesan', $kirim)->assertCreated();
});

it('pesan tutup manual pemilik didahulukan dari kalimat jam operasional; tanpa pengaturan jam outlet buka kapan saja', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    $kirim = BantuanTokoOnline::Kiriman($k);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 20:00:00', 'UTC')); // Kamis 03.00 WIB
    $this->postJson($k['AlamatToko'].'/keranjang/hitung', $kirim)->assertOk();

    $k['Outlet']->forceFill(['JamOperasional' => JamKedaiKopi()])->save();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->update(['PesanTutup' => 'Libur lebaran, kembali 1 Mei.']);
    $this->get($k['AlamatToko'])->assertInertia(fn (AssertableInertia $h) => $h->where('Aktif', false)->where('PesanTutup', 'Libur lebaran, kembali 1 Mei.'));
});

it('izin outlet.kelola dan isolasi tenant: kasir ditolak, outlet tenant lain 404', function (): void {
    $a = BantuanTokoOnline::Siapkan($this);
    BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->post("/kelola/outlet/{$a['Outlet']->Uuid}/jam-operasional", ['Jam' => JamKedaiKopi()])->assertForbidden();

    $b = BantuanKatalog::SiapkanTenantProduk('Warung Bakso Pak Kumis');
    BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
    $this->post("/kelola/outlet/{$a['Outlet']->Uuid}/jam-operasional", ['Jam' => JamKedaiKopi()])->assertNotFound();
});
