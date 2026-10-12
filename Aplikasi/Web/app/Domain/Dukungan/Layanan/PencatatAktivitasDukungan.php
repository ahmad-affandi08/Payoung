<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use App\Domain\Dukungan\Data\DataSesiDukunganAktif;
use App\Domain\Dukungan\Model\AktivitasAksesDukungan;

/**
 * Menulis jejak satu permintaan dalam sesi akses dukungan (PGL-16) ke `AktivitasAksesDukungan`, yang dibaca Owner pada
 * riwayat akses. Hanya jalur tanpa query string yang disimpan: kata kunci pencarian bisa berisi data pribadi.
 */
final class PencatatAktivitasDukungan
{
    public function Catat(DataSesiDukunganAktif $sesi, string $metode, ?string $namaRute, string $jalur, int $statusHttp, ?string $alasanDitolak): void
    {
        AktivitasAksesDukungan::query()->create([
            'IdTenant' => $sesi->idTenant,
            'IdSesiAksesDukungan' => $sesi->idSesi,
            'Metode' => mb_substr($metode, 0, 7),
            'Rute' => $namaRute === null ? null : mb_substr($namaRute, 0, 150),
            'Jalur' => mb_substr($jalur, 0, 255),
            'StatusHttp' => $statusHttp,
            'Ditolak' => $alasanDitolak !== null,
            'Alasan' => $alasanDitolak === null ? null : mb_substr($alasanDitolak, 0, 200),
            'DibuatPada' => now(),
        ]);
    }
}
