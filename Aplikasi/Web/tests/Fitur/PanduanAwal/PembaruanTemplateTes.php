<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Kueri\PembaruanTemplateSektor;
use App\Domain\PanduanAwal\Layanan\PenyediaTindakanPembaruanTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * P-03 langkah 6 / BR-P03.1 (v6.04): tawaran pembaruan template sektor ke tenant. Versi terbit baru tidak mengubah data
 * tenant sampai Owner menerapkannya; penerapan aditif (BR-01.1); Kotak Tindakan menawarkan, hilang setelah diterapkan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/** Menerbitkan versi 2 FNB-CAF dengan satu kategori, satu stasiun dapur, dan satu akun beban baru. */
function TerbitkanVersiBaruUji(): void
{
    $isi = BantuanPanduanAwal::IsiTemplateAwal('FNB-CAF');
    $isi['Kategori'][] = 'Smoothie';
    $isi['StasiunDapur'][] = 'Stasiun Smoothie';
    $isi['Akun'][] = ['Kode' => '6-2999', 'Nama' => 'Beban Uji Pembaruan', 'Tipe' => 'Beban', 'SaldoNormal' => 'Debit'];
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF', $isi);
}

/** @return array{0: array<string, mixed>, 1: Outlet} */
function SiapkanTenantBerTemplateUji(): array
{
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
    BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

    return [['Tenant' => $tenant, 'Pemilik' => $pemilik], $outlet->refresh()];
}

describe('tawaran pembaruan template sektor', function (): void {
    it('tidak ada tawaran selama versi terbit sama dengan yang diterapkan', function (): void {
        SiapkanTenantBerTemplateUji();

        expect(app(PembaruanTemplateSektor::class)->AmbilTawaran())->toBe([]);
    });

    it('BR-P03.1: versi terbit baru muncul sebagai tawaran dengan perubahannya, tanpa mengubah data tenant', function (): void {
        [, $outlet] = SiapkanTenantBerTemplateUji();
        $akunSebelum = Akun::query()->count();
        $kategoriSebelum = Kategori::query()->count();

        TerbitkanVersiBaruUji();

        $tawaran = app(PembaruanTemplateSektor::class)->AmbilTawaran();
        expect($tawaran)->toHaveCount(1)
            ->and($tawaran[0]['UuidOutlet'])->toBe($outlet->Uuid)
            ->and($tawaran[0]['VersiSaatIni'])->toBe(1)
            ->and($tawaran[0]['VersiBaru'])->toBe(2)
            ->and($tawaran[0]['Perubahan'])->toBe([
                ['Bagian' => 'Akun', 'Butir' => ['6-2999 Beban Uji Pembaruan']],
                ['Bagian' => 'Kategori', 'Butir' => ['Smoothie']],
                ['Bagian' => 'Stasiun dapur', 'Butir' => ['Stasiun Smoothie']],
            ]);
        expect(Akun::query()->count())->toBe($akunSebelum)
            ->and(Kategori::query()->count())->toBe($kategoriSebelum)
            ->and($outlet->fresh()?->IdTemplateSektorVersi)->toBe($outlet->IdTemplateSektorVersi);
    });

    it('outlet tanpa template tidak mendapat tawaran', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::BuatTenant();
        TerbitkanVersiBaruUji();

        expect(app(PembaruanTemplateSektor::class)->AmbilTawaran())->toBe([]);
    });

    it('Owner menerapkan: versi tercatat, hanya data baru ditambahkan, data lama tidak diubah, audit tercatat', function (): void {
        [$konteks, $outlet] = SiapkanTenantBerTemplateUji();
        $kategoriLama = Kategori::query()->where('Nama', 'Kopi')->sole();
        $kategoriLama->forceFill(['Nama' => 'Kopi Pilihan'])->save();
        TerbitkanVersiBaruUji();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post("/kelola/pengaturan/pembaruan-template/{$outlet->Uuid}/terapkan")
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        expect($outlet->fresh()?->IdTemplateSektorVersi)->not->toBe($outlet->IdTemplateSektorVersi)
            ->and(Akun::query()->where('Kode', '6-2999')->exists())->toBeTrue()
            ->and(Kategori::query()->where('Nama', 'Smoothie')->exists())->toBeTrue()
            ->and($kategoriLama->fresh()?->Nama)->toBe('Kopi Pilihan')
            ->and(app(PembaruanTemplateSektor::class)->AmbilTawaran())->toBe([]);

        $audit = LogAudit::query()->where('Peristiwa', 'template-sektor.pembaruan.terapkan')->sole();
        expect($audit->NilaiBaru)->toMatchArray(['KodeTemplate' => 'FNB-CAF', 'DariVersi' => 1, 'KeVersi' => 2]);
    });

    it('menerapkan dua kali ditolak karena sudah terbaru', function (): void {
        [$konteks, $outlet] = SiapkanTenantBerTemplateUji();
        TerbitkanVersiBaruUji();
        $url = "/kelola/pengaturan/pembaruan-template/{$outlet->Uuid}/terapkan";

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])->post($url)->assertSessionHasNoErrors();
        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])->post($url)->assertSessionHasErrors('UuidOutlet');
    });

    it('halaman memuat tawaran; Kasir mendapat 403', function (): void {
        [$konteks] = SiapkanTenantBerTemplateUji();
        TerbitkanVersiBaruUji();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])->get('/kelola/pengaturan/pembaruan-template')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pengaturan/PembaruanTemplate')->has('Tawaran', 1)->where('Tawaran.0.VersiBaru', 2));

        $kasir = BantuanOrganisasi::TambahAnggota($konteks['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanPanduanAwal::Masuk($this, $kasir, $konteks['Tenant'])->get('/kelola/pengaturan/pembaruan-template')->assertForbidden();
    });

    it('isolasi tenant: outlet tenant lain tidak bisa diperbarui dan tawaran hanya milik tenant sendiri', function (): void {
        [$konteks] = SiapkanTenantBerTemplateUji();
        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanPanduanAwal::BuatTenant('Toko Lain');
        $outletLain = Outlet::query()->firstOrFail();
        BantuanPanduanAwal::Terapkan($outletLain, 'FNB-CAF');
        $outletLain->refresh();
        TerbitkanVersiBaruUji();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post("/kelola/pengaturan/pembaruan-template/{$outletLain->Uuid}/terapkan")
            ->assertSessionHasErrors('UuidOutlet');

        BantuanOrganisasi::AturKonteks($lain->Id);
        expect($pemilikLain->Id)->not->toBe($konteks['Pemilik']->Id)
            ->and(app(PembaruanTemplateSektor::class)->AmbilTawaran())->toHaveCount(1)
            ->and($outletLain->fresh()?->IdTemplateSektorVersi)->toBe($outletLain->IdTemplateSektorVersi);
    });
});

describe('Kotak Tindakan pembaruan template', function (): void {
    /** @param  list<string>  $izin */
    function KonteksTindakanUji(int $idTenant, bool $pemilik, array $izin = []): DataKonteksTindakan
    {
        return new DataKonteksTindakan($idTenant, 1, $pemilik, $izin, null, CarbonImmutable::now());
    }

    it('butir tampil untuk pemegang outlet.kelola, tidak untuk lainnya, dan hilang setelah diterapkan', function (): void {
        [$konteks, $outlet] = SiapkanTenantBerTemplateUji();
        $idTenant = $konteks['Tenant']->Id;
        $penyedia = app(PenyediaTindakanPembaruanTemplate::class);

        expect($penyedia->Kumpulkan(KonteksTindakanUji($idTenant, true)))->toBe([]);
        TerbitkanVersiBaruUji();

        $butir = $penyedia->Kumpulkan(KonteksTindakanUji($idTenant, true));
        expect($butir)->toHaveCount(1)
            ->and($butir[0]->kunci)->toBe('template.pembaruan')
            ->and($butir[0]->jumlah)->toBe(1)
            ->and($butir[0]->tautan)->toBe('/kelola/pengaturan/pembaruan-template')
            ->and($penyedia->Kumpulkan(KonteksTindakanUji($idTenant, false, ['penjualan.buat'])))->toBe([])
            ->and($penyedia->Kumpulkan(KonteksTindakanUji($idTenant, false, ['outlet.kelola'])))->toHaveCount(1);

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])->post("/kelola/pengaturan/pembaruan-template/{$outlet->Uuid}/terapkan");
        BantuanOrganisasi::AturKonteks($idTenant);

        expect($penyedia->Kumpulkan(KonteksTindakanUji($idTenant, true)))->toBe([]);
    });
});
