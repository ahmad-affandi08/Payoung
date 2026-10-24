<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Impor\Enum\StatusBarisImpor;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Impor\Tugas\ValidasiImporProdukTugas;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanImpor;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * @return list<list<string|int|null>>
 */
function BarisAlurImpor(): array
{
    return [
        ['Nama Produk', 'SKU', 'Kategori', 'Satuan Dasar', 'Harga Jual', 'Barcode', 'Kelompok Pajak', 'Satuan Alternatif 1', 'Isi Satuan Alternatif 1', 'Harga Satuan Alternatif 1', 'Min. Qty Grosir 1', 'Harga Grosir 1', 'Harga Modal'],
        ['Sabun Mandi Cair Aroma Sereh Wangi 450 ml', 'SBN-001', 'Kebutuhan Rumah > Sabun', 'pcs', 'Rp 15.000', '8991234567890', 'Barang kena PPN', 'Dus', '12', '170.000', '12', '14.500', '9000'],
        ['Kopi Bubuk Robusta Temanggung 250 gram', null, 'Minuman > Kopi', 'pcs', 1250000, null, 'Barang kena PPN', null, null, null, null, null, null],
        [null, 'TANPA-NAMA', null, 'pcs', '15000', null, 'Barang kena PPN', null, null, null, null, null, null],
        ['Harga Ambigu', 'AMB-1', null, 'pcs', '15000.5', null, 'Barang kena PPN', null, null, null, null, null, null],
        ['Teh Celup Melati Isi 25', 'DUP-1', null, 'pcs', '6.500', null, 'Barang kena PPN', null, null, null, null, null, null],
        ['Teh Celup Hijau Isi 25', 'dup-1', null, 'pcs', '7.000', null, 'Barang kena PPN', null, null, null, null, null, null],
        ['Gula Pasir Minus', 'GUL-1', null, 'pcs', '-5000', null, 'Barang kena PPN', null, null, null, null, null, null],
    ];
}

describe('F-03 BR-03.6 alur impor produk (sinkron)', function (): void {
    it('unggah xlsx → pemetaan otomatis → validasi per baris dengan nomor baris → terapkan → produk, harga, barcode, satuan, riwayat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx(BarisAlurImpor(), 'produk toko.xlsx'));

        expect($impor->Status)->toBe(StatusImporProduk::MenungguPemetaan)
            ->and($impor->Format)->toBe('xlsx')
            ->and($impor->JumlahBaris)->toBe(7)
            ->and($impor->PathBerkas)->toStartWith('impor/'.$t['Tenant']->Id.'/')
            ->and($impor->Pemetaan)->toMatchArray(['Nama' => 0, 'Sku' => 1, 'Kategori' => 2, 'Satuan' => 3, 'HargaJual' => 4, 'HargaModal' => 12, 'Merek' => null]);
        Storage::disk('local')->assertExists($impor->PathBerkas);

        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/impor/{$impor->Uuid}");
        $impor->refresh();

        // ≤ BatasBarisSinkron: validasi langsung selesai di request.
        expect($impor->Status)->toBe(StatusImporProduk::Pratinjau)
            ->and($impor->JumlahValid)->toBe(2)
            ->and($impor->JumlahGalat)->toBe(5);

        $galat = ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('Status', StatusBarisImpor::Galat->value)->orderBy('NomorBaris')->get()->keyBy('NomorBaris');
        expect($galat->keys()->all())->toBe([4, 5, 6, 7, 8])
            ->and($galat[4]->Galat[0])->toEqual(['Bidang' => 'Nama Produk', 'Pesan' => 'Nama produk wajib diisi.'])
            ->and($galat[5]->Galat[0]['Bidang'])->toBe('Harga Jual')
            ->and($galat[6]->Galat[0]['Pesan'])->toContain('baris 6, 7')
            ->and($galat[8]->Galat[0]['Pesan'])->toBe('Tidak boleh negatif.');

        $masuk->get("/kelola/produk/impor/{$impor->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Produk/Impor/Detail')
            ->where('Impor.Status', 'Pratinjau')
            ->where('Impor.LabelSumber', 'Templat bawaan (Excel/CSV)')
            ->where('Impor.Progres', 100)
            ->has('Pemetaan.KolomSumber', 13)
            ->where('Pemetaan.Pemetaan.Nama', 0)
            ->where('Pemetaan.Opsi.Mode', 'TambahDanPerbarui')
            ->has('Pratinjau.BarisGalat', 5)
            ->where('Pratinjau.BarisGalat.0.NomorBaris', 4)
            ->where('Pratinjau.BarisGalat.0.Data.SKU', 'TANPA-NAMA')
            ->where('Pratinjau.RingkasanAksi', ['Buat' => 2, 'Perbarui' => 0, 'Lewati' => 0])
            ->where('Pratinjau.Peringatan.0', 'Diabaikan: HPP & stok awal diisi di menu Stok awal (Harga Modal).')
            ->where('Pratinjau.DiblokirBatasSku', null)
            ->has('KelompokPajak', 1)
            ->where('Izin.UbahHarga', true));

        $masuk->post("/kelola/produk/impor/{$impor->Uuid}/terapkan")->assertSessionHasNoErrors();
        $impor->refresh();

        expect($impor->Status)->toBe(StatusImporProduk::Selesai)
            ->and($impor->JumlahDibuat)->toBe(2)
            ->and($impor->JumlahDiterapkan)->toBe(2)
            ->and($impor->JumlahGagal)->toBe(0)
            ->and($impor->SelesaiPada)->not->toBeNull();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $sabun = Produk::query()->where('Sku', 'SBN-001')->sole();
        $dus = Satuan::query()->whereRaw('LOWER(Nama) = ?', ['dus'])->sole();
        $satuanDasar = ProdukSatuan::query()->where('IdProduk', $sabun->Id)->where('IdSatuan', $t['Pcs']->Id)->sole();
        $satuanDus = ProdukSatuan::query()->where('IdProduk', $sabun->Id)->where('IdSatuan', $dus->Id)->sole();
        $kategori = Kategori::query()->findOrFail($sabun->IdKategori);

        expect($kategori->Nama)->toBe('Sabun')
            ->and(Kategori::query()->findOrFail($kategori->IdInduk)->Nama)->toBe('Kebutuhan Rumah')
            ->and($sabun->IdKelompokPajak)->toBe($t['KelompokPajak']->Id)
            ->and($satuanDus->KonversiKeDasar)->toBe('12.0000')
            ->and(ProdukBarcode::query()->where('IdProdukSatuan', $satuanDasar->Id)->pluck('Barcode')->all())->toBe(['8991234567890'])
            ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuanDasar->Id)->orderBy('JumlahMinimum')->get(['JumlahMinimum', 'Harga'])->toArray())
            ->toBe([['JumlahMinimum' => '1.0000', 'Harga' => '15000.00'], ['JumlahMinimum' => '12.0000', 'Harga' => '14500.00']])
            ->and(ProdukHarga::query()->where('IdProdukSatuan', $satuanDus->Id)->sole()->Harga)->toBe('170000.00');

        $kopi = Produk::query()->where('Nama', 'Kopi Bubuk Robusta Temanggung 250 gram')->sole();
        expect($kopi->Sku)->toStartWith('PRD-')
            ->and(ProdukHarga::query()->where('IdProduk', $kopi->Id)->sole()->Harga)->toBe('1250000.00');

        // BR-03.3: setiap harga dari impor tercatat di RiwayatHarga bersumber Impor, dengan pengunggah sebagai pengubah.
        $riwayat = RiwayatHarga::query()->whereIn('IdProduk', [$sabun->Id, $kopi->Id])->get();
        expect($riwayat)->toHaveCount(4)
            ->and($riwayat->every(fn (RiwayatHarga $r): bool => $r->Sumber === SumberPerubahanHarga::Impor && $r->DiubahOleh === $impor->IdPengguna))->toBeTrue();

        $peristiwa = LogAudit::query()->where('IdTenant', $t['Tenant']->Id)->where('Peristiwa', 'like', 'produk.%')->pluck('Peristiwa')->all();
        expect($peristiwa)->toContain('produk.impor.unggah', 'produk.impor.pemetaan', 'produk.impor.terapkan', 'produk.impor.selesai')
            ->and($peristiwa)->not->toContain('produk.buat', 'produk.harga.ubah');
    });

    it('laporan masalah bisa diunduh sebagai xlsx dan csv: kolom asli + Nomor Baris, Status Impor, Masalah', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatXlsx(BarisAlurImpor()));
        BantuanImpor::Petakan($masuk, $impor)->assertSessionHasNoErrors();

        $xlsx = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/impor/{$impor->Uuid}/laporan?jenis=galat&format=xlsx")
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        expect($xlsx[0])->toBe([...BarisAlurImpor()[0], 'Nomor Baris', 'Status Impor', 'Galat'])
            ->and($xlsx)->toHaveCount(6)
            ->and($xlsx[1][13])->toBe('4')
            ->and($xlsx[1][14])->toBe('Bermasalah')
            ->and($xlsx[1][15])->toBe('Nama Produk: Nama produk wajib diisi.')
            // Nilai sel asli tetap ada; sel "-5000" dinetralkan dari rumus saat ditulis.
            ->and($xlsx[5][4])->toBe("'-5000");

        $csv = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/impor/{$impor->Uuid}/laporan?jenis=galat&format=csv")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8'), 'csv');
        expect($csv)->toHaveCount(6)->and($csv[2][15])->toStartWith('Harga Jual:');

        $semua = BantuanImpor::BacaUnduhan($masuk->get("/kelola/produk/impor/{$impor->Uuid}/laporan?jenis=semua&format=xlsx")->assertOk());
        expect($semua)->toHaveCount(8)->and($semua[1][14])->toBe('Valid');
    });

    it('lebih dari BatasBarisSinkron (300) baris → validasi masuk antrean; ≤ 300 langsung', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $baris = [['Nama Produk', 'Harga Jual']];

        foreach (range(1, 301) as $nomor) {
            $baris[] = ["Produk Uji Antrean {$nomor}", (string) (1000 + $nomor)];
        }

        $impor = BantuanImpor::Unggah($masuk, BantuanImpor::BuatCsv($baris));
        expect($impor->JumlahBaris)->toBe(301);

        Queue::fake();
        BantuanImpor::Petakan($masuk, $impor, ['UuidKelompokPajakBawaan' => $t['KelompokPajak']->Uuid])->assertSessionHasNoErrors();

        Queue::assertPushed(ValidasiImporProdukTugas::class, fn (ValidasiImporProdukTugas $tugas): bool => $tugas->idTenant === $t['Tenant']->Id
            && $tugas->idImporProduk === $impor->Id && $tugas->connection === null);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Memvalidasi);

        $masuk->getJson("/kelola/produk/impor/{$impor->Uuid}/status")->assertOk()
            ->assertExactJson(['Status' => 'Memvalidasi', 'LabelStatus' => 'Memeriksa data', 'Progres' => 0, 'JumlahDiterapkan' => 0, 'JumlahGagal' => 0, 'PesanGalat' => null]);

        // Worker scheduler menjalankan tugas: konteks tenant ditetapkan dari IdTenant tugas.
        app()->call([new ValidasiImporProdukTugas($t['Tenant']->Id, $impor->IdPengguna, $impor->Id), 'handle']);
        expect($impor->refresh()->Status)->toBe(StatusImporProduk::Pratinjau)->and($impor->JumlahValid)->toBe(301);
        expect(ImporProduk::query()->count())->toBe(1);
    });
});
