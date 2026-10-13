<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Persediaan\Data\HasilBarisMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\BidangImporStokAwal;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use Brick\Math\BigDecimal;

describe('F-05a enum persediaan (DesainF05a B.4)', function (): void {
    it('BR-05.1: JenisMutasi punya 18 jenis (16 F-05 + 2 penilaian ulang biaya tambahan v3.41); StokAwal dua arah, sisanya satu arah sesuai tabel', function (): void {
        $masuk = ['StokAwal', 'PenerimaanPembelian', 'ReturPenjualan', 'TransferMasuk', 'PenyesuaianMasuk', 'OpnameLebih', 'ProduksiHasil', 'KonsinyasiMasuk', 'RevaluasiMasuk'];
        $keluar = ['StokAwal', 'ReturPembelian', 'Penjualan', 'TransferKeluar', 'PenyesuaianKeluar', 'OpnameKurang', 'ProduksiPakai', 'Susut', 'KonsinyasiRetur', 'RevaluasiKeluar'];

        expect(JenisMutasi::cases())->toHaveCount(18);

        foreach (JenisMutasi::cases() as $jenis) {
            expect($jenis->CekBolehMasuk())->toBe(in_array($jenis->value, $masuk, true), $jenis->value)
                ->and($jenis->CekBolehKeluar())->toBe(in_array($jenis->value, $keluar, true), $jenis->value)
                ->and($jenis->CekBolehMasuk() || $jenis->CekBolehKeluar())->toBeTrue()
                ->and($jenis->AmbilLabel())->not->toBe('');
        }
    });

    it('StatusStokAwal: Draf → Memproses|Diposting|Dibuang, Memproses → Diposting|Draf, Diposting → Dibatalkan; Dibatalkan & Dibuang final', function (): void {
        $boleh = [
            'Draf' => ['Memproses', 'Diposting', 'Dibuang'],
            'Memproses' => ['Diposting', 'Draf'],
            'Diposting' => ['Dibatalkan'],
            'Dibatalkan' => [],
            'Dibuang' => [],
        ];

        foreach (StatusStokAwal::cases() as $dari) {
            foreach (StatusStokAwal::cases() as $ke) {
                expect($dari->BisaBerubahKe($ke))->toBe(in_array($ke->value, $boleh[$dari->value], true), "{$dari->value} → {$ke->value}");
            }
        }

        expect(StatusStokAwal::Dibatalkan->CekFinal())->toBeTrue()
            ->and(StatusStokAwal::Dibuang->CekFinal())->toBeTrue()
            ->and(StatusStokAwal::Diposting->CekFinal())->toBeFalse();
    });

    it('StatusImporStokAwal sama persis dengan StatusImporProduk (nilai & perpindahan) agar LangkahImpor dipakai ulang', function (): void {
        expect(array_map(fn (StatusImporStokAwal $s) => $s->value, StatusImporStokAwal::cases()))
            ->toBe(array_map(fn (StatusImporProduk $s) => $s->value, StatusImporProduk::cases()));

        foreach (StatusImporStokAwal::cases() as $dari) {
            foreach (StatusImporStokAwal::cases() as $ke) {
                expect($dari->BisaBerubahKe($ke))->toBe(StatusImporProduk::from($dari->value)->BisaBerubahKe(StatusImporProduk::from($ke->value)));
            }
        }
    });

    it('BidangImporStokAwal: judul kolom templat Bahasa Indonesia', function (): void {
        expect(array_combine(
            array_map(fn (BidangImporStokAwal $b) => $b->value, BidangImporStokAwal::cases()),
            array_map(fn (BidangImporStokAwal $b) => $b->AmbilLabel(), BidangImporStokAwal::cases()),
        ))->toBe([
            'Sku' => 'SKU',
            'Barcode' => 'Barcode',
            'NamaProduk' => 'Nama Produk',
            'Lokasi' => 'Lokasi Stok',
            'Jumlah' => 'Stok',
            'HargaModal' => 'Harga Modal',
            'NomorBatch' => 'Nomor Batch',
            'TanggalKedaluwarsa' => 'Kedaluwarsa',
            'NomorSeri' => 'Nomor Seri',
        ]);
    });

    it('tautan dokumen sumber hanya untuk dokumen yang halamannya ada (stok awal, penjualan F-07b, void & retur F-09) dan hanya bila Uuid ada', function (): void {
        $uuid = '01K5ZQ8X6T2N3M4P5Q6R7S8T9V';

        expect(JenisReferensiMutasi::StokAwal->BuatTautan($uuid))->toBe("/kelola/persediaan/stok-awal/{$uuid}")
            ->and(JenisReferensiMutasi::StokAwal->BuatTautan(null))->toBeNull()
            ->and(JenisReferensiMutasi::Penjualan->BuatTautan($uuid))->toBe("/kelola/penjualan/{$uuid}")
            ->and(JenisReferensiMutasi::ReturPenjualan->BuatTautan($uuid))->toBe("/kelola/penjualan/retur/{$uuid}")
            ->and(JenisReferensiMutasi::VoidPenjualan->BuatTautan($uuid))->toBe("/kelola/penjualan/{$uuid}")
            // F-05b & F-04: dokumen transfer, opname, penyesuaian, penerimaan, retur pembelian kini punya halaman.
            ->and(JenisReferensiMutasi::TransferStok->BuatTautan($uuid))->toBe("/kelola/persediaan/transfer/{$uuid}")
            ->and(JenisReferensiMutasi::StokOpname->BuatTautan($uuid))->toBe("/kelola/persediaan/opname/{$uuid}")
            ->and(JenisReferensiMutasi::PenyesuaianStok->BuatTautan($uuid))->toBe("/kelola/persediaan/penyesuaian/{$uuid}")
            ->and(JenisReferensiMutasi::PenerimaanBarang->BuatTautan($uuid))->toBe("/kelola/pembelian/penerimaan/{$uuid}")
            ->and(JenisReferensiMutasi::ReturPembelian->BuatTautan($uuid))->toBe("/kelola/pembelian/retur/{$uuid}")
            // F-05e: order produksi.
            ->and(JenisReferensiMutasi::Produksi->BuatTautan($uuid))->toBe('/kelola/persediaan/produksi/'.$uuid)
            // F-05i (v3.40): dokumen titipan kini punya halaman rincian; v3.41 biaya tambahan pembelian.
            ->and(JenisReferensiMutasi::Konsinyasi->BuatTautan($uuid))->toBe('/kelola/pembelian/konsinyasi/dokumen/'.$uuid)
            ->and(JenisReferensiMutasi::BiayaTambahanPembelian->BuatTautan($uuid))->toBe('/kelola/pembelian/biaya-tambahan/'.$uuid)
            ->and(JenisReferensiMutasi::NotaDebitPembelian->BuatTautan($uuid))->toBe('/kelola/pembelian/nota-debit/'.$uuid)
            ->and(JenisSumberJurnal::NotaDebitPembelian->BuatTautan($uuid))->toBe('/kelola/pembelian/nota-debit/'.$uuid)
            ->and(JenisSumberJurnal::ReturPenjualan->BuatTautan($uuid))->toBe("/kelola/penjualan/retur/{$uuid}")
            ->and(JenisSumberJurnal::Penjualan->BuatTautan($uuid))->toBe("/kelola/penjualan/{$uuid}")
            ->and(JenisSumberJurnal::StokAwal->BuatTautan($uuid))->toBe("/kelola/persediaan/stok-awal/{$uuid}")
            ->and(JenisSumberJurnal::TutupShift->BuatTautan($uuid))->toBe("/kelola/kasir/shift/{$uuid}")
            ->and(JenisSumberJurnal::StokAwal->BuatTautan(''))->toBeNull();
    });

    it('JenisDokumenBernomor: SA 4 digit dan JU 6 digit per periode; periode tidak valid ditolak', function (): void {
        expect(JenisDokumenBernomor::StokAwal->FormatNomor('2026-09', 1))->toBe('SA/2026/09/0001')
            ->and(JenisDokumenBernomor::StokAwal->FormatNomor('2026-12', 12345))->toBe('SA/2026/12/12345')
            ->and(JenisDokumenBernomor::Jurnal->FormatNomor('2026-09', 42))->toBe('JU/2026/09/000042');

        expect(fn () => JenisDokumenBernomor::Jurnal->FormatNomor('09/2026', 1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => JenisDokumenBernomor::Jurnal->FormatNomor('2026-09', 0))->toThrow(InvalidArgumentException::class);
    });
});

describe('F-05a DTO (DesainF05a C.2, C.5)', function (): void {
    it('DataBarisJurnal::DariSelisih: positif = debit, negatif = kredit sebesar nilai mutlak, nol = tanpa baris', function (): void {
        $debit = DataBarisJurnal::DariSelisih(PeranAkun::SelisihHpp, Uang::Dari('400.00'), 7);
        $kredit = DataBarisJurnal::DariSelisih(PeranAkun::SelisihHpp, Uang::Dari('-1250000.55'));

        expect($debit?->debit->KeString())->toBe('400.00')
            ->and($debit?->kredit->KeString())->toBe('0.00')
            ->and($debit?->idOutlet)->toBe(7)
            ->and($kredit?->debit->KeString())->toBe('0.00')
            ->and($kredit?->kredit->KeString())->toBe('1250000.55')
            ->and($kredit?->peran)->toBe(PeranAkun::SelisihHpp)
            ->and(DataBarisJurnal::DariSelisih(PeranAkun::SelisihHpp, Uang::Nol()))->toBeNull();
    });

    it('HasilCatatMutasi menjumlahkan TotalHpp, NilaiDiminta, dan Selisih bertanda (contoh BR-04.3 C.3 #3)', function (): void {
        $baris = fn (string $kunci, string $total, string $diminta, string $selisih): HasilBarisMutasi => new HasilBarisMutasi(
            1, $kunci, 1, 1, Kuantitas::Dari('10'), BigDecimal::of('1100'), Uang::Dari($total), Uang::Dari($diminta), Uang::Dari($selisih),
            Kuantitas::Dari('6'), null, null, false,
        );
        $hasil = new HasilCatatMutasi(['P/1' => $baris('P/1', '10600.00', '11000.00', '-400.00'), 'P/2' => $baris('P/2', '3703.70', '3703.70', '0.00')], false);

        expect($hasil->TotalHpp()->KeString())->toBe('14303.70')
            ->and($hasil->TotalNilaiDiminta()->KeString())->toBe('14703.70')
            ->and($hasil->TotalSelisih()->KeString())->toBe('-400.00')
            ->and((new HasilCatatMutasi([], true))->TotalHpp()->KeString())->toBe('0.00');
    });
});
