<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

/**
 * Konteks otomatis tiket dari aplikasi (P-09): tenant sudah melekat pada tiket, sisanya outlet, perangkat, versi
 * aplikasi, jumlah outbox tertunda, layar asal, dan log singkat. Semua nilai teks datar supaya tampil apa adanya di
 * rincian tiket konsol; nilai kosong dibuang.
 */
final class PenyusunKonteksTiketAplikasi
{
    private const MAKS_LAYAR = 100;

    /**
     * @param  array<string, string|int|null>  $rincian  label → nilai dari server (perangkat, outlet, versi, outbox)
     * @param  array<mixed>  $log  baris log dari klien; disaring [PenyaringLogTiket]
     * @return array<string, string>
     */
    public function Susun(array $rincian, ?string $layarAsal, array $log): array
    {
        $konteks = [];

        foreach ($rincian as $label => $nilai) {
            $teks = is_int($nilai) ? (string) $nilai : trim((string) $nilai);

            if ($teks !== '') {
                $konteks[$label] = mb_substr($teks, 0, 300);
            }
        }

        $layar = $layarAsal === null ? '' : trim($layarAsal);

        if ($layar !== '') {
            $konteks['Layar asal'] = mb_substr($layar, 0, self::MAKS_LAYAR);
        }

        $baris = PenyaringLogTiket::Saring($log);

        if ($baris !== []) {
            $konteks['Log singkat'] = implode("\n", $baris);
        }

        return $konteks;
    }
}
