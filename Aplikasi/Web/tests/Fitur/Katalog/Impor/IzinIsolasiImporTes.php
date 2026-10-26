<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Impor\Tugas\ValidasiImporProdukTugas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

describe('F-03 izin impor/ekspor produk', function (): void {
    it('Kasir (produk.lihat) boleh ekspor tetapi 403 untuk semua rute impor', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $pemilik = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($pemilik, BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi Tubruk']]));
        $kasir = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $p = "/kelola/produk/impor/{$impor->Uuid}";

        $kasir()->get('/kelola/produk/impor')->assertForbidden();
        $kasir()->get('/kelola/produk/impor/templat?format=xlsx')->assertForbidden();
        $kasir()->post('/kelola/produk/impor', ['Berkas' => BantuanImpor::BuatCsv([['Nama Produk'], ['Kopi']]), 'Sumber' => 'Umum'])->assertForbidden();
        $kasir()->get($p)->assertForbidden();
        $kasir()->get("{$p}/status")->assertForbidden();
        $kasir()->put("{$p}/pemetaan", ['Pemetaan' => $impor->Pemetaan, 'Opsi' => []])->assertForbidden();
        $kasir()->post("{$p}/terapkan")->assertForbidden();
        $kasir()->post("{$p}/lanjutkan")->assertForbidden();
        $kasir()->post("{$p}/batalkan")->assertForbidden();
        $kasir()->get("{$p}/laporan?jenis=semua&format=csv")->assertForbidden();
        $kasir()->get('/kelola/produk/ekspor?format=csv')->assertOk();

        expect($impor->refresh()->Status)->toBe(StatusImporProduk::MenungguPemetaan);
    });

    it('Manajer Outlet tanpa produk.harga.ubah: kolom harga diabaikan dengan peringatan, produk dibuat tanpa harga', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $manajer = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $impor = BantuanImpor::Unggah($manajer, BantuanImpor::BuatCsv([
            ['Nama Produk', 'SKU', 'Harga Jual', 'Min. Qty Grosir 1', 'Harga Grosir 1', 'Kelompok Pajak'],
            ['Beras Pandan Wangi 5 kg', 'BRS-5', '78.500', '10', '75.000', 'Barang kena PPN'],
        ]));
        expect($impor->Pemetaan)->toMatchArray(['HargaJual' => 2, 'JumlahGrosir1' => 3, 'HargaGrosir1' => 4]);

        BantuanImpor::Petakan($manajer, $impor)->assertSessionHasNoErrors();
        $impor->refresh();
        expect($impor->Pemetaan)->toMatchArray(['HargaJual' => null, 'JumlahGrosir1' => null, 'HargaGrosir1' => null, 'Nama' => 0])
            ->and($impor->AmbilPeringatan()[0])->toBe('Kolom harga diabaikan karena Anda tidak punya izin produk.harga.ubah: Harga Jual, Min. Qty Grosir 1, Harga Grosir 1. Produk baru dibuat tanpa harga.');
        $manajer->get("/kelola/produk/impor/{$impor->Uuid}")->assertInertia(fn ($h) => $h
            ->where('Izin.UbahHarga', false)
            ->where('Pratinjau.Peringatan.0', fn (string $pesan): bool => str_starts_with($pesan, 'Kolom harga diabaikan')));

        $manajer->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $beras = Produk::query()->where('Sku', 'BRS-5')->sole();
        expect(ProdukHarga::query()->where('IdProduk', $beras->Id)->count())->toBe(0);
    });

    it('batalkan dari Pratinjau: tidak ada produk berubah, audit produk.impor.batalkan. Tidak bisa dibatalkan dua kali', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([['Nama Produk', 'Kelompok Pajak'], ['Kopi Tubruk', 'Barang kena PPN']]));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/batalkan")->assertSessionHasNoErrors();
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/batalkan")->assertSessionHasErrors('Impor');
        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasErrors('Impor');
        $masuk->put("/kelola/produk/impor/{$impor->Uuid}/pemetaan", ['Pemetaan' => $impor->Pemetaan, 'Opsi' => ['Mode' => 'TambahSaja', 'JenisBawaan' => 'Stok', 'BuatKategoriBaru' => true, 'BuatSatuanBaru' => true]])
            ->assertSessionHasErrors('Pemetaan');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Dibatalkan)
            ->and(Produk::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.impor.batalkan')->where('IdObjek', $impor->Id)->count())->toBe(1);
    });

    it('pemetaan: Nama wajib, kolom tidak boleh ganda, jenis bawaan bukan induk varian, kelompok pajak bawaan tenant sendiri', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv([['Nama Produk', 'Merek'], ['Kopi Tubruk', 'Kapal Api']]));

        BantuanImpor::Petakan($masuk, $impor, [], array_replace($impor->Pemetaan ?? [], ['Nama' => null]))
            ->assertSessionHasErrors(['Pemetaan.Nama' => 'Nama Produk wajib dipetakan ke satu kolom.']);
        BantuanImpor::Petakan($masuk, $impor, [], array_replace($impor->Pemetaan ?? [], ['Merek' => 0]))->assertSessionHasErrors('Pemetaan.Merek');
        BantuanImpor::Petakan($masuk, $impor, [], array_replace($impor->Pemetaan ?? [], ['Sku' => 7]))->assertSessionHasErrors('Pemetaan.Sku');
        BantuanImpor::Petakan($masuk, $impor, ['JenisBawaan' => 'IndukVarian'])->assertSessionHasErrors('Opsi.JenisBawaan');

        $lain = BantuanKatalog::SiapkanTenantProduk('Toko Lain');
        BantuanImpor::Petakan(BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id), $impor, ['UuidKelompokPajakBawaan' => $lain['KelompokPajak']->Uuid])
            ->assertSessionHasErrors('Opsi.UuidKelompokPajakBawaan');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::MenungguPemetaan);
    });
});

describe('F-03 isolasi tenant impor', function (): void {
    it('setiap {uuid} impor tenant A diminta tenant B → 404 (detail, status, pemetaan, terapkan, lanjutkan, batalkan, laporan)', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $masukA = BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masukA, BantuanImpor::BuatCsv([['Nama Produk', 'Kelompok Pajak'], ['Rahasia Tenant A', 'Barang kena PPN']]));
        BantuanImpor::Petakan($masukA, $impor)->assertSessionHasNoErrors();

        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');
        $masukB = fn () => BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $p = "/kelola/produk/impor/{$impor->Uuid}";

        $masukB()->get($p)->assertNotFound();
        $masukB()->getJson("{$p}/status")->assertNotFound();
        $masukB()->put("{$p}/pemetaan", ['Pemetaan' => $impor->Pemetaan, 'Opsi' => ['Mode' => 'TambahSaja', 'JenisBawaan' => 'Stok', 'BuatKategoriBaru' => true, 'BuatSatuanBaru' => true]])->assertNotFound();
        $masukB()->post("{$p}/terapkan")->assertNotFound();
        $masukB()->post("{$p}/lanjutkan")->assertNotFound();
        $masukB()->post("{$p}/batalkan")->assertNotFound();
        $masukB()->get("{$p}/laporan?jenis=semua&format=xlsx")->assertNotFound();
        $masukB()->get('/kelola/produk/impor')->assertInertia(fn ($h) => $h->has('Riwayat.Data', 0));
        $masukB()->get('/kelola/produk/ekspor?format=csv')->assertOk();
        expect(BantuanImpor::BacaUnduhan($masukB()->get('/kelola/produk/ekspor?format=csv&saring[Status]=Semua'), 'csv'))->toHaveCount(1);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Pratinjau)->and(Produk::query()->count())->toBe(0);
    });

    it('tugas antrean memakai IdTenant yang dibawanya: tugas bertenant salah tidak melihat impor tenant lain', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Tenant A');
        $masukA = BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masukA, BantuanImpor::BuatCsv([['Nama Produk', 'Kelompok Pajak'], ['Kopi A', 'Barang kena PPN']]));
        $b = BantuanKatalog::SiapkanTenantProduk('Toko Tenant B');

        Queue::fake();
        BantuanImpor::Petakan($masukA, $impor)->assertSessionHasNoErrors();
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Memvalidasi);

        app()->call([new ValidasiImporProdukTugas($b['Tenant']->Id, $impor->IdPengguna, $impor->Id), 'handle']);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Memvalidasi)
            ->and(ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->count())->toBe(0);

        app()->call([new ValidasiImporProdukTugas($a['Tenant']->Id, $impor->IdPengguna, $impor->Id), 'handle']);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Pratinjau)
            ->and(ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->sole()->IdTenant)->toBe($a['Tenant']->Id);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(ImporProduk::query()->count())->toBe(0)->and(ImporProdukBaris::query()->count())->toBe(0);
    });
});
