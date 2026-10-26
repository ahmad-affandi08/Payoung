<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\TambahVarianAnak;
use App\Domain\Katalog\Data\DataVarianAnak;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Induk varian lewat form (SKU `KAOS`, merek & kategori), dengan definisi atribut awal.
 *
 * @param  list<array{Nama: string, Nilai: list<string>}>  $atribut
 * @return array{0: Produk, 1: array<string, mixed>}
 */
function BuatIndukVarianUji(object $tes, array $t, array $atribut = []): array
{
    $kategori = BantuanKatalog::BuatKategori('Pakaian');
    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], $t['KelompokPajak'], [
        'Nama' => 'Kaos Polos Katun Combed 30s',
        'Sku' => 'KAOS',
        'Jenis' => 'IndukVarian',
        'Merek' => 'Nusantara',
        'UuidKategori' => $kategori->Uuid,
        'AtributVarian' => $atribut,
    ]);
    BantuanKatalog::MasukSebagai($tes, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    return [Produk::query()->where('Uuid', $form['Uuid'])->sole(), $form];
}

describe('F-03 varian', function (): void {
    it('audit #18: tabel varian mengubah harga dasar & menambah barcode banyak varian sekaligus, harga bertingkat tetap', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        [$induk] = BuatIndukVarianUji($this, $t, [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]], 'JenisAnak' => 'Stok', 'HargaDasar' => '75000'])
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        [$s, $m] = Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->get()->all();
        $satuanS = ProdukSatuan::query()->where('IdProduk', $s->Id)->sole();
        ProdukHarga::query()->create(['IdProduk' => $s->Id, 'IdProdukSatuan' => $satuanS->Id, 'JumlahMinimum' => '12', 'Harga' => '70000.00']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$induk->Uuid}/varian", ['Baris' => [
            ['Uuid' => $s->Uuid, 'Harga' => '79000', 'Barcode' => '8991234500011'],
            ['Uuid' => $m->Uuid, 'Harga' => '79000', 'Barcode' => null],
        ]])->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Harga 2 varian disimpan.');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $hargaS = ProdukHarga::query()->where('IdProdukSatuan', $satuanS->Id)->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->get();
        expect($hargaS->pluck('Harga')->all())->toBe(['79000.00', '70000.00'])
            ->and(ProdukHarga::query()->where('IdProduk', $m->Id)->whereNull('IdDaftarHarga')->value('Harga'))->toBe('79000.00')
            ->and(ProdukBarcode::query()->where('IdProduk', $s->Id)->value('Barcode'))->toBe('8991234500011');

        // Varian produk lain ditolak.
        $lain = BantuanKatalog::BuatProduk(['Sku' => 'LAIN-1', 'IdKelompokPajak' => $t['KelompokPajak']->Id], '1000.00', $t['Pcs']);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$induk->Uuid}/varian", ['Baris' => [
            ['Uuid' => $lain->Uuid, 'Harga' => '5000'],
        ]])->assertSessionHasErrors();
    });

    it('generasi Kartesius: nama, SKU {SkuInduk}-NN, salinan kolom induk, satuan dasar, harga dasar; idempoten', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        [$induk] = BuatIndukVarianUji($this, $t, [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]);
        $isian = ['AtributVarian' => [['Nama' => 'Warna', 'Nilai' => ['Hitam', 'Putih']]], 'JenisAnak' => 'Stok', 'HargaDasar' => '75000'];

        // Atribut baru boleh selama belum ada anak.
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", $isian)
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '4 varian dibuat.');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $anak = Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->get();
        expect($anak->pluck('Nama')->all())->toBe([
            'Kaos Polos Katun Combed 30s S / Hitam',
            'Kaos Polos Katun Combed 30s S / Putih',
            'Kaos Polos Katun Combed 30s M / Hitam',
            'Kaos Polos Katun Combed 30s M / Putih',
        ])
            ->and($anak->pluck('Sku')->all())->toBe(['KAOS-01', 'KAOS-02', 'KAOS-03', 'KAOS-04'])
            ->and($anak[0]->KunciVarian)->toBe('ukuran=s|warna=hitam')
            ->and($anak[0]->AtributVarian)->toBe([['Nama' => 'Ukuran', 'Nilai' => 'S'], ['Nama' => 'Warna', 'Nilai' => 'Hitam']])
            ->and($anak[0]->Merek)->toBe('Nusantara')
            ->and($anak[0]->IdKategori)->toBe($induk->IdKategori)
            ->and($anak[0]->IdKelompokPajak)->toBe($t['KelompokPajak']->Id)
            ->and($anak[0]->Jenis)->toBe(JenisProduk::Stok)
            ->and(ProdukSatuan::query()->where('IdProduk', $anak[0]->Id)->sole()->IdSatuan)->toBe($t['Pcs']->Id)
            ->and(ProdukHarga::query()->where('IdProduk', $anak[3]->Id)->sole()->Harga)->toBe('75000.00')
            ->and($induk->refresh()->AtributVarian)->toBe([['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']], ['Nama' => 'Warna', 'Nilai' => ['Hitam', 'Putih']]])
            ->and(LogAudit::query()->where('Peristiwa', 'produk.varian.generasi')->count())->toBe(1);

        // Kirim ulang + nilai baru L: hanya 2 kombinasi baru, SKU lanjut -05.
        $isianL = ['AtributVarian' => [['Nama' => 'ukuran', 'Nilai' => ['L']]], 'JenisAnak' => 'Stok', 'HargaDasar' => ''];
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", $isianL)
            ->assertSessionHas('Kilat', '2 varian dibuat. 4 dilewati karena sudah ada.');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", $isianL)
            ->assertSessionHas('Kilat', 'Tidak ada varian baru. 6 dilewati karena sudah ada.');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->pluck('Sku')->all())->toBe(['KAOS-01', 'KAOS-02', 'KAOS-03', 'KAOS-04', 'KAOS-05', 'KAOS-06']);
    });

    it('ubah induk meneruskan kategori, merek, pajak, dan tampilan ke anak. Nilai yang dipakai anak tidak bisa dihapus', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        [$induk, $form] = BuatIndukVarianUji($this, $t, [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kategoriBaru = BantuanKatalog::BuatKategori('Busana Pria');
        $pajakBaru = BantuanKatalog::BuatKelompokPajak('Barang bebas PPN');
        $form['Satuan'][0]['Uuid'] = ProdukSatuan::query()->where('IdProduk', $induk->Id)->sole()->Uuid;

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$induk->Uuid}", array_replace($form, ['AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S']]]]))
            ->assertSessionHasErrors(['AtributVarian.0.Nilai' => 'Nilai M dipakai varian Kaos Polos Katun Combed 30s M. Arsipkan atau hapus varian itu dulu.']);
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$induk->Uuid}", array_replace($form, ['AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']], ['Nama' => 'Warna', 'Nilai' => ['Hitam']]]]))
            ->assertSessionHasErrors('AtributVarian');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->put("/kelola/produk/{$induk->Uuid}", array_replace($form, [
            'UuidKategori' => $kategoriBaru->Uuid,
            'Merek' => 'Garuda',
            'UuidKelompokPajak' => $pajakBaru->Uuid,
            'HargaTermasukPajak' => 'Tidak',
            'TampilDiPos' => false,
            'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M', 'XL']]],
        ]))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        foreach (Produk::query()->where('IdInduk', $induk->Id)->get() as $anak) {
            expect($anak->IdKategori)->toBe($kategoriBaru->Id)
                ->and($anak->Merek)->toBe('Garuda')
                ->and($anak->IdKelompokPajak)->toBe($pajakBaru->Id)
                ->and($anak->HargaTermasukPajak)->toBeFalse()
                ->and($anak->TampilDiPos)->toBeFalse();
        }
    });

    it('BatasSku semua atau tidak sama sekali: 12 varian dengan sisa 10 slot → tidak ada yang dibuat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk(kodePaket: 'GRATIS');
        [$induk] = BuatIndukVarianUji($this, $t, [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M', 'L', 'XL']], ['Nama' => 'Warna', 'Nilai' => ['Hitam', 'Putih', 'Abu']]]);
        foreach (range(1, 90) as $nomor) {
            BantuanKatalog::BuatProduk(['Nama' => "Produk Pengisi {$nomor}"], null, $t['Pcs']);
        }

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])
            ->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->where('IdInduk', $induk->Id)->count())->toBe(0);
    });

    it('batas kombinasi, izin harga, dan jenis induk', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        [$induk] = BuatIndukVarianUji($this, $t);
        $dua = range(1, 11);
        $masuk = fn (PeranTenantBawaan $peran = PeranTenantBawaan::Pemilik) => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, $peran);

        $masuk()->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [
            ['Nama' => 'A', 'Nilai' => array_map(fn (int $n): string => "A{$n}", $dua)],
            ['Nama' => 'B', 'Nilai' => array_map(fn (int $n): string => "B{$n}", $dua)],
        ], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])->assertSessionHasErrors(['AtributVarian' => 'Kombinasi baru 121 melebihi batas 100 per sekali buat. Kurangi nilai atribut.']);
        $masuk(PeranTenantBawaan::ManajerOutlet)->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [['Nama' => 'Rasa', 'Nilai' => ['Cokelat']]], 'JenisAnak' => 'Stok', 'HargaDasar' => '5000'])
            ->assertSessionHasErrors('HargaDasar');
        $masuk()->post("/kelola/produk/{$induk->Uuid}/varian", ['AtributVarian' => [['Nama' => 'Rasa', 'Nilai' => ['Cokelat']]], 'JenisAnak' => 'BahanBaku', 'HargaDasar' => ''])
            ->assertSessionHasErrors('JenisAnak');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $biasa = BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $masuk()->post("/kelola/produk/{$biasa->Uuid}/varian", ['AtributVarian' => [['Nama' => 'Rasa', 'Nilai' => ['Cokelat']]], 'JenisAnak' => 'Stok', 'HargaDasar' => ''])
            ->assertSessionHasErrors('AtributVarian');
    });

    it('TambahVarianAnak (impor): memperluas definisi, idempoten per kunci varian, SKU manual, harga butuh izin', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        [$induk] = BuatIndukVarianUji($this, $t);
        $aksi = app(TambahVarianAnak::class);

        $m = $aksi->Jalankan($induk, new DataVarianAnak([['Nama' => 'Ukuran', 'Nilai' => 'M']], null, JenisProduk::Stok, Uang::Dari('50000'), true, SumberPerubahanKatalog::Impor));
        $mLagi = $aksi->Jalankan($induk, new DataVarianAnak([['Nama' => 'ukuran', 'Nilai' => ' m ']], null, JenisProduk::Stok, null, true, SumberPerubahanKatalog::Impor));
        $l = $aksi->Jalankan($induk, new DataVarianAnak([['Nama' => 'Ukuran', 'Nilai' => 'L']], 'KAOS-L-MANUAL', JenisProduk::Stok, null, false, SumberPerubahanKatalog::Manual));

        expect($mLagi->Id)->toBe($m->Id)
            ->and($m->Sku)->toBe('KAOS-01')
            ->and($l->Sku)->toBe('KAOS-L-MANUAL')
            ->and(ProdukHarga::query()->where('IdProduk', $m->Id)->sole()->Harga)->toBe('50000.00')
            ->and($induk->refresh()->AtributVarian)->toBe([['Nama' => 'Ukuran', 'Nilai' => ['M', 'L']]])
            ->and(LogAudit::query()->where('Peristiwa', 'produk.varian.tambah')->count())->toBe(1)
            ->and(fn () => $aksi->Jalankan($induk, new DataVarianAnak([['Nama' => 'Warna', 'Nilai' => 'Merah']], null, JenisProduk::Stok, null, true, SumberPerubahanKatalog::Impor)))
            ->toThrow(PelanggaranAturanBisnis::class, 'Atribut baru tidak bisa ditambahkan')
            ->and(fn () => $aksi->Jalankan($induk, new DataVarianAnak([['Nama' => 'Ukuran', 'Nilai' => 'XL']], null, JenisProduk::Stok, Uang::Dari('1'), false, SumberPerubahanKatalog::Impor)))
            ->toThrow(PelanggaranAturanBisnis::class, 'izin mengubah harga');
    });
});
