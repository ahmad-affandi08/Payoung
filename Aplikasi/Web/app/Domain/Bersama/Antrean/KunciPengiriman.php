<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Antrean;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Satu pengiriman pesan (WhatsApp, email) per penerima dalam satu waktu. Pengirim membaca status "Diantrekan", memanggil
 * penyedia, lalu menandai "Terkirim" setelahnya. Dua pekerja untuk pesan yang sama (antrean at-least-once, percobaan
 * ulang saat pekerja pertama masih berjalan, dua pekerja paralel) melewati pemeriksaan status bersamaan dan mengirim
 * pesan yang sama dua kali kepada pelanggan. Kunci cache non-blocking ini membuat pekerja kedua melewati pesan itu,
 * sedangkan pekerja pertama menyelesaikannya. Kunci berumur terbatas, jadi pekerja yang mati tidak menahan pesan selamanya.
 *
 * Jaminannya "paling banyak satu pengirim aktif per pesan", bukan "tepat sekali": pekerja yang mati setelah penyedia
 * menerima pesan tetapi sebelum penandaan tersimpan masih bisa menyebabkan kiriman ulang.
 */
final class KunciPengiriman
{
    /**
     * @template T
     *
     * @param  Closure(): T  $kerja
     * @param  T  $bilaSibuk  hasil bila pekerja lain sedang mengirim pesan yang sama (pesan dilewati)
     * @return T
     */
    public static function Jalankan(string $kunci, Closure $kerja, mixed $bilaSibuk, int $detik = 300): mixed
    {
        $kuncian = Cache::lock('kirim:'.$kunci, $detik);

        if (! $kuncian->get()) {
            return $bilaSibuk;
        }

        try {
            return $kerja();
        } finally {
            $kuncian->release();
        }
    }
}
