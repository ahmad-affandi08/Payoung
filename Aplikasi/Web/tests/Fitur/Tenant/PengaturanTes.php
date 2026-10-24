<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanDaftarPengaturan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Halaman Pengaturan tenant (F-01). Sebelum ini tidak ada halaman pengaturan terpusat, dan profil usaha hanya bisa
 * diubah dari dalam wizard panduan awal yang tidak punya entri menu — jadi praktis tidak bisa ditemukan lagi setelah
 * panduan selesai.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota();
    Mail::fake();
});

describe('Indeks pengaturan', function (): void {
    it('terbuka untuk semua anggota, karena butirnya difilter per izin di halaman', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->get('/kelola/pengaturan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Pengaturan/Indeks'));

        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id)->get('/kelola/pengaturan')->assertOk();
    });

    it('setiap tautan di daftar pengaturan menunjuk rute yang benar-benar ada', function (): void {
        // Butir khusus edisi Lisensi (D-35) diperiksa di EdisiLisensiTes, karena rutenya hanya ada di edisi itu.
        $tautan = BantuanDaftarPengaturan::AmbilTautanEdisi('Saas');

        expect($tautan)->not->toBeEmpty()
            ->and(BantuanDaftarPengaturan::CariTautanTanpaRute($tautan))->toBe([]);
    });
});

describe('Pengaturan › Profil usaha', function (): void {
    it('menampilkan profil usaha berjalan beserta daftar kota dan batas logo', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->get('/kelola/pengaturan/profil-usaha')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Pengaturan/ProfilUsaha')
                ->where('Profil.NamaUsaha', 'Kopi Nusantara')
                ->has('Kota')
                ->has('BatasLogo.UkuranMaksimalKb'));
    });

    it('menyimpan nama usaha & alamat outlet lalu kembali ke halaman yang sama', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/profil-usaha', [
                'NamaUsaha' => 'Kopi Nusantara Group',
                'Alamat' => 'Jl. Slamet Riyadi No. 427, Laweyan',
                'KodeKota' => '33.72',
                'Npwp' => '0012345678901234',
                'Pkp' => '1',
            ])
            ->assertRedirect(route('kelola.pengaturan.profil-usaha'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Profil usaha disimpan.');

        BantuanOrganisasi::AturKonteks($tenant->Id);

        expect(Tenant::query()->whereKey($tenant->Id)->value('Nama'))->toBe('Kopi Nusantara Group')
            ->and(Outlet::query()->value('Alamat'))->toBe('Jl. Slamet Riyadi No. 427, Laweyan');
    });

    it('menolak anggota tanpa izin outlet.kelola', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        $tes = BantuanOrganisasi::Masuk($this, $kasir, $tenant->Id);
        $tes->get('/kelola/pengaturan/profil-usaha')->assertForbidden();
        $tes->post('/kelola/pengaturan/profil-usaha', ['NamaUsaha' => 'Coba', 'KodeKota' => '33.72', 'Pkp' => '0'])
            ->assertForbidden();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Tenant::query()->whereKey($tenant->Id)->value('Nama'))->toBe('Kopi Nusantara');
    });

    it('usaha PKP wajib mengisi NPWP', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengaturan/profil-usaha', [
                'NamaUsaha' => 'Kopi Nusantara',
                'KodeKota' => '33.72',
                'Pkp' => '1',
            ])
            ->assertSessionHasErrors('Npwp');
    });
});
