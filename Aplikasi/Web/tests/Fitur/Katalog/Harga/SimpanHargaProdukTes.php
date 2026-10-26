<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaProduk;
use App\Domain\Katalog\Harga\Data\HasilSimpanHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Model\Pengguna;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    ['Tenant' => $this->tenant, 'Pemilik' => $this->pemilik] = BantuanKatalog::BuatTenant();
    $this->produk = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Mandi Cair Aroma Sereh Wangi 450 ml'], '5000.00');
    $this->pcs = BantuanHarga::SatuanDasar($this->produk);
});

/**
 * @param  array<int, list<array{0: string, 1: string}>>  $perSatuan
 */
function SimpanHarga(Produk $produk, array $perSatuan, SumberPerubahanHarga $sumber = SumberPerubahanHarga::Manual): HasilSimpanHarga
{
    return app(SimpanHargaProduk::class)->Jalankan(
        $produk,
        array_map(fn (array $daftar): array => array_map(fn (array $baris) => BantuanHarga::Baris($baris[0], $baris[1]), $daftar), $perSatuan),
        $sumber,
    );
}

function HarapkanPelanggaran(Closure $aksi, string $kode, string $bidang): void
{
    try {
        $aksi();
    } catch (PelanggaranAturanBisnis $galat) {
        expect([$galat->kode, $galat->bidang])->toBe([$kode, $bidang]);

        return;
    }

    test()->fail("Harus ditolak dengan {$kode}.");
}

describe('F-03 SimpanHargaProduk: BR-03.3 riwayat harga', function (): void {
    it('BR-03.3 menambah tingkat harga dan mencatat RiwayatHarga dengan pengubah, sumber, dan audit', function (): void {
        $this->actingAs($this->pemilik, 'web');

        $hasil = SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000'], ['12', '4500']]]);

        expect([$hasil->ditambah, $hasil->diubah, $hasil->dihapus])->toBe([1, 0, 0])
            ->and(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00', '12.0000' => '4500.00']);

        $riwayat = RiwayatHarga::query()->sole();
        expect($riwayat->only(['IdProduk', 'IdProdukSatuan', 'IdSatuan', 'IdDaftarHarga', 'JumlahMinimum', 'HargaLama', 'HargaBaru', 'DiubahOleh']))->toBe([
            'IdProduk' => $this->produk->Id,
            'IdProdukSatuan' => $this->pcs->Id,
            'IdSatuan' => $this->pcs->IdSatuan,
            'IdDaftarHarga' => null,
            'JumlahMinimum' => '12.0000',
            'HargaLama' => null,
            'HargaBaru' => '4500.00',
            'DiubahOleh' => $this->pemilik->Id,
        ])->and($riwayat->Sumber)->toBe(SumberPerubahanHarga::Manual);

        $audit = LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->sole();
        expect($audit->IdObjek)->toBe($this->produk->Id)
            // Kolom JSON MySQL mengurutkan ulang kunci objek: bandingkan isi, bukan urutan kunci.
            ->and($audit->NilaiLama)->toEqual(['Harga' => [$this->pcs->Uuid => [['JumlahMinimum' => '1.0000', 'Harga' => '5000.00']]]])
            ->and($audit->NilaiBaru)->toEqual(['Harga' => [$this->pcs->Uuid => [
                ['JumlahMinimum' => '1.0000', 'Harga' => '5000.00'],
                ['JumlahMinimum' => '12.0000', 'Harga' => '4500.00'],
            ]], 'Sumber' => 'Manual']);
    });

    it('BR-03.3 mengubah harga dasar mencatat harga lama dan baru', function (): void {
        $hasil = SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5250.50']]]);

        expect([$hasil->ditambah, $hasil->diubah, $hasil->dihapus])->toBe([0, 1, 0])
            ->and(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5250.50'])
            ->and(RiwayatHarga::query()->sole()->only(['HargaLama', 'HargaBaru', 'DiubahOleh']))
            ->toBe(['HargaLama' => '5000.00', 'HargaBaru' => '5250.50', 'DiubahOleh' => null]);
    });

    it('BR-03.3 menghapus tingkat harga mencatat HargaBaru kosong dan jejak penghapusan katalog POS', function (): void {
        SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000'], ['12', '4500']]]);
        $uuidTingkat = ProdukHarga::query()->where('JumlahMinimum', '12')->value('Uuid');

        $hasil = SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000']]]);

        expect([$hasil->ditambah, $hasil->diubah, $hasil->dihapus])->toBe([0, 0, 1])
            ->and(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00'])
            ->and(RiwayatHarga::query()->orderByDesc('Id')->first()?->only(['JumlahMinimum', 'HargaLama', 'HargaBaru']))
            ->toBe(['JumlahMinimum' => '12.0000', 'HargaLama' => '4500.00', 'HargaBaru' => null])
            ->and(PenghapusanKatalog::query()->sole()->only(['Entitas', 'UuidEntitas']))
            ->toBe(['Entitas' => EntitasKatalog::ProdukHarga, 'UuidEntitas' => $uuidTingkat]);
    });

    it('BR-03.3 simpan ulang tanpa perubahan tidak menulis apa pun (idempoten)', function (): void {
        SimpanHarga($this->produk, [$this->pcs->Id => [['12', '4500'], ['1', '5000']]]);
        $jumlahRiwayat = RiwayatHarga::query()->count();
        $jumlahAudit = LogAudit::query()->count();
        $diubahPada = ProdukHarga::query()->orderBy('Id')->pluck('DiubahPada')->all();

        $hasil = SimpanHarga($this->produk, [$this->pcs->Id => [['1.0', '5000.00'], ['12', '4500.0']]]);

        expect($hasil->CekAdaPerubahan())->toBeFalse()
            ->and(RiwayatHarga::query()->count())->toBe($jumlahRiwayat)
            ->and(LogAudit::query()->count())->toBe($jumlahAudit)
            ->and(ProdukHarga::query()->orderBy('Id')->pluck('DiubahPada')->all())->toEqual($diubahPada);
    });

    it('hanya satuan yang disebut yang diganti. Satuan lain dan baris daftar harga tidak disentuh', function (): void {
        $pak = BantuanHarga::TambahSatuan($this->produk, BantuanKatalog::BuatSatuan('Pak', 'pak'), '10');
        SimpanHarga($this->produk, [$pak->Id => [['1', '45000'], ['5', '42000']]]);
        $daftar = BantuanHarga::BuatDaftarHarga();
        BantuanHarga::TambahHargaDaftar($daftar, $this->pcs, '12', '4000');

        SimpanHarga($this->produk, [$this->pcs->Id => [['1', '4900']]]);

        expect(BantuanHarga::HargaDasar($pak))->toBe(['1.0000' => '45000.00', '5.0000' => '42000.00'])
            ->and(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '4900.00'])
            ->and(ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->value('Harga'))->toBe('4000.00');
    });

    it('daftar kosong menghapus harga dasar satuan (satuan tidak bisa dijual)', function (): void {
        $hasil = SimpanHarga($this->produk, [$this->pcs->Id => []]);

        expect($hasil->dihapus)->toBe(1)
            ->and(BantuanHarga::HargaDasar($this->pcs))->toBe([])
            ->and(RiwayatHarga::query()->sole()->HargaBaru)->toBeNull();
    });

    it('BR-03.3 sumber Impor mencatat RiwayatHarga tanpa audit per produk', function (): void {
        SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5500']]], SumberPerubahanHarga::Impor);

        expect(RiwayatHarga::query()->sole()->Sumber)->toBe(SumberPerubahanHarga::Impor)
            ->and(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->exists())->toBeFalse();
    });

    it('BR-03.3 harga produk baru dari panduan awal F-01 (tambah produk cepat) tercatat dengan sumber PanduanAwal', function (): void {
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren'], null);
        $satuan = BantuanHarga::SatuanDasar($produk);

        SimpanHarga($produk, [$satuan->Id => [['1', '18000']]], SumberPerubahanHarga::PanduanAwal);

        expect(RiwayatHarga::query()->where('IdProduk', $produk->Id)->sole()->only(['HargaLama', 'HargaBaru', 'Sumber']))
            ->toBe(['HargaLama' => null, 'HargaBaru' => '18000.00', 'Sumber' => SumberPerubahanHarga::PanduanAwal]);
    });

    it('menerima harga jutaan Rupiah dan harga Rp 0', function (): void {
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Kulkas Dua Pintu Inverter 450 L'], null);
        $satuan = BantuanHarga::SatuanDasar($produk);

        SimpanHarga($produk, [$satuan->Id => [['1', '12500000'], ['3', '0']]]);

        expect(BantuanHarga::HargaDasar($satuan))->toBe(['1.0000' => '12500000.00', '3.0000' => '0.00']);
    });
});

describe('F-03 SimpanHargaProduk: validasi', function (): void {
    it('menolak tingkat tanpa harga dasar jumlah 1 (HargaDasarWajib) tanpa menulis apa pun', function (): void {
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['12', '4500']]]), 'HargaDasarWajib', 'Satuan.0.Harga');

        expect(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00'])
            ->and(RiwayatHarga::query()->count())->toBe(0);
    });

    it('menolak JumlahMinimum ganda', function (): void {
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000'], ['1.0000', '4000']]]), 'JumlahMinimumGanda', 'Satuan.0.Harga.1.JumlahMinimum');
    });

    it('menolak JumlahMinimum nol atau pecahan untuk satuan tidak desimal', function (): void {
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000'], ['0', '4000']]]), 'JumlahMinimumTidakValid', 'Satuan.0.Harga.1.JumlahMinimum');
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5000'], ['2.5', '4000']]]), 'JumlahMinimumTidakValid', 'Satuan.0.Harga.1.JumlahMinimum');
    });

    it('menerima JumlahMinimum pecahan untuk satuan desimal (kg)', function (): void {
        $kg = BantuanKatalog::BuatSatuan('Kilogram', 'kg', true);
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi Premium', 'IdSatuanDasar' => $kg->Id], null, $kg);
        $satuan = BantuanHarga::SatuanDasar($produk);

        SimpanHarga($produk, [$satuan->Id => [['1', '14000'], ['2.5', '13500']]]);

        expect(BantuanHarga::HargaDasar($satuan))->toBe(['1.0000' => '14000.00', '2.5000' => '13500.00']);
    });

    it('menolak harga negatif atau di atas batas', function (): void {
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '-1']]]), 'HargaTidakValid', 'Satuan.0.Harga.0.Harga');
        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '10000000000000000']]]), 'HargaTidakValid', 'Satuan.0.Harga.0.Harga');
    });

    it('menolak satuan milik produk lain dengan indeks satuan yang benar', function (): void {
        $lain = BantuanKatalog::BuatProduk(['Nama' => 'Sampo Anti Ketombe 170 ml']);
        $satuanLain = BantuanHarga::SatuanDasar($lain);

        HarapkanPelanggaran(fn () => SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5100']], $satuanLain->Id => [['1', '1']]]), 'SatuanTidakDikenal', 'Satuan.1');

        expect(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00']);
    });

    it('isolasi tenant: satuan produk tenant lain tidak dikenal', function (): void {
        $idTenantA = $this->tenant->Id;
        BantuanKatalog::BuatTenant('Toko Makmur Jaya');
        $produkB = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit 2 L']);
        $satuanB = BantuanHarga::SatuanDasar($produkB);

        BantuanOrganisasi::AturKonteks($idTenantA);
        HarapkanPelanggaran(fn () => SimpanHarga($produkB, [$satuanB->Id => [['1', '1']]]), 'SatuanTidakDikenal', 'Satuan.0');
    });

    it('tanpa satuan yang disebut tidak melakukan apa pun', function (): void {
        expect(SimpanHarga($this->produk, [])->CekAdaPerubahan())->toBeFalse();
    });
});

it('pengguna penyimpan harga tercatat sebagai DiubahOleh', function (): void {
    $pengguna = Pengguna::factory()->create();
    $this->actingAs($pengguna, 'web');

    SimpanHarga($this->produk, [$this->pcs->Id => [['1', '5100']]]);

    expect(RiwayatHarga::query()->sole()->DiubahOleh)->toBe($pengguna->Id);
});
