<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiPushDimatikan;
use App\Domain\Organisasi\Tugas\KirimNotifikasiPushTugas;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Membuat notifikasi idempoten lalu mengantrekannya ke FCM setelah transaksi selesai. Jenis yang dibisukan penerimanya
 * (`NotifikasiDibisukan`, kecuali Persetujuan) tidak dibuat dan mengembalikan null. Jenis yang push-nya dimatikan
 * (`NotifikasiPushDimatikan`, PLT-08) tetap dibuat untuk pusat notifikasi web tetapi tidak diantrekan ke FCM.
 */
final class BuatNotifikasiPengguna
{
    /** @param array<string, string> $data */
    public function Jalankan(
        int $idPengguna,
        JenisNotifikasiPengguna $jenis,
        string $kunci,
        string $judul,
        string $isi,
        array $data = [],
    ): ?NotifikasiPengguna {
        if ($jenis->BisaDibisukan() && NotifikasiDibisukan::query()->where('IdPengguna', $idPengguna)->where('Jenis', $jenis->value)->exists()) {
            return null;
        }

        try {
            $notifikasi = NotifikasiPengguna::query()->firstOrCreate(
                ['IdPengguna' => $idPengguna, 'Kunci' => $kunci],
                ['Jenis' => $jenis->value, 'Judul' => mb_substr($judul, 0, 150), 'Isi' => mb_substr($isi, 0, 500), 'Data' => $data],
            );
        } catch (UniqueConstraintViolationException) {
            $notifikasi = NotifikasiPengguna::query()->where('IdPengguna', $idPengguna)->where('Kunci', $kunci)->firstOrFail();
        }

        if ($notifikasi->wasRecentlyCreated && ! ($jenis->BisaDibisukan() && NotifikasiPushDimatikan::query()->where('IdPengguna', $idPengguna)->where('Jenis', $jenis->value)->exists())) {
            KirimNotifikasiPushTugas::dispatch($notifikasi->IdTenant, $notifikasi->Id)->afterCommit();
        }

        return $notifikasi;
    }
}
