<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Aksi\PastikanSatuanProduk;
use App\Domain\Katalog\Aksi\PerbaruiProdukSebagian;
use App\Domain\Katalog\Aksi\TambahBarcodeProduk;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Kontrak\PemeriksaPemakaianProduk;
use App\Domain\Katalog\Layanan\PembuatBarcode;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-03 SimpanProduk lewat form', function (): void {
    it('membuat produk lengkap: satuan dasar + dus isi 12 dengan barcode, harga awal, SKU otomatis, dan audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $kategori = BantuanKatalog::BuatKategori('Minuman');
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
            'Nama' => 'Teh Botol Sosro Kotak Original 250 ml',
            'NamaStruk' => 'Teh Kotak 250',
            'Merek' => 'Sosro',
            'UuidKategori' => $kategori->Uuid,
            'HargaTermasukPajak' => 'Ya',
            'Satuan' => [
                BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['8991234567890'], [['JumlahMinimum' => '1', 'Harga' => '4500'], ['JumlahMinimum' => '12', 'Harga' => '4200.50']], defaultJual: true),
                BantuanKatalog::IsiSatuanForm($dus, '12', ['18991234567897'], [['JumlahMinimum' => '1', 'Harga' => '1250000000']], defaultBeli: true),
            ],
        ]);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)
            ->assertSessionHasNoErrors()
            ->assertRedirect("/kelola/produk/{$form['Uuid']}");

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get();
        expect($produk->Sku)->toBe('PRD-000001')
            ->and($produk->NamaStruk)->toBe('Teh Kotak 250')
            ->and($produk->Merek)->toBe('Sosro')
            ->and($produk->IdKategori)->toBe($kategori->Id)
            ->and($produk->HargaTermasukPajak)->toBeTrue()
            ->and($produk->BolehMinus)->toBeNull()
            ->and($produk->Aktif)->toBeTrue()
            ->and($satuan)->toHaveCount(2)
            ->and($satuan[0]->DefaultJual)->toBeTrue()
            ->and($satuan[0]->DefaultBeli)->toBeFalse()
            ->and($satuan[1]->KonversiKeDasar)->toBe('12.0000')
            ->and($satuan[1]->DefaultBeli)->toBeTrue()
            ->and(ProdukBarcode::query()->where('IdProdukSatuan', $satuan[1]->Id)->sole()->Barcode)->toBe('18991234567897')
            ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuan[0]->Id)->orderBy('JumlahMinimum')->pluck('Harga')->all())->toBe(['4500.00', '4200.50'])
            ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuan[1]->Id)->sole()->Harga)->toBe('1250000000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'produk.buat')->where('IdObjek', $produk->Id)->sole()->NilaiBaru['Sku'])->toBe('PRD-000001');
    });

    it('BR-03.1 idempoten: kirim dua kali dengan Uuid yang sama = satu produk', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$form['Uuid']}");

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(1)->and(ProdukSatuan::query()->count())->toBe(1);
    });

    it('BR-03.1 SKU otomatis berurutan, unik per tenant tanpa beda huruf besar/kecil, boleh sama di tenant lain', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Berkah Jaya');
        $masukA = fn () => BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak']))->assertSessionHasNoErrors();
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak']))->assertSessionHasNoErrors();
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['Sku' => ' abc-01 ']))->assertSessionHasNoErrors();
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['Sku' => 'ABC-01']))
            ->assertSessionHasErrors(['Sku' => 'SKU ABC-01 sudah dipakai produk lain. Pakai SKU lain atau kosongkan agar dibuat otomatis.']);
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['Sku' => '-salah']))->assertSessionHasErrors('Sku');

        $b = BantuanKatalog::SiapkanTenantProduk('Toko Lain Sejahtera');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($b['Pcs'], $b['KelompokPajak'], ['Sku' => 'ABC-01']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Produk::query()->orderBy('Id')->pluck('Sku')->all())->toBe(['PRD-000001', 'PRD-000002', 'abc-01']);

        // Mengubah produk dengan SKU kosong mempertahankan SKU lama (tidak membuat PRD-000003 diam-diam).
        $form = BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], ['Nama' => 'Gula Pasir Gulaku 1 kg']);
        $masukA()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $gula = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $satuan = $gula->Satuan()->firstOrFail();
        $masukA()->put("/kelola/produk/{$form['Uuid']}", [...$form, 'Nama' => 'Gula Pasir Gulaku 1 kg Premium', 'Satuan' => [[...$form['Satuan'][0], 'Uuid' => $satuan->Uuid]]])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($gula->refresh()->Sku)->toBe('PRD-000003')
            ->and($gula->Nama)->toBe('Gula Pasir Gulaku 1 kg Premium')
            ->and(Produk::query()->count())->toBe(4);
    });

    it('BR-03.1 balapan SKU: pelanggaran indeks unik dipetakan ke BR-03.1 dan seluruh transaksi dibatalkan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $pesaing = BantuanKatalog::BuatProduk(['Sku' => 'SKU-LAIN'], null, $t['Pcs']);
        $jumlahSatuan = ProdukSatuan::query()->count();
        // Permintaan lain "menyalip" tepat sebelum INSERT: SKU yang sama sudah tersimpan.
        Produk::creating(function (Produk $produk) use ($pesaing): void {
            if ($produk->Sku === 'SKU-BALAPAN') {
                DB::table('Produk')->where('Id', $pesaing->Id)->update(['Sku' => 'SKU-BALAPAN']);
            }
        });

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
            'Sku' => 'SKU-BALAPAN',
            'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['8990000000017'], [['JumlahMinimum' => '1', 'Harga' => '5000']])],
        ]))->assertSessionHasErrors(['Sku' => 'SKU ini sudah dipakai produk lain. Pakai SKU lain atau kosongkan agar dibuat otomatis.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(1)
            ->and(ProdukSatuan::query()->count())->toBe($jumlahSatuan)
            ->and(ProdukBarcode::query()->count())->toBe(0);
    });

    it('BR-03.1 barcode: unik per tenant, boleh sama di tenant lain, tidak boleh dua kali di satu produk', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko Berkah Jaya');
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $masukA = fn () => BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id);
        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], [
            'Nama' => 'Indomie Goreng Original 85 gram',
            'Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'], '1', ['089686010947'])],
        ]))->assertSessionHasNoErrors();

        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'], '1', ['089686010947'])],
        ]))->assertSessionHasErrors(['Satuan.0.Barcode.0' => 'Barcode 089686010947 sudah dipakai produk Indomie Goreng Original 85 gram.']);

        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'], '1', ['ABC123']), BantuanKatalog::IsiSatuanForm($dus, '40', ['abc123'])],
        ]))->assertSessionHasErrors(['Satuan.1.Barcode.0' => 'Barcode abc123 diisi lebih dari sekali.']);

        $masukA()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($a['Pcs'], $a['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($a['Pcs'], '1', ['ab'])],
        ]))->assertSessionHasErrors('Satuan.0.Barcode.0');

        $b = BantuanKatalog::SiapkanTenantProduk('Toko Lain Sejahtera');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($b['Pcs'], $b['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($b['Pcs'], '1', ['089686010947'])],
        ]))->assertSessionHasNoErrors();
    });

    it('BR-03.1 balapan barcode: indeks unik menjadi pengaman terakhir, produk tidak tersimpan setengah jadi', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $pesaing = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $satuanPesaing = ProdukSatuan::query()->where('IdProduk', $pesaing->Id)->sole();
        ProdukBarcode::creating(function (ProdukBarcode $barcode) use ($pesaing, $satuanPesaing): void {
            if ($barcode->Barcode === '8997777000013' && $barcode->IdProduk !== $pesaing->Id) {
                DB::table('ProdukBarcode')->insert([
                    'Uuid' => (string) Str::ulid(), 'IdTenant' => $pesaing->IdTenant, 'IdProduk' => $pesaing->Id, 'IdProdukSatuan' => $satuanPesaing->Id,
                    'Barcode' => '8997777000013', 'DibuatPada' => now(), 'DiubahPada' => now(),
                ]);
            }
        });

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['8997777000013'])],
        ]))->assertSessionHasErrors(['Barcode' => 'Barcode ini sudah dipakai produk lain.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(1)->and(ProdukBarcode::query()->count())->toBe(0);
    });

    it('BR-03.1 barcode internal EAN-13 berawalan 20 dengan digit periksa benar dan unik', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        $url = "/kelola/produk/{$produk->Uuid}/satuan/{$satuan->Uuid}/barcode-internal";

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post($url)->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Barcode 2000000000015 dibuat.');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post($url)->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $barcode = ProdukBarcode::query()->orderBy('Id')->pluck('Barcode')->all();
        expect($barcode)->toBe(['2000000000015', '2000000000022'])
            ->and(PembuatBarcode::HitungDigitPeriksa('200000000002'))->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.barcode.buat')->count())->toBe(2);
    });

    it('BR-03.1 barcode diketik/dipindai dari detail produk: idempoten, ditolak bila milik produk lain, format dijaga, bisa dihapus dengan jejak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Teh Botol'], null, $t['Pcs']);
        $lain = BantuanKatalog::BuatProduk(['Nama' => 'Air Mineral', 'Sku' => 'AIR-1'], null, $t['Pcs']);
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->sole();
        $satuanLain = ProdukSatuan::query()->where('IdProduk', $lain->Id)->sole();
        $url = "/kelola/produk/{$produk->Uuid}/satuan/{$satuan->Uuid}/barcode";
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post($url, ['Barcode' => ' 8991234500017 '])->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Barcode 8991234500017 ditambahkan.');
        $masuk()->post($url, ['Barcode' => '8991234500017'])->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Barcode 8991234500017 sudah terdaftar di produk ini.');
        $masuk()->post($url, ['Barcode' => 'a b'])->assertSessionHasErrors('Barcode');
        $masuk()->post($url, ['Barcode' => ''])->assertSessionHasErrors('Barcode');
        $masuk()->post("/kelola/produk/{$lain->Uuid}/satuan/{$satuanLain->Uuid}/barcode", ['Barcode' => '8991234500017'])->assertSessionHasErrors('Barcode');
        // Satuan milik produk lain tidak bisa dipakai lewat URL produk ini.
        $masuk()->post("/kelola/produk/{$produk->Uuid}/satuan/{$satuanLain->Uuid}/barcode", ['Barcode' => '8991234500024'])->assertNotFound();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $barcode = ProdukBarcode::query()->where('IdProduk', $produk->Id)->sole();
        expect($barcode->Barcode)->toBe('8991234500017')
            ->and(ProdukBarcode::query()->where('IdProduk', $lain->Id)->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.barcode.tambah')->count())->toBe(1);

        // Barcode produk lain tidak bisa dihapus lewat URL produk ini.
        $masuk()->delete("/kelola/produk/{$lain->Uuid}/barcode/{$barcode->Uuid}")->assertNotFound();
        $masuk()->delete("/kelola/produk/{$produk->Uuid}/barcode/{$barcode->Uuid}")->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Barcode 8991234500017 dihapus.');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukBarcode::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.barcode.hapus')->count())->toBe(1)
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::ProdukBarcode->value)->where('UuidEntitas', $barcode->Uuid)->count())->toBe(1);

        // Setelah dihapus, produk lain boleh memakai barcode yang sama.
        $masuk()->post("/kelola/produk/{$lain->Uuid}/satuan/{$satuanLain->Uuid}/barcode", ['Barcode' => '8991234500017'])->assertSessionHasNoErrors();
    });

    it('satuan: tepat satu satuan dasar, konversi > 0, satuan unik. Satuan dibuang ikut menghapus barcode & harga dengan jejak', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $kp = $t['KelompokPajak'];

        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [BantuanKatalog::IsiSatuanForm($dus, '12')]]))
            ->assertSessionHasErrors(['Satuan' => 'Produk wajib punya tepat satu baris satuan dasar dengan isi 1.']);
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '2')]]))
            ->assertSessionHasErrors('Satuan.0.KonversiKeDasar');
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs']), BantuanKatalog::IsiSatuanForm($dus, '0')]]))
            ->assertSessionHasErrors('Satuan.1.KonversiKeDasar');
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs']), BantuanKatalog::IsiSatuanForm($t['Pcs'])]]))
            ->assertSessionHasErrors('Satuan.1.UuidSatuan');
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1.5')]]))
            ->assertSessionHasErrors('Satuan.0.KonversiKeDasar');

        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $kp, ['Satuan' => [
            BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['PCS-001']),
            BantuanKatalog::IsiSatuanForm($dus, '24', ['DUS-001'], [['JumlahMinimum' => '1', 'Harga' => '100000']]),
        ]]);
        $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
        $satuanDasar = ProdukSatuan::query()->where('IdProduk', $produk->Id)->where('IdSatuan', $t['Pcs']->Id)->sole();
        $satuanDus = ProdukSatuan::query()->where('IdProduk', $produk->Id)->where('IdSatuan', $dus->Id)->sole();
        $barcodeDus = ProdukBarcode::query()->where('IdProdukSatuan', $satuanDus->Id)->sole();

        // Ubah: dus dibuang, barcode PCS-001 diganti PCS-002.
        $masuk()->put("/kelola/produk/{$produk->Uuid}", array_replace($form, ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', ['PCS-002'], [], $satuanDasar->Uuid)]]))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(ProdukSatuan::query()->where('IdProduk', $produk->Id)->pluck('Id')->all())->toBe([$satuanDasar->Id])
            ->and(ProdukBarcode::query()->where('IdProduk', $produk->Id)->pluck('Barcode')->all())->toBe(['PCS-002'])
            ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuanDus->Id)->exists())->toBeFalse()
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::ProdukSatuan->value)->pluck('UuidEntitas')->all())->toBe([$satuanDus->Uuid])
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::ProdukBarcode->value)->count())->toBe(2)
            ->and(PenghapusanKatalog::query()->where('UuidEntitas', $barcodeDus->Uuid)->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.ubah')->sole()->NilaiLama['Satuan'])->toHaveCount(2);
    });

    it('harga awal satuan baru butuh izin produk.harga.ubah: Manajer Outlet ditolak IzinHargaDiperlukan, tanpa harga boleh', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $denganHarga = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '15000']])]]);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post('/kelola/produk', $denganHarga)
            ->assertSessionHasErrors(['Satuan.0.HargaAwal' => 'Anda tidak punya izin mengubah harga jual. Kosongkan harga, atau minta pemilik mengisinya.']);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']))
            ->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
            'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Pcs'], '1', [], [['JumlahMinimum' => '1', 'Harga' => '15.000']])],
        ]))->assertSessionHasErrors(['Satuan.0.HargaAwal.0.Harga' => 'Harga berupa angka tanpa titik ribuan, misal 15000 atau 15000.50.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(1)->and(ProdukHarga::query()->count())->toBe(0);
    });

    it('aturan jenis: kelompok pajak wajib untuk produk dijual, BahanBaku tidak tampil di POS, pelacakan hanya jenis berstok, Seri butuh satuan tanpa desimal', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], null))
            ->assertSessionHasErrors(['UuidKelompokPajak' => 'Pilih kelompok pajak agar pajak di struk benar.']);
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], null, ['Jenis' => 'BahanBaku', 'Nama' => 'Gula Aren Cair Premium 1 kg']))->assertSessionHasNoErrors();
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Jenis' => 'Jasa', 'Pelacakan' => 'Batch']))->assertSessionHasErrors('Pelacakan');
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Kg'], $t['KelompokPajak'], ['Pelacakan' => 'Seri', 'Satuan' => [BantuanKatalog::IsiSatuanForm($t['Kg'])]]))
            ->assertSessionHasErrors(['Pelacakan' => 'Nomor seri butuh satuan dasar tanpa desimal, misal pcs atau unit.']);
        $masuk()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], ['Nama' => 'Ponsel Android 128 GB', 'Pelacakan' => 'Seri', 'BolehMinus' => 'Ya']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('Nama', 'Gula Aren Cair Premium 1 kg')->sole()->TampilDiPos)->toBeFalse()
            ->and(Produk::query()->where('Nama', 'Ponsel Android 128 GB')->sole()->BolehMinus)->toBeFalse();
    });

    it('jenis tidak bisa diubah bila produk sudah dipakai atau ke/dari induk varian', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $satuan = ProdukSatuan::query()->sole();
        $form['Satuan'][0]['Uuid'] = $satuan->Uuid;

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Jenis' => 'IndukVarian']))
            ->assertSessionHasErrors('Jenis');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Jenis' => 'NonStok']))->assertSessionHasNoErrors();

        app()->tag([PemeriksaPalsuSudahTerjual::class], PemeriksaPemakaianProduk::TAG);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$form['Uuid']}", array_replace($form, ['Jenis' => 'Stok']))
            ->assertSessionHasErrors(['Jenis' => 'Jenis produk tidak bisa diubah karena produk sudah ada penjualan.']);
    });

    it('Kasir (produk.lihat) mendapat 403 untuk semua mutasi produk, kategori, dan satuan', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $kategori = BantuanKatalog::BuatKategori();
        $kasir = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $kasir()->post('/kelola/produk', BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']))->assertForbidden();
        $kasir()->put("/kelola/produk/{$produk->Uuid}", BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak']))->assertForbidden();
        $kasir()->post("/kelola/produk/{$produk->Uuid}/arsipkan")->assertForbidden();
        $kasir()->post("/kelola/produk/{$produk->Uuid}/pulihkan")->assertForbidden();
        $kasir()->delete("/kelola/produk/{$produk->Uuid}")->assertForbidden();
        $kasir()->post("/kelola/produk/{$produk->Uuid}/varian", [])->assertForbidden();
        $kasir()->post("/kelola/produk/{$produk->Uuid}/gambar", [])->assertForbidden();
        $kasir()->delete("/kelola/produk/{$produk->Uuid}/gambar")->assertForbidden();
        $kasir()->put("/kelola/produk/{$produk->Uuid}/batas-stok", ['Baris' => []])->assertForbidden();
        $kasir()->post('/kelola/kategori', ['Nama' => 'Snack'])->assertForbidden();
        $kasir()->put("/kelola/kategori/{$kategori->Uuid}", ['Nama' => 'Snack'])->assertForbidden();
        $kasir()->delete("/kelola/kategori/{$kategori->Uuid}")->assertForbidden();
        $kasir()->post('/kelola/satuan', ['Nama' => 'Lusin', 'Simbol' => 'lsn', 'BolehDesimal' => false])->assertForbidden();
        $kasir()->delete("/kelola/satuan/{$t['Pcs']->Uuid}")->assertForbidden();
        $kasir()->get('/kelola/produk/cari?kata=sabun')->assertOk();
    });
});

describe('F-03 Aksi untuk impor (Tim 4)', function (): void {
    it('PerbaruiProdukSebagian: null = tidak diubah, validasi sama, audit kecuali sumber Impor, tanpa perubahan tanpa tulis', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $produk = BantuanKatalog::BuatProduk(['IdKelompokPajak' => $t['KelompokPajak']->Id, 'Merek' => 'Lama'], null, $t['Pcs']);
        $aksi = app(PerbaruiProdukSebagian::class);

        $aksi->Jalankan($produk, new DataPerubahanProduk(nama: 'Sabun Batang Lifebuoy Merah 85 gram', hargaTermasukPajak: true));
        $produk->refresh();
        expect($produk->Nama)->toBe('Sabun Batang Lifebuoy Merah 85 gram')->and($produk->Merek)->toBe('Lama')->and($produk->HargaTermasukPajak)->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.ubah')->count())->toBe(1);

        $aksi->Jalankan($produk, new DataPerubahanProduk(kosongkanHargaTermasukPajak: true, merek: 'Lifebuoy'), SumberPerubahanKatalog::Impor);
        $aksi->Jalankan($produk, new DataPerubahanProduk(merek: 'Lifebuoy'));
        expect($produk->refresh()->HargaTermasukPajak)->toBeNull()->and($produk->Merek)->toBe('Lifebuoy')
            ->and(LogAudit::query()->where('Peristiwa', 'produk.ubah')->count())->toBe(1);

        expect(fn () => $aksi->Jalankan($produk, new DataPerubahanProduk(jenis: JenisProduk::Jasa, pelacakan: PelacakanProduk::Batch)))
            ->toThrow(PelanggaranAturanBisnis::class, 'tidak memakai pelacakan');
    });

    it('TambahBarcodeProduk idempoten untuk produk yang sama, BR-03.1 untuk produk lain; PastikanSatuanProduk idempoten', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $dus = BantuanKatalog::BuatSatuan('Dus', 'dus');
        $a = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $b = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $satuanA = ProdukSatuan::query()->where('IdProduk', $a->Id)->sole();
        $tambah = app(TambahBarcodeProduk::class);

        $pertama = $tambah->Jalankan($satuanA, ' 8991002101234 ');
        expect($tambah->Jalankan($satuanA, '8991002101234')->Id)->toBe($pertama->Id)
            ->and(fn () => $tambah->Jalankan(ProdukSatuan::query()->where('IdProduk', $b->Id)->sole(), '8991002101234'))->toThrow(PelanggaranAturanBisnis::class, 'sudah dipakai produk');

        $pastikan = app(PastikanSatuanProduk::class);
        $satuanDus = $pastikan->Jalankan($a, $dus, Kuantitas::Dari('12'));
        expect($pastikan->Jalankan($a, $dus, Kuantitas::Dari('12.0000'))->Id)->toBe($satuanDus->Id)
            ->and(fn () => $pastikan->Jalankan($a, $dus, Kuantitas::Dari('24')))->toThrow(PelanggaranAturanBisnis::class, 'sudah ada dengan isi 12.0000')
            ->and($pastikan->Jalankan($a, $t['Pcs'], Kuantitas::Dari('1'))->Id)->toBe($satuanA->Id);
    });
});

/** Pemeriksa pemakaian palsu (titik perluasan BR-03.2), misal F-07 penjualan. */
final class PemeriksaPalsuSudahTerjual implements PemeriksaPemakaianProduk
{
    public function PeriksaPemakaian(int $idProduk): ?string
    {
        return 'sudah ada penjualan';
    }
}
