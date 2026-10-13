<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Aksi\SimpanAnggaran;
use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Anggaran;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Uang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Anggaran vs realisasi (F8, FIN-11): anggaran bulan `BulanDari`–`BulanSampai` dibanding realisasi dari jurnal pada
 * rentang tanggal yang sama, per akun pendapatan, HPP, dan beban, dengan subtotal serta laba kotor/bersih. Realisasi
 * dihitung seperti laba rugi (pendapatan = kredit − debit; HPP & beban = debit − kredit; jurnal penutup tahun tidak
 * dihitung). Anggaran yang dibaca mengikuti outlet saringan: satu outlet → anggaran outlet itu; semua outlet →
 * anggaran seluruh usaha. `Selisih` = realisasi − anggaran; `Status` menilai arahnya (pendapatan/laba di atas target
 * itu baik, HPP/beban di atas anggaran itu buruk). `Capaian` = realisasi ÷ anggaran dalam persen (kosong bila tanpa anggaran).
 */
final class AnggaranVsRealisasi
{
    private const KELOMPOK = [
        'Pendapatan' => ['Tipe' => TipeAkun::Pendapatan, 'Label' => 'Pendapatan', 'LabelTotal' => 'Total pendapatan', 'Biaya' => false],
        'Hpp' => ['Tipe' => TipeAkun::Hpp, 'Label' => 'Harga pokok penjualan', 'LabelTotal' => 'Total HPP', 'Biaya' => true],
        'Beban' => ['Tipe' => TipeAkun::Beban, 'Label' => 'Beban', 'LabelTotal' => 'Total beban', 'Biaya' => true],
    ];

    /**
     * @return array{Periode: array{Tahun: int, BulanDari: int, BulanSampai: int, Dari: string, Sampai: string}, AdaAnggaran: bool, Baris: list<array<string, mixed>>, Ringkasan: array<string, array{Anggaran: string, Realisasi: string}>}
     */
    public function Ambil(int $tahun, int $bulanDari, int $bulanSampai, SaringLaporanKeuangan $saring): array
    {
        $awal = CarbonImmutable::parse(sprintf('%04d-%02d-01', $tahun, $bulanDari));
        $akhir = CarbonImmutable::parse(sprintf('%04d-%02d-01', $tahun, $bulanSampai))->endOfMonth()->startOfDay();
        $anggaran = SimpanAnggaran::Cakupan(Anggaran::query()->where('Tahun', $tahun)->whereBetween('Bulan', [$bulanDari, $bulanSampai]), $saring->idOutlet)
            ->groupBy('IdAkun')
            ->selectRaw('`IdAkun`, CAST(SUM(`Jumlah`) AS DECIMAL(20,2)) AS `Total`')
            ->pluck('Total', 'IdAkun');
        $realisasi = $saring->TerapkanOutlet(JurnalDetail::query()
            ->whereBetween('Tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->whereNotIn('IdJurnal', Jurnal::query()->select('Id')->where('JenisSumber', JenisSumberJurnal::TutupTahun->value)))
            ->groupBy('IdAkun')
            ->selectRaw('`IdAkun`, CAST(SUM(`Kredit` - `Debit`) AS DECIMAL(20,2)) AS `Neto`')
            ->pluck('Neto', 'IdAkun');
        $akun = Akun::query()
            ->whereIn('Jenis', array_map(fn (TipeAkun $t): string => $t->value, SimpanAnggaran::TIPE_AKUN))
            ->where(fn ($q) => $q->where('Aktif', true)->orWhereIn('Id', $anggaran->keys()->all())->orWhereIn('Id', $realisasi->keys()->all()))
            ->orderBy('Kode')
            ->get();

        $baris = [];
        $total = [];

        foreach (self::KELOMPOK as $kunci => $kelompok) {
            $jumlahAnggaran = Uang::Nol();
            $jumlahRealisasi = Uang::Nol();
            $barisAkun = [];

            foreach ($akun->filter(fn (Akun $a): bool => $a->Jenis === $kelompok['Tipe']) as $satu) {
                $a = Uang::Dari((string) ($anggaran->get($satu->Id) ?? '0'));
                $neto = Uang::Dari((string) ($realisasi->get($satu->Id) ?? '0'));
                $r = $kelompok['Biaya'] ? Uang::Nol()->Kurangi($neto) : $neto;

                if ($a->BernilaiNol() && $r->BernilaiNol()) {
                    continue;
                }

                $jumlahAnggaran = $jumlahAnggaran->Tambah($a);
                $jumlahRealisasi = $jumlahRealisasi->Tambah($r);
                $barisAkun[] = self::Baris('Akun|'.$satu->Uuid, 'Akun', $kunci, $satu->Nama, $a, $r, $kelompok['Biaya'], $satu->Kode);
            }

            $total[$kunci] = ['Anggaran' => $jumlahAnggaran, 'Realisasi' => $jumlahRealisasi];
            $baris[] = ['Id' => 'Kepala|'.$kunci, 'Jenis' => 'Kepala', 'Kelompok' => $kunci, 'Kode' => null, 'Label' => $kelompok['Label'], 'Anggaran' => null, 'Realisasi' => null, 'Selisih' => null, 'Capaian' => null, 'Status' => null];
            array_push($baris, ...$barisAkun);
            $baris[] = self::Baris('Subtotal|'.$kunci, 'Subtotal', $kunci, $kelompok['LabelTotal'], $jumlahAnggaran, $jumlahRealisasi, $kelompok['Biaya']);

            if ($kunci === 'Hpp') {
                $total['LabaKotor'] = self::Selisih($total, 'Pendapatan', 'Hpp');
                $baris[] = self::Baris('Laba|LabaKotor', 'Laba', 'LabaKotor', 'Laba kotor', $total['LabaKotor']['Anggaran'], $total['LabaKotor']['Realisasi'], false);
            }
        }

        $total['LabaBersih'] = self::Selisih($total, 'LabaKotor', 'Beban');
        $baris[] = self::Baris('Laba|LabaBersih', 'Laba', 'LabaBersih', 'Laba bersih', $total['LabaBersih']['Anggaran'], $total['LabaBersih']['Realisasi'], false);

        return [
            'Periode' => ['Tahun' => $tahun, 'BulanDari' => $bulanDari, 'BulanSampai' => $bulanSampai, 'Dari' => $awal->toDateString(), 'Sampai' => $akhir->toDateString()],
            'AdaAnggaran' => $anggaran->contains(fn (mixed $nilai): bool => ! Uang::Dari((string) $nilai)->BernilaiNol()),
            'Baris' => $baris,
            'Ringkasan' => array_map(fn (array $p): array => ['Anggaran' => $p['Anggaran']->KeString(), 'Realisasi' => $p['Realisasi']->KeString()], $total),
        ];
    }

    /**
     * Capaian (%) = realisasi ÷ anggaran × 100, dua desimal; kosong bila anggaran nol atau negatif (laba yang
     * dianggarkan rugi tidak punya persentase yang berarti).
     */
    public static function HitungCapaian(Uang $anggaran, Uang $realisasi): ?string
    {
        if ($anggaran->Bandingkan(Uang::Nol()) <= 0) {
            return null;
        }

        return (string) BigDecimal::of($realisasi->KeString())->multipliedBy(100)->dividedBy($anggaran->KeString(), 2, RoundingMode::HalfUp);
    }

    /**
     * `Sesuai` bila selisih nol; pendapatan/laba di atas anggaran dan biaya di bawah anggaran `Baik`, sebaliknya `Buruk`.
     */
    public static function TentukanStatus(Uang $selisih, bool $biaya): string
    {
        $arah = $selisih->Bandingkan(Uang::Nol());

        if ($arah === 0) {
            return 'Sesuai';
        }

        return ($arah > 0) === $biaya ? 'Buruk' : 'Baik';
    }

    /**
     * `a − b` untuk anggaran dan realisasi.
     *
     * @param  array<string, array{Anggaran: Uang, Realisasi: Uang}>  $total
     * @return array{Anggaran: Uang, Realisasi: Uang}
     */
    private static function Selisih(array $total, string $a, string $b): array
    {
        $nol = ['Anggaran' => Uang::Nol(), 'Realisasi' => Uang::Nol()];
        $kiri = $total[$a] ?? $nol;
        $kanan = $total[$b] ?? $nol;

        return ['Anggaran' => $kiri['Anggaran']->Kurangi($kanan['Anggaran']), 'Realisasi' => $kiri['Realisasi']->Kurangi($kanan['Realisasi'])];
    }

    /**
     * @return array<string, mixed>
     */
    private static function Baris(string $id, string $jenis, string $kelompok, string $label, Uang $anggaran, Uang $realisasi, bool $biaya, ?string $kode = null): array
    {
        $selisih = $realisasi->Kurangi($anggaran);

        return [
            'Id' => $id,
            'Jenis' => $jenis,
            'Kelompok' => $kelompok,
            'Kode' => $kode,
            'Label' => $label,
            'Anggaran' => $anggaran->KeString(),
            'Realisasi' => $realisasi->KeString(),
            'Selisih' => $selisih->KeString(),
            'Capaian' => self::HitungCapaian($anggaran, $realisasi),
            'Status' => self::TentukanStatus($selisih, $biaya),
        ];
    }
}
