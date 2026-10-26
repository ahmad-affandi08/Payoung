<?php

declare(strict_types=1);

use App\Domain\Persediaan\Layanan\PemvalidasiPelacakan;
use Tests\Pendukung\Persediaan\BantuanPelacakan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->produkPelacakan = BantuanPelacakan::SiapkanTenant()['Produk'];
});

/**
 * Jalankan validator untuk produk `$kunciProduk` dari `BantuanPelacakan::SiapkanTenant()` (disiapkan di beforeEach).
 *
 * @param  list<string>  $nomorSeri
 * @return list<array{Bidang: string, Pesan: string}>
 */
function PeriksaBarisPelacakanUji(string $kunciProduk, string $jumlah, ?string $nomorBatch = null, ?string $kedaluwarsa = null, array $nomorSeri = []): array
{
    $produk = test()->produkPelacakan[$kunciProduk];

    return app(PemvalidasiPelacakan::class)->PeriksaBaris(BantuanPelacakan::Info($produk), BantuanPelacakan::Baris($produk, $jumlah, $nomorBatch, $kedaluwarsa, $nomorSeri));
}

describe('F-05a PemvalidasiPelacakan (DesainF05a C.4, H-3)', function (): void {
    it('baris valid tidak menghasilkan masalah', function (string $kunciProduk, string $jumlah, ?string $nomorBatch, ?string $kedaluwarsa, array $nomorSeri): void {
        expect(PeriksaBarisPelacakanUji($kunciProduk, $jumlah, $nomorBatch, $kedaluwarsa, $nomorSeri))->toBe([]);
    })->with([
        'tanpa pelacakan' => ['Stok', '125.5', null, null, []],
        'bahan baku desimal' => ['BahanBaku', '12.75', null, null, []],
        'batch + kedaluwarsa' => ['Batch', '48', 'UHT-2026-0917A', '2027-03-17', []],
        'batch 60 karakter' => ['Batch', '1', str_repeat('B', 60), '2027-03-17', []],
        'seri 3 unit' => ['Seri', '3', null, null, ['RC18-0001', 'RC18-0002', 'RC18-0003']],
        'seri 100 karakter' => ['Seri', '1', null, null, [str_repeat('S', 100)]],
    ]);

    it('batch: nomor batch wajib (≤ 60) dan kedaluwarsa wajib (H-3). Nomor seri harus kosong', function (): void {
        expect(PeriksaBarisPelacakanUji('Batch', '48', '  ', null))->toBe([
            ['Bidang' => 'NomorBatch', 'Pesan' => 'Isi nomor batch untuk Susu UHT Full Cream 1 Liter (batch & kedaluwarsa).'],
            ['Bidang' => 'TanggalKedaluwarsa', 'Pesan' => 'Isi tanggal kedaluwarsa batch untuk Susu UHT Full Cream 1 Liter (batch & kedaluwarsa).'],
        ])->and(PeriksaBarisPelacakanUji('Batch', '48', str_repeat('B', 61), '2027-03-17'))->toBe([
            ['Bidang' => 'NomorBatch', 'Pesan' => 'Nomor batch maksimal 60 karakter.'],
        ])->and(array_column(PeriksaBarisPelacakanUji('Batch', '1', 'UHT-1', '2027-03-17', ['SN-1']), 'Bidang'))->toBe(['NomorSeri']);
    });

    it('batch: kedaluwarsa opsional bila WajibKedaluwarsaBatch dimatikan', function (): void {
        config(['persediaan.StokAwal.WajibKedaluwarsaBatch' => false]);

        expect(PeriksaBarisPelacakanUji('Batch', '48', 'UHT-2026-0917A', null))->toBe([]);
    });

    it('seri: jumlah nomor seri harus sama dengan jumlah unit', function (): void {
        expect(PeriksaBarisPelacakanUji('Seri', '3', null, null, ['RC18-0001', 'RC18-0002']))->toBe([
            ['Bidang' => 'NomorSeri', 'Pesan' => 'Jumlah 3 tetapi nomor seri yang diisi 2. Isi satu nomor seri per unit.'],
        ]);
    });

    it('seri: jumlah harus bilangan bulat', function (): void {
        expect(PeriksaBarisPelacakanUji('Seri', '1.5', null, null, ['RC18-0001']))->toBe([
            ['Bidang' => 'Jumlah', 'Pesan' => 'Jumlah produk bernomor seri harus bilangan bulat.'],
        ]);
    });

    it('seri: nomor di-trim, 1–100 karakter, unik di baris tanpa beda huruf besar/kecil', function (): void {
        $galat = PeriksaBarisPelacakanUji('Seri', '4', null, null, ['rc18-0001', ' RC18-0001 ', '', str_repeat('S', 101)]);

        expect($galat)->toBe([
            ['Bidang' => 'NomorSeri', 'Pesan' => 'Setiap nomor seri 1–100 karakter dan tidak boleh kosong.'],
            ['Bidang' => 'NomorSeri', 'Pesan' => 'Nomor seri ganda di baris ini: RC18-0001.'],
        ]);
    });

    it('seri: maksimal MaksimalNomorSeriPerBaris nomor per baris. Batch & kedaluwarsa harus kosong', function (): void {
        config(['persediaan.StokAwal.MaksimalNomorSeriPerBaris' => 2]);
        $tiga = ['RC18-0001', 'RC18-0002', 'RC18-0003'];

        expect(PeriksaBarisPelacakanUji('Seri', '3', null, null, $tiga))->toBe([
            ['Bidang' => 'NomorSeri', 'Pesan' => 'Maksimal 2 nomor seri per baris. Pecah menjadi beberapa dokumen stok awal.'],
        ])->and(array_column(PeriksaBarisPelacakanUji('Seri', '1', 'B-01', '2027-01-01', ['RC18-0001']), 'Bidang'))->toBe(['NomorBatch']);
    });

    it('tanpa pelacakan: batch, kedaluwarsa, dan nomor seri harus kosong', function (): void {
        expect(PeriksaBarisPelacakanUji('Stok', '2', 'B-01', null, ['SN-1']))->toBe([
            ['Bidang' => 'NomorBatch', 'Pesan' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter tidak dilacak per batch. Kosongkan nomor batch dan kedaluwarsa.'],
            ['Bidang' => 'NomorSeri', 'Pesan' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter tidak dilacak per nomor seri. Kosongkan nomor seri.'],
        ])->and(array_column(PeriksaBarisPelacakanUji('Stok', '2', null, '2027-01-01'), 'Bidang'))->toBe(['NomorBatch']);
    });
});
