<?php

declare(strict_types=1);

use App\Domain\Bersama\Laporan\DefinisiLaporan;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Laporan\LembarBuku;
use App\Domain\Bersama\Laporan\PenulisXlsxLaporan;
use App\Domain\Bersama\Laporan\RumusExcel;

/*
 * Buku kerja Excel banyak lembar (D-255 tahap 2): beberapa lembar dalam satu berkas, sel berumus yang membawa nilai
 * tersimpan, lembar tersembunyi, baris Jumlah berumus, dan rumus yang aman ditulis (tanda & dan < di rumus, tanpa
 * isi data yang dijalankan sebagai rumus).
 */
function BuatLembarUji(string $judul, callable $baris, array $ringkasan = [], bool $jumlahBerumus = false): DefinisiLaporan
{
    return new DefinisiLaporan(
        judul: $judul,
        namaBerkas: 'buku-uji',
        namaUsaha: 'Kopi Senja',
        cakupan: 'Semua Outlet',
        saringan: [['Periode', '01-10-2026 s.d. 31-10-2026']],
        ringkasan: $ringkasan,
        kolom: [
            new KolomLaporan('Kategori', JenisKolom::Teks, 30),
            new KolomLaporan('Jumlah', JenisKolom::Uang, 22, true),
            new KolomLaporan('Persen', JenisKolom::Persen, 22),
        ],
        baris: $baris(...),
        zonaWaktu: 'Asia/Jakarta',
        dibuatPada: new DateTimeImmutable('2026-11-01 08:15:00', new DateTimeZone('UTC')),
        jumlahBerumus: $jumlahBerumus,
    );
}

/** @return array<string, string> */
function BacaIsiBuku(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $isi = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $isi[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $isi;
}

describe('Penulis Excel buku banyak lembar (D-255)', function (): void {
    it('menulis tiga lembar bernama, lembar tersembunyi bertanda hidden, dan buku dihitung ulang saat dibuka', function (): void {
        $a = new LembarBuku(BuatLembarUji('Ringkasan', fn (): array => [['Penjualan', '100.00', null]]), 'Ringkasan');
        $b = new LembarBuku(BuatLembarUji('Pemasukan', fn (): array => [['Penjualan', new RumusExcel('SUM(1,2)', '3.00'), null]]), 'Pemasukan', true);
        $c = new LembarBuku(BuatLembarUji('Hutang & piutang', fn (): array => []), PenulisXlsxLaporan::AmankanNamaSheet('Hutang & piutang'));

        $path = PenulisXlsxLaporan::TulisBuku([$a, $b, $c]);
        $berkas = BacaIsiBuku($path);
        PenulisXlsxLaporan::Bersihkan($path);

        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/workbook.xml', '[Content_Types].xml', 'xl/_rels/workbook.xml.rels'] as $bagian) {
            expect($berkas)->toHaveKey($bagian);
            expect((new DOMDocument)->loadXML($berkas[$bagian]))->toBeTrue("XML {$bagian} harus valid");
        }

        $buku = $berkas['xl/workbook.xml'];
        expect($buku)->toContain('<sheet name="Ringkasan" sheetId="1" r:id="rId1"/>')
            ->toContain('<sheet name="Pemasukan" sheetId="2" state="hidden" r:id="rId2"/>')
            ->toContain('<sheet name="Hutang &amp; piutang" sheetId="3" r:id="rId3"/>')
            ->toContain('localSheetId="2"')
            ->toContain('<calcPr calcId="191029" fullCalcOnLoad="1"/>');
        // Hanya lembar pertama yang terpilih saat dibuka.
        expect($berkas['xl/worksheets/sheet1.xml'])->toContain('tabSelected="1"')
            ->and($berkas['xl/worksheets/sheet2.xml'])->not->toContain('tabSelected');
        expect($berkas['xl/_rels/workbook.xml.rels'])->toContain('Target="worksheets/sheet3.xml"')->toContain('Target="styles.xml"');
    });

    it('sel berumus membawa rumus dan nilai tersimpan, tanda & dan < di rumus lolos escape, teks kosong jadi str', function (): void {
        $lembar = new LembarBuku(BuatLembarUji('Pemasukan', fn (): array => [
            ['Penjualan', new RumusExcel('SUMIFS(\'Transaksi\'!$F$5:$F$9,\'Transaksi\'!$A$5:$A$9,">="&$A5,\'Transaksi\'!$A$5:$A$9,"<"&$A5+1)', '1250000.50'), new RumusExcel('IF(B5=0,"",B5/10*100)', '')],
        ]), 'Pemasukan');

        $path = PenulisXlsxLaporan::TulisBuku([$lembar]);
        $sheet = BacaIsiBuku($path)['xl/worksheets/sheet1.xml'];
        PenulisXlsxLaporan::Bersihkan($path);

        expect($sheet)->toContain('<f>SUMIFS(\'Transaksi\'!$F$5:$F$9,\'Transaksi\'!$A$5:$A$9,&quot;&gt;=&quot;&amp;$A5,\'Transaksi\'!$A$5:$A$9,&quot;&lt;&quot;&amp;$A5+1)</f><v>1250000.50</v>')
            // Tanda kutip di rumus ditulis sebagai entitas XML. Hasil kosong ditulis sebagai teks, bukan angka.
            ->and($sheet)->toMatch('/<c r="C\d+" s="\d+" t="str"><f>IF\(B5=0,&quot;&quot;,B5\/10\*100\)<\/f><v><\/v><\/c>/');
    });

    it('baris Jumlah berumus memakai SUM atas data dan membawa total yang benar, dengan sel rumus ikut terhitung', function (): void {
        $lembar = new LembarBuku(BuatLembarUji('Pemasukan', fn (): array => [
            ['A', new RumusExcel('1+1', '2.00'), null],
            ['B', '3.50', null],
        ], jumlahBerumus: true), 'Pemasukan');

        $path = PenulisXlsxLaporan::TulisBuku([$lembar]);
        $sheet = BacaIsiBuku($path)['xl/worksheets/sheet1.xml'];
        PenulisXlsxLaporan::Bersihkan($path);

        // Data mulai baris 11 (blok kop kecil), jadi Jumlah di baris 13: 2,00 + 3,50 = 5,50.
        expect($sheet)->toMatch('/<c r="B13" s="\d+"><f>SUM\(B11:B12\)<\/f><v>5\.50<\/v><\/c>/');
    });

    it('item ringkasan kop boleh berumus', function (): void {
        $lembar = new LembarBuku(BuatLembarUji('Pemasukan', fn (): array => [['A', '10.00', null]], [new ItemRingkasan('Total', new RumusExcel('B11', '10.00'), JenisKolom::Uang)]), 'Pemasukan');

        $path = PenulisXlsxLaporan::TulisBuku([$lembar]);
        $sheet = BacaIsiBuku($path)['xl/worksheets/sheet1.xml'];
        PenulisXlsxLaporan::Bersihkan($path);

        expect($sheet)->toMatch('/<f>B11<\/f><v>10\.00<\/v>/');
    });

    it('nama lembar kembar ditolak, buku kosong ditolak, dan posisi baris data pertama sama dengan hasil tulis', function (): void {
        $satu = new LembarBuku(BuatLembarUji('Satu', fn (): array => []), 'Sama');
        $dua = new LembarBuku(BuatLembarUji('Dua', fn (): array => []), 'sama');

        expect(fn () => PenulisXlsxLaporan::TulisBuku([$satu, $dua]))->toThrow(RuntimeException::class, 'Nama lembar kembar')
            ->and(fn () => PenulisXlsxLaporan::TulisBuku([]))->toThrow(RuntimeException::class);

        // Rumus lintas lembar dihitung dari `HitungBarisDataPertama`: harus sama dengan baris header + 1 yang ditulis.
        foreach ([[3, 1, 0], [3, 3, 4], [5, 3, 4], [8, 3, 3], [5, 2, 1]] as [$kolom, $saringan, $ringkasan]) {
            $kolomUji = array_map(fn (int $i): KolomLaporan => new KolomLaporan('K'.$i, JenisKolom::Teks), range(1, $kolom));
            $ringkasanUji = array_map(fn (int $i): ItemRingkasan => new ItemRingkasan('R'.$i, '1.00', JenisKolom::Uang), $ringkasan === 0 ? [] : range(1, $ringkasan));
            $saringanUji = array_map(fn (int $i): array => ['S'.$i, 'x'], range(1, $saringan));
            $definisi = new DefinisiLaporan('Uji', 'uji', 'Usaha', 'Semua', $saringanUji, $ringkasanUji, $kolomUji, fn (): array => [array_fill(0, $kolom, 'x')], dibuatPada: new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')));

            $path = PenulisXlsxLaporan::Tulis($definisi);
            $sheet = BacaIsiBuku($path)['xl/worksheets/sheet1.xml'];
            PenulisXlsxLaporan::Bersihkan($path);
            $pertama = PenulisXlsxLaporan::HitungBarisDataPertama($kolom, $saringan, $ringkasan);

            expect($sheet)->toContain('<c r="A'.$pertama.'" s="12" t="inlineStr">');
        }
    });
});
