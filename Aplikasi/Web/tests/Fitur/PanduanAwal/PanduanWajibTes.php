<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusLangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Referensi\Enum\ZonaWaktu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-24: panduan awal wajib untuk tenant baru. Setelah daftar, Pemilik/Admin hanya bisa membuka panduan (halaman sendiri)
 * sampai langkah wajib (profil, sektor, pajak, produk, metode bayar) selesai; perangkat kasir boleh "nanti saja".
 * Langganan, bantuan, keamanan akun tetap terbuka. Anggota lain melihat halaman "toko sedang disiapkan". Tenant lama
 * (tanpa tanda Wajib) tidak dialihkan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

it('pendaftaran baru menandai panduan wajib. Tenant lama bebas', function (): void {
    ['Tenant' => $baru] = BantuanOrganisasi::BuatTenant('Kopi Baru Wajib Panduan', panduanWajib: true);
    ['Tenant' => $lama, 'Pemilik' => $pemilikLama] = BantuanOrganisasi::BuatTenant('Kopi Lama Bebas Panduan');

    BantuanOrganisasi::AturKonteks($baru->Id);
    expect(ProgresPanduanAwal::query()->sole()->Wajib)->toBeTrue();
    BantuanOrganisasi::AturKonteks($lama->Id);
    expect(ProgresPanduanAwal::query()->where('Wajib', true)->exists())->toBeFalse();

    BantuanPanduanAwal::Masuk($this, $pemilikLama, $lama)->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda'));
});

it('pemilik tenant baru dialihkan ke panduan. Langkah wajib tidak bisa dilewati. Rute bebas tetap terbuka. Anggota lain menunggu', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Susu Wajib Panduan', panduanWajib: true);
    $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
    $masuk = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

    $masuk()->get('/kelola')->assertRedirect('/kelola/panduan-awal');
    $masuk()->get('/kelola/produk')->assertRedirect('/kelola/panduan-awal');
    $masuk()->post('/kelola/tindakan/tinjau', [])->assertRedirect('/kelola/panduan-awal');
    $masuk()->get('/kelola/panduan-awal')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/PanduanAwal/Indeks')
        ->where('Progres.Wajib', true)
        ->where('Progres.WajibBelumSelesai', ['ProfilUsaha', 'Sektor', 'Pajak', 'Produk', 'MetodePembayaran']));
    $masuk()->get('/kelola/keamanan')->assertOk();
    $masuk()->get('/kelola/langganan')->assertOk();

    $masuk()->post('/kelola/panduan-awal/langkah/pajak/lewati')->assertSessionHasErrors('Umum');
    $masuk()->post('/kelola/panduan-awal/langkah/produk/selesai')->assertSessionHasErrors('Umum');
    $masuk()->post('/kelola/panduan-awal/selesai')->assertSessionHasErrors('Umum');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->SelesaiPada)->toBeNull();

    BantuanPanduanAwal::Masuk($this, $kasir, $tenant)->get('/kelola')->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/PanduanAwal/Menunggu'));
});

it('langkah wajib selesai lewat siapkan otomatis → panduan langsung tuntas (audit kemudahan pakai #4), Beranda & menu terbuka, perangkat boleh nanti', function (): void {
    BantuanOrganisasi::BuatKota('73.71', 'Kota Makassar', ZonaWaktu::Wita);
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '73.71', '10.000000', true);
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Siap Jualan Solo', panduanWajib: true);
    $masuk = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

    $masuk()->post('/kelola/panduan-awal/profil-usaha', ['NamaUsaha' => 'Kopi Siap Jualan Solo', 'KodeKota' => '73.71', 'Pkp' => '0'])
        ->assertSessionHasNoErrors()->assertRedirect('/kelola/panduan-awal/sektor');
    $masuk()->get('/kelola')->assertRedirect('/kelola/panduan-awal');

    // Sejak v4.27 jalur otomatis menyelesaikan panduan begitu semua langkah wajib beres; langkah Perangkat tetap
    // ditawarkan (halaman berikutnya), tetapi tidak lagi mengunci back-office.
    $masuk()->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'FNB-CAF'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/panduan-awal/perangkat')
        ->assertSessionHas('Kilat', fn (string $kilat) => str_contains($kilat, 'Toko siap berjualan.'));

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->SelesaiPada)->not->toBeNull();
    // D-247: Beranda tidak lagi memuat ringkasan tindakan; butir persiapan toko ada di Kotak Tindakan.
    $masuk()->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Beranda')
        ->missing('Tindakan'));
    $masuk()->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Tindakan')
        ->where('Butir', fn ($butir) => collect($butir)->pluck('Kunci')->contains('awal.AktifkanPerangkat')));
    $masuk()->get('/kelola/produk')->assertOk();
});

it('belum ada template sektor terbit: langkah Sektor bisa dilanjutkan tanpa template (panduan tidak macet)', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kopi Tanpa Template', panduanWajib: true);
    BantuanPanduanAwal::TerbitkanTemplate();
    $masuk = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

    // Selama ada template terbit, Sektor hanya selesai lewat penerapan template.
    $masuk()->post('/kelola/panduan-awal/langkah/sektor/selesai')->assertNotFound();

    DB::table('TemplateSektorVersi')->where('Status', StatusTemplateSektor::Terbit->value)->update(['Status' => StatusTemplateSektor::Draf->value]);
    $masuk()->post('/kelola/panduan-awal/langkah/sektor/selesai')->assertRedirect();

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->AmbilStatus(LangkahPanduan::Sektor))->toBe(StatusLangkahPanduan::Selesai);
});
