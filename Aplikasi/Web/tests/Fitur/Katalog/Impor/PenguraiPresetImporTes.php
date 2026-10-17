<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Impor\Layanan\PembacaBerkasTabel;
use App\Domain\Katalog\Impor\Layanan\PembacaPresetImpor;
use App\Domain\Katalog\Impor\Layanan\PemetaKolomOtomatis;
use App\Domain\Katalog\Impor\Layanan\PenguraiNilaiImpor;
use App\Domain\Katalog\Impor\Layanan\PenulisTabel;

describe('F-03 BR-03.6 pengurai angka Indonesia (deterministik)', function (): void {
    it('uang: 15000, 15.000, Rp 15.000, 15.000,50, 15000.50, 1.250.000, sel angka Excel → nilai persis', function (string $masukan, string $harapan): void {
        expect(PenguraiNilaiImpor::UraiUang($masukan)?->KeString())->toBe($harapan);
    })->with([
        ['15000', '15000.00'],
        ['15.000', '15000.00'],
        ['Rp 15.000', '15000.00'],
        ['Rp15.000,50', '15000.50'],
        ['15.000,50', '15000.50'],
        ['15000.50', '15000.50'],
        ['1.250.000', '1250000.00'],
        ['1.250.000,00', '1250000.00'],
        ['IDR 2.500', '2500.00'],
        ['1250000,5', '1250000.50'],
        ['0', '0.00'],
        ['9999999999999999,99', '9999999999999999.99'],
    ]);

    it('uang ditolak: negatif, ambigu, terlalu banyak desimal, bukan angka', function (string $masukan): void {
        expect(fn () => PenguraiNilaiImpor::UraiUang($masukan))->toThrow(InvalidArgumentException::class);
    })->with(['-5000', '(5000)', '15000.5', '15.00.0', '15,000,00', '12,345', 'lima ribu', '10000000000000000']);

    it('kosong = null (tidak diubah)', function (): void {
        expect(PenguraiNilaiImpor::UraiUang(''))->toBeNull()->and(PenguraiNilaiImpor::UraiKuantitas('  '))->toBeNull();
    });

    it('jumlah: 12, 12,5, 1.5, 0.25, 12.5000, 1.000 (ribuan), 1.500,25', function (string $masukan, string $harapan): void {
        expect(PenguraiNilaiImpor::UraiKuantitas($masukan)?->KeString())->toBe($harapan);
    })->with([
        ['12', '12.0000'],
        ['12,5', '12.5000'],
        ['1.5', '1.5000'],
        ['0.25', '0.2500'],
        ['12.5000', '12.5000'],
        ['1.000', '1000.0000'],
        ['1.500,25', '1500.2500'],
    ]);

    it('sel angka Excel diubah ke teks berkoma desimal tanpa pembulatan', function (): void {
        expect(PembacaBerkasTabel::KeTeks(15000))->toBe('15000')
            ->and(PembacaBerkasTabel::KeTeks(15000.5))->toBe('15000,5')
            ->and(PenguraiNilaiImpor::UraiUang(PembacaBerkasTabel::KeTeks(15000.5))?->KeString())->toBe('15000.50')
            ->and(PembacaBerkasTabel::KeTeks(true))->toBe('true')
            ->and(PembacaBerkasTabel::KeTeks("'=1+1"))->toBe('=1+1')
            ->and(PembacaBerkasTabel::KeTeks("  Kopi\u{00A0}Susu "))->toBe('Kopi Susu');
    });

    it('format ekspor dibaca ulang sebagai nilai yang sama (round trip)', function (): void {
        expect(PenguraiNilaiImpor::FormatUangEkspor('15000.00'))->toBe('15000')
            ->and(PenguraiNilaiImpor::FormatUangEkspor('15000.50'))->toBe('15000.50')
            ->and(PenguraiNilaiImpor::FormatKuantitasEkspor('12.0000'))->toBe('12')
            ->and(PenguraiNilaiImpor::FormatKuantitasEkspor('1.5000'))->toBe('1.5')
            ->and(PenguraiNilaiImpor::FormatKuantitasEkspor('1.2500'))->toBe('1.25')
            ->and(PenguraiNilaiImpor::FormatKuantitasEkspor('0.1250'))->toBe('0.1250')
            ->and(PenguraiNilaiImpor::UraiKuantitas(PenguraiNilaiImpor::FormatKuantitasEkspor('0.1250'))?->KeString())->toBe('0.1250')
            ->and(PenguraiNilaiImpor::UraiUang(PenguraiNilaiImpor::FormatUangEkspor('15000.50'))?->KeString())->toBe('15000.50');
    });

    it('formula injection: sel ekspor yang diawali = + - @ diberi awalan kutip', function (): void {
        expect(PenulisTabel::NetralkanRumus('=HYPERLINK("http://jahat")'))->toBe('\'=HYPERLINK("http://jahat")')
            ->and(PenulisTabel::NetralkanRumus('+62811'))->toBe("'+62811")
            ->and(PenulisTabel::NetralkanRumus('-5'))->toBe("'-5")
            ->and(PenulisTabel::NetralkanRumus('@SUM(A1)'))->toBe("'@SUM(A1)")
            ->and(PenulisTabel::NetralkanRumus('Kopi = enak'))->toBe('Kopi = enak');
    });
});

describe('F-03 preset impor (data JSON, H.10)', function (): void {
    it('semua berkas preset valid menurut skema; hanya Umum yang bukan asumsi', function (): void {
        $berkas = glob(PembacaPresetImpor::AmbilFolder().'/*.json') ?: [];
        expect(array_map(fn (string $p): string => basename($p, '.json'), $berkas))->toEqualCanonicalizing(['Umum', 'Majoo', 'Moka', 'Pawoon']);

        foreach ($berkas as $path) {
            expect(PembacaPresetImpor::Validasi(PembacaPresetImpor::BacaJson($path), basename($path, '.json')))->toBe([]);
        }

        $preset = app(PembacaPresetImpor::class)->AmbilSemua();
        expect($preset[0]->kode)->toBe('Umum')
            ->and($preset[0]->asumsi)->toBeFalse()
            ->and(array_map(fn ($p): bool => $p->asumsi, array_slice($preset, 1)))->toBe([true, true, true]);

        // Setiap judul templat Umum dikenali preset Umum sendiri (round trip ekspor → impor).
        $umum = app(PembacaPresetImpor::class)->Ambil('Umum');
        foreach (BidangImpor::cases() as $bidang) {
            expect($umum->kolom[$bidang->value] ?? [])->toContain($bidang->AmbilJudul());
        }
    });

    it('validator skema menolak preset rusak', function (): void {
        $galat = PembacaPresetImpor::Validasi(['Kode' => 'contoh', 'VersiFormat' => 2, 'Kolom' => ['Harga' => 'x'], 'Varian' => ['Mode' => 'Acak']]);

        expect($galat)->toContain('Kode wajib PascalCase', 'VersiFormat harus 1', 'Asumsi wajib boolean', 'Kolom wajib objek dan memetakan Nama', 'Varian.Mode harus Tidak, KolomInduk, atau BarisPerVarian');
    });

    it('pemetaan otomatis per preset memakai alias (Moka: Item Name/Price/Category; judul dinormalisasi)', function (): void {
        $kolom = [
            ['Indeks' => 0, 'Judul' => 'Category Name', 'Contoh' => []],
            ['Indeks' => 1, 'Judul' => 'ITEM NAME', 'Contoh' => []],
            ['Indeks' => 2, 'Judul' => 'Variant name', 'Contoh' => []],
            ['Indeks' => 3, 'Judul' => 'Price', 'Contoh' => []],
            ['Indeks' => 4, 'Judul' => 'SKU', 'Contoh' => []],
            ['Indeks' => 5, 'Judul' => 'In Stock', 'Contoh' => []],
        ];
        $pemetaan = app(PemetaKolomOtomatis::class)->Petakan($kolom, app(PembacaPresetImpor::class)->Ambil('Moka'));

        expect($pemetaan)->toMatchArray(['Kategori' => 0, 'Nama' => 1, 'Varian' => 2, 'HargaJual' => 3, 'Sku' => 4, 'Stok' => 5, 'Barcode' => null])
            ->and(count($pemetaan))->toBe(count(BidangImpor::cases()));

        $majoo = app(PemetaKolomOtomatis::class)->Petakan([['Indeks' => 0, 'Judul' => 'Nama Item', 'Contoh' => []], ['Indeks' => 1, 'Judul' => 'Harga', 'Contoh' => []]], app(PembacaPresetImpor::class)->Ambil('Majoo'));
        expect($majoo)->toMatchArray(['Nama' => 0, 'HargaJual' => 1]);
    });

    it('judul templat berpenanda wajib ("Nama Produk *") tetap dikenali pemetaan otomatis', function (): void {
        $kolom = [['Indeks' => 0, 'Judul' => 'Nama Produk *', 'Contoh' => []], ['Indeks' => 1, 'Judul' => 'Harga Jual', 'Contoh' => []]];

        $pemetaan = app(PemetaKolomOtomatis::class)->Petakan($kolom, app(PembacaPresetImpor::class)->Ambil('Umum'));

        expect($pemetaan['Nama'])->toBe(0)->and($pemetaan['HargaJual'])->toBe(1);
    });

    it('jenis dikenali dari nilai, label, atau kata preset', function (): void {
        $umum = app(PembacaPresetImpor::class)->Ambil('Umum');

        expect(PenguraiNilaiImpor::UraiJenis('Jasa', $umum))->toBe(JenisProduk::Jasa)
            ->and(PenguraiNilaiImpor::UraiJenis('bahan baku', $umum))->toBe(JenisProduk::BahanBaku)
            ->and(PenguraiNilaiImpor::UraiJenis('Barang', $umum))->toBe(JenisProduk::Stok)
            ->and(PenguraiNilaiImpor::UraiJenis('Tanpa stok', $umum))->toBe(JenisProduk::NonStok)
            ->and(PenguraiNilaiImpor::UraiVarian('Ukuran: M; Warna: Merah', $umum))->toBe([['Nama' => 'Ukuran', 'Nilai' => 'M'], ['Nama' => 'Warna', 'Nilai' => 'Merah']])
            ->and(PenguraiNilaiImpor::UraiVarian('Jumbo', $umum))->toBe([['Nama' => 'Varian', 'Nilai' => 'Jumbo']])
            ->and(PenguraiNilaiImpor::UraiBarcode('8991234567890; 8991234567891|8991234567890'))->toBe(['8991234567890', '8991234567891']);
    });
});
