<?php

declare(strict_types=1);

use App\Domain\Bersama\Laporan\DefinisiLaporan;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Laporan\PenulisCsvDefinisi;
use App\Domain\Bersama\Laporan\PenulisXlsxLaporan;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\StreamedResponse;

function BuatDefinisiUji(?callable $baris = null, ?string $logo = null): DefinisiLaporan
{
    return new DefinisiLaporan(
        judul: 'Detail Penjualan',
        namaBerkas: 'detail-penjualan-2026-10-01-2026-10-04',
        namaUsaha: "Lil' Escape Coffee & Eatery",
        cakupan: 'Semua Outlet',
        saringan: [['Periode', '01 Oktober 2026 - 04 Oktober 2026'], ['Jenis Order', 'Semua Jenis Order']],
        ringkasan: [
            new ItemRingkasan('Total Penjualan', '20818500.00', JenisKolom::Uang),
            new ItemRingkasan('Total Transaksi', 340, JenisKolom::Bilangan),
            new ItemRingkasan('Margin', '12.5', JenisKolom::Persen),
        ],
        kolom: [
            new KolomLaporan('No Transaksi'),
            new KolomLaporan('Waktu Order', JenisKolom::TanggalWaktu),
            new KolomLaporan('Produk', JenisKolom::Teks, 30),
            new KolomLaporan('Qty', JenisKolom::Kuantitas, jumlahkan: true),
            new KolomLaporan('Total (Rp)', JenisKolom::Uang, jumlahkan: true),
        ],
        baris: $baris ?? fn (): iterable => [
            ['CS/01/261001/0001', '2026-10-01 08:24:35', 'Strawberry Matcha Latte', '1', '18000.00'],
            ['CS/01/261001/0002', '2026-10-01 08:26:47', '=HYPERLINK("http://jahat")', '2.5', '22000.50'],
            ['CS/01/261001/0003', null, 'Es & <Kopi> "Susu"', '1', '0.10'],
        ],
        zonaWaktu: 'Asia/Jakarta',
        dataTerakhir: new DateTimeImmutable('2026-10-04 15:37:10', new DateTimeZone('UTC')),
        dibuatPada: new DateTimeImmutable('2026-10-04 15:37:16', new DateTimeZone('UTC')),
        logo: $logo,
    );
}

/** @return array<string, string> */
function BacaIsiXlsx(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $isi = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = (string) $zip->getNameIndex($i);
        $isi[$nama] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $isi;
}

describe('Penulis Excel laporan (D-43)', function (): void {
    it('menulis kop, filter, ringkasan, header beku, filter otomatis, dan jumlah sesuai tata letak Majoo', function (): void {
        $path = PenulisXlsxLaporan::Tulis(BuatDefinisiUji());
        $berkas = BacaIsiXlsx($path);
        PenulisXlsxLaporan::Bersihkan($path);

        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'docProps/core.xml'] as $bagian) {
            expect($berkas)->toHaveKey($bagian);
            $dokumen = new DOMDocument;
            expect($dokumen->loadXML($berkas[$bagian]))->toBeTrue("XML {$bagian} harus valid");
        }

        $sheet = $berkas['xl/worksheets/sheet1.xml'];

        // Kop & blok.
        expect($sheet)->toContain('Detail Penjualan')
            ->and($sheet)->toContain("Lil' Escape Coffee &amp; Eatery")
            ->and($sheet)->toContain('Semua Outlet')
            ->and($sheet)->toContain('Periode')
            ->and($sheet)->toContain('Asia/Jakarta (GMT +7)')
            ->and($sheet)->toContain('Dibuat 04/10/2026 - 22:37:16 WIB')
            ->and($sheet)->toContain('Data terakhir diperbarui 04/10/2026 - 22:37:10 WIB');

        // Ringkasan: uang sebagai angka asli tanpa float.
        expect($sheet)->toContain('<v>20818500.00</v>')->and($sheet)->toContain('<v>340</v>');

        // Blok mulai baris 4 (3 saringan termasuk Zona Waktu, 3 ringkasan) berakhir baris 6; header di baris 11.
        expect($sheet)->toContain('ySplit="11"')
            ->and($sheet)->toContain('state="frozen"')
            ->and($sheet)->toContain('<autoFilter ref="A11:E14"')
            ->and($sheet)->toContain('orientation="landscape"')
            ->and($sheet)->toContain('fitToWidth="1"');

        // Data: tanggal & uang sebagai angka, tidak ada float; teks berformula tetap teks.
        expect($sheet)->toContain('<v>22000.50</v>')->and($sheet)->toContain('<v>0.10</v>');
        expect($sheet)->toContain('=HYPERLINK(&quot;http://jahat&quot;)')->not->toContain('<f>');
        expect($sheet)->toContain('Es &amp; &lt;Kopi&gt;');

        // Tanggal Excel: 2026-10-01 08:24:35 = 46296 + 0.35...
        expect($sheet)->toMatch('/<v>46296\.3504/');

        // Baris jumlah memakai penjumlahan desimal persis: 18000.00 + 22000.50 + 0.10 = 40000.60; qty 1 + 2.5 + 1 = 4.5.
        expect($sheet)->toContain('<v>40000.60</v>')->and($sheet)->toContain('<v>4.5</v>')->and($sheet)->toContain('Jumlah');
    });

    it('menyisipkan logo usaha dan logo Payoung sebagai gambar bila tersedia', function (): void {
        $gambar = imagecreatetruecolor(200, 100);
        ob_start();
        imagepng($gambar);
        $png = (string) ob_get_clean();

        $path = PenulisXlsxLaporan::Tulis(BuatDefinisiUji(logo: $png));
        $berkas = BacaIsiXlsx($path);
        PenulisXlsxLaporan::Bersihkan($path);

        expect($berkas)->toHaveKey('xl/drawings/drawing1.xml')->and($berkas)->toHaveKey('xl/media/image1.png');
        expect($berkas['xl/worksheets/sheet1.xml'])->toContain('<drawing r:id="rId1"/>');
        $dokumen = new DOMDocument;
        expect($dokumen->loadXML($berkas['xl/drawings/drawing1.xml']))->toBeTrue();
        // Logo usaha 200x100 -> tinggi 44 px = 44 * 9525 EMU, lebar 88 px.
        expect($berkas['xl/drawings/drawing1.xml'])->toContain('cx="838200" cy="419100"');
    });

    it('tanpa logo tetap jadi tanpa berkas gambar rusak', function (): void {
        $path = PenulisXlsxLaporan::Tulis(BuatDefinisiUji(logo: 'bukan-gambar'));
        $berkas = BacaIsiXlsx($path);
        PenulisXlsxLaporan::Bersihkan($path);

        expect($berkas['xl/worksheets/sheet1.xml'])->toContain('Detail Penjualan');
        foreach (array_keys($berkas) as $nama) {
            expect($nama)->not->toBe('xl/media/image1.png-rusak');
        }
    });

    it('membaca balik lewat pembaca XLSX dan nilai selnya cocok', function (): void {
        $path = PenulisXlsxLaporan::Tulis(BuatDefinisiUji());
        $pembaca = new Reader;
        $pembaca->open($path);
        $semua = [];

        foreach ($pembaca->getSheetIterator() as $lembar) {
            expect($lembar->getName())->toBe('Detail Penjualan');
            foreach ($lembar->getRowIterator() as $baris) {
                $semua[] = array_map(fn ($sel) => $sel->getValue(), $baris->getCells());
            }
        }

        $pembaca->close();
        PenulisXlsxLaporan::Bersihkan($path);

        $header = array_values(array_filter($semua, fn (array $b): bool => in_array('No Transaksi', $b, true)));
        expect($header)->toHaveCount(1);
        $iData = array_search('CS/01/261001/0001', array_column($semua, 0), true);
        expect($iData)->not->toBeFalse();
        expect($semua[$iData][2])->toBe('Strawberry Matcha Latte');
        expect((string) $semua[$iData][4])->toBe('18000');
    });

    it('menolak lembar terlalu besar dan membersihkan folder sementara', function (): void {
        $sebelum = glob(sys_get_temp_dir().'/payoung-laporan-*') ?: [];
        $definisi = BuatDefinisiUji(function (): iterable {
            for ($i = 0; $i < 1_100_000; $i++) {
                yield ['x', null, 'y', '1', '1.00'];
            }
        });

        expect(fn () => PenulisXlsxLaporan::Tulis($definisi))->toThrow(RuntimeException::class);
        expect(glob(sys_get_temp_dir().'/payoung-laporan-*') ?: [])->toBe($sebelum);
    })->group('lambat');

    it('mengalirkan berkas dengan header unduhan yang benar dan menghapus berkas sementara', function (): void {
        $respons = PenulisXlsxLaporan::Alirkan(BuatDefinisiUji());

        expect($respons)->toBeInstanceOf(StreamedResponse::class)
            ->and($respons->headers->get('Content-Type'))->toBe(PenulisXlsxLaporan::TIPE_KONTEN)
            ->and($respons->headers->get('Content-Disposition'))->toBe('attachment; filename="detail-penjualan-2026-10-01-2026-10-04.xlsx"')
            ->and($respons->headers->get('X-Content-Type-Options'))->toBe('nosniff');

        ob_start();
        $respons->sendContent();
        $isi = (string) ob_get_clean();
        expect(substr($isi, 0, 2))->toBe('PK');
        expect(glob(sys_get_temp_dir().'/payoung-laporan-*') ?: [])->toBe([]);
    });
});

describe('CSV data mentah dari definisi laporan', function (): void {
    it('hanya header dan baris, angka bertitik, waktu di zona laporan, dan formula dinetralkan', function (): void {
        $definisi = BuatDefinisiUji(fn (): iterable => [
            ['CS/1', new DateTimeImmutable('2026-10-01 01:24:35', new DateTimeZone('UTC')), '=1+1', '1', '18000.00'],
        ]);
        ob_start();
        PenulisCsvDefinisi::Alirkan($definisi)->sendContent();
        $isi = (string) ob_get_clean();

        expect($isi)->toStartWith("\xEF\xBB\xBF")
            ->and($isi)->toContain("\"No Transaksi\",\"Waktu Order\",Produk,Qty,\"Total (Rp)\"\n")
            ->and($isi)->toContain('CS/1,"2026-10-01 08:24:35",\'=1+1,1,18000.00')
            ->and($isi)->not->toContain('Detail Penjualan');
    });
});
