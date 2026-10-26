<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kontrak\PemeriksaPemakaianProduk;
use App\Domain\Katalog\Kueri\PemakaianSku;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Gudang;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Titik perluasan BR-03.2: produk dengan nama berawalan "Terjual" dianggap sudah punya penjualan. */
final class PemeriksaPalsuPenjualanArsip implements PemeriksaPemakaianProduk
{
    public function PeriksaPemakaian(int $idProduk): ?string
    {
        return str_starts_with((string) Produk::query()->withTrashed()->whereKey($idProduk)->value('Nama'), 'Terjual') ? 'sudah ada penjualan' : null;
    }
}

describe('F-03 BR-03.2 arsip, pulihkan, hapus', function (): void {
    it('arsip menyembunyikan dari daftar aktif & tidak dihitung BatasSku; pulihkan mengembalikan; idempoten; audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk(['Sku' => 'KOPI-01'], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post("/kelola/produk/{$produk->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $masuk()->post("/kelola/produk/{$produk->Uuid}/arsipkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk->refresh();
        expect($produk->Aktif)->toBeFalse()->and($produk->DiarsipkanPada)->not->toBeNull()->and($produk->Sku)->toBe('KOPI-01')
            ->and(app(PemakaianSku::class)->Hitung())->toBe(0);

        $masuk()->post("/kelola/produk/{$produk->Uuid}/pulihkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($produk->refresh()->Aktif)->toBeTrue()->and($produk->DiarsipkanPada)->toBeNull()
            ->and(app(PemakaianSku::class)->Hitung())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.arsipkan')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.pulihkan')->count())->toBe(1);
    });

    it('hapus produk belum dipakai: soft delete, SKU dilepas, barcode & harga & batas stok hilang, jejak dicatat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk(['Sku' => 'SABUN-01'], '4500.00', $t['Pcs']);
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        $barcode = ProdukBarcode::query()->create(['IdProduk' => $produk->Id, 'IdProdukSatuan' => $satuan->Id, 'Barcode' => '8999999000011']);
        ProdukGudang::query()->create(['IdProduk' => $produk->Id, 'IdGudang' => Gudang::query()->value('Id'), 'StokMinimum' => '5']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->delete("/kelola/produk/{$produk->Uuid}")
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/produk');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $terhapus = Produk::query()->withTrashed()->whereKey($produk->Id)->sole();
        expect($terhapus->DihapusPada)->not->toBeNull()
            ->and($terhapus->Sku)->toBeNull()
            ->and(ProdukBarcode::query()->count())->toBe(0)
            ->and(ProdukHarga::query()->count())->toBe(0)
            ->and(ProdukGudang::query()->count())->toBe(0)
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::ProdukBarcode->value)->sole()->UuidEntitas)->toBe($barcode->Uuid)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.hapus')->sole()->NilaiLama)->toMatchArray(['Sku' => 'SABUN-01']);

        // SKU yang dilepas bisa dipakai produk baru.
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Sku' => 'SABUN-01']))->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->get("/kelola/produk/{$produk->Uuid}/ubah")->assertNotFound();
    });

    it('titik perluasan: pemeriksa pemakaian palsu "sudah ada penjualan" → BR-03.2, arsip tetap bisa', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        app()->tag([PemeriksaPalsuPenjualanArsip::class], PemeriksaPemakaianProduk::TAG);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Terjual Kopi Susu Gula Aren'], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->delete("/kelola/produk/{$produk->Uuid}")->assertSessionHasErrors(['Umum' => 'Produk ini sudah ada penjualan. Arsipkan produk ini.']);
        $masuk()->post("/kelola/produk/{$produk->Uuid}/arsipkan")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereKey($produk->Id)->sole()->Aktif)->toBeFalse();
    });

    it('induk varian: anak yang sudah dipakai memblokir hapus. Bila semua anak belum dipakai, anak dihapus lebih dulu', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        app()->tag([PemeriksaPalsuPenjualanArsip::class], PemeriksaPemakaianProduk::TAG);
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'Nama' => 'Kaos Polos Katun', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]], null, $t['Pcs']);
        $anakS = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'Nama' => 'Kaos Polos Katun S', 'KunciVarian' => 'ukuran=s', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'S']]], null, $t['Pcs']);
        $anakM = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'Nama' => 'Terjual Kaos Polos Katun M', 'KunciVarian' => 'ukuran=m', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'M']]], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->delete("/kelola/produk/{$induk->Uuid}")->assertSessionHasErrors(['Umum' => 'Varian Terjual Kaos Polos Katun M sudah ada penjualan. Arsipkan produk ini.']);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereKey([$anakS->Id, $anakM->Id])->count())->toBe(2);

        Produk::query()->whereKey($anakM->Id)->update(['Nama' => 'Kaos Polos Katun M']);
        $masuk()->delete("/kelola/produk/{$induk->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(0)
            ->and(Produk::query()->withTrashed()->whereKey($anakS->Id)->sole()->KunciVarian)->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.hapus')->count())->toBe(3);
    });

    it('arsip induk varian ikut mengarsipkan anak. Pulihkan memeriksa BatasSku untuk semua anak sekaligus', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk(kodePaket: 'GRATIS');
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]], null, $t['Pcs']);
        BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'KunciVarian' => 'ukuran=s'], null, $t['Pcs']);
        BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'KunciVarian' => 'ukuran=m'], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post("/kelola/produk/{$induk->Uuid}/arsipkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereNull('DiarsipkanPada')->count())->toBe(0)->and(app(PemakaianSku::class)->Hitung())->toBe(0);

        // 99 produk aktif lain: sisa 1 slot, padahal pulihkan butuh 2 (induk tidak dihitung).
        foreach (range(1, 99) as $nomor) {
            BantuanKatalog::BuatProduk(['Nama' => "Produk Pengisi {$nomor}"], null, $t['Pcs']);
        }

        $masuk()->post("/kelola/produk/{$induk->Uuid}/pulihkan")
            ->assertSessionHasErrors(['Umum' => 'Paket Gratis mencakup maksimal 100 SKU produk dan semuanya sudah terpakai (99). Tingkatkan paket atau tambah add-on di menu Langganan untuk menambah SKU produk.']);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereNotNull('DiarsipkanPada')->count())->toBe(3);

        Produk::query()->where('Nama', 'Produk Pengisi 1')->sole()->delete();
        $masuk()->post("/kelola/produk/{$induk->Uuid}/pulihkan")->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(app(PemakaianSku::class)->Hitung())->toBe(100);
    });

    it('BatasSku saat membuat: ditolak di batas. Produk arsip dan induk varian tidak dihitung', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk(kodePaket: 'GRATIS');
        foreach (range(1, 100) as $nomor) {
            BantuanKatalog::BuatProduk(['Nama' => "Produk Pengisi {$nomor}"], null, $t['Pcs']);
        }
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']))->assertSessionHasErrors('Umum');
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Jenis' => 'IndukVarian', 'AtributVarian' => [['Nama' => 'Warna', 'Nilai' => ['Merah']]]]))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        Produk::query()->where('Nama', 'Produk Pengisi 1')->update(['Aktif' => false, 'DiarsipkanPada' => now()]);
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(102)->and(app(PemakaianSku::class)->Hitung())->toBe(100);
    });
});
