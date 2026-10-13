<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use App\Domain\Organisasi\Model\Perangkat;

/**
 * Memperbarui `TerakhirAktifPada`, `VersiAplikasi` (header `X-Versi-Aplikasi`), dan `JumlahOutboxTertunda` (header
 * `X-Outbox-Tertunda`, P-10 BR-P10.2; header tidak dikirim = nilai lama dipertahankan) setiap permintaan API POS
 * (F-02b, dipakai P-11 & dasbor perangkat). Ditulis paling sering sekali per menit agar tidak membebani database,
 * kecuali versi aplikasi atau jumlah outbox tertunda berubah.
 */
final class PencatatAktivitasPerangkat
{
    private const DETIK_JEDA = 60;

    public function Catat(Perangkat $perangkat, ?string $versiAplikasi, ?int $outboxTertunda = null): void
    {
        $versiBaru = $versiAplikasi !== null && $versiAplikasi !== '' && $versiAplikasi !== $perangkat->VersiAplikasi;
        $outboxBerubah = $outboxTertunda !== null && $outboxTertunda !== $perangkat->JumlahOutboxTertunda;
        $sudahLama = $perangkat->TerakhirAktifPada === null || $perangkat->TerakhirAktifPada->lt(now()->subSeconds(self::DETIK_JEDA));
        // P-11 PGL-20: awal outbox tidak kosong dicatat untuk alert outbox macet; perangkat lama yang sudah punya outbox
        // tertunda tetapi belum punya awal diberi awal saat kabar berikutnya masuk.
        $jumlah = $outboxBerubah ? $outboxTertunda : $perangkat->JumlahOutboxTertunda;
        $awalOutbox = $jumlah > 0 && $perangkat->OutboxTertundaSejak === null;
        $akhirOutbox = $jumlah === 0 && $perangkat->OutboxTertundaSejak !== null;

        if (! $versiBaru && ! $outboxBerubah && ! $sudahLama && ! $awalOutbox && ! $akhirOutbox) {
            return;
        }

        $perangkat->TerakhirAktifPada = now();

        if ($outboxBerubah) {
            $perangkat->JumlahOutboxTertunda = $outboxTertunda;
        }

        if ($awalOutbox) {
            $perangkat->OutboxTertundaSejak = now();
        } elseif ($akhirOutbox) {
            $perangkat->OutboxTertundaSejak = null;
        }

        if ($versiBaru) {
            $perangkat->VersiAplikasi = mb_substr($versiAplikasi, 0, 30);
        }

        $perangkat->save();
    }
}
