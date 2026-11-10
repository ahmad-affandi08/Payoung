<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Laporan;

use App\Domain\Akuntansi\Kueri\PemasukanPengeluaran;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Http\Kontroler\Kelola\Akuntansi\DasarAkuntansiKontroler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Laporan Pemasukan & Pengeluaran (D-255; `/kelola/laporan/pemasukan-pengeluaran`, izin `laporan.keuangan.lihat`, fitur
 * `laporan.lengkap`, outlet akses pelaku): uang yang benar-benar masuk dan keluar kas & bank pada satu periode, dengan
 * pembanding periode sebelumnya. Ekspor mengikuti templat seragam D-43 (Excel, CSV, cetak).
 */
final class PemasukanPengeluaranKontroler extends DasarAkuntansiKontroler
{
    public function Halaman(Request $permintaan, PemasukanPengeluaran $kueri): Response
    {
        $saring = $this->AmbilSaringLaporan($permintaan);

        return Inertia::render('Kelola/Laporan/PemasukanPengeluaran', [
            'Saring' => self::PetakanSaring($saring, $permintaan),
            'Laporan' => $kueri->Ambil($saring),
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
        ]);
    }

    public function Ekspor(Request $permintaan, PemasukanPengeluaran $kueri): SymfonyResponse
    {
        $saring = $this->AmbilSaringLaporan($permintaan);
        $laporan = $kueri->Ambil($saring);
        /** @var array{Dari: string, Sampai: string, DariSebelumnya: string, SampaiSebelumnya: string, Hari: int} $periode */
        $periode = $laporan['Periode'];
        /** @var array<string, string|null> $ringkasan */
        $ringkasan = $laporan['Ringkasan'];
        $namaOutlet = '';

        foreach ($this->AmbilOpsiOutlet() as $o) {
            if ($saring->idOutlet !== null && $o['Uuid'] === $permintaan->query('outlet')) {
                $namaOutlet = $o['Nama'];
            }
        }

        $baris = static function () use ($laporan): iterable {
            foreach (['Masuk' => 'Uang masuk', 'Keluar' => 'Uang keluar'] as $kunci => $arah) {
                /** @var list<array<string, string|null>> $isi */
                $isi = $laporan[$kunci];

                foreach ($isi as $b) {
                    yield [$arah, (string) $b['Kelompok'], (string) $b['Label'], (string) $b['Nilai'], $b['Porsi'], (string) $b['NilaiSebelumnya']];
                }
            }
        };

        return $this->SajikanLaporan(
            $permintaan,
            'Pemasukan & Pengeluaran',
            'pemasukan-pengeluaran-'.$saring->dari.'-'.$saring->sampai,
            [
                new KolomLaporan('Arah', JenisKolom::Teks, 14),
                new KolomLaporan('Kelompok', JenisKolom::Teks, 32),
                new KolomLaporan('Kategori', JenisKolom::Teks, 40),
                new KolomLaporan('Periode ini', JenisKolom::Uang, 22),
                new KolomLaporan('Porsi (%)', JenisKolom::Persen, 12),
                new KolomLaporan('Periode sebelumnya', JenisKolom::Uang, 22),
            ],
            $baris,
            [
                ['Periode', $this->LabelPeriode(CarbonImmutable::parse($periode['Dari']), CarbonImmutable::parse($periode['Sampai']))],
                ['Pembanding', $this->LabelPeriode(CarbonImmutable::parse($periode['DariSebelumnya']), CarbonImmutable::parse($periode['SampaiSebelumnya']))],
                ['Dasar hitung', 'Uang masuk dan keluar kas atau bank'],
            ],
            [
                new ItemRingkasan('Uang masuk', (string) $ringkasan['Masuk'], JenisKolom::Uang),
                new ItemRingkasan('Uang keluar', (string) $ringkasan['Keluar'], JenisKolom::Uang),
                new ItemRingkasan('Selisih', (string) $ringkasan['Selisih'], JenisKolom::Uang),
                new ItemRingkasan('Di luar hitungan usaha', (string) $ringkasan['DiLuarUsaha'], JenisKolom::Uang),
            ],
            $this->LabelCakupan($namaOutlet),
        );
    }
}
