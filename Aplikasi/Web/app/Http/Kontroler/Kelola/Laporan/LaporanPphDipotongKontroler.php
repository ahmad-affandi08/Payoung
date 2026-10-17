<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Laporan;

use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Penjualan\Kueri\DaftarPphDipotong;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Laporan PPh yang dipotong pelanggan (F-21 bagian 5; `/kelola/laporan/pajak/pph-dipotong`, izin
 * `laporan.keuangan.lihat`, fitur `laporan.lengkap` lewat rute `kelola.laporan.pajak*`, outlet akses pelaku): satu baris per
 * pelunasan yang memotong PPh beserta nomor bukti potongnya, supaya bukti potong yang belum diterima mudah ditagih.
 * Ekspor D-43 mengikuti saringan tabel. Angka bantu pembukuan, bukan SPT.
 */
final class LaporanPphDipotongKontroler extends DasarKelolaKontroler
{
    public function Halaman(Request $permintaan, DaftarPphDipotong $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPphDipotong::KOLOM_URUT, DaftarPphDipotong::URUT_BAWAAN, DaftarPphDipotong::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Laporan/PphDipotong', 'Pelunasan', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh()));
    }

    public function Ekspor(Request $permintaan, DaftarPphDipotong $daftar): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPphDipotong::KOLOM_URUT, DaftarPphDipotong::URUT_BAWAAN, DaftarPphDipotong::KOLOM_SARING);
        $idOutlet = $this->IdOutletBoleh();
        $ringkasan = $daftar->Ambil(new DataPermintaanTabel($tabel->cari, $tabel->urut, 1, 1, $tabel->saring), $idOutlet)['Ringkasan'];
        $baris = $this->AmbilSemuaBarisTabel($tabel, fn (DataPermintaanTabel $t): array => $daftar->Ambil($t, $idOutlet));
        $kolom = [
            new KolomLaporan('Tanggal pelunasan', JenisKolom::Tanggal), new KolomLaporan('No. pelunasan', JenisKolom::Teks, 22),
            new KolomLaporan('Pelanggan (pemotong)', JenisKolom::Teks, 30), new KolomLaporan('NPWP pemotong', JenisKolom::Teks, 22),
            new KolomLaporan('Invoice / dokumen', JenisKolom::Teks, 30), new KolomLaporan('Jenis PPh', JenisKolom::Teks, 14),
            new KolomLaporan('Tarif (%)', JenisKolom::Teks, 10), new KolomLaporan('Dasar PPh', JenisKolom::Uang),
            new KolomLaporan('PPh dipotong', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('No. bukti potong', JenisKolom::Teks, 24),
            new KolomLaporan('Status bukti', JenisKolom::Teks, 18),
        ];
        $isi = array_map(function (array $b): array {
            /** @var list<array<string, mixed>> $dokumen */
            $dokumen = $b['Dokumen'];
            $tarif = array_values(array_unique(array_filter(array_map(fn (array $d): ?string => is_string($d['Tarif']) ? rtrim(rtrim($d['Tarif'], '0'), '.') : null, $dokumen))));

            return [
                $b['Tanggal'], $b['Nomor'], $b['NamaPelanggan'], $b['Npwp'] ?? '',
                implode(', ', array_map(fn (array $d): string => (string) $d['Nomor'], $dokumen)), $b['JenisPph'],
                implode(', ', $tarif), count($dokumen) === 1 && is_string($dokumen[0]['DasarPph']) ? $dokumen[0]['DasarPph'] : null,
                $b['PotonganPph'], $b['NomorBuktiPotong'] ?? '', $b['BuktiLengkap'] === true ? 'Sudah ada' : 'Belum diterima',
            ];
        }, $baris);

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan PPh Dipotong Pelanggan',
            'laporan-pph-dipotong-pelanggan',
            $kolom,
            $isi,
            [
                ['Tanggal pelunasan', (string) ($tabel->saring['Tanggal'] ?? 'Semua')],
                ['Status bukti', match ($tabel->saring['Bukti'] ?? null) {
                    'Ada' => 'Sudah ada bukti potong',
                    'Belum' => 'Belum ada bukti potong',
                    default => 'Semua',
                }],
            ],
            [
                new ItemRingkasan('Total PPh dipotong', $ringkasan['Total'], JenisKolom::Uang),
                new ItemRingkasan('Sudah ada bukti potong', $ringkasan['SudahBukti']['Nilai'], JenisKolom::Uang),
                new ItemRingkasan('Belum ada bukti potong', $ringkasan['BelumBukti']['Nilai'], JenisKolom::Uang),
            ],
            $this->LabelCakupan(),
        );
    }
}
