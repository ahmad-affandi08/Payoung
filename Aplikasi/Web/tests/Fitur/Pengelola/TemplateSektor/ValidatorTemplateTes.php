<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\TarifPajak;
use App\Domain\PanduanAwal\Enum\StatusTemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanSatuanStandarBawaan;
use App\Domain\Pengelola\TemplateSektor\Aksi\SiapkanTemplateSektorBawaan;
use App\Domain\Pengelola\TemplateSektor\Layanan\ValidatorTemplate;
use App\Domain\Referensi\Model\SatuanStandar;
use Illuminate\Support\Carbon;

function TerbitkanPpnUji(): void
{
    TarifPajak::query()->create([
        'IdJenisPajak' => JenisPajak::query()->where('Kode', 'Ppn')->value('Id'),
        'Tarif' => '12',
        'PengaliDppPembilang' => 11,
        'PengaliDppPenyebut' => 12,
        'BerlakuMulai' => '2026-01-01',
        'Status' => StatusDataMaster::Terbit,
    ]);
}

/**
 * @return array<string, mixed>
 */
function AmbilIsiTemplateAwal(string $kode): array
{
    return TemplateSektor::query()->where('Kode', $kode)->sole()->Versi()->sole()->Isi;
}

/**
 * @param  array<string, mixed>  $isi
 * @return list<string>
 */
function AmbilPesanValidasi(array $isi, ?string $bagian = null): array
{
    $hasil = app(ValidatorTemplate::class)->Validasi($isi);

    return array_values(array_map(
        fn (array $galat) => $galat['Pesan'],
        array_filter($hasil['Galat'], fn (array $galat) => $bagian === null || $galat['Bagian'] === $bagian),
    ));
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanSatuanStandarBawaan::class)->Jalankan();
    app(SiapkanPajakBawaan::class)->Jalankan();
    app(SiapkanKatalogBawaan::class)->Jalankan();
    app(SiapkanTemplateSektorBawaan::class)->Jalankan();
});

const KODE_TEMPLATE_AWAL = ['FNB-BAK', 'FNB-CAF', 'FNB-QSR', 'FNB-RST', 'RTL-BLD', 'RTL-ELC', 'RTL-FLR', 'RTL-FRS', 'RTL-FSH', 'RTL-GEN', 'RTL-PET', 'RTL-PHR', 'SVC-CWS', 'SVC-FIT', 'SVC-GEN', 'SVC-LDR', 'SVC-SLN', 'SVC-WRK', 'WHS-DST'];

describe('Data awal template sektor (P-03)', function (): void {
    // v3.80: 3 template MVP + 9 sektor lain; K25 (keputusan pemilik produk "bangun sekarang"): + Bengkel SVC-WRK
    // dan Apotek RTL-PHR; D-88: + sayur/buah & sembako, gym & kursus, cuci kendaraan, pet shop, florist.
    it('memuat 19 template sektor sebagai draf versi 1 secara idempoten (termasuk Bengkel, Apotek, dan 5 sektor D-88)', function (): void {
        app(SiapkanTemplateSektorBawaan::class)->Jalankan();

        expect(TemplateSektor::query()->orderBy('Kode')->pluck('Kode')->all())->toBe(KODE_TEMPLATE_AWAL)
            ->and(TemplateSektorVersi::query()->count())->toBe(19)
            ->and(TemplateSektorVersi::query()->where('Status', StatusTemplateSektor::Draf->value)->where('Versi', 1)->count())->toBe(19);
    });

    it('semua template awal lolos validasi setelah tarif PPN terbit (BR-P03.3)', function (): void {
        TerbitkanPpnUji();

        foreach (KODE_TEMPLATE_AWAL as $kode) {
            expect(AmbilPesanValidasi(AmbilIsiTemplateAwal($kode)))->toBe([]);
        }
    });

    it('template F&B memakai ekstensi COA 4-1010 dan 4-1020 (§11.2)', function (): void {
        $kode = array_column(AmbilIsiTemplateAwal('FNB-CAF')['Akun'], 'Kode');

        expect($kode)->toContain('4-1010', '4-1020')
            ->and(array_column(AmbilIsiTemplateAwal('RTL-GEN')['Akun'], 'Kode'))->not->toContain('4-1010');
    });
});

describe('Validasi otomatis template (BR-P03.3)', function (): void {
    it('menolak kelompok pajak nasional yang belum punya tarif terbit di P-02', function (): void {
        expect(AmbilPesanValidasi(AmbilIsiTemplateAwal('RTL-GEN'), 'KelompokPajak'))
            ->toBe(['Kelompok Barang kena PPN: PPN belum punya tarif terbit yang masih berlaku.']);
    });

    it('menerima pajak daerah tanpa tarif terbit karena tarifnya dipilih per kota outlet', function (): void {
        expect(AmbilPesanValidasi(AmbilIsiTemplateAwal('FNB-CAF'), 'KelompokPajak'))->toBe([]);
    });

    it('menolak jenis pajak yang tidak ada dan dasar pengenaan yang tidak dikenal', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['KelompokPajak'][0]['Detail'][] = ['KodeJenisPajak' => 'PajakHiburan', 'DasarPengenaan' => 'Subtotal', 'Urutan' => 2];
        $isi['KelompokPajak'][0]['Detail'][0]['DasarPengenaan'] = 'Total';

        expect(AmbilPesanValidasi($isi, 'KelompokPajak'))->toBe([
            'Kelompok Makan & minum: dasar pengenaan PbjtMakananMinuman tidak dikenal.',
            'Kelompok Makan & minum: jenis pajak PajakHiburan tidak ada di data regulasi (P-02).',
        ]);
    });

    it('menolak kode akun ganda, format salah, digit tipe salah, dan saldo normal tidak konsisten', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['Akun'][] = ['Kode' => '1-1100', 'Nama' => 'Kas ganda', 'Tipe' => 'Aset', 'SaldoNormal' => 'Debit', 'Kontra' => false];
        $isi['Akun'][] = ['Kode' => '11100', 'Nama' => 'Tanpa tanda hubung', 'Tipe' => 'Aset', 'SaldoNormal' => 'Debit', 'Kontra' => false];
        $isi['Akun'][] = ['Kode' => '6-7000', 'Nama' => 'Beban salah tipe', 'Tipe' => 'Pendapatan', 'SaldoNormal' => 'Kredit', 'Kontra' => false];
        $isi['Akun'][] = ['Kode' => '6-8000', 'Nama' => 'Beban kredit', 'Tipe' => 'Beban', 'SaldoNormal' => 'Kredit', 'Kontra' => false];

        expect(AmbilPesanValidasi($isi, 'Akun'))->toBe([
            'Kode akun 1-1100 ganda.',
            'Kode akun 11100 harus berformat 1-1100 (digit tipe, tanda hubung, empat digit).',
            'Akun 6-7000 bertipe Pendapatan harus berkode awal 4-.',
            'Saldo normal akun 6-8000 seharusnya Debit.',
        ]);
    });

    it('mewajibkan setiap peran akun §11.3 terpetakan ke akun COA dengan tipe dan sifat kontra yang sesuai', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        unset($isi['PemetaanAkun'][PeranAkun::HutangPbjt->value]);
        $isi['PemetaanAkun'][PeranAkun::KasOutlet->value] = '9-9999';
        $isi['PemetaanAkun'][PeranAkun::Hpp->value] = '6-3000';
        $isi['PemetaanAkun'][PeranAkun::DiskonPenjualan->value] = '4-1000';
        $isi['PemetaanAkun']['Kasbon'] = '1-1450';

        expect(AmbilPesanValidasi($isi, 'PemetaanAkun'))->toBe([
            'Peran akun Kasbon tidak dikenal.',
            'Peran "Kas outlet" merujuk akun 9-9999 yang tidak ada di COA.',
            'Peran "Hutang PB1/PBJT" belum dipetakan ke akun.',
            'Peran "Diskon penjualan" harus memakai akun kontra (4-1000 bukan akun kontra).',
            'Peran "Harga pokok penjualan" harus memakai akun HPP, bukan Beban (6-3000).',
        ]);
    });

    it('menolak fitur di luar katalog P-04 dan satuan yang tidak aktif', function (): void {
        SatuanStandar::query()->where('Kode', 'PORSI')->update(['Aktif' => false]);
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['KunciFitur'][] = 'pos.terbang';
        $isi['KodeSatuan'][] = 'TIDAKADA';

        expect(AmbilPesanValidasi($isi, 'KunciFitur'))->toBe(['Fitur pos.terbang tidak ada di katalog fitur (P-04).'])
            ->and(AmbilPesanValidasi($isi, 'KodeSatuan'))->toBe([
                'Satuan PORSI tidak ada atau tidak aktif di satuan standar (P-02).',
                'Satuan TIDAKADA tidak ada atau tidak aktif di satuan standar (P-02).',
            ]);
    });

    it('PRD v1.46: kelipatan pembulatan tunai di atas 1.000 ditolak', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-CAF');
        $isi['Pengaturan']['PembulatanTunai']['Kelipatan'] = 5000;

        expect(AmbilPesanValidasi($isi, 'Pengaturan'))->toBe(['Kelipatan pembulatan tunai harus bilangan bulat Rupiah 1 sampai 1.000 (misal 100).']);
    });

    it('memeriksa mode kasir, pengaturan, dan nama ganda', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-CAF');
        $isi['ModeKasir'] = ['Meja', 'Terbang'];
        $isi['ModeKasirDefault'] = 'Cepat';
        $isi['Pengaturan']['PembulatanTunai']['Kelipatan'] = 0;
        $isi['Pengaturan']['PersenBiayaLayanan'] = '12.5';
        $isi['Pengaturan']['MetodeHpp'] = 'Lifo';
        $isi['Pengaturan']['StokBolehMinus'] = 'ya';
        $isi['Kategori'][] = ' kopi ';
        $isi['LaporanUnggulan'][] = 'OmzetBulanan';

        expect(AmbilPesanValidasi($isi, 'ModeKasir'))->toBe([
            'Mode kasir Terbang tidak dikenal.',
            'Mode kasir default harus salah satu mode yang dipilih.',
        ])->and(AmbilPesanValidasi($isi, 'Pengaturan'))->toBe([
            'Kelipatan pembulatan tunai harus bilangan bulat Rupiah 1 sampai 1.000 (misal 100).',
            'Biaya layanan harus 0 sampai 10 persen.',
            'Metode HPP tidak dikenal.',
            'Pengaturan StokBolehMinus wajib ya atau tidak.',
        ])->and(AmbilPesanValidasi($isi, 'Kategori'))->toBe(['Nama kopi ganda.'])
            ->and(AmbilPesanValidasi($isi, 'LaporanUnggulan'))->toBe(['Laporan OmzetBulanan tidak dikenal.']);
    });

    it('melaporkan elemen daftar yang bukan teks, bukan membuangnya diam-diam', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['KunciFitur'][] = 123;
        $isi['LaporanUnggulan'][] = null;

        expect(AmbilPesanValidasi($isi, 'KunciFitur'))->toBe(['Isian harus berupa daftar teks.'])
            ->and(AmbilPesanValidasi($isi, 'LaporanUnggulan'))->toBe(['Isian harus berupa daftar teks.']);
    });

    it('memeriksa urutan pajak dan konsistensi service charge masuk DPP', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['KelompokPajak'][0]['Detail'][0]['DasarPengenaan'] = 'SubtotalPlusLayanan';
        $isi['KelompokPajak'][0]['Detail'][] = ['KodeJenisPajak' => 'Ppn', 'DasarPengenaan' => 'Subtotal', 'Urutan' => 1];
        TerbitkanPpnUji();

        expect(AmbilPesanValidasi($isi, 'KelompokPajak'))->toBe([
            'Kelompok Makan & minum: PbjtMakananMinuman memakai subtotal + biaya layanan, padahal pengaturan biaya layanan tidak masuk DPP.',
            'Kelompok Makan & minum: urutan Ppn harus angka 1–9 dan tidak ganda.',
        ]);
    });

    it('tarif nasional yang sudah berakhir tidak dihitung sebagai tarif terbit', function (): void {
        TerbitkanPpnUji();
        TarifPajak::query()->update(['BerlakuSampai' => '2026-06-30']);

        expect(AmbilPesanValidasi(AmbilIsiTemplateAwal('RTL-GEN'), 'KelompokPajak'))
            ->toBe(['Kelompok Barang kena PPN: PPN belum punya tarif terbit yang masih berlaku.']);
    });

    it('melaporkan isi yang rusak sebagai galat, bukan exception', function (): void {
        $hasil = app(ValidatorTemplate::class)->Validasi(['Akun' => 'bukan daftar', 'Pengaturan' => null, 'ModeKasir' => [1]]);

        expect($hasil['Lolos'])->toBeFalse()
            ->and(array_unique(array_column($hasil['Galat'], 'Bagian')))->toContain('ModeKasir', 'Akun', 'PemetaanAkun', 'KodeSatuan', 'Pengaturan');
    });
});

describe('Versi template tidak berubah setelah terbit (BR-P03.4)', function (): void {
    it('menolak perubahan isi dan penghapusan versi terbit, tetapi mengizinkan Terbit → Usang', function (): void {
        $versi = TemplateSektorVersi::query()->firstOrFail();
        $versi->update(['Status' => StatusTemplateSektor::Terbit, 'DiterbitkanPada' => now()]);

        expect(fn () => $versi->update(['Isi' => ['ModeKasir' => ['Retail']]]))->toThrow(LogicException::class)
            ->and(fn () => $versi->fresh()?->delete())->toThrow(LogicException::class);

        $versi->refresh()->update(['Status' => StatusTemplateSektor::Usang, 'DiusangkanPada' => now()]);
        expect($versi->refresh()->Status)->toBe(StatusTemplateSektor::Usang)
            ->and(fn () => $versi->update(['Status' => StatusTemplateSektor::Terbit]))->toThrow(LogicException::class);
    });
});

describe('Produk contoh template (F-01 langkah 4a, DesainF01 C3)', function (): void {
    it('semua template awal membawa 8–12 produk contoh berharga string desimal Rupiah', function (): void {
        foreach (KODE_TEMPLATE_AWAL as $kode) {
            $isi = AmbilIsiTemplateAwal($kode);

            expect(count($isi['ProdukContoh']))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(12)
                ->and(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe([]);

            foreach ($isi['ProdukContoh'] as $produk) {
                expect($produk['Harga'])->toBeString()->toMatch(ValidatorTemplate::POLA_HARGA);
            }
        }

        // Urutan kunci di kolom JSON berbeda antar mesin (MySQL mengurutkan ulang, MariaDB menyimpan apa adanya).
        expect(AmbilIsiTemplateAwal('FNB-CAF')['ProdukContoh'])->toContainEqual(
            ['Nama' => 'Es Kopi Susu Gula Aren', 'Harga' => '22000', 'Jenis' => 'NonStok', 'Kategori' => 'Kopi', 'KodeSatuan' => 'PCS'],
        );
    });

    it('kunci ProdukContoh boleh tidak ada (versi lama) dan daftar kosong juga lolos', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        unset($isi['ProdukContoh']);
        expect(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe([]);

        $isi['ProdukContoh'] = [];
        expect(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe([]);
    });

    it('menolak kategori & satuan di luar isi template, nama ganda, harga bukan string desimal, dan jenis tak dikenal', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-CAF');
        $isi['ProdukContoh'] = [
            ['Nama' => 'Es Kopi Susu Gula Aren', 'Kategori' => 'kopi ', 'Harga' => '22000.50', 'KodeSatuan' => 'PCS', 'Jenis' => 'NonStok'],
            ['Nama' => ' es kopi susu gula aren', 'Kategori' => null, 'Harga' => '23000', 'KodeSatuan' => 'PCS', 'Jenis' => 'NonStok'],
            ['Nama' => 'Croissant Mentega Prancis', 'Kategori' => 'Roti', 'Harga' => '28.000', 'KodeSatuan' => 'LUSIN', 'Jenis' => 'Paket'],
            ['Nama' => 'Kue Lapis Legit Sepotong', 'Kategori' => 'Camilan', 'Harga' => 18500.5, 'KodeSatuan' => 'PORSI', 'Jenis' => 'Stok'],
            ['Nama' => '', 'Kategori' => null, 'Harga' => '-5000', 'KodeSatuan' => null, 'Jenis' => 'Jasa'],
            'bukan produk',
        ];

        expect(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe([
            'Produk contoh es kopi susu gula aren ganda.',
            'Produk contoh Croissant Mentega Prancis: kategori Roti tidak ada di daftar kategori template.',
            'Produk contoh Croissant Mentega Prancis: harga harus angka Rupiah tanpa titik ribuan, paling banyak 2 desimal (misal 22000).',
            'Produk contoh Croissant Mentega Prancis: satuan LUSIN tidak ada di daftar satuan template.',
            'Produk contoh Croissant Mentega Prancis: jenis Paket tidak dikenal. Pilih salah satu: Stok, NonStok, Jasa.',
            'Produk contoh Kue Lapis Legit Sepotong: harga harus angka Rupiah tanpa titik ribuan, paling banyak 2 desimal (misal 22000).',
            'Nama produk contoh baris 5 wajib diisi.',
            'Produk contoh baris 5: harga harus angka Rupiah tanpa titik ribuan, paling banyak 2 desimal (misal 22000).',
            'Produk contoh baris 5: satuan (kosong) tidak ada di daftar satuan template.',
            'Produk contoh baris 6 tidak berbentuk isian produk.',
        ]);
    });

    it('menolak ProdukContoh yang bukan daftar dan yang melebihi 100 item', function (): void {
        $isi = AmbilIsiTemplateAwal('RTL-GEN');
        $isi['ProdukContoh'] = ['Nama' => 'Beras Premium Pulen Wangi Kemasan 5 kg'];
        expect(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe(['Produk contoh harus berupa daftar.']);

        $isi['ProdukContoh'] = array_map(
            fn (int $nomor) => ['Nama' => "Air Mineral Botol 600 ml Varian {$nomor}", 'Kategori' => 'Minuman', 'Harga' => '3500', 'KodeSatuan' => 'BOTOL', 'Jenis' => 'Stok'],
            range(1, 101),
        );
        expect(AmbilPesanValidasi($isi, 'ProdukContoh'))->toBe(['Produk contoh paling banyak 100 item.']);
    });
});

describe('Kunci peran lama di versi terbit (BR-P03.4, DesainF01 H1)', function (): void {
    it('pemetaan dengan kunci lama PiutangSettlement/Waste tetap lolos dan terhitung terisi', function (): void {
        TerbitkanPpnUji();
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        $isi['PemetaanAkun']['PiutangSettlement'] = $isi['PemetaanAkun'][PeranAkun::PiutangPencairan->value];
        $isi['PemetaanAkun']['Waste'] = $isi['PemetaanAkun'][PeranAkun::SusutPersediaan->value];
        unset($isi['PemetaanAkun'][PeranAkun::PiutangPencairan->value], $isi['PemetaanAkun'][PeranAkun::SusutPersediaan->value]);

        expect(AmbilPesanValidasi($isi))->toBe([]);
    });

    it('kunci lama tetap diperiksa tipe akunnya, dan kunci lama + baru sekaligus dilaporkan ganda', function (): void {
        $isi = AmbilIsiTemplateAwal('FNB-QSR');
        unset($isi['PemetaanAkun'][PeranAkun::SusutPersediaan->value]);
        $isi['PemetaanAkun']['Waste'] = '6-3000';
        $isi['PemetaanAkun']['PiutangSettlement'] = '1-1300';

        expect(AmbilPesanValidasi($isi, 'PemetaanAkun'))->toBe([
            'Peran "Piutang pencairan (QRIS/EDC/gateway/ojol)" dipetakan dua kali (kunci PiutangPencairan dan PiutangSettlement). Hapus salah satunya.',
            'Peran "Susut & barang rusak" harus memakai akun HPP, bukan Beban (6-3000).',
        ]);
    });
});
