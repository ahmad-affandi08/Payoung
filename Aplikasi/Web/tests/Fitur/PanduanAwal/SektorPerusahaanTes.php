<?php

declare(strict_types=1);

use App\Domain\Organisasi\Kueri\SektorOutletTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Kueri\LangkahBerikutnya;
use App\Domain\PanduanAwal\Kueri\ProgresPanduan;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-167: sektor Perusahaan (BIZ-JSA jasa profesional, BIZ-KTR kontraktor, BIZ-MFG manufaktur) menagih lewat invoice,
 * bukan kasir. Template-nya bisa diterapkan, panduan awal mengarah ke invoice pertama, checklist Beranda tidak
 * menawarkan perangkat/PIN/metode pembayaran kasir, dan Beranda tidak menampilkan omzet kasir kosong.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

it('ketiga template Perusahaan bisa diterapkan dan memuat produk contoh', function (string $kode): void {
    $versi = BantuanPanduanAwal::TerbitkanTemplate($kode);
    ['Outlet' => $outlet, 'Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant('Usaha Bisnis '.$kode);

    BantuanPanduanAwal::Terapkan($outlet, $kode);
    BantuanOrganisasi::AturKonteks($tenant->Id);

    expect(count(app(PembacaIsiTemplate::class)->Baca($versi->Isi)->produkContoh))->toBeGreaterThan(0)
        ->and(Outlet::query()->whereKey($outlet->Id)->sole()->IdTemplateSektorVersi)->not->toBeNull()
        ->and(app(SektorOutletTenant::class)->CekHanyaPerusahaan())->toBeTrue();
})->with(['BIZ-JSA', 'BIZ-KTR', 'BIZ-MFG']);

it('CekHanyaPerusahaan: salah bila belum ada sektor atau bercampur dengan sektor non-Perusahaan', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('BIZ-JSA');
    BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
    ['Outlet' => $outlet, 'Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant('Konsultan Dan Toko');

    expect(app(SektorOutletTenant::class)->CekHanyaPerusahaan())->toBeFalse();

    BantuanPanduanAwal::Terapkan($outlet, 'BIZ-JSA', ['RTL-GEN']);
    BantuanOrganisasi::AturKonteks($tenant->Id);

    expect(app(SektorOutletTenant::class)->CekHanyaPerusahaan())->toBeFalse();
});

it('siapkan otomatis template Perusahaan menyelesaikan panduan dan mengarah ke invoice pertama', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('BIZ-JSA');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Konsultan Otomatis Wajib', panduanWajib: true);
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $progres = ProgresPanduanAwal::query()->sole();
    $progres->StatusLangkah = [...($progres->StatusLangkah ?? []), 'ProfilUsaha' => ['Status' => 'Selesai', 'Pada' => now()->toIso8601ZuluString()]];
    $progres->save();

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/sektor/siapkan-otomatis', ['KodeTemplate' => 'BIZ-JSA'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/kelola/invoice/buat');

    BantuanOrganisasi::AturKonteks($tenant->Id);
    expect(ProgresPanduanAwal::query()->sole()->SelesaiPada)->not->toBeNull();
});

it('checklist Beranda usaha Perusahaan menawarkan invoice pertama, bukan perangkat, PIN, atau metode bayar kasir', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('BIZ-JSA');
    BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
    ['Outlet' => $outlet, 'Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant('Agensi Checklist');

    // Sebelum sektor dipilih: perilaku kasir biasa.
    $kunciAwal = array_column(app(LangkahBerikutnya::class)->Ambil($tenant->Id, $pemilik->Id), 'Kunci');
    expect($kunciAwal)->toContain('AturPin')->and($kunciAwal)->not->toContain('BuatInvoice');

    BantuanPanduanAwal::Terapkan($outlet, 'BIZ-JSA');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    $kunci = array_column(app(LangkahBerikutnya::class)->Ambil($tenant->Id, $pemilik->Id), 'Kunci');

    expect($kunci)->toContain('BuatInvoice')->and($kunci)->toContain('PanduanAwal')
        ->and($kunci)->not->toContain('AturPin')
        ->and($kunci)->not->toContain('AturMetodePembayaran')
        ->and($kunci)->not->toContain('AktifkanPerangkat');
});

it('Beranda usaha Perusahaan tanpa omzet kasir: Dasbor kosong; Penagihan null sebelum ada invoice', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('BIZ-KTR');
    ['Outlet' => $outlet, 'Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant('Kontraktor Beranda');
    BantuanPanduanAwal::Terapkan($outlet, 'BIZ-KTR');

    BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola')->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Beranda')->where('Dasbor', null)->where('Penagihan', null));
});

it('panduan awal usaha Perusahaan tidak memuat langkah Metode pembayaran dan Perangkat kasir; tenant biasa tetap memuatnya', function (): void {
    BantuanPanduanAwal::TerbitkanTemplate('BIZ-JSA');
    BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
    ['Outlet' => $outlet, 'Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant('Konsultan Langkah Panduan', panduanWajib: true);
    BantuanOrganisasi::AturKonteks($tenant->Id);

    $kunci = fn (): array => array_column(app(ProgresPanduan::class)->Ambil()['Langkah'], 'Kunci');
    expect($kunci())->toContain('MetodePembayaran', 'Perangkat');

    BantuanPanduanAwal::Terapkan($outlet, 'BIZ-JSA');
    BantuanOrganisasi::AturKonteks($tenant->Id);

    expect($kunci())->toBe(['ProfilUsaha', 'Sektor', 'Pajak', 'Produk'])
        ->and(app(ProgresPanduan::class)->AmbilBerikutnya(LangkahPanduan::Produk))->toBeNull()
        ->and(app(ProgresPanduan::class)->AmbilLangkahWajibBelumSelesai())->not->toContain(LangkahPanduan::MetodePembayaran)
        ->and(LangkahPanduan::Pajak->AmbilBerikutnya(tanpaKasir: true))->toBe(LangkahPanduan::Produk)
        ->and(LangkahPanduan::Produk->AmbilBerikutnya())->toBe(LangkahPanduan::MetodePembayaran);
});
