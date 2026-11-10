<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Laporan;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use XMLWriter;
use ZipArchive;

/**
 * Penulis Excel (.xlsx) untuk semua laporan (D-43), tata letak mengikuti kop laporan Majoo dengan perbaikan:
 *
 *  - Kop: judul laporan (kiri), nama usaha + cakupan outlet + logo usaha (kanan).
 *  - Blok saringan (kiri) dan ringkasan angka (kanan), lalu jejak waktu "Dibuat" dan "Data terakhir diperbarui".
 *  - Tabel: header beku, filter otomatis, uang/tanggal sebagai nilai asli Excel (bisa dijumlah dan diurutkan),
 *    baris "Jumlah" bila kolom meminta, kaki "Dibuat dengan Payoung" + logo, cetak A4 landscape pas lebar halaman
 *    dengan header tabel berulang dan nomor halaman.
 *
 * Ditulis sendiri di atas ZipArchive + XMLWriter (bukan openspout) karena: angka uang harus masuk sebagai teks
 * desimal persis ke `<v>` tanpa float (aturan emas #7), logo harus tersisip, dan baris dialirkan ke berkas sementara
 * sehingga memori tetap kecil untuk puluhan ribu baris. Teks ditulis sebagai inline string, jadi isi yang diawali
 * `=`, `+`, `-`, `@` tidak pernah dijalankan sebagai rumus.
 */
final class PenulisXlsxLaporan
{
    public const TIPE_KONTEN = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MAKS_BARIS = 1_048_000;

    // Indeks gaya (cellXfs) di styles.xml di bawah.
    private const G_JUDUL = 1;

    private const G_KOP_TEBAL = 2;

    private const G_KOP = 3;

    private const G_LABEL = 4;

    private const G_NILAI = 5;

    private const G_RING_UANG = 6;

    private const G_RING_BILANGAN = 7;

    private const G_RING_PERSEN = 8;

    private const G_RING_TEKS = 9;

    private const G_CAP = 10;

    private const G_HEADER = 11;

    private const G_TEKS = 12;

    private const G_UANG = 13;

    private const G_BILANGAN = 14;

    private const G_KUANTITAS = 15;

    private const G_PERSEN = 16;

    private const G_TANGGAL = 17;

    private const G_TANGGAL_WAKTU = 18;

    private const G_JUM_TEKS = 19;

    private const G_JUM_UANG = 20;

    private const G_JUM_BILANGAN = 21;

    private const G_JUM_KUANTITAS = 22;

    private const G_LABEL_KANAN = 23;

    public static function Alirkan(DefinisiLaporan $definisi): StreamedResponse
    {
        return self::AlirkanBerkas(self::Tulis($definisi), $definisi->namaBerkas);
    }

    /**
     * Buku kerja Excel banyak lembar (D-255), lembar boleh berumus dan disembunyikan.
     *
     * @param  list<LembarBuku>  $lembar
     */
    public static function AlirkanBuku(array $lembar, string $namaBerkas): StreamedResponse
    {
        return self::AlirkanBerkas(self::TulisBuku($lembar), $namaBerkas);
    }

    private static function AlirkanBerkas(string $berkas, string $namaBerkas): StreamedResponse
    {
        $ukuran = filesize($berkas);

        return new StreamedResponse(function () use ($berkas): void {
            $buka = fopen($berkas, 'rb');

            if ($buka !== false) {
                while (! feof($buka)) {
                    echo fread($buka, 65536);
                }

                fclose($buka);
            }

            self::Bersihkan($berkas);
        }, 200, [
            'Content-Type' => self::TIPE_KONTEN,
            'Content-Disposition' => 'attachment; filename="'.self::AmankanNamaBerkas($namaBerkas).'.xlsx"',
            'Content-Length' => $ukuran === false ? '' : (string) $ukuran,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function Bersihkan(string $berkas): void
    {
        @unlink($berkas);
        @rmdir(dirname($berkas));
    }

    /**
     * Menulis berkas .xlsx ke folder sementara dan mengembalikan path-nya (pemanggil wajib `Bersihkan`).
     */
    public static function Tulis(DefinisiLaporan $d): string
    {
        return self::TulisBuku([new LembarBuku($d, self::AmankanNamaSheet($d->judul))]);
    }

    /**
     * Menulis buku kerja satu atau banyak lembar. Nama lembar harus unik dan sudah aman (`AmankanNamaSheet`), karena
     * rumus lembar lain merujuknya. Bila ada sel `RumusExcel`, buku dihitung ulang penuh saat dibuka.
     *
     * @param  list<LembarBuku>  $lembar
     */
    public static function TulisBuku(array $lembar): string
    {
        $folder = sys_get_temp_dir().'/payoung-laporan-'.bin2hex(random_bytes(8));

        if (! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Folder sementara laporan gagal dibuat.');
        }

        try {
            return self::RakitBuku($lembar, $folder);
        } catch (Throwable $galat) {
            self::BersihkanFolder($folder);

            throw $galat;
        }
    }

    /**
     * Nomor baris Excel data pertama pada sebuah lembar, dari jumlah kolom, baris saringan (tanpa baris Zona Waktu yang
     * selalu ditambahkan penulis), dan item ringkasan. Dipakai penyusun buku kerja untuk menulis rumus yang merujuk lembar
     * lain sebelum lembar itu ditulis. Rumusnya sama dengan `TulisLembar`: jaga keduanya tetap sejalan.
     */
    public static function HitungBarisDataPertama(int $jumlahKolom, int $jumlahSaringan, int $jumlahRingkasan): int
    {
        $saringan = $jumlahSaringan + 1;
        $barisBlok = max(1, $jumlahKolom) >= 5 ? max($saringan, $jumlahRingkasan) : $saringan + $jumlahRingkasan;

        return 3 + $barisBlok + 5 + 1;
    }

    /**
     * @param  list<LembarBuku>  $lembar
     */
    private static function RakitBuku(array $lembar, string $folder): string
    {
        if ($lembar === []) {
            throw new RuntimeException('Buku kerja minimal berisi satu lembar.');
        }

        $dibuat = $lembar[0]->definisi->dibuatPada ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $info = [];
        $berumus = false;
        $namaPakai = [];

        foreach ($lembar as $i => $satu) {
            if (in_array(mb_strtolower($satu->nama), $namaPakai, true)) {
                throw new RuntimeException('Nama lembar kembar: '.$satu->nama);
            }

            $namaPakai[] = mb_strtolower($satu->nama);
            $hasil = self::TulisLembar($satu->definisi, $folder.'/sheet'.($i + 1).'.xml', $i === 0, $dibuat);
            $berumus = $berumus || $hasil['Berumus'];
            $info[$i] = $hasil;
        }

        $path = $folder.'/laporan.xlsx';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Berkas Excel gagal dibuat.');
        }

        $denganGambar = [];

        foreach ($info as $i => $hasil) {
            if ($hasil['Gambar'] !== []) {
                $denganGambar[] = $i + 1;
            }
        }

        $zip->addFromString('[Content_Types].xml', self::TipeKonten(count($lembar), $denganGambar));
        $zip->addFromString('_rels/.rels', self::RelasiPaket());
        $zip->addFromString('docProps/core.xml', self::PropertiInti($lembar[0]->definisi->judul, $dibuat));
        $zip->addFromString('xl/workbook.xml', self::Buku($lembar, $info, $berumus));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::RelasiBuku(count($lembar)));
        $zip->addFromString('xl/styles.xml', self::Gaya());

        // Berkas gambar diberi nomor berurutan di seluruh buku (image1.png, image2.png, …).
        $nomorGambar = 0;

        foreach ($info as $i => $hasil) {
            $nomor = $i + 1;
            $zip->addFile($folder.'/sheet'.$nomor.'.xml', 'xl/worksheets/sheet'.$nomor.'.xml');

            if ($hasil['Gambar'] === []) {
                continue;
            }

            $nomorMedia = [];

            foreach ($hasil['Gambar'] as $g) {
                $nomorGambar++;
                $nomorMedia[] = $nomorGambar;
                $zip->addFromString('xl/media/image'.$nomorGambar.'.png', $g['Png']);
            }

            $zip->addFromString('xl/worksheets/_rels/sheet'.$nomor.'.xml.rels', self::RelasiSheet($nomor));
            $zip->addFromString('xl/drawings/drawing'.$nomor.'.xml', self::Gambar($hasil['Gambar']));
            $zip->addFromString('xl/drawings/_rels/drawing'.$nomor.'.xml.rels', self::RelasiGambar($nomorMedia));
        }

        if (! $zip->close()) {
            throw new RuntimeException('Berkas Excel gagal ditutup.');
        }

        foreach (array_keys($info) as $i) {
            @unlink($folder.'/sheet'.($i + 1).'.xml');
        }

        return $path;
    }

    /**
     * Menulis XML satu lembar ke `$berkasSheet` dan mengembalikan keterangan yang dibutuhkan perakit paket.
     *
     * @return array{BarisHeader: int, AkhirFilter: int, KolomTerakhir: int, Gambar: list<array{Png: string, Kolom: int, Baris: int, Lebar: int, Tinggi: int}>, Berumus: bool}
     */
    private static function TulisLembar(DefinisiLaporan $d, string $berkasSheet, bool $terpilih, DateTimeImmutable $dibuat): array
    {
        $zona = ZonaLaporan::Buat($d->zonaWaktu);
        $jumlahKolom = max(1, count($d->kolom));
        $kolomTerakhir = $jumlahKolom - 1;
        $berumus = false;

        $logoUsaha = GambarLaporan::Siapkan($d->logo, 44);
        $logoPayoung = GambarLaporan::LogoPayoung(26);

        $saringan = [...$d->saringan, ['Zona Waktu', ZonaLaporan::Label($d->zonaWaktu)]];
        $samping = $jumlahKolom >= 5;
        $barisBlok = $samping
            ? max(count($saringan), count($d->ringkasan))
            : count($saringan) + count($d->ringkasan);
        $akhirBlok = 3 + $barisBlok;
        $barisCap1 = $akhirBlok + 2;
        $barisHeader = $akhirBlok + 5;

        // Sel kop dikumpulkan per baris lalu ditulis berurutan.
        /** @var array<int, array<int, array{0: int, 1: string|int|RumusExcel|null, 2: string}>> $kop */
        $kop = [];
        $taruh = static function (int $baris, int $kolom, string|int|RumusExcel|null $nilai, int $gaya, string $jenis = 's') use (&$kop): void {
            $kop[$baris][$kolom] = [$gaya, $nilai, $jenis];
        };

        $taruh(1, 0, $d->judul, self::G_JUDUL);

        for ($i = 1; $i < $jumlahKolom; $i++) {
            $taruh(1, $i, null, self::G_JUDUL, 'e');
        }

        $kolomNama = max(0, $kolomTerakhir - 1);
        $taruh(1, $kolomNama, $d->namaUsaha, self::G_KOP_TEBAL);
        $taruh(2, $kolomNama, $d->cakupan, self::G_KOP);

        $r = 4;

        foreach ($saringan as [$label, $nilai]) {
            $taruh($r, 0, $label, self::G_LABEL);
            $taruh($r, 1, $nilai, self::G_NILAI);
            $r++;
        }

        $r = $samping ? 4 : $r;
        $kolomLabelRingkas = $samping ? $jumlahKolom - 2 : 0;
        $kolomNilaiRingkas = $kolomLabelRingkas + 1;

        foreach ($d->ringkasan as $item) {
            // Rata kanan: label panjang meluap ke kiri (sel kosong) dan tidak terpotong oleh nilai di sebelahnya.
            $taruh($r, $kolomLabelRingkas, $item->label, self::G_LABEL_KANAN);
            [$gaya, $jenisSel] = self::GayaRingkasan($item);

            if ($item->nilai instanceof RumusExcel) {
                $jenisIsi = 'f';
                $berumus = true;
            } else {
                $jenisIsi = $jenisSel === 'n' && self::CekAngkaTeks($item->nilai) ? 'n' : 's';
            }

            $taruh($r, $kolomNilaiRingkas, $item->nilai, $gaya, $jenisIsi);
            $r++;
        }

        $taruh($barisCap1, $kolomTerakhir, 'Dibuat '.ZonaLaporan::Jejak($dibuat, $d->zonaWaktu), self::G_CAP);

        if ($d->dataTerakhir !== null) {
            $taruh($barisCap1 + 1, $kolomTerakhir, 'Data terakhir diperbarui '.ZonaLaporan::Jejak($d->dataTerakhir, $d->zonaWaktu), self::G_CAP);
        }

        // Lembar kerja.
        $x = new XMLWriter;
        $x->openUri($berkasSheet);
        $x->startDocument('1.0', 'UTF-8', 'yes');
        $x->startElement('worksheet');
        $x->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $x->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $x->startElement('sheetPr');
        $x->startElement('pageSetUpPr');
        $x->writeAttribute('fitToPage', '1');
        $x->endElement();
        $x->endElement();
        // dimension ditulis setelah tahu baris terakhir? Elemen opsional, dilewati agar bisa streaming.
        $x->startElement('sheetViews');
        $x->startElement('sheetView');
        $x->writeAttribute('workbookViewId', '0');
        $x->writeAttribute('showGridLines', '0');

        if ($terpilih) {
            $x->writeAttribute('tabSelected', '1');
        }

        $x->startElement('pane');
        $x->writeAttribute('ySplit', (string) $barisHeader);
        $x->writeAttribute('topLeftCell', 'A'.($barisHeader + 1));
        $x->writeAttribute('activePane', 'bottomLeft');
        $x->writeAttribute('state', 'frozen');
        $x->endElement();
        $x->startElement('selection');
        $x->writeAttribute('pane', 'bottomLeft');
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->startElement('sheetFormatPr');
        $x->writeAttribute('defaultRowHeight', '15');
        $x->endElement();
        $x->startElement('cols');

        foreach ($d->kolom === [] ? [new KolomLaporan('')] : $d->kolom as $i => $kolom) {
            $x->startElement('col');
            $x->writeAttribute('min', (string) ($i + 1));
            $x->writeAttribute('max', (string) ($i + 1));
            $x->writeAttribute('width', (string) $kolom->AmbilLebar());
            $x->writeAttribute('customWidth', '1');
            $x->endElement();
        }

        $x->endElement();
        $x->startElement('sheetData');

        // Baris 1..barisHeader-1: kop dan blok.
        for ($baris = 1; $baris < $barisHeader; $baris++) {
            $x->startElement('row');
            $x->writeAttribute('r', (string) $baris);

            if ($baris === 1) {
                $x->writeAttribute('ht', '30');
                $x->writeAttribute('customHeight', '1');
            } elseif ($baris === 2) {
                $x->writeAttribute('ht', '18');
                $x->writeAttribute('customHeight', '1');
            }

            $sel = $kop[$baris] ?? [];
            ksort($sel);

            foreach ($sel as $kolom => [$gaya, $nilai, $jenis]) {
                self::TulisSelTerisi($x, $baris, $kolom, $gaya, $nilai, $jenis);
            }

            $x->endElement();
        }

        // Header tabel.
        $x->startElement('row');
        $x->writeAttribute('r', (string) $barisHeader);
        $x->writeAttribute('ht', '30');
        $x->writeAttribute('customHeight', '1');

        foreach ($d->kolom as $i => $kolom) {
            self::TulisSelTerisi($x, $barisHeader, $i, self::G_HEADER, $kolom->judul, 's');
        }

        $x->endElement();

        // Isi tabel, dialirkan.
        $barisTerakhir = $barisHeader;
        /** @var array<int, BigDecimal> $jumlah */
        $jumlah = [];

        foreach (($d->baris)() as $isi) {
            $barisTerakhir++;

            if ($barisTerakhir > self::MAKS_BARIS) {
                throw new RuntimeException('Baris laporan melebihi batas satu lembar Excel. Persempit periode atau pakai CSV.');
            }

            $x->startElement('row');
            $x->writeAttribute('r', (string) $barisTerakhir);

            foreach ($d->kolom as $i => $kolom) {
                $nilai = $isi[$i] ?? null;
                self::TulisSelData($x, $barisTerakhir, $i, $nilai, $kolom, $zona);

                if ($nilai instanceof RumusExcel) {
                    $berumus = true;
                    $nilai = $nilai->nilai;
                }

                if ($kolom->jumlahkan && $kolom->jenis->CekAngka() && is_string($nilai) && self::CekAngkaTeks($nilai)) {
                    $jumlah[$i] = ($jumlah[$i] ?? BigDecimal::zero())->plus(BigDecimal::of($nilai));
                } elseif ($kolom->jumlahkan && $kolom->jenis->CekAngka() && is_int($nilai)) {
                    $jumlah[$i] = ($jumlah[$i] ?? BigDecimal::zero())->plus(BigDecimal::of($nilai));
                }
            }

            $x->endElement();

            if (($barisTerakhir - $barisHeader) % 2000 === 0) {
                $x->flush();
            }
        }

        $barisDataTerakhir = $barisTerakhir;

        // Baris jumlah.
        if ($d->AdaJumlah()) {
            $barisTerakhir++;
            $x->startElement('row');
            $x->writeAttribute('r', (string) $barisTerakhir);

            foreach ($d->kolom as $i => $kolom) {
                if ($kolom->jumlahkan && $kolom->jenis->CekAngka()) {
                    $gaya = match ($kolom->jenis) {
                        JenisKolom::Uang => self::G_JUM_UANG,
                        JenisKolom::Kuantitas => self::G_JUM_KUANTITAS,
                        default => self::G_JUM_BILANGAN,
                    };
                    $total = (string) ($jumlah[$i] ?? BigDecimal::zero());

                    if ($d->jumlahBerumus && $barisDataTerakhir > $barisHeader) {
                        $berumus = true;
                        $huruf = self::Huruf($i);
                        self::TulisSelTerisi($x, $barisTerakhir, $i, $gaya, new RumusExcel('SUM('.$huruf.($barisHeader + 1).':'.$huruf.$barisDataTerakhir.')', $total), 'f');
                    } else {
                        self::TulisSelTerisi($x, $barisTerakhir, $i, $gaya, $total, 'n');
                    }
                } elseif ($i === 0) {
                    self::TulisSelTerisi($x, $barisTerakhir, $i, self::G_JUM_TEKS, 'Jumlah', 's');
                } else {
                    self::TulisSelTerisi($x, $barisTerakhir, $i, self::G_JUM_TEKS, null, 'e');
                }
            }

            $x->endElement();
        }

        // Kaki: "Dibuat dengan [logo Payoung]".
        $barisKaki = $barisTerakhir + 2;
        $x->startElement('row');
        $x->writeAttribute('r', (string) $barisKaki);
        $x->writeAttribute('ht', '24');
        $x->writeAttribute('customHeight', '1');
        self::TulisSelTerisi($x, $barisKaki, max(0, $kolomTerakhir - 1), self::G_CAP, 'Dibuat dengan Payoung | payoung.id', 's');
        $x->endElement();

        $x->endElement(); // sheetData

        $akhirFilter = max($barisHeader, $barisDataTerakhir);
        $x->startElement('autoFilter');
        $x->writeAttribute('ref', 'A'.$barisHeader.':'.self::Huruf($kolomTerakhir).$akhirFilter);
        $x->endElement();

        $x->startElement('pageMargins');
        foreach (['left' => '0.4', 'right' => '0.4', 'top' => '0.5', 'bottom' => '0.6', 'header' => '0.3', 'footer' => '0.3'] as $sisi => $nilai) {
            $x->writeAttribute($sisi, $nilai);
        }
        $x->endElement();
        $x->startElement('pageSetup');
        $x->writeAttribute('paperSize', '9');
        $x->writeAttribute('orientation', 'landscape');
        $x->writeAttribute('fitToWidth', '1');
        $x->writeAttribute('fitToHeight', '0');
        $x->endElement();
        $x->startElement('headerFooter');
        $x->writeElement('oddFooter', '&L&8 '.str_replace('&', '&&', $d->judul).'&C&8 Dibuat dengan Payoung&R&8 Halaman &P dari &N');
        $x->endElement();

        $gambar = [];

        if ($logoUsaha !== null) {
            $gambar[] = ['Png' => $logoUsaha['Png'], 'Kolom' => $kolomTerakhir, 'Baris' => 0, 'Lebar' => $logoUsaha['Lebar'], 'Tinggi' => $logoUsaha['Tinggi']];
        }

        if ($logoPayoung !== null) {
            $gambar[] = ['Png' => $logoPayoung['Png'], 'Kolom' => $kolomTerakhir, 'Baris' => $barisKaki - 1, 'Lebar' => $logoPayoung['Lebar'], 'Tinggi' => $logoPayoung['Tinggi']];
        }

        if ($gambar !== []) {
            $x->startElement('drawing');
            $x->writeAttribute('r:id', 'rId1');
            $x->endElement();
        }

        $x->endElement(); // worksheet
        $x->endDocument();
        $x->flush();

        return ['BarisHeader' => $barisHeader, 'AkhirFilter' => $akhirFilter, 'KolomTerakhir' => $kolomTerakhir, 'Gambar' => $gambar, 'Berumus' => $berumus];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function GayaRingkasan(ItemRingkasan $item): array
    {
        return match ($item->jenis) {
            JenisKolom::Uang => [self::G_RING_UANG, 'n'],
            JenisKolom::Bilangan, JenisKolom::Kuantitas => [self::G_RING_BILANGAN, 'n'],
            JenisKolom::Persen => [self::G_RING_PERSEN, 'n'],
            default => [self::G_RING_TEKS, 's'],
        };
    }

    private static function CekAngkaTeks(string|int|null $nilai): bool
    {
        return is_int($nilai) || (is_string($nilai) && preg_match('/^-?\d+(\.\d+)?$/', $nilai) === 1);
    }

    private static function TulisSelData(XMLWriter $x, int $baris, int $kolom, mixed $nilai, KolomLaporan $kolomLaporan, DateTimeZone $zona): void
    {
        if ($nilai instanceof RumusExcel) {
            $gaya = $kolomLaporan->jenis === JenisKolom::Teks ? self::G_TEKS : self::GayaData($kolomLaporan->jenis, is_string($nilai->nilai) ? $nilai->nilai : null);
            self::TulisSelTerisi($x, $baris, $kolom, $gaya, $nilai, 'f');

            return;
        }

        if ($nilai === null || $nilai === '') {
            self::TulisSelTerisi($x, $baris, $kolom, self::GayaData($kolomLaporan->jenis, null), null, 'e');

            return;
        }

        switch ($kolomLaporan->jenis) {
            case JenisKolom::Tanggal:
            case JenisKolom::TanggalWaktu:
                $waktu = self::UraiWaktu($nilai, $zona);
                $dengan = $kolomLaporan->jenis === JenisKolom::TanggalWaktu;

                if ($waktu === null) {
                    self::TulisSelTerisi($x, $baris, $kolom, self::G_TEKS, is_scalar($nilai) ? (string) $nilai : null, 's');

                    return;
                }

                self::TulisSelTerisi($x, $baris, $kolom, $dengan ? self::G_TANGGAL_WAKTU : self::G_TANGGAL, self::SerialExcel($waktu, $dengan), 'n');

                return;
            case JenisKolom::Teks:
                self::TulisSelTerisi($x, $baris, $kolom, self::G_TEKS, is_scalar($nilai) ? (string) $nilai : null, 's');

                return;
            default:
                $teks = is_scalar($nilai) ? (string) $nilai : '';

                if (preg_match('/^-?\d+(\.\d+)?$/', $teks) !== 1) {
                    self::TulisSelTerisi($x, $baris, $kolom, self::G_TEKS, $teks, 's');

                    return;
                }

                self::TulisSelTerisi($x, $baris, $kolom, self::GayaData($kolomLaporan->jenis, $teks), $teks, 'n');
        }
    }

    private static function GayaData(JenisKolom $jenis, ?string $nilai): int
    {
        return match ($jenis) {
            JenisKolom::Uang => self::G_UANG,
            JenisKolom::Bilangan => self::G_BILANGAN,
            JenisKolom::Kuantitas => $nilai !== null && preg_match('/\.0*[1-9]/', $nilai) === 1 ? self::G_KUANTITAS : self::G_BILANGAN,
            JenisKolom::Persen => self::G_PERSEN,
            JenisKolom::Tanggal => self::G_TANGGAL,
            JenisKolom::TanggalWaktu => self::G_TANGGAL_WAKTU,
            JenisKolom::Teks => self::G_TEKS,
        };
    }

    /** Waktu dinding (jam di zona laporan) sebagai DateTimeImmutable berzona UTC agar serial Excel sama dengan yang tampil. */
    private static function UraiWaktu(mixed $nilai, DateTimeZone $zona): ?DateTimeImmutable
    {
        try {
            $waktu = match (true) {
                $nilai instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($nilai)->setTimezone($zona),
                is_string($nilai) => new DateTimeImmutable($nilai, $zona),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return $waktu === null ? null : new DateTimeImmutable($waktu->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private static function SerialExcel(DateTimeImmutable $waktuDinding, bool $denganJam): string
    {
        $detik = $waktuDinding->getTimestamp();
        $hari = intdiv($detik, 86400) + 25569;
        $sisa = $detik % 86400;

        if (! $denganJam || $sisa === 0) {
            return (string) $hari;
        }

        return $hari.substr(sprintf('%.9F', $sisa / 86400), 1);
    }

    private static function TulisSelTerisi(XMLWriter $x, int $baris, int $kolom, int $gaya, string|int|RumusExcel|null $nilai, string $jenis): void
    {
        $x->startElement('c');
        $x->writeAttribute('r', self::Huruf($kolom).$baris);

        if ($gaya !== 0) {
            $x->writeAttribute('s', (string) $gaya);
        }

        // Sel berumus: rumus ditulis bersama nilai tersimpan hasil hitungan server (angka, atau teks bila bukan angka).
        if ($nilai instanceof RumusExcel) {
            if ($nilai->nilai === null || ! self::CekAngkaTeks($nilai->nilai)) {
                $x->writeAttribute('t', 'str');
            }

            $x->writeElement('f', $nilai->rumus);
            $x->writeElement('v', $nilai->nilai === null ? '' : (string) $nilai->nilai);
            $x->endElement();

            return;
        }

        if ($nilai === null || $nilai === '' || $jenis === 'e') {
            $x->endElement();

            return;
        }

        if ($jenis === 'n') {
            $x->writeElement('v', (string) $nilai);
            $x->endElement();

            return;
        }

        $x->writeAttribute('t', 'inlineStr');
        $x->startElement('is');
        $x->startElement('t');
        $x->writeAttribute('xml:space', 'preserve');
        $x->text(self::BersihkanTeks((string) $nilai));
        $x->endElement();
        $x->endElement();
        $x->endElement();
    }

    private static function BersihkanTeks(string $teks): string
    {
        $teks = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $teks) ?? '';

        return mb_substr($teks, 0, 32000);
    }

    public static function Huruf(int $indeks): string
    {
        $huruf = '';
        $n = $indeks + 1;

        while ($n > 0) {
            $sisa = ($n - 1) % 26;
            $huruf = chr(65 + $sisa).$huruf;
            $n = intdiv($n - 1, 26);
        }

        return $huruf;
    }

    public static function AmankanNamaBerkas(string $nama): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nama) ?? 'laporan';

        return trim($bersih, '-.') === '' ? 'laporan' : trim($bersih, '-.');
    }

    /** Nama lembar yang sah di Excel: tanpa karakter terlarang, maksimal 31 huruf. */
    public static function AmankanNamaSheet(string $judul): string
    {
        $nama = trim(trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $judul) ?? ''), "' ");

        return mb_substr($nama === '' ? 'Laporan' : $nama, 0, 31);
    }

    private static function BersihkanFolder(string $folder): void
    {
        foreach (glob($folder.'/*') ?: [] as $berkas) {
            @unlink($berkas);
        }

        @rmdir($folder);
    }

    /**
     * @param  list<int>  $denganGambar  nomor lembar (mulai 1) yang punya gambar
     */
    private static function TipeKonten(int $jumlahLembar, array $denganGambar): string
    {
        $isi = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .($denganGambar !== [] ? '<Default Extension="png" ContentType="image/png"/>' : '')
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

        for ($n = 1; $n <= $jumlahLembar; $n++) {
            $isi .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $isi .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach ($denganGambar as $n) {
            $isi .= '<Override PartName="/xl/drawings/drawing'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }

        return $isi.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private static function RelasiPaket(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private static function PropertiInti(string $judul, DateTimeImmutable $dibuat): string
    {
        $utc = $dibuat->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.htmlspecialchars(self::BersihkanTeks($judul), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</dc:title>'
            .'<dc:creator>Payoung</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$utc.'</dcterms:created>'
            .'</cp:coreProperties>';
    }

    /**
     * @param  list<LembarBuku>  $lembar
     * @param  array<int, array{BarisHeader: int, AkhirFilter: int, KolomTerakhir: int, Gambar: list<array{Png: string, Kolom: int, Baris: int, Lebar: int, Tinggi: int}>, Berumus: bool}>  $info
     */
    private static function Buku(array $lembar, array $info, bool $berumus): string
    {
        $daftar = '';
        $nama = '';

        foreach ($lembar as $i => $satu) {
            $teks = htmlspecialchars($satu->nama, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rujukan = "'".str_replace("'", "''", $teks)."'";
            $hasil = $info[$i];
            $daftar .= '<sheet name="'.$teks.'" sheetId="'.($i + 1).'"'.($satu->tersembunyi ? ' state="hidden"' : '').' r:id="rId'.($i + 1).'"/>';
            $nama .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">'.$rujukan.'!$A$'.$hasil['BarisHeader'].':$'.self::Huruf($hasil['KolomTerakhir']).'$'.$hasil['AkhirFilter'].'</definedName>'
                .'<definedName name="_xlnm.Print_Titles" localSheetId="'.$i.'">'.$rujukan.'!$'.$hasil['BarisHeader'].':$'.$hasil['BarisHeader'].'</definedName>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$daftar.'</sheets>'
            .'<definedNames>'.$nama.'</definedNames>'
            .($berumus ? '<calcPr calcId="191029" fullCalcOnLoad="1"/>' : '')
            .'</workbook>';
    }

    private static function RelasiBuku(int $jumlahLembar): string
    {
        $isi = '';

        for ($n = 1; $n <= $jumlahLembar; $n++) {
            $isi .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$isi
            .'<Relationship Id="rId'.($jumlahLembar + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function RelasiSheet(int $nomor): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$nomor.'.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  list<int>  $nomorMedia  nomor berkas `xl/media/image{n}.png` milik gambar-gambar lembar ini, berurutan
     */
    private static function RelasiGambar(array $nomorMedia): string
    {
        $isi = '';

        foreach ($nomorMedia as $urutan => $media) {
            $isi .= '<Relationship Id="rId'.($urutan + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$media.'.png"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$isi.'</Relationships>';
    }

    /**
     * @param  list<array{Png: string, Kolom: int, Baris: int, Lebar: int, Tinggi: int}>  $gambar
     */
    private static function Gambar(array $gambar): string
    {
        $isi = '';

        foreach ($gambar as $i => $g) {
            $id = $i + 1;
            $lebar = $g['Lebar'] * 9525;
            $tinggi = $g['Tinggi'] * 9525;
            $isi .= '<xdr:oneCellAnchor><xdr:from><xdr:col>'.$g['Kolom'].'</xdr:col><xdr:colOff>38100</xdr:colOff><xdr:row>'.$g['Baris'].'</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from>'
                .'<xdr:ext cx="'.$lebar.'" cy="'.$tinggi.'"/>'
                .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($id + 1).'" name="Gambar '.$id.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                .'<xdr:blipFill><a:blip r:embed="rId'.$id.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$lebar.'" cy="'.$tinggi.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                .'<xdr:clientData/></xdr:oneCellAnchor>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$isi.'</xdr:wsDr>';
    }

    private static function Gaya(): string
    {
        $angka = static fn (int $id, string $kode): string => '<numFmt numFmtId="'.$id.'" formatCode="'.htmlspecialchars($kode, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
        $xf = static fn (int $fmt, int $font, int $isi, int $garis, string $rata, bool $bungkus = false): string => '<xf numFmtId="'.$fmt.'" fontId="'.$font.'" fillId="'.$isi.'" borderId="'.$garis.'" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="'.$rata.'" vertical="center"'.($bungkus ? ' wrapText="1"' : '').'/></xf>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="6">'
            .$angka(164, '[$Rp-421] #,##0.00').$angka(165, '#,##0').$angka(166, '#,##0.00##').$angka(167, '0.00"%"').$angka(168, 'dd-mm-yyyy').$angka(169, 'dd-mm-yyyy hh:mm:ss')
            .'</numFmts>'
            .'<fonts count="7">'
            .'<font><sz val="10"/><color rgb="FF111827"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FF3B5B5D"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><i/><sz val="9"/><color rgb="FF6B7280"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF4B5563"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF111827"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF3B5B5D"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border>'
            .'<border><left/><right/><top style="thin"><color rgb="FF111827"/></top><bottom style="thin"><color rgb="FF111827"/></bottom><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="medium"><color rgb="FF3B5B5D"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="24">'
            .$xf(0, 0, 0, 0, 'left')
            .$xf(0, 1, 0, 3, 'left')
            .$xf(0, 2, 0, 0, 'right')
            .$xf(0, 3, 0, 0, 'right')
            .$xf(0, 4, 0, 0, 'left')
            .$xf(0, 0, 0, 0, 'left')
            .$xf(164, 6, 0, 0, 'right')
            .$xf(165, 6, 0, 0, 'right')
            .$xf(167, 6, 0, 0, 'right')
            .$xf(0, 6, 0, 0, 'right')
            .$xf(0, 3, 0, 0, 'right')
            .$xf(0, 5, 2, 0, 'center', true)
            .$xf(0, 0, 0, 1, 'left')
            .$xf(164, 0, 0, 1, 'right')
            .$xf(165, 0, 0, 1, 'right')
            .$xf(166, 0, 0, 1, 'right')
            .$xf(167, 0, 0, 1, 'right')
            .$xf(168, 0, 0, 1, 'center')
            .$xf(169, 0, 0, 1, 'center')
            .$xf(0, 6, 3, 2, 'left')
            .$xf(164, 6, 3, 2, 'right')
            .$xf(165, 6, 3, 2, 'right')
            .$xf(166, 6, 3, 2, 'right')
            .$xf(0, 4, 0, 0, 'right')
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
