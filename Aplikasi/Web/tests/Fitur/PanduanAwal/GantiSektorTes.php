<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\StasiunDapur;
use App\Domain\Tenant\Kueri\FiturOutlet;
use App\Domain\Tenant\Model\OutletFitur;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-165 (F-01/P-03): Pemilik mengganti jenis usaha outlet. Template baru tetap aditif (BR-01.1); sektor lama dilepas;
 * sisa template lama hanya dibersihkan bila belum dipakai; transaksi & produk tidak disentuh.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/** Tenant salah memilih FNB-CAF (kafe) padahal usahanya toko kelontong RTL-GEN. @return array{0: array<string, mixed>, 1: Outlet} */
function SiapkanTenantSalahSektorUji(): array
{
    BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
    BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
    ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
    BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

    return [['Tenant' => $tenant, 'Pemilik' => $pemilik], $outlet->refresh()];
}

/** @return array<string, mixed> */
function SektorTenantUji(Tenant $tenant): array
{
    return (array) (Tenant::query()->whereKey($tenant->Id)->firstOrFail()->Pengaturan['Sektor'] ?? []);
}

describe('ganti jenis usaha (D-165)', function (): void {
    it('pratinjau menjelaskan perubahan dan tidak menulis apa pun', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();
        $kategoriSebelum = Kategori::query()->count();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->get("/kelola/pengaturan/ganti-sektor?UuidOutlet={$outlet->Uuid}&KodeTemplate=RTL-GEN")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Pengaturan/GantiSektor')
                ->where('Pratinjau.Lama.Kode', 'FNB-CAF')
                ->where('Pratinjau.Baru.Kode', 'RTL-GEN')
                ->where('Pratinjau.Baru.ModeKasir', ['Retail (scan barcode)'])
                ->where('Pratinjau.Dibersihkan.Kategori', ['Kopi', 'Non-kopi', 'Teh', 'Makanan', 'Camilan'])
                ->where('Pratinjau.Dibersihkan.Akun', ['4-1020 Penjualan Minuman', '4-1010 Penjualan Makanan'])
                ->where('Pratinjau.Dibersihkan.StasiunDapur', ['Bar', 'Dapur']));

        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        expect(Kategori::query()->count())->toBe($kategoriSebelum)
            ->and($outlet->fresh()?->TemplateSektor)->toBe('FNB-CAF')
            ->and(SektorTenantUji($konteks['Tenant']))->toBe(['FNB-CAF']);
    });

    it('menerapkan dengan pembersihan: sektor berganti, kategori & stasiun lama yang belum dipakai hilang, mode kasir mengikuti', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => 'RTL-GEN', 'Bersihkan' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/pengaturan/ganti-sektor');

        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        $kategori = Kategori::query()->pluck('Nama')->all();
        expect($outlet->fresh()?->TemplateSektor)->toBe('RTL-GEN')
            ->and(SektorTenantUji($konteks['Tenant']))->toBe(['RTL-GEN'])
            ->and($kategori)->toContain('Sembako', 'Minuman')
            ->and($kategori)->not->toContain('Kopi', 'Teh')
            ->and(Akun::query()->whereIn('Kode', ['4-1010', '4-1020'])->count())->toBe(0)
            ->and(Akun::query()->where('Kode', '4-1000')->exists())->toBeTrue()
            ->and(StasiunDapur::query()->where('Status', 'Aktif')->count())->toBe(0)
            ->and(StasiunDapur::query()->where('Status', 'Diarsipkan')->count())->toBe(2)
            ->and(OutletFitur::query()->where('IdOutlet', $outlet->Id)->where('KunciFitur', 'pos.kds')->exists())->toBeFalse()
            ->and(OutletFitur::query()->where('IdOutlet', $outlet->Id)->where('KunciFitur', 'harga.daftar-harga')->exists())->toBeTrue()
            ->and(app(FiturOutlet::class)->AmbilModeKasirPos($outlet->Id)['ModeKasir'])->toBe(['Retail']);

        $audit = LogAudit::query()->where('Peristiwa', 'sektor.ganti')->sole();
        expect($audit->NilaiLama)->toBe(['TemplateSektor' => 'FNB-CAF'])
            ->and($audit->NilaiBaru['TemplateSektor'])->toBe('RTL-GEN');
    });

    it('kategori yang sudah berisi produk dipertahankan, produk tidak disentuh', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();
        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        $kopi = Kategori::query()->where('Nama', 'Kopi')->sole();
        $produk = BantuanKatalog::BuatProduk(['IdKategori' => $kopi->Id]);

        $respons = BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->get("/kelola/pengaturan/ganti-sektor?UuidOutlet={$outlet->Uuid}&KodeTemplate=RTL-GEN");
        $respons->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Pratinjau.Dibersihkan.Kategori', ['Non-kopi', 'Teh', 'Makanan', 'Camilan'])
            ->where('Pratinjau.Dipertahankan', fn ($baris) => collect($baris)->contains(fn ($satu) => $satu['Nama'] === 'Kopi' && str_contains($satu['Alasan'], 'masih dipakai 1 produk'))));

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => 'RTL-GEN', 'Bersihkan' => true])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        expect(Kategori::query()->where('Nama', 'Kopi')->exists())->toBeTrue()
            ->and(Kategori::query()->where('Nama', 'Teh')->exists())->toBeFalse()
            ->and($produk->fresh()?->IdKategori)->toBe($kopi->Id);
    });

    it('tanpa pembersihan: data lama dibiarkan, sektor dan mode kasir tetap berganti', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => 'RTL-GEN', 'Bersihkan' => false])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        expect(Kategori::query()->pluck('Nama')->all())->toContain('Kopi', 'Sembako')
            ->and(StasiunDapur::query()->where('Status', 'Aktif')->count())->toBe(2)
            ->and(OutletFitur::query()->where('IdOutlet', $outlet->Id)->where('KunciFitur', 'pos.kds')->exists())->toBeTrue()
            ->and(SektorTenantUji($konteks['Tenant']))->toBe(['RTL-GEN'])
            ->and(app(FiturOutlet::class)->AmbilModeKasirPos($outlet->Id)['ModeKasir'])->toBe(['Retail']);
    });

    it('sektor yang sama atau yang tidak tersedia ditolak', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();
        $kirim = fn (string $kode) => BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => $kode]);

        $kirim('FNB-CAF')->assertSessionHasErrors('KodeTemplate');
        $kirim('TIDAK-ADA')->assertSessionHasErrors('KodeTemplate');
        expect($outlet->fresh()?->TemplateSektor)->toBe('FNB-CAF');
    });

    it('khusus Pemilik: Kasir ditolak; outlet tenant lain tidak bisa diganti', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();
        $kasir = BantuanOrganisasi::TambahAnggota($konteks['Tenant']->Id, PeranTenantBawaan::Kasir);

        BantuanPanduanAwal::Masuk($this, $kasir, $konteks['Tenant'])->get('/kelola/pengaturan/ganti-sektor')->assertForbidden();
        BantuanPanduanAwal::Masuk($this, $kasir, $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => 'RTL-GEN'])
            ->assertForbidden();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'KodeTemplate' => 'RTL-GEN'])
            ->assertSessionHasErrors('UuidOutlet');
        expect($outlet->fresh()?->TemplateSektor)->toBe('FNB-CAF');
    });

    it('sektor lama tetap tercatat selama masih dipakai outlet lain', function (): void {
        [$konteks, $outlet] = SiapkanTenantSalahSektorUji();
        BantuanOrganisasi::AturKonteks($konteks['Tenant']->Id);
        $kedua = $outlet->replicate(['Id', 'Uuid', 'Kode']);
        $kedua->forceFill(['Kode' => 'CBG2', 'Nama' => 'Cabang Dua'])->save();

        BantuanPanduanAwal::Masuk($this, $konteks['Pemilik'], $konteks['Tenant'])
            ->post('/kelola/pengaturan/ganti-sektor', ['UuidOutlet' => $outlet->Uuid, 'KodeTemplate' => 'RTL-GEN', 'Bersihkan' => false])
            ->assertSessionHasNoErrors();

        expect(SektorTenantUji($konteks['Tenant']))->toBe(['FNB-CAF', 'RTL-GEN']);
    });
});
