<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pemilik\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-183: fitur khusus sektor (peran bawaan, izin, jenis perangkat, jenis notifikasi) hanya ditawarkan ke tenant yang
 * memakai sektornya; sektor yang belum diketahui menampilkan semuanya; yang sudah terpakai tidak pernah disembunyikan.
 */
beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    Cache::flush();
});

/** @return array{0: \Tests\TestCase, 1: object} */
function TenantBersektor(object $uji, ?string $kodeSektor): array
{
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Usaha '.($kodeSektor ?? 'Tanpa Template'));
    BantuanOrganisasi::AturKonteks($tenant->Id);
    Outlet::query()->update(['TemplateSektor' => $kodeSektor]);

    return [BantuanOrganisasi::Masuk($uji, $pemilik, $tenant->Id), $tenant];
}

it('toko kelontong tidak ditawari peran Pelayan/Salesman/Apoteker maupun izin sektor lain di halaman Peran', function (): void {
    [$tes] = TenantBersektor($this, 'RTL-GEN');

    $tes->get('/kelola/peran')->assertOk()->assertInertia(function (AssertableInertia $halaman): void {
        $halaman->component('Kelola/Peran/Daftar')
            ->where('Peran', function ($peran): bool {
                $nama = collect($peran)->pluck('Nama')->all();

                return ! in_array('Pelayan', $nama, true) && ! in_array('Salesman', $nama, true) && ! in_array('Apoteker', $nama, true)
                    && in_array('Kasir', $nama, true);
            })
            ->where('DaftarIzin', function ($izin): bool {
                $kunci = collect($izin)->pluck('Kunci')->all();

                return ! array_intersect($kunci, ['pesanan.meja.catat', 'salesman.kunjungan', 'apotek.obat-keras.jual', 'laundry.kelola', 'bengkel.kelola'])
                    && in_array('penjualan.buat', $kunci, true);
            });
    });
});

it('restoran melihat Pelayan dan izin pesanan meja, tetapi bukan Apoteker/Salesman', function (): void {
    [$tes] = TenantBersektor($this, 'FNB-RST');

    $tes->get('/kelola/peran')->assertOk()->assertInertia(function (AssertableInertia $halaman): void {
        $halaman->where('Peran', function ($peran): bool {
            $nama = collect($peran)->pluck('Nama')->all();

            return in_array('Pelayan', $nama, true) && ! in_array('Apoteker', $nama, true) && ! in_array('Salesman', $nama, true);
        })->where('DaftarIzin', fn ($izin): bool => in_array('pesanan.meja.catat', collect($izin)->pluck('Kunci')->all(), true));
    });
});

it('sektor belum diketahui menampilkan semua peran dan izin (tidak ada yang hilang diam-diam)', function (): void {
    [$tes] = TenantBersektor($this, null);

    $tes->get('/kelola/peran')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('Peran', fn ($peran): bool => count(collect($peran)->pluck('Nama')->intersect(['Pelayan', 'Salesman', 'Apoteker'])) === 3)
        ->where('DaftarIzin', fn ($izin): bool => in_array('apotek.obat-keras.jual', collect($izin)->pluck('Kunci')->all(), true)));
});

it('peran sektor lain yang sudah punya anggota tetap tampil (tenant yang berganti sektor tidak kehilangan datanya)', function (): void {
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Berganti Sektor');
    BantuanOrganisasi::AturKonteks($tenant->Id);
    Outlet::query()->update(['TemplateSektor' => 'RTL-GEN']);
    BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::Pelayan);

    BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->get('/kelola/peran')->assertOk()
        ->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Peran', fn ($peran): bool => in_array('Pelayan', collect($peran)->pluck('Nama')->all(), true)));
});

it('jenis perangkat KDS, Pelayan, dan Salesman hanya ditawarkan ke sektor yang memakainya', function (): void {
    [$tes] = TenantBersektor($this, 'SVC-SLN');
    $tes->get('/kelola/perangkat')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('JenisPerangkat', fn ($jenis): bool => collect($jenis)->pluck('Nilai')->sort()->values()->all() === ['Gudang', 'Kasir']));

    [$tesGrosir] = TenantBersektor($this, 'WHS-DST');
    $tesGrosir->get('/kelola/perangkat')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('JenisPerangkat', fn ($jenis): bool => in_array('Salesman', collect($jenis)->pluck('Nilai')->all(), true)
            && ! in_array('Kds', collect($jenis)->pluck('Nilai')->all(), true)));
});

it('pengaturan notifikasi tidak menawarkan "barang mendekati kedaluwarsa" ke usaha yang tidak menyimpannya', function (): void {
    [$tes] = TenantBersektor($this, 'SVC-GEN');
    $tes->get('/kelola/notifikasi')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('Jenis', fn ($jenis): bool => ! in_array('StokKedaluwarsa', collect($jenis)->pluck('Nilai')->all(), true)));

    [$tesApotek] = TenantBersektor($this, 'RTL-PHR');
    $tesApotek->get('/kelola/notifikasi')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
        ->where('Jenis', fn ($jenis): bool => in_array('StokKedaluwarsa', collect($jenis)->pluck('Nilai')->all(), true)));
});

it('konfigurasi-aplikasi Owner membawa sinyal usaha tanpa kasir (HanyaPerusahaan)', function (): void {
    $data = BantuanOrganisasi::BuatTenant('Konsultan Digital');
    $data['Pemilik']->forceFill(['EmailDiverifikasiPada' => now()])->save();
    BantuanOrganisasi::AturKonteks($data['Tenant']->Id);
    Outlet::query()->update(['TemplateSektor' => 'BIZ-JSA']);
    $token = (string) $this->withHeader('X-Versi-Aplikasi', '1.0.0')->postJson('/api/pemilik/v1/masuk', [
        'Email' => $data['Pemilik']->Email,
        'KataSandi' => BantuanAutentikasi::KATA_SANDI,
        'NamaPerangkat' => 'Pixel Owner',
    ])->assertOk()->json('Token');

    $this->withToken($token)->withHeader('X-Tenant', $data['Tenant']->Uuid)->getJson('/api/pemilik/v1/konfigurasi-aplikasi')
        ->assertOk()->assertJsonPath('HanyaPerusahaan', true)->assertJsonPath('Sektor', ['BIZ-JSA']);

    Outlet::query()->update(['TemplateSektor' => 'RTL-GEN']);
    $this->withToken($token)->withHeader('X-Tenant', $data['Tenant']->Uuid)->getJson('/api/pemilik/v1/konfigurasi-aplikasi')
        ->assertOk()->assertJsonPath('HanyaPerusahaan', false);
});
