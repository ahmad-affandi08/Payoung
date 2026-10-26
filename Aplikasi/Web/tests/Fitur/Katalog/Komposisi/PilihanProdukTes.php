<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Pilihan\Aksi\AturKelompokPilihanProduk;
use App\Domain\Katalog\Pilihan\Kueri\PilihanProduk;
use App\Domain\Katalog\Pilihan\Model\ProdukKelompokPilihan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('Pilihan produk', function (): void {
    it('memasang kelompok berurutan, lalu mengurutkan ulang dan melepas satu dengan jejak. Tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $gula = BantuanKomposisi::BuatKelompokPilihan('Level Gula');
        $es = BantuanKomposisi::BuatKelompokPilihan('Level Es', [['Normal', '0'], ['Tanpa Es', '0']]);
        $topping = BantuanKomposisi::BuatKelompokPilihan('Topping', [['Boba', '4000'], ['Jelly', '3000']], 0, 2);
        $menu = BantuanKomposisi::BuatProdukResep();
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->put("/kelola/produk/{$menu->Uuid}/pilihan", ['KelompokPilihan' => [$gula->Uuid, $es->Uuid, $topping->Uuid]])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $tautanEs = ProdukKelompokPilihan::query()->where('IdKelompokPilihan', $es->Id)->sole();

        $tes->put("/kelola/produk/{$menu->Uuid}/pilihan", ['KelompokPilihan' => [$topping->Uuid, $gula->Uuid]])->assertSessionHasNoErrors();
        $tes->put("/kelola/produk/{$menu->Uuid}/pilihan", ['KelompokPilihan' => [$topping->Uuid, $gula->Uuid]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(ProdukKelompokPilihan::query()->where('IdProduk', $menu->Id)->orderBy('Urutan')->pluck('IdKelompokPilihan')->all())->toBe([$topping->Id, $gula->Id])
            ->and(PenghapusanKatalog::query()->get()->map(fn (PenghapusanKatalog $baris): array => [$baris->Entitas, $baris->UuidEntitas])->all())
            ->toBe([[EntitasKatalog::ProdukKelompokPilihan, $tautanEs->Uuid]]);
        // PUT ketiga sama dengan kedua: tidak ada perubahan, tidak ada audit baru.
        expect(LogAudit::query()->where('Peristiwa', 'produk.pilihan.ubah')->count())->toBe(2);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'produk.pilihan.ubah', 'IdObjek' => $menu->Id, 'IdPengguna' => $pemilik->Id]);
    });

    it('bahan baku dan anak varian tidak bisa dipasangi kelompok (JenisTidakMendukung)', function (): void {
        BantuanKatalog::BuatTenant();
        $kelompok = BantuanKomposisi::BuatKelompokPilihan();
        $bahan = BantuanKomposisi::BuatBahan();
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'Nama' => 'Es Teh Manis Jumbo', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['M', 'L']]]], harga: null);
        $anak = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'L']], 'KunciVarian' => 'ukuran=l']);

        foreach ([$bahan, $anak] as $produk) {
            expect(fn () => app(AturKelompokPilihanProduk::class)->Jalankan($produk, [$kelompok->Id]))
                ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['JenisTidakMendukung', 'KelompokPilihan']));
        }

        app(AturKelompokPilihanProduk::class)->Jalankan($induk, [$kelompok->Id]);
        $props = app(PilihanProduk::class)->Ambil($anak);
        expect($props['DariInduk'])->toBeTrue()
            ->and($props['Terpasang'])->toBe([['Uuid' => $kelompok->Uuid, 'Nama' => 'Level Gula', 'Ringkasan' => 'Wajib pilih 1 | 3 pilihan']])
            ->and($props['Tersedia'])->toBe([]);
    });

    it('isolasi: kelompok tenant lain tidak ditemukan, produk tenant lain 404. Kasir 403', function (): void {
        BantuanKatalog::BuatTenant('Warung Kopi Tetangga Sebelah');
        $kelompokB = BantuanKomposisi::BuatKelompokPilihan();
        $menuB = BantuanKomposisi::BuatProdukResep();
        ['Tenant' => $tenantA, 'Pemilik' => $pemilikA] = BantuanKatalog::BuatTenant('Kedai Kopi Senja Solo');
        $kelompokA = BantuanKomposisi::BuatKelompokPilihan('Level Es', [['Normal', '0']]);
        $menuA = BantuanKomposisi::BuatProdukResep();

        expect(fn () => app(AturKelompokPilihanProduk::class)->Jalankan($menuA, [$kelompokB->Id]))
            ->toThrow(fn (PelanggaranAturanBisnis $galat) => expect([$galat->kode, $galat->bidang])->toBe(['KelompokPilihanTidakDikenal', 'KelompokPilihan.0']));

        $tes = BantuanOrganisasi::Masuk($this, $pemilikA, $tenantA->Id);
        $tes->put("/kelola/produk/{$menuA->Uuid}/pilihan", ['KelompokPilihan' => [$kelompokA->Uuid, $kelompokB->Uuid]])->assertSessionHasErrors(['KelompokPilihan.1']);
        $tes->put("/kelola/produk/{$menuB->Uuid}/pilihan", ['KelompokPilihan' => [$kelompokA->Uuid]])->assertNotFound();
        $tes->get("/kelola/produk/{$menuB->Uuid}/pilihan")->assertNotFound();
        BantuanKatalog::MasukSebagai($this, $tenantA->Id, PeranTenantBawaan::Kasir)
            ->put("/kelola/produk/{$menuA->Uuid}/pilihan", ['KelompokPilihan' => [$kelompokA->Uuid]])->assertForbidden();

        BantuanOrganisasi::AturKonteks($tenantA->Id);
        expect(ProdukKelompokPilihan::query()->count())->toBe(0);
    });

    it('prop Terpasang berurutan dan Tersedia sisanya dengan ringkasan batas', function (): void {
        BantuanKatalog::BuatTenant();
        $gula = BantuanKomposisi::BuatKelompokPilihan('Level Gula');
        $topping = BantuanKomposisi::BuatKelompokPilihan('Topping', [['Boba', '4000'], ['Jelly', '3000']], 0, 2);
        $ukuran = BantuanKomposisi::BuatKelompokPilihan('Ukuran Gelas', [['Regular', '0'], ['Large', '5000']], 1, 1);
        $menu = BantuanKomposisi::BuatProdukResep();
        app(AturKelompokPilihanProduk::class)->Jalankan($menu, [$topping->Id, $gula->Id]);

        expect(app(PilihanProduk::class)->Ambil($menu))->toBe([
            'Terpasang' => [
                ['Uuid' => $topping->Uuid, 'Nama' => 'Topping', 'Ringkasan' => 'Opsional, maks. 2 | 2 pilihan'],
                ['Uuid' => $gula->Uuid, 'Nama' => 'Level Gula', 'Ringkasan' => 'Wajib pilih 1 | 3 pilihan'],
            ],
            'Tersedia' => [['Uuid' => $ukuran->Uuid, 'Nama' => 'Ukuran Gelas', 'Ringkasan' => 'Wajib pilih 1 | 2 pilihan']],
            'DariInduk' => false,
        ]);
    });

    it('GET pilihan produk merender Kelola/Produk/Pilihan dengan Kepala (butuh halaman FE). Bahan baku 404', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanKatalog::BuatTenant();
        $menu = BantuanKomposisi::BuatProdukResep();
        $bahan = BantuanKomposisi::BuatBahan();
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $tes->get("/kelola/produk/{$bahan->Uuid}/pilihan")->assertNotFound();
        $tes->get("/kelola/produk/{$menu->Uuid}/pilihan")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/Produk/Pilihan')
                ->where('Kepala.Uuid', $menu->Uuid)
                ->where('DariInduk', false)
                ->has('Terpasang', 0));
    });
});
