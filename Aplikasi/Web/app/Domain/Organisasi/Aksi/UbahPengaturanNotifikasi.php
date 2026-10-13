<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use Illuminate\Support\Facades\DB;

/**
 * F31 (PLT-08): mengganti daftar jenis notifikasi yang dibisukan satu pengguna di tenant aktif. Daftar yang dikirim
 * menggantikan yang lama (jenis yang tidak disebut aktif lagi). Jenis tidak dikenal dan Persetujuan ditolak. Ini
 * preferensi pribadi, jadi tidak diaudit; notifikasi yang sudah ada tidak dihapus.
 */
final class UbahPengaturanNotifikasi
{
    /**
     * @param  list<string>  $jenisDibisukan
     *
     * @throws PelanggaranAturanBisnis JenisNotifikasiTidakDikenal, PersetujuanTidakBisaDibisukan
     */
    public function Jalankan(int $idPengguna, array $jenisDibisukan): void
    {
        $jenis = [];

        foreach (array_unique($jenisDibisukan) as $nilai) {
            $pilihan = JenisNotifikasiPengguna::tryFrom($nilai);

            if ($pilihan === null) {
                throw new PelanggaranAturanBisnis('JenisNotifikasiTidakDikenal', 'Jenis notifikasi tidak dikenal.');
            }

            if (! $pilihan->BisaDibisukan()) {
                throw new PelanggaranAturanBisnis('PersetujuanTidakBisaDibisukan', 'Permintaan persetujuan tidak bisa dibisukan karena menunggu keputusan Anda.');
            }

            $jenis[$pilihan->value] = $pilihan;
        }

        DB::transaction(function () use ($idPengguna, $jenis): void {
            NotifikasiDibisukan::query()->where('IdPengguna', $idPengguna)->whereNotIn('Jenis', array_keys($jenis))->delete();
            $ada = NotifikasiDibisukan::query()->where('IdPengguna', $idPengguna)->pluck('Jenis')->map(fn (mixed $j): string => $j instanceof JenisNotifikasiPengguna ? $j->value : (string) $j)->all();

            foreach (array_diff(array_keys($jenis), $ada) as $baru) {
                NotifikasiDibisukan::query()->create(['IdPengguna' => $idPengguna, 'Jenis' => $baru]);
            }
        });
    }
}
