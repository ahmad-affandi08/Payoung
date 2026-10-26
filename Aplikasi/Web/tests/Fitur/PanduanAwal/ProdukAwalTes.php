<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\TambahProdukCepat;
use App\Domain\Katalog\Data\DataProdukCepat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Pajak\Model\KelompokPajak;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('F-01 langkah 4: produk awal (contoh template & tambah cepat)', function (): void {
    it('(a) produk contoh terpilih menjadi Produk + ProdukSatuan + ProdukHarga dengan kategori, satuan, dan kelompok pajak template', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk/contoh', ['ProdukContoh' => [
            ['Nama' => 'Es Kopi Susu Gula Aren', 'Harga' => '22000'],
            ['Nama' => 'Americano Panas Single Origin Gayo', 'Harga' => '27500.50'],
        ]])
            ->assertRedirect('/kelola/panduan-awal/produk')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', '2 produk contoh ditambahkan.');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $produk = Produk::query()->where('Nama', 'Es Kopi Susu Gula Aren')->sole();
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        $harga = ProdukHarga::query()->where('IdProduk', $produk->Id)->sole();
        expect($produk->Jenis)->toBe(JenisProduk::NonStok)
            ->and($produk->Sku)->toBe('PRD-000001') // F-03 (DesainF03 C.2 no. 19): SKU produk cepat dibuat otomatis.
            ->and($produk->IdKategori)->toBe(Kategori::query()->where('Nama', 'Kopi')->value('Id'))
            ->and($produk->IdSatuanDasar)->toBe(Satuan::query()->where('KodeStandar', 'PCS')->value('Id'))
            ->and($produk->IdKelompokPajak)->toBe(KelompokPajak::query()->where('Nama', 'Makan & minum')->value('Id'))
            ->and($satuan->KonversiKeDasar)->toBe('1.0000')
            ->and($satuan->DefaultJual)->toBeTrue()
            ->and($harga->Harga)->toBe('22000.00')
            ->and($harga->IdProdukSatuan)->toBe($satuan->Id)
            ->and($harga->IdDaftarHarga)->toBeNull()
            ->and(Produk::query()->where('Nama', 'Americano Panas Single Origin Gayo')->sole()->Harga->sole()->Harga)->toBe('27500.50')
            ->and(LogAudit::query()->where('IdTenant', $tenant->Id)->where('Peristiwa', 'produk.buat')->count())->toBe(2);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/produk')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Produk')
                ->where('AdaTemplate', true)
                ->where('ProdukContoh.0', ['Nama' => 'Es Kopi Susu Gula Aren', 'NamaKategori' => 'Kopi', 'Harga' => '22000', 'KodeSatuan' => 'PCS', 'SudahAda' => true])
                ->where('ProdukContoh.2.SudahAda', false)
                ->where('JumlahProduk', 2)
                ->where('BatasSku', ['Batas' => null, 'Terpakai' => 2])
                ->where('Produk.0.Nama', 'Americano Panas Single Origin Gayo')
                ->where('Produk.0.Harga', '27500.50')
                ->where('Produk.0.NamaKategori', 'Kopi')
                ->has('Kategori', 5));
    });

    it('kirim dua kali: kiriman kedua dilewati semua karena namanya sudah ada (idempoten)', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $isian = ['ProdukContoh' => [['Nama' => 'Es Kopi Susu Gula Aren', 'Harga' => '22000'], ['Nama' => 'es kopi susu gula aren', 'Harga' => '23000']]];

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk/contoh', $isian)
            ->assertSessionHas('Kilat', '1 produk contoh ditambahkan. 1 dilewati karena namanya sudah ada.');
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk/contoh', $isian)
            ->assertSessionHas('Kilat', 'Tidak ada produk contoh baru. 2 dilewati karena namanya sudah ada.');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Produk::query()->count())->toBe(1)
            ->and(ProdukHarga::query()->sole()->Harga)->toBe('22000.00');
    });

    it('produk contoh yang tidak ada di template ditolak, tanpa template ProdukContoh kosong', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF', ['ProdukContoh' => []]);
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/produk')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('AdaTemplate', false)->where('ProdukContoh', []));

        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk/contoh', ['ProdukContoh' => [['Nama' => 'Es Kopi Susu Gula Aren', 'Harga' => '22000']]])
            ->assertSessionHasErrors('ProdukContoh.0.Nama');
    });

    it('(d) tambah cepat: harga "15.000" ditolak, "15000" diterima. Jenis NonStok untuk kafe, Stok untuk retail', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        BantuanPanduanAwal::TerbitkanTemplate('RTL-GEN');
        ['Tenant' => $kafe, 'Pemilik' => $pemilikKafe, 'Outlet' => $outletKafe] = BantuanPanduanAwal::BuatTenant();
        BantuanPanduanAwal::Terapkan($outletKafe, 'FNB-CAF');
        ['Tenant' => $toko, 'Pemilik' => $pemilikToko, 'Outlet' => $outletToko] = BantuanPanduanAwal::BuatTenant('Toko Kelontong Berkah');
        BantuanPanduanAwal::Terapkan($outletToko, 'RTL-GEN');

        BantuanPanduanAwal::Masuk($this, $pemilikKafe, $kafe)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Roti Bakar Cokelat Keju', 'Harga' => '15.000', 'Kategori' => null]]])
            ->assertSessionHasErrors(['Produk.0.Harga' => 'Harga berupa angka tanpa titik ribuan, misal 15000.']);

        BantuanOrganisasi::AturKonteks($kafe->Id);
        $uuidKategori = Kategori::query()->where('Nama', 'Makanan')->value('Uuid');
        BantuanPanduanAwal::Masuk($this, $pemilikKafe, $kafe)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Roti Bakar Cokelat Keju', 'Harga' => '15000', 'Kategori' => $uuidKategori]]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', '1 produk ditambahkan.');
        BantuanPanduanAwal::Masuk($this, $pemilikToko, $toko)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Sabun Mandi Batang Wangi Melati 90 gram', 'Harga' => '4500', 'Kategori' => null]]])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($kafe->Id);
        $roti = Produk::query()->sole();
        expect($roti->Jenis)->toBe(JenisProduk::NonStok)
            ->and($roti->Kategori?->Nama)->toBe('Makanan')
            ->and($roti->Harga->sole()->Harga)->toBe('15000.00');
        BantuanOrganisasi::AturKonteks($toko->Id);
        expect(Produk::query()->sole()->Jenis)->toBe(JenisProduk::Stok)
            ->and(Produk::query()->sole()->IdKelompokPajak)->toBe(KelompokPajak::query()->where('Nama', 'Barang kena PPN')->value('Id'));
    });

    it('tanpa template: jenis Stok dengan satuan PCS dibuat otomatis. Data realistis (nama 60 karakter, Rp 1.250.000.000)', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        $nama = 'Paket Katering Pernikahan Premium 500 Porsi Menu Nusantara A';

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => $nama, 'Harga' => '1250000000', 'Kategori' => null]]])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $produk = Produk::query()->sole();
        expect(mb_strlen($nama))->toBe(60)
            ->and($produk->Nama)->toBe($nama)
            ->and($produk->Jenis)->toBe(JenisProduk::Stok)
            ->and(Satuan::query()->sole()->KodeStandar)->toBe('PCS')
            ->and($produk->Harga->sole()->Harga)->toBe('1250000000.00')
            ->and(Uang::Dari($produk->Harga->sole()->Harga)->FormatRupiah())->toBe('Rp 1.250.000.000');
    });

    it('BatasSku: paket GRATIS dengan 99 produk menolak 2 produk baru sekaligus (Umum) tanpa baris setengah jadi', function (): void {
        BantuanPanduanAwal::TerbitkanTemplate('FNB-CAF');
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant(kodePaket: 'GRATIS');
        BantuanPanduanAwal::Terapkan($outlet, 'FNB-CAF');
        $idSatuan = Satuan::query()->where('KodeStandar', 'PCS')->value('Id');
        app(TambahProdukCepat::class)->Jalankan(array_map(
            fn (int $nomor): DataProdukCepat => new DataProdukCepat("Menu Uji {$nomor}", Uang::Dari('10000'), null, $idSatuan, JenisProduk::NonStok, null),
            range(1, 99),
        ));

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk', ['Produk' => [
            ['Nama' => 'Es Teh Manis Jumbo', 'Harga' => '8000', 'Kategori' => null],
            ['Nama' => 'Kopi Tubruk Robusta Temanggung', 'Harga' => '12000', 'Kategori' => null],
        ]])->assertSessionHasErrors(['Umum' => 'Paket Gratis mencakup maksimal 100 SKU produk dan semuanya sudah terpakai (99). Tingkatkan paket atau tambah add-on di menu Langganan untuk menambah SKU produk.']);

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Produk::query()->count())->toBe(99)
            ->and(ProdukSatuan::query()->count())->toBe(99)
            ->and(ProdukHarga::query()->count())->toBe(99);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/produk', ['Produk' => [['Nama' => 'Es Teh Manis Jumbo', 'Harga' => '8000', 'Kategori' => null]]])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Produk::query()->count())->toBe(100);
    });
});
