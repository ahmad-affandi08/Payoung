<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\BatasiTenantDitangguhkan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota();
    Mail::fake();
});

/**
 * Semua rute panduan awal (metode, URL dengan parameter contoh).
 *
 * @return list<array{0: string, 1: string}>
 */
function DaftarRutePanduanAwalUji(): array
{
    $uuid = strtolower((string) Str::ulid());

    return [
        ['GET', '/kelola/panduan-awal'],
        ['GET', '/kelola/panduan-awal/profil-usaha'],
        ['POST', '/kelola/panduan-awal/profil-usaha'],
        ['GET', '/kelola/panduan-awal/profil-usaha/logo'],
        ['GET', '/kelola/panduan-awal/sektor'],
        ['POST', '/kelola/panduan-awal/sektor'],
        ['POST', '/kelola/panduan-awal/sektor/siapkan-otomatis'],
        ['GET', '/kelola/panduan-awal/pajak'],
        ['POST', '/kelola/panduan-awal/pajak'],
        ['GET', '/kelola/panduan-awal/produk'],
        ['POST', '/kelola/panduan-awal/produk/contoh'],
        ['POST', '/kelola/panduan-awal/produk'],
        ['GET', '/kelola/panduan-awal/metode-pembayaran'],
        ['POST', '/kelola/panduan-awal/metode-pembayaran'],
        ['POST', "/kelola/panduan-awal/metode-pembayaran/{$uuid}/nonaktifkan"],
        ['POST', "/kelola/panduan-awal/metode-pembayaran/{$uuid}/batas-hari-menunggu"],
        ['POST', "/kelola/panduan-awal/metode-pembayaran/{$uuid}/teks-qris"],
        ['POST', "/kelola/panduan-awal/metode-pembayaran/{$uuid}/aktifkan"],
        ['GET', "/kelola/panduan-awal/metode-pembayaran/{$uuid}/gambar-qris"],
        ['GET', '/kelola/panduan-awal/perangkat'],
        ['POST', '/kelola/panduan-awal/perangkat'],
        ['POST', "/kelola/panduan-awal/perangkat/{$uuid}/kode-aktivasi"],
        ['POST', '/kelola/panduan-awal/langkah/pajak/lewati'],
        ['POST', '/kelola/panduan-awal/langkah/produk/selesai'],
        ['POST', '/kelola/panduan-awal/selesai'],
    ];
}

describe('Izin panduan-awal.kelola (F-01, §19.1)', function (): void {
    it('daftar rute uji mencakup semua rute kelola.panduan-awal', function (): void {
        $terdaftar = collect(app('router')->getRoutes()->getRoutesByName())
            ->filter(fn ($rute, string $nama) => str_starts_with($nama, 'kelola.panduan-awal'))
            ->count();

        expect(count(DaftarRutePanduanAwalUji()))->toBe($terdaftar);
    });

    it('Pemilik dan Admin bisa membuka panduan awal', function (PeranTenantBawaan $peran): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $pengguna = $peran === PeranTenantBawaan::Pemilik ? $pemilik : BantuanOrganisasi::TambahAnggota($tenant->Id, $peran);

        BantuanPanduanAwal::Masuk($this, $pengguna, $tenant)->get('/kelola/panduan-awal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/PanduanAwal/Indeks'));
        BantuanPanduanAwal::Masuk($this, $pengguna, $tenant)->post('/kelola/panduan-awal/langkah/pajak/lewati')->assertRedirect('/kelola/panduan-awal/produk');
    })->with([PeranTenantBawaan::Pemilik, PeranTenantBawaan::Admin]);

    it('Kasir dan Manajer Outlet mendapat 403 "Tanpa izin" di semua rute panduan awal', function (PeranTenantBawaan $peran): void {
        ['Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant();
        $anggota = BantuanOrganisasi::TambahAnggota($tenant->Id, $peran);

        foreach (DaftarRutePanduanAwalUji() as [$metode, $url]) {
            BantuanPanduanAwal::Masuk($this, $anggota, $tenant)->call($metode, $url)
                ->assertForbidden()
                ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/TanpaIzin'));
        }

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProgresPanduanAwal::query()->count())->toBe(0);
    })->with([PeranTenantBawaan::Kasir, PeranTenantBawaan::ManajerOutlet]);

    it('tenant ditangguhkan: semua POST panduan awal ditolak (Umum) dan tidak ada data yang berubah', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        BantuanPerangkat::AturStatusLangganan($tenant->Id, StatusLangganan::Ditangguhkan);
        $namaAwal = $tenant->Nama;
        $isian = [
            '/kelola/panduan-awal/profil-usaha' => ['NamaUsaha' => 'Nama Baru', 'KodeKota' => '33.72', 'Pkp' => '0'],
            '/kelola/panduan-awal/sektor' => ['KodeTemplate' => 'FNB-CAF'],
            '/kelola/panduan-awal/sektor/siapkan-otomatis' => ['KodeTemplate' => 'FNB-CAF'],
            '/kelola/panduan-awal/produk' => ['Produk' => [['Nama' => 'Es Teh', 'Harga' => '5000', 'Kategori' => null]]],
            '/kelola/panduan-awal/metode-pembayaran' => ['Jenis' => 'Edc', 'Nama' => 'EDC', 'KodeBank' => 'BCA'],
            '/kelola/panduan-awal/perangkat' => ['Nama' => 'Kasir Depan'],
            '/kelola/panduan-awal/langkah/pajak/lewati' => [],
            '/kelola/panduan-awal/selesai' => [],
        ];

        foreach (array_filter(DaftarRutePanduanAwalUji(), fn (array $rute) => $rute[0] === 'POST') as [, $url]) {
            BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post($url, $isian[$url] ?? [])
                ->assertSessionHasErrors(['Umum' => BatasiTenantDitangguhkan::PESAN]);
        }

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Tenant::query()->findOrFail($tenant->Id)->Nama)->toBe($namaAwal)
            ->and(Akun::query()->count())->toBe(0)
            ->and(Produk::query()->count())->toBe(0)
            ->and(Perangkat::query()->count())->toBe(0)
            ->and(MetodePembayaran::query()->count())->toBe(1)
            ->and(ProgresPanduanAwal::query()->count())->toBe(0);
    });
});
