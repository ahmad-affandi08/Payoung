<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Anggaran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Illuminate\Support\Facades\DB;

/**
 * F8 (FIN-11): menyalin anggaran tahun sebelumnya ke tahun yang masih kosong (cakupan sama: seluruh usaha atau satu
 * outlet) sebagai titik awal; angkanya lalu diubah di halaman susun. Ditolak bila tahun tujuan sudah punya anggaran
 * (tidak menimpa) atau tahun asal kosong. Akun yang sudah nonaktif tidak ikut disalin. Audit `anggaran.salin`.
 */
final class SalinAnggaranTahunLalu
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return int jumlah angka bulanan yang disalin
     *
     * @throws PelanggaranAturanBisnis AnggaranSudahAda, AnggaranTahunLaluKosong
     */
    public function Jalankan(int $tahun, ?int $idOutlet, ?array $idOutletBoleh): int
    {
        SimpanAnggaran::PeriksaCakupan($tahun, $idOutlet, $idOutletBoleh);

        return DB::transaction(function () use ($tahun, $idOutlet): int {
            $akunAktif = Akun::query()->where('Aktif', true)->pluck('Id')->all();

            // Kunci baris tahun tujuan lebih dulu supaya dua salinan bersamaan tidak sama-sama lolos pemeriksaan kosong.
            if (SimpanAnggaran::Cakupan(Anggaran::query()->where('Tahun', $tahun), $idOutlet)->lockForUpdate()->exists()) {
                throw new PelanggaranAturanBisnis('AnggaranSudahAda', "Anggaran {$tahun} sudah ada; ubah langsung di halaman susun.");
            }

            $asal = SimpanAnggaran::Cakupan(Anggaran::query()->where('Tahun', $tahun - 1), $idOutlet)
                ->whereIn('IdAkun', $akunAktif)
                ->get();

            if ($asal->isEmpty()) {
                throw new PelanggaranAturanBisnis('AnggaranTahunLaluKosong', 'Tahun '.($tahun - 1).' belum punya anggaran yang bisa disalin.');
            }

            foreach ($asal as $baris) {
                Anggaran::query()->create([
                    'Tahun' => $tahun,
                    'Bulan' => $baris->Bulan,
                    'IdAkun' => $baris->IdAkun,
                    'IdOutlet' => $idOutlet,
                    'Jumlah' => $baris->Jumlah,
                ]);
            }

            $this->audit->Catat('anggaran.salin', null, nilaiBaru: ['Tahun' => $tahun, 'DariTahun' => $tahun - 1, 'IdOutlet' => $idOutlet, 'Jumlah' => $asal->count()]);

            return $asal->count();
        });
    }
}
