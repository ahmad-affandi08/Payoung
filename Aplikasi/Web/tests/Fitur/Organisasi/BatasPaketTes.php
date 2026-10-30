<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Organisasi\Surel\UndanganAnggota;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * @return array<string, mixed>
 */
function IsianOutletBatasUji(int $idTenant, string $kode): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return ['Nama' => "Cabang {$kode}", 'Kode' => $kode, 'Merek' => Merek::query()->value('Uuid'), 'ZonaWaktu' => 'WIB', 'JamTutupBuku' => '04:00'];
}

function GantiPaketUji(int $idTenant, string $kodePaket): void
{
    Langganan::query()->where('IdTenant', $idTenant)->update(['IdPaket' => Paket::query()->where('Kode', $kodePaket)->value('Id')]);
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('BR-02.1 / BR-P04.3: batas outlet ditegakkan server', function (): void {
    it('paket Gratis (1 outlet) menolak outlet kedua dengan pesan ajakan upgrade', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'GRATIS');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, 'SLO1'))
            ->assertSessionHasErrors(['Umum' => 'Paket Gratis mencakup maksimal 1 outlet dan semuanya sudah terpakai (1). Tingkatkan paket atau tambah add-on di menu Langganan untuk menambah outlet.']);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Outlet::query()->count())->toBe(1);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/outlet')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('BatasOutlet', ['Batas' => 1, 'Terpakai' => 1]));
    });

    it('paket Pro (3 outlet): outlet ke-4 ditolak. Outlet arsip tidak dihitung, tetapi memulihkannya memakai kuota lagi', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'PRO');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, 'CBG1'))->assertSessionHasNoErrors();
        $masuk()->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, 'CBG2'))->assertSessionHasNoErrors();
        $masuk()->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, 'CBG3'))->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $cbg1 = Outlet::query()->where('Kode', 'CBG1')->sole();
        $masuk()->post("/kelola/outlet/{$cbg1->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $masuk()->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, 'CBG3'))->assertSessionHasNoErrors();
        $masuk()->post("/kelola/outlet/{$cbg1->Uuid}/pulihkan")->assertSessionHasErrors('Umum');

        expect($cbg1->refresh()->Status->value)->toBe('Diarsipkan');
    });

    it('paket tanpa batas (Enterprise) tidak membatasi outlet', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'GRATIS');
        GantiPaketUji($tenant->Id, 'ENTERPRISE');
        // Enterprise memuat keamanan.2fa-wajib (BR-00.8): Owner wajib 2FA aktif sebelum bisa mengelola usaha.
        BantuanAutentikasi::AktifkanDuaFaktor($pemilik);

        foreach (['CBG1', 'CBG2', 'CBG3', 'CBG4'] as $kode) {
            BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/outlet', IsianOutletBatasUji($tenant->Id, $kode))->assertSessionHasNoErrors();
        }

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Outlet::query()->count())->toBe(5);
    });
});

describe('BR-02.1 / BR-P04.3: batas pengguna ditegakkan server', function (): void {
    it('paket Gratis (2 pengguna): undangan yang menunggu ikut dihitung sehingga undangan kedua ditolak', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'GRATIS');
        $kasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid;
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/pengguna/undangan', ['Email' => 'kasir1@contoh.id', 'Peran' => $kasir, 'SemuaOutlet' => true])->assertSessionHasNoErrors();
        $masuk()->post('/kelola/pengguna/undangan', ['Email' => 'kasir2@contoh.id', 'Peran' => $kasir, 'SemuaOutlet' => true])
            ->assertSessionHasErrors('Umum');
        // Mengirim ulang ke email yang sama tidak memakai kursi tambahan.
        $masuk()->post('/kelola/pengguna/undangan', ['Email' => 'kasir1@contoh.id', 'Peran' => $kasir, 'SemuaOutlet' => true])->assertSessionHasNoErrors();

        expect(UndanganPengguna::query()->whereNull('DibatalkanPada')->pluck('Email')->all())->toBe(['kasir1@contoh.id']);

        // Membatalkan undangan membebaskan kursi.
        $masuk()->post('/kelola/pengguna/undangan/'.UndanganPengguna::query()->whereNull('DibatalkanPada')->value('Uuid').'/batalkan')->assertSessionHasNoErrors();
        $masuk()->post('/kelola/pengguna/undangan', ['Email' => 'kasir2@contoh.id', 'Peran' => $kasir, 'SemuaOutlet' => true])->assertSessionHasNoErrors();
    });

    it('undangan yang diterima setelah paket turun ditolak bila kursi sudah penuh', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'PRO');
        $kasir = BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::Kasir)->Uuid;
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/pengguna/undangan', ['Email' => 'kasir1@contoh.id', 'Peran' => $kasir, 'SemuaOutlet' => true])
            ->assertSessionHasNoErrors();
        $tautan = '';
        Mail::assertSent(UndanganAnggota::class, function (UndanganAnggota $surel) use (&$tautan): bool {
            $tautan = (string) parse_url($surel->Tautan(), PHP_URL_PATH);

            return true;
        });
        BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Admin);
        $this->post('/keluar');
        GantiPaketUji($tenant->Id, 'GRATIS');

        $this->post($tautan, ['Nama' => 'Kasir Satu', 'KataSandi' => 'kasir12345', 'KonfirmasiKataSandi' => 'kasir12345'])
            ->assertSessionHasErrors('Umum');
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->count())->toBe(2);
    });

    it('mengaktifkan kembali anggota ditolak bila kursi penuh', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant(kodePaket: 'GRATIS');
        $mantan = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdPengguna', $mantan->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);
        BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Kasir);

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->post('/kelola/tim/status', ['Aksi' => 'Aktifkan', 'UuidPengguna' => $mantan->Uuid])->assertSessionHasErrors('Umum');
        expect(TenantPengguna::query()->where('IdPengguna', $mantan->Id)->value('Status'))->toBe(StatusKeanggotaan::Nonaktif);
    });
});
