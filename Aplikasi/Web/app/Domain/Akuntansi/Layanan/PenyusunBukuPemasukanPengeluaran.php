<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Layanan;

use App\Domain\Akuntansi\Data\AkumulatorUangKas;
use App\Domain\Akuntansi\Data\HitunganUangKas;
use App\Domain\Bersama\Laporan\DefinisiLaporan;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Laporan\LembarBuku;
use App\Domain\Bersama\Laporan\PembuatDefinisiLaporan;
use App\Domain\Bersama\Laporan\PenulisXlsxLaporan;
use App\Domain\Bersama\Laporan\RumusExcel;
use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Menyusun buku kerja Excel Pemasukan & Pengeluaran (D-255, tahap 2): beberapa lembar dalam satu berkas, dengan rumus
 * sungguhan. Lembar Transaksi (dirangkum per tanggal, outlet, dan kategori) jadi sumber: Pemasukan, Pengeluaran, Per
 * hari atau bulan, dan Per outlet menghitung dari situ lewat SUMIFS, Ringkasan mengambil dari Pemasukan dan
 * Pengeluaran. Jadi bila pemilik atau akuntan mengubah kategori satu baris Transaksi, semua lembar ikut berubah.
 *
 * Setiap sel berumus ikut membawa nilai hasil hitungan server (`RumusExcel`), supaya pratinjau yang tidak menghitung
 * ulang tetap benar dan supaya kita bisa memverifikasi rumus dengan menghitung ulang. Lembar yang tidak dipilih tetapi
 * dibutuhkan rumus (Pemasukan, Pengeluaran, Transaksi) tetap ada dalam keadaan tersembunyi.
 */
final class PenyusunBukuPemasukanPengeluaran
{
    public const KODE_RINGKASAN = 'Ringkasan';

    public const KODE_KATEGORI = 'Kategori';

    public const KODE_PER_PERIODE = 'PerPeriode';

    public const KODE_PER_OUTLET = 'PerOutlet';

    public const KODE_TRANSAKSI = 'Transaksi';

    public const KODE_HUTANG_PIUTANG = 'HutangPiutang';

    public const KODE_LABA_RUGI = 'LabaRugi';

    public const KODE_STOK = 'Stok';

    /** Semua kode lembar yang boleh dipilih. */
    public const KODE_LEMBAR = [
        self::KODE_RINGKASAN, self::KODE_KATEGORI, self::KODE_PER_PERIODE, self::KODE_PER_OUTLET, self::KODE_TRANSAKSI,
        self::KODE_HUTANG_PIUTANG, self::KODE_LABA_RUGI, self::KODE_STOK,
    ];

    /** Lembar yang bahannya dari luar laporan uang kas, jadi tidak tercentang bawaan: Laba rugi memakai HPP. */
    public const KODE_TIDAK_BAWAAN = [self::KODE_LABA_RUGI, self::KODE_STOK];

    private const NAMA_RINGKASAN = 'Ringkasan';

    private const NAMA_MASUK = 'Pemasukan';

    private const NAMA_KELUAR = 'Pengeluaran';

    private const NAMA_OUTLET = 'Per outlet';

    private const NAMA_TRANSAKSI = 'Transaksi';

    private const NAMA_LABA_RUGI = 'Laba rugi';

    private const NAMA_STOK = 'Stok';

    /** Rentang (hari) paling panjang yang rincian periodenya per hari, selebihnya per bulan. */
    private const MAKS_HARI_HARIAN = 62;

    private const JUMLAH_SARINGAN = 3;

    /** Kolom lembar Transaksi. */
    private const KOLOM_TANGGAL = 'A';

    private const KOLOM_OUTLET = 'B';

    private const KOLOM_KELOMPOK = 'D';

    private const KOLOM_KATEGORI = 'E';

    private const KOLOM_MASUK = 'F';

    private const KOLOM_KELUAR = 'G';

    private const KOLOM_DI_LUAR = 'H';

    private int $idTenant = 0;

    private string $cakupan = '';

    /** @var list<array{0: string, 1: string}> */
    private array $saringan = [];

    public function __construct(private readonly PembuatDefinisiLaporan $pembuat) {}

    /**
     * Memilih Laba rugi otomatis menyertakan Stok: laba kotor bergantung pada HPP, jadi pemilik perlu melihat nilai
     * persediaan di sebelahnya. Hanya kode yang dikenal yang dipertahankan.
     *
     * @param  list<string>  $dipilih
     * @return list<string>
     */
    public static function NormalkanPilihan(array $dipilih): array
    {
        if (in_array(self::KODE_LABA_RUGI, $dipilih, true)) {
            $dipilih[] = self::KODE_STOK;
        }

        return array_values(array_intersect(self::KODE_LEMBAR, $dipilih));
    }

    /**
     * @param  array<int, string>  $namaOutlet  nama outlet menurut id
     * @param  list<string>  $dipilih  kode lembar yang dipilih pengguna, lihat `KODE_LEMBAR`
     * @param  array{LabaRugi?: array<string, mixed>, Stok?: array<string, mixed>}  $tambahan  bahan lembar opsional dari domain lain
     * @return list<LembarBuku>
     */
    public function Susun(int $idTenant, HitunganUangKas $h, array $namaOutlet, array $dipilih, string $cakupan, array $tambahan = []): array
    {
        $this->idTenant = $idTenant;
        $this->cakupan = $cakupan;
        $dari = CarbonImmutable::parse($h->saring->dari);
        $sampai = CarbonImmutable::parse($h->saring->sampai);
        $this->saringan = [
            ['Periode', self::Tgl($dari).' s.d. '.self::Tgl($sampai)],
            ['Pembanding', self::Tgl(CarbonImmutable::parse($h->dariSebelumnya)).' s.d. '.self::Tgl(CarbonImmutable::parse($h->sampaiSebelumnya))],
            ['Dasar hitung', 'Uang masuk dan keluar kas atau bank'],
        ];

        $rincian = $this->UrutkanRincian($h->kini, $namaOutlet);
        $berumus = $rincian !== [];
        $harian = $dari->diffInDays($sampai) < self::MAKS_HARI_HARIAN;
        $namaPeriode = $harian ? 'Per hari' : 'Per bulan';
        $ada = fn (string $kode): bool => in_array($kode, $dipilih, true);

        $lembar = [new LembarBuku($this->LembarRingkasan($h, $rincian, $berumus), self::NAMA_RINGKASAN)];
        [$masuk, $keluar] = [
            $this->LembarKategori(true, $h, $rincian, $berumus),
            $this->LembarKategori(false, $h, $rincian, $berumus),
        ];
        $lembar[] = new LembarBuku($masuk, self::NAMA_MASUK, ! $ada(self::KODE_KATEGORI));
        $lembar[] = new LembarBuku($keluar, self::NAMA_KELUAR, ! $ada(self::KODE_KATEGORI));

        if ($ada(self::KODE_PER_PERIODE)) {
            $lembar[] = new LembarBuku($this->LembarPerPeriode($h, $rincian, $berumus, $harian, $dari, $sampai), $namaPeriode);
        }

        if ($ada(self::KODE_PER_OUTLET)) {
            $lembar[] = new LembarBuku($this->LembarPerOutlet($rincian, $berumus), self::NAMA_OUTLET);
        }

        $lembar[] = new LembarBuku($this->LembarTransaksi($rincian, $berumus), self::NAMA_TRANSAKSI, ! $ada(self::KODE_TRANSAKSI));

        if ($ada(self::KODE_HUTANG_PIUTANG)) {
            $lembar[] = new LembarBuku($this->LembarHutangPiutang($h), PenulisXlsxLaporan::AmankanNamaSheet('Hutang & piutang'));
        }

        if ($ada(self::KODE_LABA_RUGI) && isset($tambahan['LabaRugi'])) {
            $lembar[] = new LembarBuku($this->LembarLabaRugi($tambahan['LabaRugi']), self::NAMA_LABA_RUGI);
        }

        if ($ada(self::KODE_STOK) && isset($tambahan['Stok'])) {
            $lembar[] = new LembarBuku($this->LembarStok($tambahan['Stok'], $sampai), self::NAMA_STOK);
        }

        return $lembar;
    }

    // ------------------------------------------------------------------------------------------------ Transaksi

    /**
     * Rincian diurutkan menurut tanggal, outlet, arah, kelompok, lalu kategori, dengan nama outlet siap tampil.
     *
     * @param  array<int, string>  $namaOutlet
     * @return list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>
     */
    private function UrutkanRincian(AkumulatorUangKas $a, array $namaOutlet): array
    {
        $urutArah = ['Masuk' => 0, 'Keluar' => 1, 'DiLuar' => 2];
        $baris = [];

        foreach ($a->rincian as $r) {
            if ($r['Nilai']->BernilaiNol()) {
                continue;
            }

            $baris[] = [
                'Tanggal' => $r['Tanggal'],
                'Outlet' => $r['IdOutlet'] === null ? 'Tanpa outlet' : ($namaOutlet[$r['IdOutlet']] ?? 'Outlet '.$r['IdOutlet']),
                'Arah' => $r['Arah'],
                'Kelompok' => $r['Kelompok'],
                'Label' => $r['Label'],
                'Nilai' => $r['Nilai'],
            ];
        }

        usort($baris, static fn (array $x, array $y): int => [$x['Tanggal'], $x['Outlet'], $urutArah[$x['Arah']] ?? 9, $x['Kelompok'], $x['Label']] <=> [$y['Tanggal'], $y['Outlet'], $urutArah[$y['Arah']] ?? 9, $y['Kelompok'], $y['Label']]);

        return $baris;
    }

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function LembarTransaksi(array $rincian, bool $berumus): DefinisiLaporan
    {
        $baris = static function () use ($rincian): iterable {
            foreach ($rincian as $r) {
                $nilai = $r['Nilai']->KeString();
                $arah = ['Masuk' => 'Uang masuk', 'Keluar' => 'Uang keluar', 'DiLuar' => 'Di luar usaha'][$r['Arah']] ?? $r['Arah'];

                yield [
                    $r['Tanggal'],
                    $r['Outlet'],
                    $arah,
                    $r['Kelompok'],
                    $r['Label'],
                    $r['Arah'] === 'Masuk' ? $nilai : null,
                    $r['Arah'] === 'Keluar' ? $nilai : null,
                    $r['Arah'] === 'DiLuar' ? $nilai : null,
                ];
            }
        };

        $kolomJumlah = fn (string $kolom): ?RumusExcel => $berumus ? $this->RumusJumlah($kolom, 'Transaksi', $rincian, fn (array $r): ?Uang => match ($kolom) {
            self::KOLOM_MASUK => $r['Arah'] === 'Masuk' ? $r['Nilai'] : null,
            self::KOLOM_KELUAR => $r['Arah'] === 'Keluar' ? $r['Nilai'] : null,
            default => $r['Arah'] === 'DiLuar' ? $r['Nilai'] : null,
        }) : null;

        return $this->Definisi('Transaksi per hari dan kategori', [
            new ItemRingkasan('Jumlah baris', count($rincian), JenisKolom::Bilangan),
            new ItemRingkasan('Total uang masuk', $kolomJumlah(self::KOLOM_MASUK) ?? $this->TotalArah($rincian, 'Masuk')->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Total uang keluar', $kolomJumlah(self::KOLOM_KELUAR) ?? $this->TotalArah($rincian, 'Keluar')->KeString(), JenisKolom::Uang),
        ], [
            new KolomLaporan('Tanggal', JenisKolom::Tanggal, 14),
            new KolomLaporan('Outlet', JenisKolom::Teks, 24),
            new KolomLaporan('Arah', JenisKolom::Teks, 16),
            new KolomLaporan('Kelompok', JenisKolom::Teks, 30),
            new KolomLaporan('Kategori', JenisKolom::Teks, 40),
            new KolomLaporan('Uang masuk', JenisKolom::Uang, 22, true),
            new KolomLaporan('Uang keluar', JenisKolom::Uang, 22, true),
            new KolomLaporan('Di luar usaha', JenisKolom::Uang, 24, true),
        ], $baris, $berumus);
    }

    // ------------------------------------------------------------------------------------------------ Pemasukan & Pengeluaran

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function LembarKategori(bool $masuk, HitunganUangKas $h, array $rincian, bool $berumus): DefinisiLaporan
    {
        $kini = $masuk ? $h->kini->masuk : $h->kini->keluar;
        $lalu = $masuk ? $h->lalu->masuk : $h->lalu->keluar;
        $urutKelompok = $masuk ? PenggolongUangKas::URUT_MASUK : PenggolongUangKas::URUT_KELUAR;
        $baris = $this->BarisKategori($kini, $lalu, $urutKelompok);
        $posisi = $this->PosisiTransaksi(count($rincian));
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(7, self::JUMLAH_SARINGAN, 2);
        $jumlahBaris = $pertama + count($baris);
        $kolomNilai = $masuk ? self::KOLOM_MASUK : self::KOLOM_KELUAR;
        $totalKini = Uang::Nol();
        $totalLalu = Uang::Nol();

        foreach ($baris as $b) {
            $totalKini = $totalKini->Tambah($b['Kini']);
            $totalLalu = $totalLalu->Tambah($b['Lalu']);
        }

        $isi = [];

        foreach ($baris as $i => $b) {
            $er = $pertama + $i;
            $perubahan = $b['Kini']->Kurangi($b['Lalu']);

            if (! $berumus) {
                $isi[] = [$b['Kelompok'], $b['Label'], $b['Kini']->KeString(), self::Persen($b['Kini'], $totalKini), $b['Lalu']->KeString(), $perubahan->KeString(), self::PersenPerubahan($perubahan, $b['Lalu'])];

                continue;
            }

            $isi[] = [
                $b['Kelompok'],
                $b['Label'],
                new RumusExcel(sprintf('SUMIFS(%s,%s,$A%d,%s,$B%d)', $this->Rentang('Transaksi', $kolomNilai, $posisi), $this->Rentang('Transaksi', self::KOLOM_KELOMPOK, $posisi), $er, $this->Rentang('Transaksi', self::KOLOM_KATEGORI, $posisi), $er), $b['Kini']->KeString()),
                new RumusExcel(sprintf('IF($C$%d=0,0,C%d/$C$%d*100)', $jumlahBaris, $er, $jumlahBaris), self::Persen($b['Kini'], $totalKini) ?? '0.00'),
                $b['Lalu']->KeString(),
                new RumusExcel(sprintf('C%d-E%d', $er, $er), $perubahan->KeString()),
                new RumusExcel(sprintf('IF(E%d=0,"",F%d/E%d*100)', $er, $er, $er), self::PersenPerubahan($perubahan, $b['Lalu']) ?? ''),
            ];
        }

        $labelIni = $this->LabelPeriode($h->saring->dari, $h->saring->sampai);
        $labelLalu = $this->LabelPeriode($h->dariSebelumnya, $h->sampaiSebelumnya);
        $judul = $masuk ? 'Pemasukan per kategori' : 'Pengeluaran per kategori';
        $ringkasan = [
            new ItemRingkasan($masuk ? 'Total pemasukan' : 'Total pengeluaran', $berumus && $baris !== [] ? new RumusExcel('C'.$jumlahBaris, $totalKini->KeString()) : $totalKini->KeString(), JenisKolom::Uang),
            new ItemRingkasan('Periode sebelumnya', $berumus && $baris !== [] ? new RumusExcel('E'.$jumlahBaris, $totalLalu->KeString()) : $totalLalu->KeString(), JenisKolom::Uang),
        ];

        return $this->Definisi($judul, $ringkasan, [
            new KolomLaporan('Kelompok', JenisKolom::Teks, 30),
            new KolomLaporan('Kategori', JenisKolom::Teks, 40),
            new KolomLaporan($labelIni, JenisKolom::Uang, 26, true),
            new KolomLaporan('Porsi (%)', JenisKolom::Persen, 12),
            new KolomLaporan($labelLalu, JenisKolom::Uang, 26, true),
            new KolomLaporan('Perubahan', JenisKolom::Uang, 22, true),
            new KolomLaporan('Perubahan (%)', JenisKolom::Persen, 22),
        ], static fn (): array => $isi, $berumus);
    }

    /**
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $kini
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $lalu
     * @param  list<string>  $urutKelompok
     * @return list<array{Kelompok: string, Label: string, Kini: Uang, Lalu: Uang}>
     */
    private function BarisKategori(array $kini, array $lalu, array $urutKelompok): array
    {
        $baris = [];

        foreach (array_unique([...array_keys($kini), ...array_keys($lalu)]) as $kunci) {
            $nilai = $kini[$kunci]['Nilai'] ?? Uang::Nol();
            $nilaiLalu = $lalu[$kunci]['Nilai'] ?? Uang::Nol();

            if ($nilai->BernilaiNol() && $nilaiLalu->BernilaiNol()) {
                continue;
            }

            $sumber = $kini[$kunci] ?? $lalu[$kunci];
            $baris[] = ['Kelompok' => $sumber['Kelompok'], 'Label' => $sumber['Label'], 'Kini' => $nilai, 'Lalu' => $nilaiLalu];
        }

        usort($baris, static function (array $a, array $b) use ($urutKelompok): int {
            $urut = array_search($a['Kelompok'], $urutKelompok, true) <=> array_search($b['Kelompok'], $urutKelompok, true);

            return $urut !== 0 ? $urut : $b['Kini']->Bandingkan($a['Kini']);
        });

        return $baris;
    }

    // ------------------------------------------------------------------------------------------------ Ringkasan

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function LembarRingkasan(HitunganUangKas $h, array $rincian, bool $berumus): DefinisiLaporan
    {
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(5, self::JUMLAH_SARINGAN, 4);
        $posisiTransaksi = $this->PosisiTransaksi(count($rincian));
        $masukKini = $this->BarisKategori($h->kini->masuk, $h->lalu->masuk, PenggolongUangKas::URUT_MASUK);
        $keluarKini = $this->BarisKategori($h->kini->keluar, $h->lalu->keluar, PenggolongUangKas::URUT_KELUAR);
        // Posisi data lembar Pemasukan dan Pengeluaran (7 kolom, 2 item ringkasan).
        $awalKategori = PenulisXlsxLaporan::HitungBarisDataPertama(7, self::JUMLAH_SARINGAN, 2);

        $barisSemua = [];
        $er = $pertama;
        $tambah = function (string $label, ?Uang $kini, ?Uang $lalu, string|RumusExcel|null $rumusKini = null, string|RumusExcel|null $rumusLalu = null, bool $angka = true) use (&$barisSemua, &$er): int {
            $nomor = $er++;
            $barisSemua[$nomor] = [$label, $kini, $lalu, $rumusKini, $rumusLalu, $angka];

            return $nomor;
        };

        $kelompokMasuk = $this->Kelompokkan($h->kini->masuk, $h->lalu->masuk, PenggolongUangKas::URUT_MASUK);
        $kelompokKeluar = $this->Kelompokkan($h->kini->keluar, $h->lalu->keluar, PenggolongUangKas::URUT_KELUAR);
        $rangeMasuk = $this->KategoriRentang(self::NAMA_MASUK, $awalKategori, count($masukKini));
        $rangeKeluar = $this->KategoriRentang(self::NAMA_KELUAR, $awalKategori, count($keluarKini));

        $barisJudulMasuk = $tambah('UANG MASUK', null, null, null, null, false);
        $barisKelompokMasuk = [];

        foreach ($kelompokMasuk as $k) {
            $barisKelompokMasuk[] = $tambah('   '.$k['Kelompok'], $k['Kini'], $k['Lalu'], $berumus ? new RumusExcel(sprintf('SUMIF(%s,TRIM($A%d),%s)', $rangeMasuk['Kelompok'], $er, $rangeMasuk['Kini']), $k['Kini']->KeString()) : null, $berumus ? new RumusExcel(sprintf('SUMIF(%s,TRIM($A%d),%s)', $rangeMasuk['Kelompok'], $er, $rangeMasuk['Lalu']), $k['Lalu']->KeString()) : null);
        }

        $totalMasukKini = $h->kini->TotalMasuk();
        $totalMasukLalu = $h->lalu->TotalMasuk();
        $barisTotalMasuk = $tambah('Total uang masuk', $totalMasukKini, $totalMasukLalu, $berumus && $barisKelompokMasuk !== [] ? new RumusExcel(sprintf('SUM(B%d:B%d)', $barisKelompokMasuk[0], $barisKelompokMasuk[count($barisKelompokMasuk) - 1]), $totalMasukKini->KeString()) : null, $berumus && $barisKelompokMasuk !== [] ? new RumusExcel(sprintf('SUM(C%d:C%d)', $barisKelompokMasuk[0], $barisKelompokMasuk[count($barisKelompokMasuk) - 1]), $totalMasukLalu->KeString()) : null);

        $tambah('UANG KELUAR', null, null, null, null, false);
        $barisKelompokKeluar = [];

        foreach ($kelompokKeluar as $k) {
            $barisKelompokKeluar[] = $tambah('   '.$k['Kelompok'], $k['Kini'], $k['Lalu'], $berumus ? new RumusExcel(sprintf('SUMIF(%s,TRIM($A%d),%s)', $rangeKeluar['Kelompok'], $er, $rangeKeluar['Kini']), $k['Kini']->KeString()) : null, $berumus ? new RumusExcel(sprintf('SUMIF(%s,TRIM($A%d),%s)', $rangeKeluar['Kelompok'], $er, $rangeKeluar['Lalu']), $k['Lalu']->KeString()) : null);
        }

        $totalKeluarKini = $h->kini->TotalKeluar();
        $totalKeluarLalu = $h->lalu->TotalKeluar();
        $barisTotalKeluar = $tambah('Total uang keluar', $totalKeluarKini, $totalKeluarLalu, $berumus && $barisKelompokKeluar !== [] ? new RumusExcel(sprintf('SUM(B%d:B%d)', $barisKelompokKeluar[0], $barisKelompokKeluar[count($barisKelompokKeluar) - 1]), $totalKeluarKini->KeString()) : null, $berumus && $barisKelompokKeluar !== [] ? new RumusExcel(sprintf('SUM(C%d:C%d)', $barisKelompokKeluar[0], $barisKelompokKeluar[count($barisKelompokKeluar) - 1]), $totalKeluarLalu->KeString()) : null);

        $selisihKini = $totalMasukKini->Kurangi($totalKeluarKini);
        $selisihLalu = $totalMasukLalu->Kurangi($totalKeluarLalu);
        $tambah('SELISIH (uang masuk dikurangi uang keluar)', null, null, null, null, false);
        $barisSelisih = $tambah('Selisih uang', $selisihKini, $selisihLalu, $berumus ? new RumusExcel(sprintf('B%d-B%d', $barisTotalMasuk, $barisTotalKeluar), $selisihKini->KeString()) : null, $berumus ? new RumusExcel(sprintf('C%d-C%d', $barisTotalMasuk, $barisTotalKeluar), $selisihLalu->KeString()) : null);

        $diLuarKini = $h->kini->TotalDiLuar();
        $diLuarLalu = $h->lalu->TotalDiLuar();
        $tambah('DI LUAR HITUNGAN USAHA', null, null, null, null, false);
        $barisDiLuar = $tambah('   Modal, prive, pinjaman, dan beli aset (bertanda)', $diLuarKini, $diLuarLalu, $berumus ? new RumusExcel(sprintf('SUM(%s)', $this->Rentang('Transaksi', self::KOLOM_DI_LUAR, $posisiTransaksi)), $diLuarKini->KeString()) : null, null);
        $perubahanKasKini = $selisihKini->Tambah($diLuarKini);
        $perubahanKasLalu = $selisihLalu->Tambah($diLuarLalu);
        $tambah('Perubahan saldo kas dan bank', $perubahanKasKini, $perubahanKasLalu, $berumus ? new RumusExcel(sprintf('B%d+B%d', $barisSelisih, $barisDiLuar), $perubahanKasKini->KeString()) : null, $berumus ? new RumusExcel(sprintf('C%d+C%d', $barisSelisih, $barisDiLuar), $perubahanKasLalu->KeString()) : null);

        $tambah('CATATAN (tidak masuk hitungan di atas, per akhir periode)', null, null, null, null, false);
        $tambah('   Hutang ke pemasok belum dibayar', Uang::Dari($h->catatan['Hutang'] ?? '0'), null);
        $tambah('   Piutang belum diterima', Uang::Dari($h->catatan['Piutang'] ?? '0'), null);
        $tambah('   Dana QRIS dan ojol menunggu cair', Uang::Dari($h->catatan['MenungguCair'] ?? '0'), null);

        $isi = [];

        foreach ($barisSemua as $nomor => [$label, $kini, $lalu, $rumusKini, $rumusLalu, $angka]) {
            if (! $angka || $kini === null) {
                $isi[] = [$label, null, null, null, null];

                continue;
            }

            $perubahan = $lalu === null ? null : $kini->Kurangi($lalu);
            $kolomKini = $rumusKini ?? $kini->KeString();
            $kolomLalu = $lalu === null ? null : ($rumusLalu ?? $lalu->KeString());
            $kolomPerubahan = $perubahan === null ? null : ($berumus ? new RumusExcel(sprintf('B%d-C%d', $nomor, $nomor), $perubahan->KeString()) : $perubahan->KeString());
            $persen = $perubahan === null || $lalu === null ? null : self::PersenPerubahan($perubahan, $lalu);
            $kolomPersen = $perubahan === null ? null : ($berumus ? new RumusExcel(sprintf('IF(C%d=0,"",D%d/C%d*100)', $nomor, $nomor, $nomor), $persen ?? '') : $persen);
            $isi[] = [$label, $kolomKini, $kolomLalu, $kolomPerubahan, $kolomPersen];
        }

        $rumusKop = fn (int $baris, string $kolom, Uang $nilai): string|RumusExcel => $berumus ? new RumusExcel($kolom.$baris, $nilai->KeString()) : $nilai->KeString();
        $labelIni = $this->LabelPeriode($h->saring->dari, $h->saring->sampai);
        $labelLalu = $this->LabelPeriode($h->dariSebelumnya, $h->sampaiSebelumnya);

        return $this->Definisi('Ringkasan', [
            new ItemRingkasan('Total uang masuk', $rumusKop($barisTotalMasuk, 'B', $totalMasukKini), JenisKolom::Uang),
            new ItemRingkasan('Total uang keluar', $rumusKop($barisTotalKeluar, 'B', $totalKeluarKini), JenisKolom::Uang),
            new ItemRingkasan('Selisih uang', $rumusKop($barisSelisih, 'B', $selisihKini), JenisKolom::Uang),
            new ItemRingkasan('Selisih periode sebelumnya', $rumusKop($barisSelisih, 'C', $selisihLalu), JenisKolom::Uang),
        ], [
            new KolomLaporan('Uraian', JenisKolom::Teks, 58),
            new KolomLaporan($labelIni, JenisKolom::Uang, 26),
            new KolomLaporan($labelLalu, JenisKolom::Uang, 26),
            new KolomLaporan('Perubahan', JenisKolom::Uang, 22),
            new KolomLaporan('Perubahan (%)', JenisKolom::Persen, 22),
        ], static fn (): array => $isi, false);
    }

    /**
     * Total per kelompok untuk periode ini dan sebelumnya (hanya kelompok yang punya nilai).
     *
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $kini
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $lalu
     * @param  list<string>  $urutKelompok
     * @return list<array{Kelompok: string, Kini: Uang, Lalu: Uang}>
     */
    private function Kelompokkan(array $kini, array $lalu, array $urutKelompok): array
    {
        $hasil = [];

        foreach ($urutKelompok as $kelompok) {
            $nilai = Uang::Nol();
            $nilaiLalu = Uang::Nol();

            foreach ($kini as $b) {
                if ($b['Kelompok'] === $kelompok) {
                    $nilai = $nilai->Tambah($b['Nilai']);
                }
            }

            foreach ($lalu as $b) {
                if ($b['Kelompok'] === $kelompok) {
                    $nilaiLalu = $nilaiLalu->Tambah($b['Nilai']);
                }
            }

            if (! $nilai->BernilaiNol() || ! $nilaiLalu->BernilaiNol()) {
                $hasil[] = ['Kelompok' => $kelompok, 'Kini' => $nilai, 'Lalu' => $nilaiLalu];
            }
        }

        return $hasil;
    }

    /**
     * Rentang kolom lembar Pemasukan atau Pengeluaran yang dirujuk Ringkasan.
     *
     * @return array{Kelompok: string, Kini: string, Lalu: string}
     */
    private function KategoriRentang(string $lembar, int $awal, int $jumlah): array
    {
        $akhir = max($awal, $awal + $jumlah - 1);
        $kolom = fn (string $huruf): string => sprintf("'%s'!\$%s\$%d:\$%s\$%d", $lembar, $huruf, $awal, $huruf, $akhir);

        return ['Kelompok' => $kolom('A'), 'Kini' => $kolom('C'), 'Lalu' => $kolom('E')];
    }

    // ------------------------------------------------------------------------------------------------ Per hari / bulan

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function LembarPerPeriode(HitunganUangKas $h, array $rincian, bool $berumus, bool $harian, CarbonImmutable $dari, CarbonImmutable $sampai): DefinisiLaporan
    {
        $posisi = $this->PosisiTransaksi(count($rincian));
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(5, self::JUMLAH_SARINGAN, 3);
        $titik = [];

        for ($t = $harian ? $dari : $dari->startOfMonth(); $t->lte($sampai); $t = $harian ? $t->addDay() : $t->addMonthNoOverflow()->startOfMonth()) {
            $titik[] = $t;
        }

        $jumlahBaris = $pertama + count($titik);
        $isi = [];
        $totalMasuk = Uang::Nol();
        $totalKeluar = Uang::Nol();

        foreach ($titik as $i => $t) {
            $masuk = Uang::Nol();
            $keluar = Uang::Nol();

            foreach ($h->kini->perHari as $tanggal => $nilai) {
                $cocok = $harian ? $tanggal === $t->toDateString() : str_starts_with($tanggal, $t->format('Y-m'));

                if ($cocok) {
                    $masuk = $masuk->Tambah($nilai['Masuk']);
                    $keluar = $keluar->Tambah($nilai['Keluar']);
                }
            }

            $totalMasuk = $totalMasuk->Tambah($masuk);
            $totalKeluar = $totalKeluar->Tambah($keluar);
            $er = $pertama + $i;
            $selisih = $masuk->Kurangi($keluar);
            $label = ucfirst($this->BahasaIndonesia($t)->translatedFormat($harian ? 'l' : 'F Y'));
            $batasAtas = $harian ? '$A'.$er.'+1' : 'EDATE($A'.$er.',1)';
            $rumusJumlah = fn (string $kolom): string => sprintf('SUMIFS(%s,%s,">="&$A%d,%s,"<"&%s)', $this->Rentang('Transaksi', $kolom, $posisi), $this->Rentang('Transaksi', self::KOLOM_TANGGAL, $posisi), $er, $this->Rentang('Transaksi', self::KOLOM_TANGGAL, $posisi), $batasAtas);

            $isi[] = $berumus
                ? [$t->toDateString(), $label, new RumusExcel($rumusJumlah(self::KOLOM_MASUK), $masuk->KeString()), new RumusExcel($rumusJumlah(self::KOLOM_KELUAR), $keluar->KeString()), new RumusExcel(sprintf('C%d-D%d', $er, $er), $selisih->KeString())]
                : [$t->toDateString(), $label, $masuk->KeString(), $keluar->KeString(), $selisih->KeString()];
        }

        $selisihTotal = $totalMasuk->Kurangi($totalKeluar);
        $kop = fn (string $kolom, Uang $nilai): string|RumusExcel => $berumus && $titik !== [] ? new RumusExcel($kolom.$jumlahBaris, $nilai->KeString()) : $nilai->KeString();

        return $this->Definisi($harian ? 'Per hari' : 'Per bulan', [
            new ItemRingkasan('Total uang masuk', $kop('C', $totalMasuk), JenisKolom::Uang),
            new ItemRingkasan('Total uang keluar', $kop('D', $totalKeluar), JenisKolom::Uang),
            new ItemRingkasan('Selisih uang', $kop('E', $selisihTotal), JenisKolom::Uang),
        ], [
            new KolomLaporan($harian ? 'Tanggal' : 'Bulan mulai', JenisKolom::Tanggal, 16),
            new KolomLaporan($harian ? 'Hari' : 'Bulan', JenisKolom::Teks, 20),
            new KolomLaporan('Uang masuk', JenisKolom::Uang, 22, true),
            new KolomLaporan('Uang keluar', JenisKolom::Uang, 22, true),
            new KolomLaporan('Selisih', JenisKolom::Uang, 24, true),
        ], static fn (): array => $isi, $berumus);
    }

    // ------------------------------------------------------------------------------------------------ Per outlet

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function LembarPerOutlet(array $rincian, bool $berumus): DefinisiLaporan
    {
        $posisi = $this->PosisiTransaksi(count($rincian));
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(5, self::JUMLAH_SARINGAN, 2);
        /** @var array<string, array{Masuk: Uang, Keluar: Uang}> $perOutlet */
        $perOutlet = [];

        foreach ($rincian as $r) {
            $perOutlet[$r['Outlet']] ??= ['Masuk' => Uang::Nol(), 'Keluar' => Uang::Nol()];

            if ($r['Arah'] === 'Masuk') {
                $perOutlet[$r['Outlet']]['Masuk'] = $perOutlet[$r['Outlet']]['Masuk']->Tambah($r['Nilai']);
            } elseif ($r['Arah'] === 'Keluar') {
                $perOutlet[$r['Outlet']]['Keluar'] = $perOutlet[$r['Outlet']]['Keluar']->Tambah($r['Nilai']);
            }
        }

        ksort($perOutlet);
        $jumlahBaris = $pertama + count($perOutlet);
        $totalSelisih = Uang::Nol();

        foreach ($perOutlet as $n) {
            $totalSelisih = $totalSelisih->Tambah($n['Masuk']->Kurangi($n['Keluar']));
        }

        $isi = [];
        $i = 0;

        foreach ($perOutlet as $nama => $n) {
            $er = $pertama + $i++;
            $selisih = $n['Masuk']->Kurangi($n['Keluar']);
            $isi[] = $berumus
                ? [
                    $nama,
                    new RumusExcel(sprintf('SUMIFS(%s,%s,$A%d)', $this->Rentang('Transaksi', self::KOLOM_MASUK, $posisi), $this->Rentang('Transaksi', self::KOLOM_OUTLET, $posisi), $er), $n['Masuk']->KeString()),
                    new RumusExcel(sprintf('SUMIFS(%s,%s,$A%d)', $this->Rentang('Transaksi', self::KOLOM_KELUAR, $posisi), $this->Rentang('Transaksi', self::KOLOM_OUTLET, $posisi), $er), $n['Keluar']->KeString()),
                    new RumusExcel(sprintf('B%d-C%d', $er, $er), $selisih->KeString()),
                    new RumusExcel(sprintf('IF($D$%d=0,0,D%d/$D$%d*100)', $jumlahBaris, $er, $jumlahBaris), self::Persen($selisih, $totalSelisih) ?? '0.00'),
                ]
                : [$nama, $n['Masuk']->KeString(), $n['Keluar']->KeString(), $selisih->KeString(), self::Persen($selisih, $totalSelisih)];
        }

        return $this->Definisi('Per outlet', [
            new ItemRingkasan('Jumlah outlet', count($perOutlet), JenisKolom::Bilangan),
            new ItemRingkasan('Selisih uang gabungan', $berumus && $perOutlet !== [] ? new RumusExcel('D'.$jumlahBaris, $totalSelisih->KeString()) : $totalSelisih->KeString(), JenisKolom::Uang),
        ], [
            new KolomLaporan('Outlet', JenisKolom::Teks, 34),
            new KolomLaporan('Uang masuk', JenisKolom::Uang, 22, true),
            new KolomLaporan('Uang keluar', JenisKolom::Uang, 22, true),
            new KolomLaporan('Selisih', JenisKolom::Uang, 22, true),
            new KolomLaporan('Porsi selisih (%)', JenisKolom::Persen, 24),
        ], static fn (): array => $isi, $berumus);
    }

    // ------------------------------------------------------------------------------------------------ lembar opsional

    /** Hutang pemasok, piutang pelanggan, dan dana menunggu cair pada akhir periode (angka dari laporan, tanpa rumus). */
    private function LembarHutangPiutang(HitunganUangKas $h): DefinisiLaporan
    {
        $hutang = Uang::Dari($h->catatan['Hutang'] ?? '0.00');
        $piutang = Uang::Dari($h->catatan['Piutang'] ?? '0.00');
        $menunggu = Uang::Dari($h->catatan['MenungguCair'] ?? '0.00');
        $isi = [
            ['Hutang ke pemasok yang belum dibayar', $hutang->KeString()],
            ['Piutang dari pelanggan yang belum dibayar', $piutang->KeString()],
            ['Dana non-tunai yang belum cair dari platform', $menunggu->KeString()],
        ];

        return $this->Definisi('Hutang & piutang', [
            new ItemRingkasan('Posisi per tanggal', self::Tgl(CarbonImmutable::parse($h->saring->sampai)), JenisKolom::Teks),
        ], [
            new KolomLaporan('Keterangan', JenisKolom::Teks, 48),
            new KolomLaporan('Jumlah', JenisKolom::Uang, 24),
        ], static fn (): array => $isi, false, 'Saldo akun hutang, piutang, dan dana menunggu cair pada akhir periode');
    }

    /**
     * Laba rugi akuntansi (pendapatan − HPP − beban) untuk periode dan pembandingnya. Subtotal dan laba berumus atas
     * baris akunnya. Sengaja terpisah dari angka uang masuk dan keluar: ini basis akrual dan memakai HPP.
     *
     * @param  array<string, mixed>  $laba  hasil `LabaRugi::Ambil`
     */
    private function LembarLabaRugi(array $laba): DefinisiLaporan
    {
        /** @var list<array{Jenis: string, Kelompok: string, Label: string, Nilai: string|null, NilaiSebelumnya: string|null, Kode?: string|null}> $barisLaba */
        $barisLaba = $laba['Baris'];
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(3, self::JUMLAH_SARINGAN, 2);
        /** @var array<string, int> $subtotal baris Subtotal per kelompok */
        $subtotal = [];
        /** @var array<string, array{0: int, 1: int}> $rentangAkun baris akun pertama dan terakhir per kelompok */
        $rentangAkun = [];

        foreach ($barisLaba as $i => $b) {
            $er = $pertama + $i;

            if ($b['Jenis'] === 'Akun') {
                $rentangAkun[$b['Kelompok']] = [$rentangAkun[$b['Kelompok']][0] ?? $er, $er];
            } elseif ($b['Jenis'] === 'Subtotal') {
                $subtotal[$b['Kelompok']] = $er;
            }
        }

        /** @var array<string, array{Nilai: string, NilaiSebelumnya: string}> $ringkasanLaba */
        $ringkasanLaba = $laba['Ringkasan'];
        $isi = [];

        foreach ($barisLaba as $i => $b) {
            $er = $pertama + $i;
            $nilai = $b['Nilai'];
            $lalu = $b['NilaiSebelumnya'];
            $label = $b['Jenis'] === 'Akun' && ($b['Kode'] ?? null) !== null ? $b['Kode'].' '.$b['Label'] : $b['Label'];

            if ($b['Jenis'] === 'Kepala' || $nilai === null || $lalu === null) {
                $isi[] = [$label, null, null];

                continue;
            }

            $isi[] = match ($b['Jenis']) {
                'Subtotal' => isset($rentangAkun[$b['Kelompok']])
                    ? [$label, new RumusExcel(sprintf('SUM(B%d:B%d)', ...$rentangAkun[$b['Kelompok']]), $nilai), new RumusExcel(sprintf('SUM(C%d:C%d)', ...$rentangAkun[$b['Kelompok']]), $lalu)]
                    : [$label, $nilai, $lalu],
                'Laba' => $this->BarisLaba($b['Kelompok'], $label, $subtotal, $nilai, $lalu),
                default => [$label, $nilai, $lalu],
            };
        }

        $barisBersih = $pertama + count($barisLaba) - 1;

        return $this->Definisi('Laba rugi', [
            new ItemRingkasan('Laba kotor', isset($subtotal['Hpp']) ? new RumusExcel('B'.($subtotal['Hpp'] + 1), $ringkasanLaba['LabaKotor']['Nilai']) : $ringkasanLaba['LabaKotor']['Nilai'], JenisKolom::Uang),
            new ItemRingkasan('Laba bersih', isset($subtotal['Beban']) ? new RumusExcel('B'.$barisBersih, $ringkasanLaba['LabaBersih']['Nilai']) : $ringkasanLaba['LabaBersih']['Nilai'], JenisKolom::Uang),
        ], [
            new KolomLaporan('Akun', JenisKolom::Teks, 44),
            new KolomLaporan('Periode ini', JenisKolom::Uang, 22),
            new KolomLaporan('Periode sebelumnya', JenisKolom::Uang, 24),
        ], static fn (): array => $isi, false, 'Pendapatan dikurangi HPP dan beban (akuntansi, bukan uang masuk dan keluar)');
    }

    /**
     * Laba kotor = subtotal Pendapatan − subtotal HPP. Laba bersih = laba kotor − subtotal Beban. Rumus hanya dipakai
     * bila baris yang dirujuk memang ada.
     *
     * @param  array<string, int>  $subtotal
     * @return list<string|RumusExcel>
     */
    private function BarisLaba(string $kelompok, string $label, array $subtotal, string $nilai, string $lalu): array
    {
        if ($kelompok === 'LabaKotor' && isset($subtotal['Pendapatan'], $subtotal['Hpp'])) {
            return [$label, new RumusExcel(sprintf('B%d-B%d', $subtotal['Pendapatan'], $subtotal['Hpp']), $nilai), new RumusExcel(sprintf('C%d-C%d', $subtotal['Pendapatan'], $subtotal['Hpp']), $lalu)];
        }

        if ($kelompok === 'LabaBersih' && isset($subtotal['Hpp'], $subtotal['Beban'])) {
            // Baris Laba kotor ditulis tepat setelah subtotal HPP.
            $kotor = $subtotal['Hpp'] + 1;

            return [$label, new RumusExcel(sprintf('B%d-B%d', $kotor, $subtotal['Beban']), $nilai), new RumusExcel(sprintf('C%d-C%d', $kotor, $subtotal['Beban']), $lalu)];
        }

        return [$label, $nilai, $lalu];
    }

    /**
     * Nilai persediaan per kategori pada akhir periode. Barang titipan tidak ikut karena bukan aset toko.
     *
     * @param  array<string, mixed>  $stok  hasil `LaporanStok::NilaiPersediaan`
     */
    private function LembarStok(array $stok, CarbonImmutable $sampai): DefinisiLaporan
    {
        /** @var list<array{NamaKategori: string, JumlahProduk: int, Nilai: string}> $perKategori */
        $perKategori = $stok['PerKategori'];
        /** @var array{Nilai: string, JumlahProduk: int} $total */
        $total = $stok['Total'];
        $isi = array_map(static fn (array $k): array => [$k['NamaKategori'], $k['JumlahProduk'], $k['Nilai']], $perKategori);

        return $this->Definisi('Stok', [
            new ItemRingkasan('Posisi per tanggal', self::Tgl($sampai), JenisKolom::Teks),
            new ItemRingkasan('Nilai persediaan', $total['Nilai'], JenisKolom::Uang),
        ], [
            new KolomLaporan('Kategori', JenisKolom::Teks, 40),
            new KolomLaporan('Jumlah produk', JenisKolom::Bilangan, 18),
            new KolomLaporan('Nilai persediaan', JenisKolom::Uang, 24, true),
        ], static fn (): array => $isi, true, 'Nilai persediaan (HPP rata-rata) pada akhir periode, tanpa barang titipan');
    }

    // ------------------------------------------------------------------------------------------------ bantuan

    /** Tanggal dengan nama hari dan bulan berbahasa Indonesia. */
    private function BahasaIndonesia(CarbonImmutable $t): CarbonImmutable
    {
        $lokal = $t->locale('id');

        return $lokal instanceof CarbonImmutable ? $lokal : $t;
    }

    /**
     * @param  list<ItemRingkasan>  $ringkasan
     * @param  list<KolomLaporan>  $kolom
     * @param  callable(): iterable<list<string|int|DateTimeInterface|RumusExcel|null>>  $baris
     */
    private function Definisi(string $judul, array $ringkasan, array $kolom, callable $baris, bool $jumlahBerumus, ?string $dasarHitung = null): DefinisiLaporan
    {
        $saringan = $this->saringan;

        if ($dasarHitung !== null) {
            $saringan[2] = ['Dasar hitung', $dasarHitung];
        }

        $dasar = $this->pembuat->Buat(
            idTenant: $this->idTenant,
            judul: 'Pemasukan & Pengeluaran | '.$judul,
            namaBerkas: 'pemasukan-pengeluaran',
            cakupan: $this->cakupan,
            saringan: $saringan,
            ringkasan: $ringkasan,
            kolom: $kolom,
            baris: $baris(...),
            denganLogo: true,
        );

        return new DefinisiLaporan(
            judul: $dasar->judul,
            namaBerkas: $dasar->namaBerkas,
            namaUsaha: $dasar->namaUsaha,
            cakupan: $dasar->cakupan,
            saringan: $dasar->saringan,
            ringkasan: $dasar->ringkasan,
            kolom: $dasar->kolom,
            baris: $dasar->baris,
            zonaWaktu: $dasar->zonaWaktu,
            dataTerakhir: $dasar->dataTerakhir,
            dibuatPada: $dasar->dibuatPada,
            logo: $dasar->logo,
            jumlahBerumus: $jumlahBerumus,
        );
    }

    /**
     * Baris data pertama dan terakhir lembar Transaksi (8 kolom, 3 item ringkasan) untuk `$jumlah` baris.
     *
     * @return array{0: int, 1: int}
     */
    private function PosisiTransaksi(int $jumlah): array
    {
        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(8, self::JUMLAH_SARINGAN, 3);

        return [$pertama, max($pertama, $pertama + $jumlah - 1)];
    }

    /**
     * Rentang mutlak satu kolom lembar tertentu, mis. `'Transaksi'!$F$12:$F$80`.
     *
     * @param  array{0: int, 1: int}  $posisi
     */
    private function Rentang(string $lembar, string $kolom, array $posisi): string
    {
        return sprintf("'%s'!\$%s\$%d:\$%s\$%d", $lembar, $kolom, $posisi[0], $kolom, $posisi[1]);
    }

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     * @param  callable(array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}): ?Uang  $ambil
     */
    private function RumusJumlah(string $kolom, string $lembar, array $rincian, callable $ambil): RumusExcel
    {
        $total = Uang::Nol();

        foreach ($rincian as $r) {
            $nilai = $ambil($r);

            if ($nilai !== null) {
                $total = $total->Tambah($nilai);
            }
        }

        $pertama = PenulisXlsxLaporan::HitungBarisDataPertama(8, self::JUMLAH_SARINGAN, 3);

        return new RumusExcel(sprintf('SUM(%s%d:%s%d)', $kolom, $pertama, $kolom, max($pertama, $pertama + count($rincian) - 1)), $total->KeString());
    }

    /**
     * @param  list<array{Tanggal: string, Outlet: string, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>  $rincian
     */
    private function TotalArah(array $rincian, string $arah): Uang
    {
        $total = Uang::Nol();

        foreach ($rincian as $r) {
            if ($r['Arah'] === $arah) {
                $total = $total->Tambah($r['Nilai']);
            }
        }

        return $total;
    }

    private function LabelPeriode(string $dari, string $sampai): string
    {
        return self::Tgl(CarbonImmutable::parse($dari)).' s.d. '.self::Tgl(CarbonImmutable::parse($sampai));
    }

    private static function Tgl(CarbonImmutable $tanggal): string
    {
        return $tanggal->format('d-m-Y');
    }

    /** Porsi dalam persen dua desimal ("94.30"); null bila total nol. */
    private static function Persen(Uang $bagian, Uang $total): ?string
    {
        if ($total->BernilaiNol()) {
            return null;
        }

        return (string) BigDecimal::of($bagian->KeString())->multipliedBy(100)->dividedBy($total->KeString(), 2, RoundingMode::HalfUp);
    }

    /** Perubahan terhadap periode sebelumnya dalam persen dua desimal; null bila periode sebelumnya nol. */
    private static function PersenPerubahan(Uang $perubahan, Uang $lalu): ?string
    {
        if ($lalu->BernilaiNol()) {
            return null;
        }

        return (string) BigDecimal::of($perubahan->KeString())->multipliedBy(100)->dividedBy(BigDecimal::of($lalu->KeString())->abs(), 2, RoundingMode::HalfUp);
    }
}
