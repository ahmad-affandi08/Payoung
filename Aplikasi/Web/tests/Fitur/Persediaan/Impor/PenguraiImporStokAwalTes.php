<?php

declare(strict_types=1);

use App\Domain\Persediaan\Enum\BidangImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PemetaKolomImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PenguraiBarisImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PenguraiHppImpor;

describe('F-05a impor stok awal: pengurai harga modal format Indonesia (≤ 6 desimal, tanpa float)', function (): void {
    it('mengurai harga modal sah menjadi BigDecimal skala 6 tanpa pembulatan', function (string $teks, string $harapan): void {
        expect((string) app(PenguraiHppImpor::class)->Urai($teks))->toBe($harapan);
    })->with([
        'bulat' => ['12500', '12500.000000'],
        'titik ribuan' => ['1.250.000', '1250000.000000'],
        'satu titik 3 angka = ribuan' => ['15.000', '15000.000000'],
        'koma desimal 6' => ['1.234,567891', '1234.567891'],
        'koma desimal tanpa ribuan' => ['1234,5678', '1234.567800'],
        'satu titik 1 angka = desimal' => ['1234.5', '1234.500000'],
        'satu titik 2 angka = desimal' => ['0.25', '0.250000'],
        'satu titik 4 angka = desimal' => ['1234.5678', '1234.567800'],
        'satu titik 5 angka = desimal' => ['333.33333', '333.333330'],
        'satu titik 6 angka = desimal' => ['333.333333', '333.333333'],
        'awalan Rp & spasi' => ['Rp 38.500,50', '38500.500000'],
        'nol di belakang koma tidak dihitung' => ['12,50000000', '12.500000'],
        'nol' => ['0', '0.000000'],
        'sel angka Excel (koma desimal dari pembaca)' => ['1234,568', '1234.568000'],
    ]);

    it('kosong = null', function (): void {
        expect(app(PenguraiHppImpor::class)->Urai('  '))->toBeNull();
    });

    it('menolak harga modal tidak sah dengan pesan Indonesia', function (string $teks, string $pesan): void {
        expect(fn () => app(PenguraiHppImpor::class)->Urai($teks))->toThrow(InvalidArgumentException::class, $pesan);
    })->with([
        'negatif' => ['-5000', 'tidak boleh negatif'],
        'kurung' => ['(5000)', 'tidak boleh negatif'],
        'lebih dari 6 desimal' => ['1,1234567', 'maksimal 6 angka'],
        'titik ribuan tidak rapi' => ['1.25.000', 'tidak dikenali'],
        'huruf' => ['dua ribu', 'bukan angka'],
        'dua koma' => ['1,2,3', 'bukan angka'],
        'terlalu besar' => ['12345678901234', 'terlalu besar'],
    ]);
});

describe('F-05a impor stok awal: pengurai baris & pemetaan otomatis', function (): void {
    it('pemetaan otomatis dari judul templat dan sinonim', function (): void {
        $kolom = [];

        foreach (['Kode Barang', 'Nama Barang', 'Satuan', 'Gudang', 'Qty', 'HPP', 'No Batch', 'Expired', 'Serial'] as $i => $judul) {
            $kolom[] = ['Indeks' => $i, 'Judul' => $judul, 'Contoh' => []];
        }

        expect(app(PemetaKolomImporStokAwal::class)->Petakan($kolom))->toBe([
            'Sku' => 0, 'Barcode' => null, 'NamaProduk' => 1, 'Lokasi' => 3, 'Jumlah' => 4, 'HargaModal' => 5,
            'NomorBatch' => 6, 'TanggalKedaluwarsa' => 7, 'NomorSeri' => 8,
        ]);
    });

    it('mengurai stok, harga modal, tanggal kedaluwarsa, dan nomor seri; masalah per kolom berlabel judul', function (): void {
        $pemetaan = ['Sku' => 0, 'Barcode' => null, 'NamaProduk' => 1, 'Lokasi' => 2, 'Jumlah' => 3, 'HargaModal' => 4, 'NomorBatch' => 5, 'TanggalKedaluwarsa' => 6, 'NomorSeri' => 7];
        $pengurai = app(PenguraiBarisImporStokAwal::class);

        $sah = $pengurai->Urai(['RC-18', 'Rice Cooker', 'TK-01', '3', '675.000', '', '', "SN-1, SN-2;SN-3\n"], $pemetaan);
        expect($sah['Galat'])->toBe([])
            ->and($sah['Data'])->toMatchArray(['Sku' => 'RC-18', 'Lokasi' => 'TK-01', 'Jumlah' => '3.0000', 'HargaModal' => '675000.000000', 'NomorSeri' => ['SN-1', 'SN-2', 'SN-3']]);

        $batch = $pengurai->Urai(['SUSU-1', '', '', '12,5', '19.250,125', 'B-2026-09', '31/12/2027', ''], $pemetaan);
        expect($batch['Galat'])->toBe([])
            ->and($batch['Data'])->toMatchArray(['Jumlah' => '12.5000', 'HargaModal' => '19250.125000', 'NomorBatch' => 'B-2026-09', 'TanggalKedaluwarsa' => '2027-12-31']);

        $galat = $pengurai->Urai(['', '', '', '0', '-1', str_repeat('B', 61), '31/02/2027', ''], $pemetaan);
        expect(array_column($galat['Galat'], 'Bidang'))->toBe(['Produk', BidangImporStokAwal::Jumlah->AmbilLabel(), 'Harga Modal', 'Nomor Batch', 'Kedaluwarsa'])
            ->and($galat['Galat'][1]['Pesan'])->toBe('Stok harus lebih dari 0.')
            ->and($galat['Galat'][4]['Pesan'])->toContain('31/02/2027');

        $kosong = $pengurai->Urai(['KOPI-1', '', '', '', '', '', '', ''], $pemetaan);
        expect(array_column($kosong['Galat'], 'Pesan'))->toBe(['Stok wajib diisi.', 'Harga modal wajib diisi. Isi 0 bila barang tidak punya modal.']);
    });
});
