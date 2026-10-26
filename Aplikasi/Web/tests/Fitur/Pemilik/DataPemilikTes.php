<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Kueri\LabaRugi;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Laporan\Kueri\LaporanPenjualan;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pembelian\Aksi\AjukanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Kueri\KartuStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Laporan\BantuanLaporan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    // Prasyarat pendaftaran dibuat pada tanggal tetap sebelum semua tanggal skenario.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    // Rabu 7 Oktober 2026 pukul 12.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

/** Masuk Aplikasi Owner lalu kembalikan token akses (email ditandai terverifikasi dulu). */
function TokenPemilikUji(object $tes, Pengguna $pengguna, string $kataSandi = BantuanAutentikasi::KATA_SANDI): string
{
    $pengguna->forceFill(['EmailDiverifikasiPada' => now()])->save();
    $token = $tes->postJson('/api/pemilik/v1/masuk', ['Email' => $pengguna->Email, 'KataSandi' => $kataSandi, 'NamaPerangkat' => 'HP Uji'])->assertOk()->json('Token');

    return is_string($token) ? $token : '';
}

function CabutPerangkatUji(Perangkat $perangkat): void
{
    Perangkat::query()->whereKey($perangkat->Id)->update(['DicabutPada' => now()->subHour()]);
}

function KirimPemilikUji(object $tes, string $token, string $uuidTenant, string $url, array $data = []): TestResponse
{
    return $tes->withToken($token)->withHeaders(['X-Tenant' => $uuidTenant, 'X-Versi-Aplikasi' => '1.0.0'])->postJson('/api/pemilik/v1'.$url, $data);
}

function AmbilPemilikUji(object $tes, string $token, string $uuidTenant, string $url): TestResponse
{
    return $tes->withToken($token)->withHeaders(['X-Tenant' => $uuidTenant, 'X-Versi-Aplikasi' => '1.0.0'])->getJson('/api/pemilik/v1'.$url);
}

/**
 * Minyak goreng (HPP 30.000, harga 38.500, stok 10, minimum 6): 1 terjual Rabu lalu (30/9), 2 kemarin (6/10), 1 hari ini.
 *
 * @return array<string, mixed>
 */
function SiapkanPenjualanDasborUji(object $tes): array
{
    $tes->travelTo(CarbonImmutable::parse('2026-09-30 05:00:00', 'UTC'));
    $k = BantuanPenjualan::Siapkan($tes);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    ProdukGudang::query()->create(['IdProduk' => $minyak->Id, 'IdGudang' => $k['Gudang']->Id, 'StokMinimum' => '6']);
    $jual = fn (string $jumlah): Penjualan => BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => $jumlah, 'Harga' => '38500.00']]]);
    $jual('1');
    $tes->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
    $jual('2');
    $tes->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    $hariIni = $jual('1');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return $k + ['Minyak' => $minyak, 'PenjualanHariIni' => $hariIni];
}

describe('OWN-02 dasbor Aplikasi Owner', function (): void {
    it('omzet bersih, laba kotor, transaksi, rata-rata, kemarin & minggu lalu, per outlet, per jam lokal, produk teratas, dan perlu tindakan', function (): void {
        $k = SiapkanPenjualanDasborUji($this);
        Penjualan::query()->whereKey($k['PenjualanHariIni']->Id)->update(['PerluTinjauan' => true]);
        $perluTinjauan = Penjualan::query()->where('PerluTinjauan', true)->where('TanggalBisnis', '2026-10-07')->count();
        Shift::query()->where('Uuid', $k['UuidShift'])->update([
            'TanggalBisnis' => '2026-10-07',
            'Status' => StatusShift::Tertutup->value,
            'DitutupOleh' => $k['Kasir']->Id,
            'DitutupPada' => now()->subMinutes(2),
            'KasSeharusnya' => '615500.00',
            'KasAktual' => '595500.00',
            'Selisih' => '-20000.00',
        ]);
        $token = TokenPemilikUji($this, $k['Pemilik']);
        $outlet = ['Uuid' => $k['Outlet']->Uuid, 'Nama' => $k['Outlet']->Nama];

        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/dasbor')
            ->assertOk()
            ->assertExactJson([
                'Tanggal' => '2026-10-07',
                'Outlet' => [$outlet],
                'Ringkasan' => [
                    'Omzet' => '38500.00',
                    'LabaKotor' => '8500.00',
                    'Transaksi' => 1,
                    'RataRata' => '38500.00',
                    'OmzetKemarin' => '77000.00',
                    'OmzetMingguLalu' => '38500.00',
                ],
                'PerOutlet' => [[...$outlet, 'Omzet' => '38500.00', 'Transaksi' => 1]],
                // Dijual 11.55 WIB (dibuat 5 menit sebelum dikirim).
                'PerJam' => [['Jam' => 11, 'Omzet' => '38500.00']],
                'ProdukTeratas' => [['Nama' => $k['Minyak']->Nama, 'Jumlah' => '1.0000', 'Omzet' => '38500.00']],
                'PerluTindakan' => [
                    ['Jenis' => 'SelisihKas', 'Judul' => 'Selisih kas shift '.$k['Kasir']->Nama, 'Keterangan' => 'Kurang Rp 20.000 di '.$k['Outlet']->Nama],
                    ['Jenis' => 'PenjualanPerluTinjauan', 'Judul' => "{$perluTinjauan} penjualan perlu ditinjau", 'Keterangan' => 'Penjualan dari kasir yang ditandai untuk diperiksa. Tinjau di back-office.'],
                    ['Jenis' => 'StokMenipis', 'Judul' => '1 stok menipis', 'Keterangan' => 'Stok produk sudah mencapai batas minimum. Segera pesan ulang ke pemasok.'],
                ],
            ]);

        // Perangkat kasir terakhir menghubungi server saat mengirim penjualan (05.00 UTC); 31 menit kemudian dianggap tidak aktif.
        $this->travel(31)->minutes();
        $tindakan = AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/dasbor')->assertOk()->json('PerluTindakan');
        expect(array_values(array_filter($tindakan, fn (array $t): bool => $t['Jenis'] === 'PerangkatTidakAktif')))->toBe([[
            'Jenis' => 'PerangkatTidakAktif',
            'Judul' => 'Perangkat Kasir Depan tidak aktif',
            'Keterangan' => 'Terakhir terhubung 31 menit lalu di '.$k['Outlet']->Nama,
        ]]);

        // Tanggal lampau: angka tanggal itu; perangkat & stok (keadaan saat ini) tidak ikut.
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/dasbor?tanggal=2026-10-06&outlet='.$k['Outlet']->Uuid)
            ->assertOk()
            ->assertJsonPath('Tanggal', '2026-10-06')
            ->assertJsonPath('Ringkasan.Omzet', '77000.00')
            ->assertJsonPath('Ringkasan.Transaksi', 1)
            ->assertJsonPath('Ringkasan.OmzetKemarin', '0.00')
            ->assertJsonPath('ProdukTeratas.0.Jumlah', '2.0000')
            ->assertJsonPath('PerluTindakan', []);
    });

    it('laba kotor null tanpa izin laporan.keuangan.lihat, tanpa izin laporan.penjualan.lihat → 403 TanpaIzin. Dibatasi outlet akses', function (): void {
        $k = SiapkanPenjualanDasborUji($this);
        $manajer = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $tokenManajer = TokenPemilikUji($this, $manajer, 'kata-sandi-uji');

        AmbilPemilikUji($this, $tokenManajer, $k['Tenant']->Uuid, '/dasbor')
            ->assertOk()
            ->assertJsonPath('Ringkasan.Omzet', '38500.00')
            ->assertJsonPath('Ringkasan.LabaKotor', null);

        $tokenKasir = TokenPemilikUji($this, $k['Kasir'], 'kata-sandi-uji');
        foreach (['/dasbor', '/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&kelompok=Produk', '/shift', '/perangkat'] as $url) {
            AmbilPemilikUji($this, $tokenKasir, $k['Tenant']->Uuid, $url)->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');
        }

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $cabang, nama: 'Kasir Cabang');
        $tokenCabang = TokenPemilikUji($this, $manajerCabang, 'kata-sandi-uji');

        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/dasbor')
            ->assertOk()
            ->assertJsonPath('Outlet', [['Uuid' => $cabang->Uuid, 'Nama' => 'Cabang Solo Baru']])
            ->assertJsonPath('Ringkasan.Omzet', '0.00')
            ->assertJsonPath('Ringkasan.OmzetKemarin', '0.00')
            ->assertJsonPath('PerOutlet', [['Uuid' => $cabang->Uuid, 'Nama' => 'Cabang Solo Baru', 'Omzet' => '0.00', 'Transaksi' => 0]])
            ->assertJsonPath('ProdukTeratas', [])
            ->assertJsonPath('PerluTindakan', []);
        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/dasbor?outlet='.$k['Outlet']->Uuid)
            ->assertStatus(404)
            ->assertJsonPath('Galat.Kode', 'OutletTidakDitemukan');
        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&kelompok=Produk')
            ->assertOk()
            ->assertJsonPath('Baris', []);
        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/shift')->assertOk()->assertJsonPath('Shift', []);
        expect(array_column(AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/perangkat')->assertOk()->json('Perangkat'), 'Nama'))->toBe(['Kasir Cabang']);
    });

    it('isolasi tenant: tanpa X-Tenant atau tenant orang lain → 403 TenantTidakDiizinkan. Pemilik tenant lain tidak melihat angka tenant ini', function (): void {
        $k = SiapkanPenjualanDasborUji($this);
        $token = TokenPemilikUji($this, $k['Pemilik']);
        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        $tokenLain = TokenPemilikUji($this, $lain['Pemilik']);

        $this->withToken($token)->getJson('/api/pemilik/v1/dasbor')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
        AmbilPemilikUji($this, $token, $lain['Tenant']->Uuid, '/dasbor')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
        AmbilPemilikUji($this, $token, '01JZZZZZZZZZZZZZZZZZZZZZZZ', '/perangkat')->assertStatus(403);
        AmbilPemilikUji($this, $tokenLain, $k['Tenant']->Uuid, '/shift')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');

        AmbilPemilikUji($this, $tokenLain, $lain['Tenant']->Uuid, '/dasbor')
            ->assertOk()
            ->assertJsonPath('Ringkasan.Omzet', '0.00')
            ->assertJsonPath('ProdukTeratas', []);
        AmbilPemilikUji($this, $tokenLain, $lain['Tenant']->Uuid, '/perangkat')->assertOk()->assertJsonPath('Perangkat', []);
        AmbilPemilikUji($this, $tokenLain, $lain['Tenant']->Uuid, '/dasbor?outlet='.$k['Outlet']->Uuid)->assertStatus(404);
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/dasbor')->assertOk()->assertJsonPath('Ringkasan.Omzet', '38500.00');
    });
});

describe('OWN-05 laporan ringkas penjualan & shift', function (): void {
    it('penjualan per produk, kategori, kasir, jam, kanal (angka sama dengan laporan F-14a. Void dikeluarkan, retur mengurangi)', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $sembako = Kategori::query()->create(['Nama' => 'Sembako & Kebutuhan Dapur']);
        $d['Minyak']->forceFill(['IdKategori' => $sembako->Id])->save();
        $token = TokenPemilikUji($this, $d['Pemilik']);
        $url = fn (string $kelompok): string => "/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&kelompok={$kelompok}";

        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url('Produk'))
            ->assertOk()
            ->assertExactJson([
                'Kelompok' => 'Produk',
                'Baris' => [
                    ['Nama' => $d['Minyak']->Nama, 'Jumlah' => '2.0000', 'Omzet' => '73150.00'],
                    ['Nama' => 'Jasa Antar Belanja Dalam Kota', 'Jumlah' => '1.0000', 'Omzet' => '10000.00'],
                ],
                'Total' => ['Jumlah' => '3.0000', 'Omzet' => '83150.00'],
            ]);

        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url('Kategori'))
            ->assertOk()
            ->assertJsonPath('Baris', [
                ['Nama' => 'Sembako & Kebutuhan Dapur', 'Jumlah' => '2.0000', 'Omzet' => '73150.00'],
                ['Nama' => 'Tanpa kategori', 'Jumlah' => '1.0000', 'Omzet' => '10000.00'],
            ])
            ->assertJsonPath('Total', ['Jumlah' => '3.0000', 'Omzet' => '83150.00']);

        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url('Kasir'))
            ->assertOk()
            ->assertExactJson([
                'Kelompok' => 'Kasir',
                'Baris' => [['Nama' => $d['Kasir']->Nama, 'Jumlah' => '2', 'Omzet' => '83150.00']],
                'Total' => ['Jumlah' => '2', 'Omzet' => '83150.00'],
            ]);

        $kanal = AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url('Kanal'))->assertOk();
        expect(array_column($kanal->json('Baris'), 'Nama'))->toEqualCanonicalizing(['Bawa pulang', 'Makan di tempat'])
            ->and($kanal->json('Total'))->toBe(['Jumlah' => '2', 'Omzet' => '83150.00']);

        $jam = AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url('Jam'))->assertOk();
        expect($jam->json('Baris.0.Nama'))->toBe('11')
            ->and($jam->json('Baris.0.Jumlah'))->toBe('2')
            ->and($jam->json('Baris'))->toHaveCount(1)
            ->and($jam->json('Total.Omzet'))->toBe($jam->json('Baris.0.Omzet'));
    });

    it('rentang maks 31 hari (lebih → 422 RentangTerlaluPanjang). Tanggal terbalik & kelompok tak dikenal → 422', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $token = TokenPemilikUji($this, $d['Pemilik']);

        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/laporan/penjualan?dari=2026-09-07&sampai=2026-10-07&kelompok=Produk')
            ->assertOk()
            ->assertJsonPath('Total.Omzet', '83150.00');
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/laporan/penjualan?dari=2026-09-06&sampai=2026-10-07&kelompok=Produk')
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'RentangTerlaluPanjang');
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/laporan/penjualan?dari=2026-10-07&sampai=2026-10-01&kelompok=Produk')
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&kelompok=Metode')
            ->assertStatus(422)
            ->assertJsonPath('Galat.Kode', 'ValidasiGagal');
    });

    it('OWN-05 laba rugi sederhana: angka sama dengan laporan keuangan web, laba bersih = pendapatan − HPP − beban, pembanding bulan lalu', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $token = TokenPemilikUji($this, $k['Pemilik']);

        $respons = AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-31')->assertOk();
        $ringkasan = $respons->json('Ringkasan');
        expect($respons->json('Periode'))->toBe(['Dari' => '2026-10-01', 'Sampai' => '2026-10-31', 'DariSebelumnya' => '2026-09-01', 'SampaiSebelumnya' => '2026-09-30'])
            ->and($ringkasan['Pendapatan']['Nilai'])->toBe('77000.00')
            ->and($ringkasan['Hpp']['Nilai'])->toBe('60000.00')
            ->and($ringkasan['LabaKotor']['Nilai'])->toBe('17000.00')
            ->and($ringkasan['LabaBersih']['Nilai'])->toBe('17000.00')
            ->and($ringkasan['LabaBersih']['NilaiSebelumnya'])->toBe('0.00');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $web = app(LabaRugi::class)->Ambil(new SaringLaporanKeuangan('2026-10-01', '2026-10-31', null, null));
        expect($respons->json('Baris'))->toBe($web['Baris'])->and($ringkasan)->toBe($web['Ringkasan']);
    });

    it('OWN-05 laba rugi: izin laporan.keuangan.lihat wajib, rentang maks 366 hari, outlet di luar akses 404, tenant lain 403', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $tokenKasir = TokenPemilikUji($this, $k['Kasir'], 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenKasir, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-07')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');

        $token = TokenPemilikUji($this, $k['Pemilik']);
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2025-10-07&sampai=2026-10-07')->assertOk();
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2025-10-06&sampai=2026-10-07')->assertStatus(422)->assertJsonPath('Galat.Kode', 'RentangTerlaluPanjang');
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-07&sampai=2026-10-01')->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-07&outlet=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(404);

        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        $tokenCabang = TokenPemilikUji($this, $manajerCabang, 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-31')
            ->assertOk()->assertJsonPath('Ringkasan.Pendapatan.Nilai', '0.00');
        AmbilPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-31&outlet='.$k['Outlet']->Uuid)->assertStatus(404);

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        AmbilPemilikUji($this, TokenPemilikUji($this, $lain['Pemilik']), $k['Tenant']->Uuid, '/laporan/laba-rugi?dari=2026-10-01&sampai=2026-10-07')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
    });

    it('OWN-09 anti-fraud: skor risiko kasir sama dengan tab Anti-fraud back-office. Wajib izin laporan & fitur Anti-fraud. Rentang maks 31 hari', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Minimarket Jujur Makmur Sragen');
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $jual = [];
        for ($i = 0; $i < 4; $i++) {
            $jual[] = BantuanPenjualan::Jual($this, $k, $baris);
        }
        foreach (array_slice($jual, 0, 3) as $p) {
            expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);
        }
        for ($i = 0; $i < 10; $i++) {
            expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::Item($k, $baris, ['UuidPengguna' => $k['Supervisor']->Uuid])]))->toBe([['Diterima', null]]);
        }

        $token = TokenPemilikUji($this, $k['Pemilik']);
        $url = '/laporan/anti-fraud?dari=2026-10-01&sampai=2026-10-07';

        // Tanpa fitur Anti-fraud di paket: ditolak server.
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, $url)->assertStatus(403);

        BantuanFitur::Beri($k['Tenant']->Id, 'keamanan.anti-fraud');
        $respons = AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, $url)->assertOk();
        $kasir = $respons->json('Kasir');
        expect($kasir[0]['NamaKasir'])->toBe($k['Kasir']->Nama)
            ->and($kasir[0]['JumlahTransaksi'])->toBe(4)
            ->and($kasir[0]['JumlahVoid'])->toBe(3)
            ->and($kasir[0]['VoidCepatTunai'])->toBe(3)
            ->and($kasir[0]['Tingkat'])->toBe('Sedang')
            ->and($kasir[0]['Skor'])->toBe(50)
            ->and($kasir[0]['Alasan'])->toContain('3 void tunai ≤ 10 menit setelah bayar')
            ->and($kasir[1]['NamaKasir'])->toBe($k['Supervisor']->Nama)
            ->and($kasir[1]['Tingkat'])->toBe('Rendah');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $web = app(LaporanPenjualan::class)->AntiFraud(new DataSaringLaporanPenjualan(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07'), null));
        expect($kasir)->toBe($web);

        $tokenKasir = TokenPemilikUji($this, $k['Kasir'], 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenKasir, $k['Tenant']->Uuid, $url)->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/laporan/anti-fraud?dari=2026-09-06&sampai=2026-10-07')->assertStatus(422)->assertJsonPath('Galat.Kode', 'RentangTerlaluPanjang');
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, $url.'&outlet=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(404);

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        AmbilPemilikUji($this, TokenPemilikUji($this, $lain['Pemilik']), $k['Tenant']->Uuid, $url)->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
    });

    it('daftar shift satu tanggal bisnis: terbuka tanpa selisih, tertutup dengan selisih. Tanggal lain kosong', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $token = TokenPemilikUji($this, $k['Pemilik']);
        $shift = Shift::query()->where('Uuid', $k['UuidShift'])->sole();

        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/shift')
            ->assertOk()
            ->assertExactJson(['Shift' => [[
                'Uuid' => $k['UuidShift'],
                'Outlet' => $k['Outlet']->Nama,
                'Kasir' => $k['Kasir']->Nama,
                'DibukaPada' => $shift->DibukaPada->utc()->toIso8601ZuluString(),
                'DitutupPada' => null,
                'Status' => 'Terbuka',
                'Selisih' => null,
            ]]]);

        $shift->forceFill([
            'Status' => StatusShift::Tertutup,
            'DitutupOleh' => $k['Kasir']->Id,
            'DitutupPada' => CarbonImmutable::parse('2026-10-07 04:30:00', 'UTC'),
            'KasSeharusnya' => '500000.00',
            'KasAktual' => '1485000.00',
            'Selisih' => '985000.00',
        ])->save();

        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/shift?tanggal=2026-10-07&outlet='.$k['Outlet']->Uuid)
            ->assertOk()
            ->assertJsonPath('Shift.0.Status', 'Tertutup')
            ->assertJsonPath('Shift.0.DitutupPada', '2026-10-07T04:30:00Z')
            ->assertJsonPath('Shift.0.Selisih', '985000.00');
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/dasbor')
            ->assertOk()
            ->assertJsonPath('PerluTindakan.0', ['Jenis' => 'SelisihKas', 'Judul' => 'Selisih kas shift '.$k['Kasir']->Nama, 'Keterangan' => 'Lebih Rp 985.000 di '.$k['Outlet']->Nama]);
        AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/shift?tanggal=2026-10-06')->assertOk()->assertExactJson(['Shift' => []]);
    });
});

describe('OWN-06 cek stok & kartu stok', function (): void {
    it('ringkasan nilai persediaan, stok kritis, saldo berhalaman dengan pencarian, dan kartu stok sama dengan back-office', function (): void {
        $d = SiapkanPenjualanDasborUji($this);
        $token = TokenPemilikUji($this, $d['Pemilik']);
        $respons = AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/stok')->assertOk();

        // Stok awal 10 @ 30.000, terjual 4 → saldo 6 (= minimum 6, jadi kritis), nilai 180.000.
        expect($respons->json('Ringkasan.NilaiPersediaan'))->toBe('180000.00')
            ->and($respons->json('Ringkasan.JumlahProduk'))->toBe(1)
            ->and($respons->json('Ringkasan.JumlahKritis'))->toBe(1)
            ->and($respons->json('Kritis.0.UuidProduk'))->toBe($d['Minyak']->Uuid)
            ->and(BigDecimal::of($respons->json('Kritis.0.Saldo'))->isEqualTo('6'))->toBeTrue()
            ->and($respons->json('Saldo.Meta.Total'))->toBe(1)
            ->and($respons->json('Saldo.Data.0.NamaProduk'))->toBe($d['Minyak']->Nama)
            ->and($respons->json('Saldo.Data.0.NilaiPersediaan'))->toBe('180000.00');

        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/stok?cari=tidak-ada-barang-ini')->assertOk()->assertJsonPath('Saldo.Meta.Total', 0)->assertJsonPath('Saldo.Data', []);
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/stok?cari=minyak')->assertOk()->assertJsonPath('Saldo.Meta.Total', 1);

        $uuidGudang = $respons->json('Saldo.Data.0.UuidGudang');
        $kartu = AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, "/stok/kartu?produk={$d['Minyak']->Uuid}&gudang={$uuidGudang}&dari=2026-09-01&sampai=2026-10-07")->assertOk();
        expect($kartu->json('Produk.Uuid'))->toBe($d['Minyak']->Uuid)
            ->and($kartu->json('SaldoAkhir.Jumlah'))->toBe($respons->json('Saldo.Data.0.JumlahTersedia'))
            ->and($kartu->json('Mutasi.Data'))->not->toBeEmpty()
            ->and(array_sum(array_map(fn (array $m): int => $m['Keluar'] === null ? 0 : 1, $kartu->json('Mutasi.Data'))))->toBe(3);

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $web = app(KartuStok::class)->Ambil($d['Minyak']->Id, Gudang::query()->where('Uuid', $uuidGudang)->value('Id'), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-07'), 1, 50);
        expect($kartu->json('SaldoAkhir'))->toBe($web['SaldoAkhir'])->and($kartu->json('Mutasi.Meta'))->toBe($web['Mutasi']['Meta']);
    });

    it('cek stok: izin persediaan.lihat wajib, outlet & lokasi di luar akses 404, rentang kartu maks 92 hari, tenant lain 403', function (): void {
        $d = SiapkanPenjualanDasborUji($this);
        $uuidGudang = $d['Gudang']->Uuid;
        $url = "/stok/kartu?produk={$d['Minyak']->Uuid}&gudang={$uuidGudang}";

        $tokenKasir = TokenPemilikUji($this, $d['Kasir'], 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenKasir, $d['Tenant']->Uuid, '/stok')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');
        AmbilPemilikUji($this, $tokenKasir, $d['Tenant']->Uuid, $url)->assertStatus(403);

        $token = TokenPemilikUji($this, $d['Pemilik']);
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url)->assertOk();
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url.'&dari=2026-07-08&sampai=2026-10-07')->assertOk();
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, $url.'&dari=2026-07-07&sampai=2026-10-07')->assertStatus(422)->assertJsonPath('Galat.Kode', 'RentangTerlaluPanjang');
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, '/stok?outlet=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(404);
        AmbilPemilikUji($this, $token, $d['Tenant']->Uuid, "/stok/kartu?produk={$d['Minyak']->Uuid}&gudang=01ARZ3NDEKTSV4RRFFQ69G5FAV")->assertStatus(404);

        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($d['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        $tokenCabang = TokenPemilikUji($this, $manajerCabang, 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenCabang, $d['Tenant']->Uuid, '/stok')->assertOk()->assertJsonPath('Saldo.Meta.Total', 0)->assertJsonPath('Ringkasan.NilaiPersediaan', '0.00');
        AmbilPemilikUji($this, $tokenCabang, $d['Tenant']->Uuid, $url)->assertStatus(404);

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        AmbilPemilikUji($this, TokenPemilikUji($this, $lain['Pemilik']), $d['Tenant']->Uuid, '/stok')->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
    });
});

describe('OWN-04 persetujuan dokumen jarak jauh (PO & penyesuaian stok)', function (): void {
    it('daftar menurut izin; Owner menyetujui/menolak PO dan penyesuaian stok dengan aturan yang sama dengan back-office', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $minyak = $produk['Stok'];
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($minyak, '100', '38500')], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $pemasok = BantuanPembelian::BuatPemasok('PT Sumber Pangan Nusantara');
        $staf = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $poDitolak = app(AjukanPesananPembelian::class)->Jalankan(BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$minyak, '200', '31500']], $staf->Id), $staf->Id);
        $poDisetujui = app(AjukanPesananPembelian::class)->Jalankan(BantuanPembelian::BuatPo($pemasok, $t['Gudang'], [[$minyak, '300', '31500']], $staf->Id), $staf->Id);
        $penyesuaian = app(AjukanPenyesuaianStok::class)->Jalankan(BantuanDokumenPersediaan::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [BantuanDokumenPersediaan::Baris($minyak, '-13')], 'Hilang saat bongkar muat'), $staf->Id);
        expect($poDitolak->Status)->toBe(StatusPesananPembelian::MenungguPersetujuan)->and($penyesuaian->Status)->toBe(StatusPenyesuaianStok::MenungguPersetujuan);

        $token = TokenPemilikUji($this, $t['Pemilik']);
        $daftar = AmbilPemilikUji($this, $token, $t['Tenant']->Uuid, '/persetujuan-dokumen')->assertOk();
        expect($daftar->json('PesananPembelian'))->toHaveCount(2)
            ->and($daftar->json('PesananPembelian.0.Nomor'))->toBe($poDitolak->Nomor)
            ->and($daftar->json('PesananPembelian.0.NamaPemasok'))->toBe('PT Sumber Pangan Nusantara')
            ->and($daftar->json('PesananPembelian.0.Total'))->toBe('6300000.00')
            ->and($daftar->json('PesananPembelian.0.DiajukanOleh'))->toBe($staf->Nama)
            ->and($daftar->json('PesananPembelian.0.BisaDisetujui'))->toBeTrue()
            ->and($daftar->json('PenyesuaianStok'))->toHaveCount(1)
            ->and($daftar->json('PenyesuaianStok.0.NilaiPerkiraan'))->toBe('500500.00')
            ->and($daftar->json('PenyesuaianStok.0.Alasan'))->toBe(AlasanPenyesuaian::Hilang->AmbilLabel())
            ->and($daftar->json('PenyesuaianStok.0.BisaDisetujui'))->toBeTrue();

        // Tanpa izin persetujuan: daftar kosong (bukan galat), aksi 403.
        $kasir = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $tokenKasir = TokenPemilikUji($this, $kasir, 'kata-sandi-uji');
        AmbilPemilikUji($this, $tokenKasir, $t['Tenant']->Uuid, '/persetujuan-dokumen')->assertOk()->assertExactJson(['PesananPembelian' => [], 'PenyesuaianStok' => []]);
        KirimPemilikUji($this, $tokenKasir, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDitolak->Uuid}/setujui")->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');
        KirimPemilikUji($this, $tokenKasir, $t['Tenant']->Uuid, "/persetujuan-dokumen/penyesuaian-stok/{$penyesuaian->Uuid}/setujui")->assertStatus(403);

        // Tolak: alasan wajib (min. 5), lalu kembali ke Draf dengan alasan tercatat.
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDitolak->Uuid}/tolak", ['Alasan' => 'x'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'ValidasiGagal');
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDitolak->Uuid}/tolak", ['Alasan' => 'Harga terlalu tinggi, minta penawaran ulang'])
            ->assertOk()->assertJsonPath('Dokumen.Status', 'Draf');
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDisetujui->Uuid}/setujui")->assertOk()->assertJsonPath('Dokumen.Status', 'Disetujui');
        // Sudah diputuskan: tidak bisa diputuskan lagi (status dicek di bawah kunci).
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDisetujui->Uuid}/setujui")->assertStatus(422)->assertJsonPath('Galat.Kode', 'StatusTidakSesuai');
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/pesanan-pembelian/{$poDitolak->Uuid}/setujui")->assertStatus(422)->assertJsonPath('Galat.Kode', 'StatusTidakSesuai');

        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/penyesuaian-stok/{$penyesuaian->Uuid}/setujui")->assertOk()->assertJsonPath('Dokumen.Status', 'Diposting');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PesananPembelian::query()->whereKey($poDitolak->Id)->value('AlasanDitolak'))->toBe('Harga terlalu tinggi, minta penawaran ulang')
            ->and(PesananPembelian::query()->whereKey($poDisetujui->Id)->value('DisetujuiOleh'))->toBe($t['Pemilik']->Id)
            ->and(BantuanDokumenPersediaan::Saldo($minyak, $t['Gudang'])[0])->toBe('87.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        $akhir = AmbilPemilikUji($this, $token, $t['Tenant']->Uuid, '/persetujuan-dokumen')->assertOk();
        expect($akhir->json('PesananPembelian'))->toBe([])->and($akhir->json('PenyesuaianStok'))->toBe([]);

        // Penyesuaian kedua ditolak lewat Owner: kembali ke Draf, stok tidak bergerak.
        $kedua = app(AjukanPenyesuaianStok::class)->Jalankan(BantuanDokumenPersediaan::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [BantuanDokumenPersediaan::Baris($minyak, '-14')]), $staf->Id);
        KirimPemilikUji($this, $token, $t['Tenant']->Uuid, "/persetujuan-dokumen/penyesuaian-stok/{$kedua->Uuid}/tolak", ['Alasan' => 'Hitung ulang dulu di gudang'])->assertOk()->assertJsonPath('Dokumen.Status', 'Draf');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(BantuanDokumenPersediaan::Saldo($minyak, $t['Gudang'])[0])->toBe('87.0000')
            ->and(PenyesuaianStok::query()->whereKey($kedua->Id)->value('AlasanTolak'))->toBe('Hitung ulang dulu di gudang');
    });

    it('persetujuan dokumen: tenant lain 403, dokumen tak dikenal 404, pengaju tidak boleh menyetujui sendiri (penyesuaian)', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $minyak = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($minyak, '100', '38500')], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
        $admin = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $dokumen = app(AjukanPenyesuaianStok::class)->Jalankan(BantuanDokumenPersediaan::DrafPenyesuaian($t['Gudang'], AlasanPenyesuaian::Hilang, [BantuanDokumenPersediaan::Baris($minyak, '-13')]), $admin->Id);

        $tokenAdmin = TokenPemilikUji($this, $admin, 'kata-sandi-uji');
        $daftar = AmbilPemilikUji($this, $tokenAdmin, $t['Tenant']->Uuid, '/persetujuan-dokumen')->assertOk();
        expect($daftar->json('PenyesuaianStok.0.BisaDisetujui'))->toBeFalse();
        KirimPemilikUji($this, $tokenAdmin, $t['Tenant']->Uuid, "/persetujuan-dokumen/penyesuaian-stok/{$dokumen->Uuid}/setujui")->assertStatus(422)->assertJsonPath('Galat.Kode', 'PenyetujuTidakBoleh');
        KirimPemilikUji($this, $tokenAdmin, $t['Tenant']->Uuid, '/persetujuan-dokumen/penyesuaian-stok/01ARZ3NDEKTSV4RRFFQ69G5FAV/setujui')->assertStatus(404);

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        KirimPemilikUji($this, TokenPemilikUji($this, $lain['Pemilik']), $t['Tenant']->Uuid, "/persetujuan-dokumen/penyesuaian-stok/{$dokumen->Uuid}/setujui")->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PenyesuaianStok::query()->whereKey($dokumen->Id)->value('Status'))->toBe(StatusPenyesuaianStok::MenungguPersetujuan);
    });
});

describe('OWN-08 status perangkat POS', function (): void {
    it('daftar perangkat: status Aktif/BelumDiaktifkan/Dicabut, terakhir aktif (UTC), outbox tertunda, versi aplikasi, yang dicabut di akhir', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        Perangkat::query()->whereKey($k['Perangkat']->Id)->update(['JumlahOutboxTertunda' => 3]);
        $belum = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Kds, 'Layar Dapur Utama')['Perangkat'];
        $token = TokenPemilikUji($this, $k['Pemilik']);
        $perangkat = $k['Perangkat']->fresh();

        CabutPerangkatUji($belum);
        $respons = AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/perangkat')->assertOk();

        expect($respons->json('Perangkat'))->toBe([
            [
                'Uuid' => $perangkat?->Uuid,
                'Kode' => $perangkat?->Kode,
                'Nama' => 'Kasir Depan',
                'Jenis' => 'Kasir',
                'Outlet' => $k['Outlet']->Nama,
                'Status' => 'Aktif',
                'TerakhirAktifPada' => '2026-10-07T05:00:00Z',
                'JumlahOutboxTertunda' => 3,
                'VersiAplikasi' => $perangkat?->VersiAplikasi,
            ],
            [
                'Uuid' => $belum->Uuid,
                'Kode' => $belum->Kode,
                'Nama' => 'Layar Dapur Utama',
                'Jenis' => 'Kds',
                'Outlet' => $k['Outlet']->Nama,
                'Status' => 'Dicabut',
                'TerakhirAktifPada' => null,
                'JumlahOutboxTertunda' => 0,
                'VersiAplikasi' => null,
            ],
        ]);

        $baru = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Kasir, 'Kasir Belakang')['Perangkat'];
        $status = array_column(AmbilPemilikUji($this, $token, $k['Tenant']->Uuid, '/perangkat')->assertOk()->json('Perangkat'), 'Status', 'Uuid');
        expect($status[$baru->Uuid])->toBe('BelumDiaktifkan');
    });
    it('OWN-08 cabut perangkat dari Owner: perangkat langsung ditolak POS, tercatat di audit, idempoten', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $perangkat = $k['Perangkat'];
        $token = TokenPemilikUji($this, $k['Pemilik']);

        $respons = KirimPemilikUji($this, $token, $k['Tenant']->Uuid, "/perangkat/{$perangkat->Uuid}/cabut")->assertOk();
        expect($respons->json('Perangkat.Uuid'))->toBe($perangkat->Uuid)
            ->and($respons->json('Perangkat.Status'))->toBe('Dicabut');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($perangkat->fresh()?->CekDicabut())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'perangkat.cabut')->count())->toBe(1);

        // Mengulang tidak mengubah apa pun dan tidak menambah audit.
        KirimPemilikUji($this, $token, $k['Tenant']->Uuid, "/perangkat/{$perangkat->Uuid}/cabut")->assertOk()->assertJsonPath('Perangkat.Status', 'Dicabut');
        expect(LogAudit::query()->where('Peristiwa', 'perangkat.cabut')->count())->toBe(1);
    });

    it('OWN-08 cabut perangkat: tanpa izin perangkat.kelola 403, perangkat di luar outlet akses 404, tenant lain 403', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $uuid = $k['Perangkat']->Uuid;

        $tokenKasir = TokenPemilikUji($this, $k['Kasir'], 'kata-sandi-uji');
        KirimPemilikUji($this, $tokenKasir, $k['Tenant']->Uuid, "/perangkat/{$uuid}/cabut")->assertStatus(403)->assertJsonPath('Galat.Kode', 'TanpaIzin');

        $cabang = BantuanJurnal::BuatOutlet();
        $manajerCabang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $cabang->Id, 'IdPengguna' => $manajerCabang->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        $tokenCabang = TokenPemilikUji($this, $manajerCabang, 'kata-sandi-uji');
        KirimPemilikUji($this, $tokenCabang, $k['Tenant']->Uuid, "/perangkat/{$uuid}/cabut")->assertStatus(404);

        $lain = BantuanOrganisasi::BuatTenant('Warung Bakso Pak Kumis');
        $tokenLain = TokenPemilikUji($this, $lain['Pemilik']);
        KirimPemilikUji($this, $tokenLain, $k['Tenant']->Uuid, "/perangkat/{$uuid}/cabut")->assertStatus(403)->assertJsonPath('Galat.Kode', 'TenantTidakDiizinkan');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Perangkat']->fresh()?->CekDicabut())->toBeFalse();
    });
});
