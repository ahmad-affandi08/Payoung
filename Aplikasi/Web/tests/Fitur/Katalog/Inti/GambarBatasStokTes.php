<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Gudang;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
    config(['katalog.DiskGambar' => 'local']);
});

describe('F-03 gambar produk (disk privat)', function (): void {
    it('unggah: diubah ukurannya (besar ≤ 800, kecil ≤ 256), nama berversi, unduh privat dengan cache immutable. Ganti & hapus membersihkan berkas', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi-susu.png', 1200, 900)])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk->refresh();
        $pathLama = (string) $produk->PathGambar;
        $versi = PenyimpanGambarProduk::AmbilVersi($produk);
        expect($pathLama)->toStartWith("produk/{$t['Tenant']->Id}/{$produk->Uuid}-")->toEndWith('.webp')
            ->and($versi)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
        Storage::disk('local')->assertExists([$pathLama, PenyimpanGambarProduk::PathKecil($pathLama)]);
        expect(getimagesizefromstring((string) Storage::disk('local')->get($pathLama)))->toMatchArray([0 => 800, 1 => 600])
            ->and(getimagesizefromstring((string) Storage::disk('local')->get(PenyimpanGambarProduk::PathKecil($pathLama))))->toMatchArray([0 => 256, 1 => 192]);

        $unduh = $masuk()->get("/kelola/produk/{$produk->Uuid}/gambar?ukuran=kecil&versi={$versi}")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        expect((string) $unduh->headers->get('Cache-Control'))->toContain('immutable')->toContain('private')->toContain('max-age=31536000');

        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('kopi-baru.jpg', 400, 400)])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $pathBaru = (string) $produk->refresh()->PathGambar;
        Storage::disk('local')->assertMissing([$pathLama, PenyimpanGambarProduk::PathKecil($pathLama)]);
        Storage::disk('local')->assertExists($pathBaru);

        $masuk()->delete("/kelola/produk/{$produk->Uuid}/gambar")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($produk->refresh()->PathGambar)->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.gambar.ubah')->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.gambar.hapus')->count())->toBe(1);
        Storage::disk('local')->assertMissing($pathBaru);
        $masuk()->get("/kelola/produk/{$produk->Uuid}/gambar?ukuran=besar")->assertNotFound();
    });

    it('menolak gambar terlalu kecil, bukan gambar, atau terlalu besar (GambarTidakValid)', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('ikon.png', 120, 120)])
            ->assertSessionHasErrors(['Gambar' => 'Gambar minimal 200×200 piksel agar tetap jelas di layar kasir.']);
        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')])->assertSessionHasErrors('Gambar');
        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->create('palsu.png', 100, 'image/png')])->assertSessionHasErrors('Gambar');
        $masuk()->post("/kelola/produk/{$produk->Uuid}/gambar", ['Gambar' => UploadedFile::fake()->image('besar.jpg', 400, 400)->size(6000)])->assertSessionHasErrors('Gambar');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($produk->refresh()->PathGambar)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('F-03 batas stok per lokasi stok (ProdukGudang)', function (): void {
    it('StafGudang (persediaan.kelola) menyimpan min/max. Set diganti. Baris kosong dihapus. Min > max ditolak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $toko = Gudang::query()->orderBy('Id')->firstOrFail();
        $dapur = Gudang::query()->create(['IdOutlet' => $t['Outlet']->Id, 'Kode' => 'DPR', 'Nama' => 'Dapur', 'Jenis' => JenisGudang::cases()[0]]);
        $produk = BantuanKatalog::BuatProduk([], null, $t['Kg']);
        $url = "/kelola/produk/{$produk->Uuid}/batas-stok";
        $staf = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang);

        $staf()->put($url, ['Baris' => [
            ['UuidGudang' => $toko->Uuid, 'StokMinimum' => '2.5', 'StokMaksimum' => '100'],
            ['UuidGudang' => $dapur->Uuid, 'StokMinimum' => '', 'StokMaksimum' => '12.1234'],
        ]])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukGudang::query()->where('IdGudang', $toko->Id)->sole()->StokMinimum)->toBe('2.5000')
            ->and(ProdukGudang::query()->where('IdGudang', $dapur->Id)->sole()->StokMinimum)->toBeNull();

        $staf()->put($url, ['Baris' => [['UuidGudang' => $toko->Uuid, 'StokMinimum' => '10', 'StokMaksimum' => '5']]])
            ->assertSessionHasErrors(['Baris.0.StokMaksimum' => 'Stok minimum tidak boleh negatif dan tidak boleh lebih besar dari stok maksimum.']);
        $staf()->put($url, ['Baris' => [['UuidGudang' => $toko->Uuid, 'StokMinimum' => '1.12345', 'StokMaksimum' => '']]])->assertSessionHasErrors('Baris.0.StokMinimum');
        $staf()->put($url, ['Baris' => [['UuidGudang' => $toko->Uuid, 'StokMinimum' => '', 'StokMaksimum' => '']]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukGudang::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.batas-stok.ubah')->count())->toBe(2);

        Gudang::query()->whereKey($dapur->Id)->update(['Status' => StatusOrganisasi::Diarsipkan->value]);
        $staf()->put($url, ['Baris' => [['UuidGudang' => $dapur->Uuid, 'StokMinimum' => '1', 'StokMaksimum' => '']]])->assertSessionHasErrors('Baris.0.UuidGudang');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir)->put($url, ['Baris' => []])->assertForbidden();
    });

    it('hanya untuk jenis produk yang punya stok', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $jasa = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::Jasa], null, $t['Pcs']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$jasa->Uuid}/batas-stok", ['Baris' => [
            ['UuidGudang' => Gudang::query()->value('Uuid'), 'StokMinimum' => '1', 'StokMaksimum' => ''],
        ]])->assertSessionHasErrors(['Baris' => 'Produk Jasa tidak punya stok, jadi tidak memakai batas stok.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(1)->and(ProdukGudang::query()->count())->toBe(0);
    });
});
