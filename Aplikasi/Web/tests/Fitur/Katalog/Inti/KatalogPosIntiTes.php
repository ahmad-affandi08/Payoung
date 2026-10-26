<?php

declare(strict_types=1);

use App\Domain\Katalog\Data\KonteksKatalogPos;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kontrak\BagianKatalogPos;
use App\Domain\Katalog\Kontrak\PemeriksaPemakaianProduk;
use App\Domain\Katalog\Layanan\PemakaianVarianProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukSatuan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Gabungan semua bagian katalog POS yang ditandai (tanpa amplop Tim 2).
 *
 * @return array<string, list<array<string, mixed>>>
 */
function AmbilBagianPosUji(int $idTenant, int $idOutlet, ?CarbonImmutable $sejak): array
{
    $hasil = [];

    foreach (app()->tagged(BagianKatalogPos::TAG) as $bagian) {
        $hasil = array_merge($hasil, $bagian->AmbilBagian(new KonteksKatalogPos($idTenant, $idOutlet, $sejak)));
    }

    return $hasil;
}

describe('F-03 bagian katalog POS Tim 1 (D.3)', function (): void {
    it('lengkap: Kategori, Satuan, Produk (arsip ikut, terhapus tidak), ProdukSatuan, ProdukBarcode dengan FK Uuid', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $kopi = BantuanKatalog::BuatKategori('Kopi', $minuman);
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'Sku' => 'ESKOPI', 'IdKategori' => $kopi->Id, 'IdKelompokPajak' => $t['KelompokPajak']->Id, 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['Reguler']]], 'PathGambar' => 'produk/1/x-01JBGAMBARVERSI0000000000A.webp'], null, $t['Pcs']);
        $anak = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'KunciVarian' => 'ukuran=reguler', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'Reguler']], 'Aktif' => false, 'DiarsipkanPada' => now()], null, $t['Pcs']);
        $satuanAnak = ProdukSatuan::query()->where('IdProduk', $anak->Id)->sole();
        $barcode = ProdukBarcode::query()->create(['IdProduk' => $anak->Id, 'IdProdukSatuan' => $satuanAnak->Id, 'Barcode' => '8990001112223']);
        $terhapus = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $terhapus->delete();

        $bagian = AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, null);

        // Bagian Tim 1 wajib ada; bagian tim lain (Pilihan, Resep, PaketProduk) ikut terdaftar lewat tag yang sama.
        expect(array_keys($bagian))->toContain('Kategori', 'Satuan', 'Produk', 'ProdukSatuan', 'ProdukBarcode')
            ->and($bagian['Kategori'])->toContain(['Uuid' => $kopi->Uuid, 'UuidInduk' => $minuman->Uuid, 'Nama' => 'Kopi', 'Urutan' => 0])
            ->and($bagian['Satuan'])->toContain(['Uuid' => $t['Kg']->Uuid, 'Nama' => 'Kilogram', 'Simbol' => 'kg', 'BolehDesimal' => true])
            ->and(array_column($bagian['Produk'], 'Uuid'))->toBe([$induk->Uuid, $anak->Uuid])
            ->and($bagian['ProdukSatuan'])->toHaveCount(2)
            ->and($bagian['ProdukSatuan'][1])->toBe(['Uuid' => $satuanAnak->Uuid, 'UuidProduk' => $anak->Uuid, 'UuidSatuan' => $t['Pcs']->Uuid, 'KonversiKeDasar' => '1.0000', 'DefaultJual' => true, 'DefaultBeli' => true])
            ->and($bagian['ProdukBarcode'])->toBe([['Uuid' => $barcode->Uuid, 'UuidProduk' => $anak->Uuid, 'UuidProdukSatuan' => $satuanAnak->Uuid, 'Barcode' => '8990001112223']]);

        $barisInduk = $bagian['Produk'][0];
        expect($barisInduk)->toMatchArray([
            'Sku' => 'ESKOPI',
            'Jenis' => 'IndukVarian',
            'UuidKategori' => $kopi->Uuid,
            'UuidSatuanDasar' => $t['Pcs']->Uuid,
            'UuidKelompokPajak' => $t['KelompokPajak']->Uuid,
            'UuidInduk' => null,
            'HargaTermasukPajak' => null,
            'Aktif' => true,
            'Dihapus' => false,
        ])
            ->and($barisInduk['UrlGambar'])->toEndWith("/api/pos/v1/katalog/gambar/{$induk->Uuid}?ukuran=besar&versi=01JBGAMBARVERSI0000000000A")
            ->and($barisInduk['UrlGambarKecil'])->toEndWith('?ukuran=kecil&versi=01JBGAMBARVERSI0000000000A')
            ->and($bagian['Produk'][1])->toMatchArray(['UuidInduk' => $induk->Uuid, 'Aktif' => false, 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'Reguler']], 'UrlGambar' => null]);
    });

    it('delta: hanya baris dengan DiubahPada ≥ sejak, termasuk produk terhapus (Dihapus: true). Tenant lain tidak ikut', function (): void {
        // Jam dibekukan sebelum tenant & satuan dibuat: tanpa itu `CreatedAt` satuan memakai jam nyata yang sudah
        // melewati batas 2026-10-01 03:30 dan tes menjadi bergantung pada tanggal berjalan.
        Carbon::setTestNow('2026-10-01 03:00:00');
        $t = BantuanKatalog::SiapkanTenantProduk();
        $lama = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $akanDihapus = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        Carbon::setTestNow('2026-10-01 04:00:00');
        $baru = BantuanKatalog::BuatProduk(['Nama' => 'Es Teh Manis Jumbo'], null, $t['Pcs']);
        $akanDihapus->delete();
        BantuanKatalog::SiapkanTenantProduk('Toko Tenant Lain');
        BantuanKatalog::BuatProduk(['Nama' => 'Produk Tenant Lain'], null);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $bagian = AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, CarbonImmutable::parse('2026-10-01 03:30:00'));
        Carbon::setTestNow();

        expect(array_column($bagian['Produk'], 'Uuid'))->toEqualCanonicalizing([$baru->Uuid, $akanDihapus->Uuid])
            ->and(collect($bagian['Produk'])->firstWhere('Uuid', $akanDihapus->Uuid)['Dihapus'])->toBeTrue()
            ->and(array_column($bagian['ProdukSatuan'], 'UuidProduk'))->toBe([$baru->Uuid])
            ->and($bagian['Kategori'])->toBe([])
            ->and($bagian['Satuan'])->toBe([])
            ->and(array_column($bagian['Produk'], 'Uuid'))->not->toContain($lama->Uuid);
    });

    it('PemakaianVarianProduk terdaftar: induk yang masih punya anak dianggap dipakai', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian], null, $t['Pcs']);
        $pemeriksa = collect(iterator_to_array(app()->tagged(PemeriksaPemakaianProduk::TAG)));

        expect($pemeriksa->contains(fn (object $p): bool => $p instanceof PemakaianVarianProduk))->toBeTrue()
            ->and(app(PemakaianVarianProduk::class)->PeriksaPemakaian($induk->Id))->toBeNull();

        BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'KunciVarian' => 'ukuran=s'], null, $t['Pcs']);
        expect(app(PemakaianVarianProduk::class)->PeriksaPemakaian($induk->Id))->toBe('masih punya 1 varian')
            ->and(Produk::query()->count())->toBe(2);
    });
});

describe('F-05h katalog POS: masa garansi produk bernomor seri', function (): void {
    it('Produk membawa MasaGaransiBulan (null = tanpa garansi) supaya struk cetak kasir bisa memuat garansi', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $hp = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 8/256 GB Hitam', 'Pelacakan' => 'Seri', 'MasaGaransiBulan' => 12, 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);
        $biasa = BantuanKatalog::BuatProduk(['Nama' => 'Casing Silikon Bening', 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);

        $produk = collect(AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, null)['Produk'])->keyBy('Uuid');

        expect($produk[$hp->Uuid]['MasaGaransiBulan'])->toBe(12)
            ->and($produk[$biasa->Uuid]['MasaGaransiBulan'])->toBeNull();
    });
});

describe('K-25 katalog POS: item harga terbuka', function (): void {
    it('Produk membawa HargaTerbuka supaya kasir meminta harga saat produk ditambahkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $lain = BantuanKatalog::BuatProduk(['Nama' => 'Barang lain-lain', 'Jenis' => 'NonStok', 'HargaTerbuka' => true, 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);
        $biasa = BantuanKatalog::BuatProduk(['Nama' => 'Casing Silikon Bening', 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);

        $produk = collect(AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, null)['Produk'])->keyBy('Uuid');

        expect($produk[$lain->Uuid]['HargaTerbuka'])->toBeTrue()
            ->and($produk[$biasa->Uuid]['HargaTerbuka'])->toBeFalse();
    });
});

describe('K-16 katalog POS: produk favorit pilihan pemilik', function (): void {
    it('Produk membawa Favorit (tingkat tenant, false bila tidak ditandai). Perubahan favorit masuk delta', function (): void {
        Carbon::setTestNow('2026-10-01 03:00:00');
        $t = BantuanKatalog::SiapkanTenantProduk();
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren', 'Favorit' => true, 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);
        $roti = BantuanKatalog::BuatProduk(['Nama' => 'Roti Bakar Cokelat Keju', 'IdKelompokPajak' => $t['KelompokPajak']->Id], null, $t['Pcs']);
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

        $produk = collect(AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, null)['Produk'])->keyBy('Uuid');
        expect($produk[$kopi->Uuid]['Favorit'])->toBeTrue()->and($produk[$roti->Uuid]['Favorit'])->toBeFalse();

        Carbon::setTestNow('2026-10-01 04:00:00');
        $roti->update(['Favorit' => true]);
        $delta = collect(AmbilBagianPosUji($t['Tenant']->Id, $t['Outlet']->Id, CarbonImmutable::parse('2026-10-01 03:30:00'))['Produk']);
        Carbon::setTestNow();

        expect($delta->pluck('Uuid')->all())->toBe([$roti->Uuid])->and($delta[0]['Favorit'])->toBeTrue();
    });
});
