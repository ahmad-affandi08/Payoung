<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Referensi\Enum\ZonaWaktu;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota('73.71', 'Kota Makassar', ZonaWaktu::Wita);
    Storage::fake('local');
    Mail::fake();
});

/**
 * Isian multipart seperti dikirim frontend (`forceFormData`: boolean "1"/"0").
 *
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianProfilUsahaUji(array $ubah = []): array
{
    return [
        'NamaUsaha' => 'Warung Kopi Daeng Makassar Pantai Losari',
        'Alamat' => 'Jl. Penghibur No. 12, Losari',
        'KodeKota' => '73.71',
        'Npwp' => '01.234.567.8-901.000',
        'Pkp' => '1',
        'HapusLogo' => '0',
        ...$ubah,
    ];
}

describe('F-01 langkah 1: profil usaha', function (): void {
    it('menyimpan nama, NPWP (angka saja), PKP, zona waktu dari kota ke tenant, serta alamat & kota outlet wizard', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji())
            ->assertRedirect('/kelola/panduan-awal/sektor')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Profil usaha disimpan.');

        $tenant = Tenant::query()->findOrFail($tenant->Id);
        $outlet = Outlet::query()->findOrFail($outlet->Id);
        expect($tenant->Nama)->toBe('Warung Kopi Daeng Makassar Pantai Losari')
            ->and($tenant->Npwp)->toBe('012345678901000')
            ->and($tenant->Pkp)->toBeTrue()
            ->and($tenant->ZonaWaktu)->toBe('Asia/Makassar')
            ->and($outlet->Alamat)->toBe('Jl. Penghibur No. 12, Losari')
            ->and($outlet->KodeKota)->toBe('73.71')
            ->and($outlet->ZonaWaktu)->toBe('Asia/Makassar')
            ->and($outlet->ProfilPajak['Pkp'] ?? null)->toBeTrue()
            ->and(ProgresPanduanAwal::query()->sole()->StatusLangkah['ProfilUsaha']['Status'])->toBe('Selesai')
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'tenant.profil.ubah')->count())->toBe(1)
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'outlet.ubah')->count())->toBe(1);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/profil-usaha')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/ProfilUsaha')
                ->where('Profil.NamaUsaha', 'Warung Kopi Daeng Makassar Pantai Losari')
                ->where('Profil.KodeKota', '73.71')
                ->where('Profil.Npwp', '012345678901000')
                ->where('Profil.Pkp', true)
                ->where('Profil.TautanLogo', null)
                ->where('Kota.0.Kode', '73.71')
                ->where('BatasLogo', ['UkuranMaksimalKb' => 1024, 'Ekstensi' => ['png', 'jpg', 'jpeg', 'webp']])
                ->where('Progres.Langkah.0.Status', 'Selesai'));
    });

    it('PKP tanpa NPWP ditolak; NPWP 18 angka ditolak. Kota tidak dikenal ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $tes = fn () => BantuanPanduanAwal::Masuk($this, $pemilik, $tenant);

        $tes()->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Npwp' => null]))->assertSessionHasErrors(['Npwp' => 'Usaha PKP wajib mengisi NPWP.']);
        $tes()->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Npwp' => '123456789012345678']))->assertSessionHasErrors(['Npwp' => 'NPWP berisi 15 atau 16 angka.']);
        $tes()->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['KodeKota' => '99.99']))->assertSessionHasErrors('KodeKota');
        $tes()->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Npwp' => null, 'Pkp' => '0']))->assertSessionHasNoErrors();

        expect(Tenant::query()->findOrFail($tenant->Id)->Npwp)->toBeNull();
    });

    it('logo diunggah, diganti (berkas lama dihapus), diunduh dengan izin, lalu dihapus. Semua di disk privat', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $kasir = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Logo' => UploadedFile::fake()->image('logo.png', 200, 200)]))
            ->assertSessionHasNoErrors();
        $pathPertama = Tenant::query()->findOrFail($tenant->Id)->Pengaturan['PathLogo'];
        expect($pathPertama)->toStartWith("tenant/{$tenant->Id}/logo-");
        Storage::disk('local')->assertExists($pathPertama);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Logo' => UploadedFile::fake()->image('logo-baru.jpg', 200, 200)]))
            ->assertSessionHasNoErrors();
        $pathKedua = Tenant::query()->findOrFail($tenant->Id)->Pengaturan['PathLogo'];
        expect($pathKedua)->not->toBe($pathPertama);
        Storage::disk('local')->assertMissing($pathPertama);
        Storage::disk('local')->assertExists($pathKedua);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/profil-usaha/logo')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        BantuanPanduanAwal::Masuk($this, $kasir, $tenant)->get('/kelola/panduan-awal/profil-usaha/logo')->assertForbidden();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['Logo' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf')]))
            ->assertSessionHasErrors('Logo');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/profil-usaha', IsianProfilUsahaUji(['HapusLogo' => '1']))
            ->assertSessionHasNoErrors();
        expect(Tenant::query()->findOrFail($tenant->Id)->Pengaturan)->not->toHaveKey('PathLogo');
        Storage::disk('local')->assertMissing($pathKedua);
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/profil-usaha/logo')->assertNotFound();

        $log = LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'tenant.profil.ubah')->orderBy('Id')->get();
        expect($log->pluck('NilaiBaru.Logo')->filter()->values()->all())->toBe(['Diganti', 'Diganti', 'Dihapus'])
            ->and($log->pluck('NilaiBaru')->flatten()->filter(fn ($nilai) => is_string($nilai) && str_contains($nilai, 'tenant/'))->all())->toBe([]);
    });
});
