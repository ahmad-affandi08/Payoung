<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Pilihan\Aksi\AturKelompokPilihanProduk;
use App\Domain\Katalog\Pilihan\Aksi\SimpanKelompokPilihan;
use App\Domain\Katalog\Pilihan\Kueri\DaftarKelompokPilihan;
use App\Domain\Katalog\Pilihan\Model\KelompokPilihan;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Katalog\Pilihan\Model\ProdukKelompokPilihan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/**
 * Isian HTTP kelompok pilihan (tipe FE `FormKelompokPilihan`).
 *
 * @param  list<array<string, mixed>>  $pilihan
 * @return array<string, mixed>
 */
function IsianKelompokPilihanUji(string $nama = 'Level Gula', array $pilihan = [], string $minimal = '1', string $maksimal = '1'): array
{
    $pilihan = $pilihan !== [] ? $pilihan : [
        ['Uuid' => null, 'Nama' => 'Normal', 'Harga' => '0', 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''],
        ['Uuid' => null, 'Nama' => 'Kurang Manis', 'Harga' => '0', 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''],
    ];

    return ['Nama' => $nama, 'MinimalPilih' => $minimal, 'MaksimalPilih' => $maksimal, 'Urutan' => '0', 'Pilihan' => $pilihan];
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('Kelompok pilihan (modifier)', function (): void {
    it('menambah kelompok dengan pilihan berharga dan berbahan, tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $sirup = BantuanKomposisi::BuatBahan('Sirup Hazelnut Premium Import', 'ml', 'Mililiter');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->from('/kelola/kelompok-pilihan/buat')->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('Tambahan Rasa', [
            ['Uuid' => null, 'Nama' => 'Hazelnut', 'Harga' => '5000', 'Aktif' => true, 'UuidProdukBahan' => $sirup->Uuid, 'Jumlah' => '15'],
            ['Uuid' => null, 'Nama' => 'Extra Shot Espresso', 'Harga' => '6000.50', 'Aktif' => false, 'UuidProdukBahan' => null, 'Jumlah' => ''],
        ], '0', '2'))->assertSessionHasNoErrors()->assertRedirect('/kelola/kelompok-pilihan');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $kelompok = KelompokPilihan::query()->sole();
        expect($kelompok->only(['Nama', 'MinimalPilih', 'MaksimalPilih']))->toBe(['Nama' => 'Tambahan Rasa', 'MinimalPilih' => 0, 'MaksimalPilih' => 2])
            ->and(Pilihan::query()->orderBy('Urutan')->get()->map->only(['Nama', 'Harga', 'IdProduk', 'Jumlah', 'Aktif', 'Urutan'])->all())->toBe([
                ['Nama' => 'Hazelnut', 'Harga' => '5000.00', 'IdProduk' => $sirup->Id, 'Jumlah' => '15.0000', 'Aktif' => true, 'Urutan' => 0],
                ['Nama' => 'Extra Shot Espresso', 'Harga' => '6000.50', 'IdProduk' => null, 'Jumlah' => null, 'Aktif' => false, 'Urutan' => 1],
            ]);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'kelompok-pilihan.buat', 'IdObjek' => $kelompok->Id, 'IdPengguna' => $pemilik->Id]);
    });

    it('nama kelompok unik per tenant: kirim ganda menghasilkan satu kelompok, tenant lain boleh memakai nama sama', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji())->assertSessionHasNoErrors();
        $tes->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('level gula'))->assertSessionHasErrors(['Nama']);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(KelompokPilihan::query()->count())->toBe(1);

        BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        expect(BantuanKomposisi::BuatKelompokPilihan()->Nama)->toBe('Level Gula');
    });

    it('batas pilih: minimal ≤ maksimal, maksimal 1–20, minimal ≤ pilihan aktif, 1–50 pilihan dengan nama unik', function (): void {
        BantuanKatalog::BuatTenant();
        $galat = function (int $minimal, int $maksimal, array $pilihan): array {
            try {
                BantuanKomposisi::BuatKelompokPilihan('Topping Martabak Manis', $pilihan, $minimal, $maksimal);
            } catch (PelanggaranAturanBisnis $galat) {
                return [$galat->kode, $galat->bidang];
            }

            return [];
        };
        $dua = [['Keju', '5000'], ['Cokelat', '3000']];

        expect($galat(2, 1, $dua))->toBe(['BatasPilihanTidakValid', 'MinimalPilih'])
            ->and($galat(0, 0, $dua))->toBe(['BatasPilihanTidakValid', 'MaksimalPilih'])
            ->and($galat(0, 21, $dua))->toBe(['BatasPilihanTidakValid', 'MaksimalPilih'])
            ->and($galat(3, 3, $dua))->toBe(['BatasPilihanTidakValid', 'MinimalPilih'])
            ->and($galat(1, 1, []))->toBe(['BatasPilihanTidakValid', 'Pilihan'])
            ->and($galat(0, 1, array_fill(0, 51, ['Keju', '0'])))->toBe(['BatasPilihanTidakValid', 'Pilihan'])
            ->and($galat(0, 1, [['Keju', '0'], ['KEJU ', '0']]))->toBe(['PilihanGanda', 'Pilihan.1.Nama'])
            ->and($galat(2, 20, $dua))->toBe([]);
    });

    it('bahan pilihan harus jenis bahan dengan jumlah > 0. Bahan tenant lain tidak ditemukan', function (): void {
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Kurir Internal', 'Jenis' => JenisProduk::Jasa]);
        $susu = BantuanKomposisi::BuatBahan('Susu Oat Barista Edition', 'ml', 'Mililiter');

        expect(fn () => BantuanKomposisi::BuatKelompokPilihan('Susu', [['Oat', '8000', $jasa, '1']]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['BahanTidakValid', 'Pilihan.0.UuidProdukBahan']));
        expect(fn () => BantuanKomposisi::BuatKelompokPilihan('Susu', [['Oat', '8000', $susu, '0']]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['BahanTidakValid', 'Pilihan.0.Jumlah']));
        expect(fn () => BantuanKomposisi::BuatKelompokPilihan('Susu', [['Oat', '8000', $susu]]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->bidang)->toBe('Pilihan.0.Jumlah'));

        BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        $susuB = BantuanKomposisi::BuatBahan('Susu Almond Tanpa Gula', 'ml', 'Mililiter');
        expect(fn () => app(SimpanKelompokPilihan::class)->Jalankan(null, BantuanKomposisi::DataKelompokPilihan('Susu', [['Almond', '0', $susu, '150']], 0)))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect($galat->kode)->toBe('BahanTidakValid'));

        BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id)->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('Susu', [
            ['Uuid' => null, 'Nama' => 'Almond', 'Harga' => '0', 'Aktif' => true, 'UuidProdukBahan' => $susuB->Uuid, 'Jumlah' => '150'],
        ], '0'))->assertSessionHasErrors(['Pilihan.0.UuidProdukBahan']);

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(KelompokPilihan::query()->count())->toBe(0);
    });

    it('mengubah kelompok: pilihan diganti per Uuid (ubah, tukar nama, hapus dengan jejak, tambah) dan tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $kelompok = BantuanKomposisi::BuatKelompokPilihan('Level Es', [['Normal', '0'], ['Sedikit Es', '0'], ['Tanpa Es', '0']]);
        [$normal, $sedikit, $tanpa] = Pilihan::query()->orderBy('Urutan')->get()->all();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->put("/kelola/kelompok-pilihan/{$kelompok->Uuid}", IsianKelompokPilihanUji('Level Es Batu', [
            ['Uuid' => $sedikit->Uuid, 'Nama' => 'Normal', 'Harga' => '0', 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''],
            ['Uuid' => $normal->Uuid, 'Nama' => 'Sedikit Es', 'Harga' => '0', 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''],
            ['Uuid' => null, 'Nama' => 'Es Dipisah', 'Harga' => '1000', 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''],
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($kelompok->refresh()->Nama)->toBe('Level Es Batu')
            ->and(Pilihan::query()->orderBy('Urutan')->get()->map->only(['Uuid', 'Nama'])->all())->toBe([
                ['Uuid' => $sedikit->Uuid, 'Nama' => 'Normal'],
                ['Uuid' => $normal->Uuid, 'Nama' => 'Sedikit Es'],
                ['Uuid' => Pilihan::query()->where('Nama', 'Es Dipisah')->value('Uuid'), 'Nama' => 'Es Dipisah'],
            ])
            ->and(PenghapusanKatalog::query()->get()->map(fn (PenghapusanKatalog $baris): array => [$baris->Entitas, $baris->UuidEntitas])->all())
            ->toBe([[EntitasKatalog::Pilihan, $tanpa->Uuid]]);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'kelompok-pilihan.ubah', 'IdObjek' => $kelompok->Id]);
    });

    it('izin harga: tanpa produk.harga.ubah, harga pilihan baru selain 0 atau harga lama yang berubah ditolak', function (): void {
        ['Tenant' => $tenant] = BantuanKatalog::BuatTenant();
        $kelompok = BantuanKomposisi::BuatKelompokPilihan('Topping', [['Keju', '5000']], 0);
        $keju = Pilihan::query()->sole();
        $tes = BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::ManajerOutlet);
        $baris = fn (?string $uuid, string $nama, string $harga): array => ['Uuid' => $uuid, 'Nama' => $nama, 'Harga' => $harga, 'Aktif' => true, 'UuidProdukBahan' => null, 'Jumlah' => ''];

        $tes->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('Saus', [$baris(null, 'Sambal Bawang', '2000')], '0'))
            ->assertSessionHasErrors(['Pilihan.0.Harga']);
        $tes->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('Saus', [$baris(null, 'Sambal Bawang', '0')], '0'))
            ->assertSessionHasNoErrors();
        $tes->put("/kelola/kelompok-pilihan/{$kelompok->Uuid}", IsianKelompokPilihanUji('Topping', [$baris($keju->Uuid, 'Keju', '6000')], '0'))
            ->assertSessionHasErrors(['Pilihan.0.Harga']);
        $tes->put("/kelola/kelompok-pilihan/{$kelompok->Uuid}", IsianKelompokPilihanUji('Topping Martabak', [$baris($keju->Uuid, 'Keju Cheddar', '5000')], '0'))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($keju->refresh()->only(['Nama', 'Harga']))->toBe(['Nama' => 'Keju Cheddar', 'Harga' => '5000.00'])
            ->and(KelompokPilihan::query()->pluck('Nama')->sort()->values()->all())->toBe(['Saus', 'Topping Martabak']);
    });

    it('menghapus kelompok: lepas dari produk, hapus pilihan, semua dengan jejak, tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $kelompok = BantuanKomposisi::BuatKelompokPilihan();
        $menu = BantuanKomposisi::BuatProdukResep();
        app(AturKelompokPilihanProduk::class)->Jalankan($menu, [$kelompok->Id]);
        $tautan = ProdukKelompokPilihan::query()->sole();
        $uuidPilihan = Pilihan::query()->orderBy('Urutan')->pluck('Uuid')->all();

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)->delete("/kelola/kelompok-pilihan/{$kelompok->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(KelompokPilihan::query()->count() + Pilihan::query()->count() + ProdukKelompokPilihan::query()->count())->toBe(0)
            ->and(PenghapusanKatalog::query()->orderBy('Id')->pluck('UuidEntitas')->all())->toBe([$tautan->Uuid, ...$uuidPilihan, $kelompok->Uuid]);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'kelompok-pilihan.hapus', 'IdObjek' => $kelompok->Id]);
    });

    it('isolasi & izin: kelompok tenant lain 404. Kasir 403 untuk tambah, ubah, hapus', function (): void {
        BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        $kelompokB = BantuanKomposisi::BuatKelompokPilihan();
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $kelompokA = BantuanKomposisi::BuatKelompokPilihan();

        $tes = BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);
        $tes->put("/kelola/kelompok-pilihan/{$kelompokB->Uuid}", IsianKelompokPilihanUji())->assertNotFound();
        $tes->delete("/kelola/kelompok-pilihan/{$kelompokB->Uuid}")->assertNotFound();

        $kasir = BantuanKatalog::MasukSebagai($this, $tenantA->Id, PeranTenantBawaan::Kasir);
        $kasir->post('/kelola/kelompok-pilihan', IsianKelompokPilihanUji('Saus'))->assertForbidden();
        $kasir->put("/kelola/kelompok-pilihan/{$kelompokA->Uuid}", IsianKelompokPilihanUji())->assertForbidden();
        $kasir->delete("/kelola/kelompok-pilihan/{$kelompokA->Uuid}")->assertForbidden();

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(KelompokPilihan::query()->count())->toBe(1);
    });

    it('prop daftar: batas sebagai string, Wajib, jumlah produk terpasang, nama & satuan bahan pilihan', function (): void {
        BantuanKatalog::BuatTenant();
        $sirup = BantuanKomposisi::BuatBahan('Sirup Hazelnut Premium Import', 'ml', 'Mililiter');
        $kelompok = BantuanKomposisi::BuatKelompokPilihan('Tambahan Rasa', [['Hazelnut', '5000', $sirup, '15']], 1, 2);
        app(AturKelompokPilihanProduk::class)->Jalankan(BantuanKomposisi::BuatProdukResep(), [$kelompok->Id]);
        $terhapus = BantuanKomposisi::BuatProdukResep('Kopi Susu Lama Sudah Dihapus');
        app(AturKelompokPilihanProduk::class)->Jalankan($terhapus, [$kelompok->Id]);
        Produk::query()->whereKey($terhapus->Id)->firstOrFail()->delete();

        expect(app(DaftarKelompokPilihan::class)->Ambil())->toBe([[
            'Uuid' => $kelompok->Uuid,
            'Nama' => 'Tambahan Rasa',
            'MinimalPilih' => '1',
            'MaksimalPilih' => '2',
            'Urutan' => '0',
            'Wajib' => true,
            'JumlahProduk' => 1,
            'Pilihan' => [[
                'Uuid' => Pilihan::query()->sole()->Uuid,
                'Nama' => 'Hazelnut',
                'Harga' => '5000.00',
                'Aktif' => true,
                'UuidProdukBahan' => $sirup->Uuid,
                'Jumlah' => '15.0000',
                'NamaProdukBahan' => $sirup->Nama,
                'SimbolSatuanBahan' => 'ml',
            ]],
        ]]);
    });

    it('GET halaman tambah kelompok pilihan (halaman penuh) merender Kelola/KelompokPilihan/Buat dengan izin; Kasir 403', function (): void {
        ['Tenant' => $tenant] = BantuanKatalog::BuatTenant();

        BantuanKatalog::MasukSebagai($this, $tenant->Id)->get('/kelola/kelompok-pilihan/buat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/KelompokPilihan/Buat')
                ->where('Izin.Kelola', true)
                ->where('Izin.UbahHarga', true)
                ->missing('KelompokPilihan'));

        BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Kasir)->get('/kelola/kelompok-pilihan/buat')->assertForbidden();
    });

    it('GET daftar kelompok pilihan merender Kelola/KelompokPilihan/Daftar (butuh halaman FE)', function (): void {
        ['Tenant' => $tenant] = BantuanKatalog::BuatTenant();
        BantuanKomposisi::BuatKelompokPilihan();

        BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Kasir)->get('/kelola/kelompok-pilihan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/KelompokPilihan/Daftar')
                ->has('KelompokPilihan', 1)
                ->where('Izin.Kelola', false));
    });
});
