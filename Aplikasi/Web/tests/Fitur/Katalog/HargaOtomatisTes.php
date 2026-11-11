<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Aksi\HapusAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Aksi\SimpanAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaOtomatisUmum;
use App\Domain\Katalog\Harga\Aksi\TerapkanHargaDariModalMassal;
use App\Domain\Katalog\Harga\Aksi\TerapkanHargaOtomatis;
use App\Domain\Katalog\Harga\Aksi\TerapkanUsulanHarga;
use App\Domain\Katalog\Harga\Aksi\TolakUsulanHarga;
use App\Domain\Katalog\Harga\Aksi\UbahPengaturanHargaOtomatis;
use App\Domain\Katalog\Harga\Data\DataAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Kueri\PratinjauHargaDariModal;
use App\Domain\Katalog\Harga\Layanan\PenyediaTindakanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Pembelian\Aksi\TerimaBarang;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataPenerimaanBarang;
use App\Domain\Pembelian\Kueri\HargaBeliTerakhirProduk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-266 harga jual otomatis mengikuti harga beli terakhir (F-03 + F-04): aturan per cakupan, batas aman jadi usulan,
 * penerapan lewat riwayat harga sumber Otomatis, kabar ke pemilik, serta "Harga dari harga beli" massal.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant Pro dengan harga otomatis menyala (batas aman 20%) dan satu pemasok.
 *
 * @return array<string, mixed>
 */
function SiapkanHargaOtomatis(bool $nyala = true): array
{
    $t = BantuanPembelian::SiapkanTenant();
    BantuanFitur::Beri($t['Tenant']->Id, 'harga.otomatis');
    $t['Pemasok'] = BantuanPembelian::BuatPemasok();

    if ($nyala) {
        app(UbahPengaturanHargaOtomatis::class)->Jalankan(true, '20', $t['Pemilik']->Id);
    }

    return $t;
}

function AturHargaOtomatis(array $t, string $persen = '20', int $pembulatan = 0, CakupanAturanHarga $cakupan = CakupanAturanHarga::Semua, ?string $uuidKategori = null, ?string $uuidProduk = null, ArahHargaOtomatis $arah = ArahHargaOtomatis::NaikTurun): AturanHargaOtomatis
{
    return app(SimpanAturanHargaOtomatis::class)->Jalankan(null, new DataAturanHargaOtomatis($cakupan, $uuidKategori, $uuidProduk, $persen, $pembulatan, $arah, true), $t['Pemilik']->Id);
}

function ProdukBerharga(array $t, string $harga = '11000.00', array $atribut = []): Produk
{
    return BantuanKatalog::BuatProduk($atribut, $harga, $t['Pcs']);
}

function TerimaDenganHarga(array $t, Produk $produk, string $harga, string $jumlah = '10', int $hariMundur = 0): void
{
    BantuanPembelian::TerimaTanpaPo($t['Pemasok'], $t['Gudang'], [[$produk, $jumlah, $harga]], $t['Pemilik']->Id, tanggal: BantuanPembelian::Hari($hariMundur));
}

function HargaDasar(Produk $produk): string
{
    return (string) ProdukHarga::query()->where('IdProduk', $produk->Id)->whereNull('IdDaftarHarga')->where('JumlahMinimum', '1')->value('Harga');
}

describe('penerimaan barang menggerakkan harga jual', function (): void {
    it('harga beli naik: harga jual naik ke modal × (1 + untung), tercatat di riwayat harga sumber Otomatis dan audit', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');

        TerimaDenganHarga($t, $produk, '10000');

        $riwayat = RiwayatHarga::query()->where('IdProduk', $produk->Id)->where('Sumber', 'Otomatis')->sole();
        expect(HargaDasar($produk))->toBe('12000.00')
            ->and($riwayat->HargaLama)->toBe('11000.00')
            ->and($riwayat->HargaBaru)->toBe('12000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'produk.harga.ubah')->count())->toBe(1);
    });

    it('harga beli turun: harga jual ikut turun (naik dan turun)', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '9500');

        expect(HargaDasar($produk))->toBe('11400.00');
    });

    it('arah Hanya naik: harga beli turun tidak menurunkan harga jual', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t, arah: ArahHargaOtomatis::HanyaNaik);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '9500');
        expect(HargaDasar($produk))->toBe('12000.00');

        TerimaDenganHarga($t, $produk, '10300');
        expect(HargaDasar($produk))->toBe('12360.00');
    });

    it('hasil hitungan sama dengan harga sekarang: tidak ada perubahan dan tidak ada riwayat baru', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '10000');

        expect(RiwayatHarga::query()->where('Sumber', 'Otomatis')->count())->toBe(0)
            ->and(HargaDasar($produk))->toBe('12000.00');
    });

    it('pembulatan ke kelipatan 500 terdekat', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t, pembulatan: 500);
        $produk = ProdukBerharga($t, '11000.00');

        TerimaDenganHarga($t, $produk, '10250');

        expect(HargaDasar($produk))->toBe('12500.00');
    });

    it('penerimaan dalam satuan dus: modal dibagi konversi, harga diubah di satuan jual utama', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');
        $dus = BantuanPembelian::TambahSatuan($produk, $t['Dus'], '12', false);

        // Satu dus isi 12 seharga Rp 120.000 = Rp 10.000 per pcs.
        app(TerimaBarang::class)->Jalankan(new DataPenerimaanBarang(
            null, $t['Pemasok']->Uuid, $t['Gudang']->Id, BantuanPembelian::Hari(), null, Uang::Nol(), null,
            [new DataBarisPenerimaanBarang(null, $produk->Uuid, $dus->Uuid, Kuantitas::Dari('2'), Uang::Dari('120000'), Uang::Nol())],
            null, $t['Pemilik']->Id,
        ));

        // Satuan jual utama tetap pcs (default jual), jadi harga pcs yang berubah.
        expect(HargaDasar($produk))->toBe('12000.00');
    });

    it('diskon baris ikut memotong modal, ongkos kirim dan pajak tidak', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');

        // 10 pcs @ 10.000 diskon Rp 10.000 = subtotal 90.000 = modal bersih 9.000; ongkir 50.000 tidak dihitung.
        BantuanPembelian::TerimaTanpaPo($t['Pemasok'], $t['Gudang'], [[$produk, '10', '10000', '10000']], $t['Pemilik']->Id, '50000');

        expect(HargaDasar($produk))->toBe('10800.00');
    });

    it('produk tanpa aturan tidak berubah', function (): void {
        $t = SiapkanHargaOtomatis();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        AturHargaOtomatis($t, cakupan: CakupanAturanHarga::Kategori, uuidKategori: $minuman->Uuid);
        $produk = ProdukBerharga($t, '11000.00');

        TerimaDenganHarga($t, $produk, '10000');

        expect(HargaDasar($produk))->toBe('11000.00');
    });
});

describe('pemilihan aturan', function (): void {
    it('Produk mengalahkan Kategori, Kategori mengalahkan Semua, subkategori mewarisi aturan induk', function (): void {
        $t = SiapkanHargaOtomatis();
        $induk = BantuanKatalog::BuatKategori('Minuman');
        $anak = BantuanKatalog::BuatKategori('Minuman Dingin', $induk);
        AturHargaOtomatis($t, '10');
        AturHargaOtomatis($t, '30', cakupan: CakupanAturanHarga::Kategori, uuidKategori: $induk->Uuid);

        $diKategoriInduk = ProdukBerharga($t, '5000.00', ['IdKategori' => $induk->Id]);
        $diSubkategori = ProdukBerharga($t, '5000.00', ['IdKategori' => $anak->Id]);
        $tanpaKategori = ProdukBerharga($t, '5000.00');
        $khusus = ProdukBerharga($t, '5000.00', ['IdKategori' => $induk->Id]);
        AturHargaOtomatis($t, '50', cakupan: CakupanAturanHarga::Produk, uuidProduk: $khusus->Uuid);

        foreach ([$diKategoriInduk, $diSubkategori, $tanpaKategori, $khusus] as $p) {
            TerimaDenganHarga($t, $p, '4000');
        }

        // Aturan Produk 50%: 5.000 → 6.000 = +20%, tepat di batas aman jadi masih diterapkan langsung.
        expect(HargaDasar($diKategoriInduk))->toBe('5200.00')   // 4000 × 1,3
            ->and(HargaDasar($diSubkategori))->toBe('5200.00')  // mewarisi aturan Minuman 30%
            ->and(HargaDasar($tanpaKategori))->toBe('4400.00')  // aturan Semua 10%
            ->and(HargaDasar($khusus))->toBe('6000.00');        // aturan Produk 50% mengalahkan Minuman 30%
    });

    it('aturan ganda untuk cakupan yang sama ditolak', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);

        expect(fn () => AturHargaOtomatis($t, '25'))->toThrow(PelanggaranAturanBisnis::class, 'Sudah ada aturan');
    });

    it('cakupan Produk hanya untuk produk berstok biasa yang harganya tidak diketik kasir', function (): void {
        $t = SiapkanHargaOtomatis();
        $jasa = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::Jasa]);
        $terbuka = BantuanKatalog::BuatProduk(['HargaTerbuka' => true]);

        foreach ([$jasa, $terbuka] as $p) {
            expect(fn () => AturHargaOtomatis($t, cakupan: CakupanAturanHarga::Produk, uuidProduk: $p->Uuid))->toThrow(PelanggaranAturanBisnis::class, 'Hanya produk berstok');
        }
    });
});

describe('batas aman dan usulan', function (): void {
    it('perubahan di atas batas ditahan jadi usulan dan harga tidak berubah', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');

        // Salah ketik harga beli: 100.000, bukan 10.000.
        TerimaDenganHarga($t, $produk, '100000');

        $usulan = UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->sole();
        expect(HargaDasar($produk))->toBe('12000.00')
            ->and($usulan->Status)->toBe(StatusUsulanHarga::Menunggu)
            ->and($usulan->HargaLama)->toBe('12000.00')
            ->and($usulan->HargaBaru)->toBe('120000.00')
            ->and($usulan->PerubahanPersen)->toBe('900.00')
            ->and(RiwayatHarga::query()->where('Sumber', 'Otomatis')->count())->toBe(0);
    });

    it('usulan baru menggantikan usulan lama yang masih menunggu, dan penerimaan yang pas menutupnya', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '100000');
        TerimaDenganHarga($t, $produk, '50000');

        $semua = UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get();
        expect($semua)->toHaveCount(2)
            ->and($semua[0]->Status)->toBe(StatusUsulanHarga::Digantikan)
            ->and($semua[1]->Status)->toBe(StatusUsulanHarga::Menunggu);

        // Harga beli kembali normal: harga sama dengan sekarang, usulan yang menunggu ditutup.
        TerimaDenganHarga($t, $produk, '10000');
        expect(UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->where('Status', 'Menunggu')->count())->toBe(0);
    });

    it('pemilik menerapkan usulan: harga berubah lewat riwayat Otomatis dan usulan jadi Diterapkan', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $produk, '20000');
        $usulan = UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->sole();

        $berhasil = app(TerapkanUsulanHarga::class)->Jalankan($usulan->Uuid, $t['Pemilik']->Id);

        expect($berhasil)->toBeTrue()
            ->and(HargaDasar($produk))->toBe('24000.00')
            ->and($usulan->refresh()->Status)->toBe(StatusUsulanHarga::Diterapkan)
            ->and(RiwayatHarga::query()->where('IdProduk', $produk->Id)->where('Sumber', 'Otomatis')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'harga-otomatis.usulan.terapkan')->count())->toBe(1);
        expect(fn () => app(TerapkanUsulanHarga::class)->Jalankan($usulan->Uuid, $t['Pemilik']->Id))->toThrow(PelanggaranAturanBisnis::class, 'sudah diputuskan');
    });

    it('usulan yang harga produknya sudah berubah sejak dibuat ditutup, bukan diterapkan', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $produk, '20000');
        $usulan = UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->sole();
        ProdukHarga::query()->where('IdProduk', $produk->Id)->whereNull('IdDaftarHarga')->update(['Harga' => '13000.00']);

        $berhasil = app(TerapkanUsulanHarga::class)->Jalankan($usulan->Uuid, $t['Pemilik']->Id);

        expect($berhasil)->toBeFalse()
            ->and(HargaDasar($produk))->toBe('13000.00')
            ->and($usulan->refresh()->Status)->toBe(StatusUsulanHarga::Digantikan);
    });

    it('pemilik menolak usulan: harga tetap dan usulan Ditolak', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $produk, '20000');
        $usulan = UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->sole();

        app(TolakUsulanHarga::class)->Jalankan($usulan->Uuid, $t['Pemilik']->Id);

        expect(HargaDasar($produk))->toBe('12000.00')
            ->and($usulan->refresh()->Status)->toBe(StatusUsulanHarga::Ditolak);
    });

    it('batas aman bisa diatur dan harus 1 sampai 100 persen', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        app(UbahPengaturanHargaOtomatis::class)->Jalankan(true, '50', $t['Pemilik']->Id);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '15000');   // 12.000 → 18.000 = +50%, tepat di batas, diterapkan
        expect(HargaDasar($produk))->toBe('18000.00');

        foreach (['0', '101', 'abc', ''] as $salah) {
            expect(fn () => app(UbahPengaturanHargaOtomatis::class)->Jalankan(true, $salah, $t['Pemilik']->Id))->toThrow(PelanggaranAturanBisnis::class);
        }
    });

    it('butir Kotak Tindakan menghitung usulan yang menunggu dan hilang setelah diputuskan', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $produk, '20000');
        $konteks = new DataKonteksTindakan($t['Tenant']->Id, $t['Pemilik']->Id, true, [], null, CarbonImmutable::now());

        $butir = app(PenyediaTindakanHargaOtomatis::class)->Kumpulkan($konteks);
        expect($butir)->toHaveCount(1)
            ->and($butir[0]->jumlah)->toBe(1);

        app(TolakUsulanHarga::class)->Jalankan(UsulanHargaOtomatis::query()->sole()->Uuid, $t['Pemilik']->Id);
        expect(app(PenyediaTindakanHargaOtomatis::class)->Kumpulkan($konteks))->toBe([]);
    });
});

describe('saklar dan fitur paket', function (): void {
    it('saklar utama mati: penerimaan tidak mengubah harga', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');

        TerimaDenganHarga($t, $produk, '10000');

        expect(HargaDasar($produk))->toBe('11000.00');
    });

    it('tanpa fitur harga.otomatis di paket tenant: tidak ada perubahan walau saklar menyala', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $t['Pemasok'] = BantuanPembelian::BuatPemasok();
        BantuanFitur::PindahPaket($t['Tenant']->Id, 'STARTER');
        app(UbahPengaturanHargaOtomatis::class)->Jalankan(true, '20', $t['Pemilik']->Id);
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');

        TerimaDenganHarga($t, $produk, '10000');

        expect(HargaDasar($produk))->toBe('11000.00');
    });

    it('produk berharga terbuka dan produk jasa tidak disentuh, produk lain di penerimaan yang sama tetap jalan', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $terbuka = ProdukBerharga($t, '11000.00', ['HargaTerbuka' => true]);
        $biasa = ProdukBerharga($t, '11000.00');

        BantuanPembelian::TerimaTanpaPo($t['Pemasok'], $t['Gudang'], [[$terbuka, '5', '10000'], [$biasa, '5', '10000']], $t['Pemilik']->Id);

        expect(HargaDasar($terbuka))->toBe('11000.00')
            ->and(HargaDasar($biasa))->toBe('12000.00');
    });

    it('produk tanpa harga dasar dilewati tanpa galat', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = BantuanKatalog::BuatProduk([], null, $t['Pcs']);

        TerimaDenganHarga($t, $produk, '10000');

        expect(ProdukHarga::query()->where('IdProduk', $produk->Id)->count())->toBe(0);
    });
});

describe('kabar ke pemilik', function (): void {
    it('pemilik dikabari satu kali per penerimaan, memuat jumlah dan contoh', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00', ['Nama' => 'Gula Pasir Lokal 1 kg']);

        TerimaDenganHarga($t, $produk, '10000');

        $kabar = NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::HargaOtomatis->value)->get();
        expect($kabar)->toHaveCount(1)
            ->and($kabar[0]->IdPengguna)->toBe($t['Pemilik']->Id)
            ->and($kabar[0]->Isi)->toContain('1 harga jual berubah')->toContain('Gula Pasir Lokal 1 kg');
    });

    it('penerimaan yang tidak mengubah apa pun tidak mengirim kabar', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');

        TerimaDenganHarga($t, $produk, '10000');

        expect(NotifikasiPengguna::query()->where('Jenis', JenisNotifikasiPengguna::HargaOtomatis->value)->count())->toBe(0);
    });
});

describe('isolasi tenant', function (): void {
    it('aturan dan saklar tenant lain tidak memengaruhi tenant ini', function (): void {
        $lain = SiapkanHargaOtomatis();
        AturHargaOtomatis($lain);

        $t = BantuanPembelian::SiapkanTenant(namaUsaha: 'Toko Kelontong Bu Sri Wonogiri');
        BantuanFitur::Beri($t['Tenant']->Id, 'harga.otomatis');
        $t['Pemasok'] = BantuanPembelian::BuatPemasok('PT Pangan Jaya Abadi');
        $produk = ProdukBerharga($t, '11000.00');
        TerimaDenganHarga($t, $produk, '10000');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(HargaDasar($produk))->toBe('11000.00')
            ->and(AturanHargaOtomatis::query()->count())->toBe(0);
    });
});

describe('satu simpan untuk pengaturan utama', function (): void {
    it('membuat untung utama bila belum ada dan memperbarui yang sama pada simpan berikutnya (tidak ganda)', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $simpan = app(SimpanHargaOtomatisUmum::class);

        $simpan->Jalankan(true, '20', '25', 500, ArahHargaOtomatis::NaikTurun, $t['Pemilik']->Id);
        $simpan->Jalankan(true, '30', '40', 100, ArahHargaOtomatis::HanyaNaik, $t['Pemilik']->Id);

        $umum = AturanHargaOtomatis::query()->sole();
        expect($umum->Cakupan)->toBe(CakupanAturanHarga::Semua)
            ->and((string) $umum->PersenUntung)->toBe('40.0000')
            ->and($umum->Pembulatan)->toBe(100)
            ->and($umum->Arah)->toBe(ArahHargaOtomatis::HanyaNaik)
            ->and(PengaturanHargaOtomatis::query()->sole()->Aktif)->toBeTrue()
            ->and((string) PengaturanHargaOtomatis::query()->sole()->BatasPerubahanPersen)->toBe('30.00');
    });

    it('pembulatan dan arah menular ke untung khusus, untungnya sendiri tidak berubah', function (): void {
        $t = SiapkanHargaOtomatis();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        AturHargaOtomatis($t, '20');
        AturHargaOtomatis($t, '35', pembulatan: 500, cakupan: CakupanAturanHarga::Kategori, uuidKategori: $minuman->Uuid);

        app(SimpanHargaOtomatisUmum::class)->Jalankan(true, '20', '20', 1000, ArahHargaOtomatis::HanyaNaik, $t['Pemilik']->Id);

        $khusus = AturanHargaOtomatis::query()->where('Cakupan', 'Kategori')->sole();
        expect($khusus->Pembulatan)->toBe(1000)
            ->and($khusus->Arah)->toBe(ArahHargaOtomatis::HanyaNaik)
            ->and((string) $khusus->PersenUntung)->toBe('35.0000')
            ->and(AturanHargaOtomatis::query()->count())->toBe(2);
    });

    it('isian salah menggagalkan semuanya: saklar dan aturan tidak berubah', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);

        expect(fn () => app(SimpanHargaOtomatisUmum::class)->Jalankan(true, '20', 'banyak', 500, ArahHargaOtomatis::NaikTurun, $t['Pemilik']->Id))
            ->toThrow(PelanggaranAturanBisnis::class);

        expect(AturanHargaOtomatis::query()->count())->toBe(0)
            ->and(PengaturanHargaOtomatis::query()->count())->toBe(0);
    });
});

describe('harga beli terakhir', function (): void {
    it('memakai penerimaan terbaru, bersih dari diskon, dan produk yang belum pernah dibeli tidak muncul', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $produk = ProdukBerharga($t);
        $belum = ProdukBerharga($t);

        TerimaDenganHarga($t, $produk, '8000', hariMundur: 3);
        BantuanPembelian::TerimaTanpaPo($t['Pemasok'], $t['Gudang'], [[$produk, '10', '10000', '10000']], $t['Pemilik']->Id, tanggal: BantuanPembelian::Hari(1));
        // Penerimaan bertanggal lebih lama yang dicatat belakangan bukan yang terbaru.
        TerimaDenganHarga($t, $produk, '5000', hariMundur: 6);

        $hasil = app(HargaBeliTerakhirProduk::class)->Ambil([$produk->Id, $belum->Id]);

        expect($hasil)->toHaveKey($produk->Id)->not->toHaveKey($belum->Id)
            ->and($hasil[$produk->Id]['Modal'])->toBe('9000.000000')
            ->and($hasil[$produk->Id]['Tanggal'])->toBe(BantuanPembelian::Hari(1)->toDateString());
    });
});

describe('Terapkan sekarang dan hitung ulang', function (): void {
    it('menghitung semua produk berstok dari harga beli terakhirnya', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $a = ProdukBerharga($t, '11000.00');
        $b = ProdukBerharga($t, '5500.00');
        TerimaDenganHarga($t, $a, '10000');
        TerimaDenganHarga($t, $b, '5000');
        // Aturan baru dibuat sesudah penerimaan, jadi harga baru berubah saat "Terapkan sekarang".
        app(UbahPengaturanHargaOtomatis::class)->Jalankan(true, '20', $t['Pemilik']->Id);
        AturHargaOtomatis($t);

        $hasil = app(TerapkanHargaOtomatis::class)->JalankanUntukSemua('uji', $t['Pemilik']->Id);

        expect($hasil->diterapkan)->toBe(2)
            ->and(HargaDasar($a))->toBe('12000.00')
            ->and(HargaDasar($b))->toBe('6000.00');
    });

    it('dijalankan dua kali tidak menggandakan perubahan', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');
        TerimaDenganHarga($t, $produk, '10000');

        $ulang = app(TerapkanHargaOtomatis::class)->Jalankan([$produk->Id], 'ulang', $t['Pemilik']->Id);

        expect($ulang->diterapkan)->toBe(0)
            ->and($ulang->tetap)->toBe(1)
            ->and(RiwayatHarga::query()->where('IdProduk', $produk->Id)->where('Sumber', 'Otomatis')->count())->toBe(1);
    });

    it('menghapus aturan menghentikan penerimaan berikutnya mengubah harga, harga lama tidak dikembalikan', function (): void {
        $t = SiapkanHargaOtomatis();
        $aturan = AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '11000.00');
        TerimaDenganHarga($t, $produk, '10000');

        app(HapusAturanHargaOtomatis::class)->Jalankan($aturan->Uuid, $t['Pemilik']->Id);
        TerimaDenganHarga($t, $produk, '15000');

        expect(HargaDasar($produk))->toBe('12000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'harga-otomatis.aturan.hapus')->count())->toBe(1);
    });
});

describe('Harga dari harga beli massal (manual)', function (): void {
    it('pratinjau memberi harga lama, modal, harga baru, dan alasan produk yang dilewati', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $dibeli = ProdukBerharga($t, '11000.00');
        $belumDibeli = ProdukBerharga($t, '11000.00');
        $sudahPas = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $dibeli, '10000');
        TerimaDenganHarga($t, $sudahPas, '10000');

        $hasil = app(PratinjauHargaDariModal::class)->Ambil([$dibeli->Uuid, $belumDibeli->Uuid, $sudahPas->Uuid], '20', 0);
        $baris = collect($hasil['Baris'])->keyBy('Uuid');

        expect($baris[$dibeli->Uuid])->toMatchArray(['Status' => 'Berubah', 'HargaLama' => '11000.00', 'Modal' => '10000.00', 'HargaBaru' => '12000.00'])
            ->and($baris[$belumDibeli->Uuid]['Status'])->toBe('TanpaHargaBeli')
            ->and($baris[$sudahPas->Uuid]['Status'])->toBe('Sama')
            ->and($hasil['Ringkasan'])->toMatchArray(['Berubah' => 1, 'TanpaHargaBeli' => 1, 'Sama' => 1])
            ->and(HargaDasar($dibeli))->toBe('11000.00');
    });

    it('terapkan menyimpan yang berubah dengan sumber Manual, memakai angka yang sama dengan pratinjau', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $a = ProdukBerharga($t, '11000.00');
        $b = ProdukBerharga($t, '11000.00');
        TerimaDenganHarga($t, $a, '10250');

        $hasil = app(TerapkanHargaDariModalMassal::class)->Jalankan([$a->Uuid, $b->Uuid], '20', 500, $t['Pemilik']->Id);

        expect($hasil)->toBe(['Produk' => 1, 'Dilewati' => 1])
            ->and(HargaDasar($a))->toBe('12500.00')
            ->and(HargaDasar($b))->toBe('11000.00')
            ->and(RiwayatHarga::query()->where('IdProduk', $a->Id)->where('Sumber', 'Manual')->count())->toBe(1);
    });

    it('terapkan manual menutup usulan harga otomatis yang masih menunggu untuk produk itu', function (): void {
        $t = SiapkanHargaOtomatis();
        AturHargaOtomatis($t);
        $produk = ProdukBerharga($t, '12000.00');
        TerimaDenganHarga($t, $produk, '20000');

        app(TerapkanHargaDariModalMassal::class)->Jalankan([$produk->Uuid], '20', 0, $t['Pemilik']->Id);

        expect(UsulanHargaOtomatis::query()->where('IdProduk', $produk->Id)->value('Status'))->toBe(StatusUsulanHarga::Digantikan);
    });

    it('menolak pilihan kosong, terlalu banyak, untung tidak sah, dan produk yang tidak dikenal', function (): void {
        $t = SiapkanHargaOtomatis(nyala: false);
        $produk = ProdukBerharga($t);
        $aksi = app(PratinjauHargaDariModal::class);

        expect(fn () => $aksi->Ambil([], '20', 0))->toThrow(PelanggaranAturanBisnis::class, 'minimal satu')
            ->and(fn () => $aksi->Ambil(array_map(fn (int $i): string => (string) Str::ulid(), range(1, 201)), '20', 0))->toThrow(PelanggaranAturanBisnis::class, 'Maksimal')
            ->and(fn () => $aksi->Ambil([$produk->Uuid], 'dua puluh', 0))->toThrow(PelanggaranAturanBisnis::class, 'angka')
            ->and(fn () => $aksi->Ambil([(string) Str::ulid()], '20', 0))->toThrow(PelanggaranAturanBisnis::class, 'tidak ditemukan');
    });
});
