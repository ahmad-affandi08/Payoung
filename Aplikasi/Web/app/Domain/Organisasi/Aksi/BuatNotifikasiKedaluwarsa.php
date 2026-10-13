<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Laporan\Kueri\LaporanStok;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Model\PengingatKedaluwarsaBatch;
use App\Domain\Organisasi\Model\PerangkatPengguna;
use App\Domain\Organisasi\Model\TenantPengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F23 (INV-09, OWN-03): push ke Aplikasi Owner saat batch masuk batas kedaluwarsa. Satu batch diumumkan **sekali per tingkat**
 * (`H30` dalam 30 hari, `H7` dalam 7 hari, `Lewat` sudah lewat; penanda di `PengingatKedaluwarsaBatch`), bukan tiap hari.
 * Setiap anggota berizin `persediaan.lihat` yang punya perangkat Owner aktif menerima satu ringkasan dari batch baru yang
 * ada di outlet aksesnya. Dijalankan penjadwal bersama notifikasi operasional (`tindakan:buat-notifikasi-operasional`).
 */
final class BuatNotifikasiKedaluwarsa
{
    /** Batas batch yang dibaca per jalan; sisanya diumumkan pada jalan berikutnya (penanda hanya untuk yang terbaca). */
    public const BATAS_BATCH = 500;

    private const URUTAN_TINGKAT = ['Lewat' => 0, 'H7' => 1, 'H30' => 2];

    public function __construct(
        private readonly LaporanStok $laporan,
        private readonly KonteksTindakanPengguna $konteks,
        private readonly BuatNotifikasiPengguna $buat,
    ) {}

    /**
     * @return int jumlah notifikasi baru yang dibuat
     */
    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        $hasil = $this->laporan->BatchKedaluwarsa(null, $hariIni, batas: self::BATAS_BATCH);

        if ($hasil['Baris'] === []) {
            return 0;
        }

        return DB::transaction(function () use ($idTenant, $hariIni, $hasil): int {
            $baru = $this->TandaiBaru($idTenant, $hasil['Baris']);

            return $baru === [] ? 0 : $this->Umumkan($idTenant, $hariIni, $baru);
        });
    }

    /**
     * Menulis penanda untuk (batch, tingkat, tanggal) yang belum pernah diumumkan; mengembalikan barisnya.
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array{Baris: array<string, mixed>, Tingkat: string}>
     */
    private function TandaiBaru(int $idTenant, array $baris): array
    {
        $kunci = array_values(array_unique(array_map(fn (array $b): string => (string) $b['Kunci'], $baris)));
        $sudah = [];

        foreach (PengingatKedaluwarsaBatch::query()->whereIn('KunciBatch', $kunci)->get() as $p) {
            $sudah[$p->KunciBatch.'|'.$p->Tingkat.'|'.$p->TanggalKedaluwarsa->toDateString()] = true;
        }

        $baru = [];
        $sisip = [];
        $sekarang = now();

        foreach ($baris as $b) {
            $tingkat = self::Tingkat((string) $b['Status']);
            $tanggal = (string) $b['TanggalKedaluwarsa'];

            if (isset($sudah[$b['Kunci'].'|'.$tingkat.'|'.$tanggal])) {
                continue;
            }

            $baru[] = ['Baris' => $b, 'Tingkat' => $tingkat];
            $sisip[] = ['IdTenant' => $idTenant, 'KunciBatch' => (string) $b['Kunci'], 'Tingkat' => $tingkat, 'TanggalKedaluwarsa' => $tanggal, 'DibuatPada' => $sekarang];
        }

        if ($sisip !== []) {
            PengingatKedaluwarsaBatch::query()->insertOrIgnore($sisip);
        }

        return $baru;
    }

    /**
     * @param  list<array{Baris: array<string, mixed>, Tingkat: string}>  $baru
     */
    private function Umumkan(int $idTenant, CarbonImmutable $hariIni, array $baru): int
    {
        $idPengguna = PerangkatPengguna::query()->where('Aktif', true)->distinct()->pluck('IdPengguna');
        $anggota = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->whereIn('IdPengguna', $idPengguna)
            ->pluck('IdPengguna');
        $dibuat = 0;

        foreach ($anggota as $id) {
            $konteks = $this->konteks->Buat($idTenant, (int) $id, $hariIni);

            if (! $konteks->CekIzin(IzinTenant::PersediaanLihat->value)) {
                continue;
            }

            // Pemilik/Admin melihat semua outlet; peran berbatas outlet hanya batch di lokasi stok outlet aksesnya.
            $kunciBoleh = $konteks->idOutletBoleh === null
                ? null
                : array_flip(array_map(
                    fn (array $b): string => (string) $b['Kunci'],
                    $this->laporan->BatchKedaluwarsa($konteks->idOutletBoleh, $hariIni, batas: self::BATAS_BATCH)['Baris'],
                ));
            $terlihat = array_values(array_filter(
                $baru,
                fn (array $b): bool => $kunciBoleh === null || isset($kunciBoleh[$b['Baris']['Kunci']]),
            ));

            if ($terlihat === []) {
                continue;
            }

            [$judul, $isi] = $this->Susun($terlihat);
            $sidik = substr(hash('sha256', implode('|', array_map(fn (array $b): string => $b['Baris']['Kunci'].':'.$b['Tingkat'], $terlihat))), 0, 12);
            $dibuat += (int) $this->buat->Jalankan(
                (int) $id,
                JenisNotifikasiPengguna::StokKedaluwarsa,
                "Kedaluwarsa:{$hariIni->toDateString()}:{$sidik}",
                $judul,
                $isi,
                ['Tautan' => 'notifikasi'],
            )->wasRecentlyCreated;
        }

        return $dibuat;
    }

    /**
     * @param  list<array{Baris: array<string, mixed>, Tingkat: string}>  $terlihat
     * @return array{0: string, 1: string}
     */
    private function Susun(array $terlihat): array
    {
        $hitung = ['Lewat' => 0, 'H7' => 0, 'H30' => 0];

        foreach ($terlihat as $b) {
            $hitung[$b['Tingkat']]++;
        }

        usort($terlihat, fn (array $a, array $b): int => [self::URUTAN_TINGKAT[$a['Tingkat']], $a['Baris']['TanggalKedaluwarsa']] <=> [self::URUTAN_TINGKAT[$b['Tingkat']], $b['Baris']['TanggalKedaluwarsa']]);
        $ringkas = array_filter([
            $hitung['Lewat'] > 0 ? "{$hitung['Lewat']} batch sudah lewat kedaluwarsa" : null,
            $hitung['H7'] > 0 ? "{$hitung['H7']} batch tinggal 7 hari atau kurang" : null,
            $hitung['H30'] > 0 ? "{$hitung['H30']} batch dalam 30 hari" : null,
        ]);
        $contoh = array_map(
            fn (array $b): string => (string) $b['Baris']['NamaProduk'].' (batch '.(string) $b['Baris']['NomorBatch'].')',
            array_slice($terlihat, 0, 3),
        );
        $judul = $hitung['Lewat'] > 0 ? 'Barang sudah lewat kedaluwarsa' : 'Barang mendekati kedaluwarsa';

        return [$judul, implode(', ', $ringkas).'. '.implode(', ', $contoh).(count($terlihat) > 3 ? ', dan lainnya.' : '.')];
    }

    private static function Tingkat(string $status): string
    {
        return match ($status) {
            'Lewat' => 'Lewat',
            'Segera' => 'H7',
            default => 'H30',
        };
    }
}
