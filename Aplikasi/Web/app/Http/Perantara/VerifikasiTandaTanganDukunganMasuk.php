<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Http\Respons\GalatApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keaslian pesan masuk dukungan (PGL-15): badan JSON mentah ditandatangani HMAC-SHA256 atas `{X-Waktu-Kirim}.{badan}`
 * dengan rahasia bersama `dukungan.MasukRahasia` (`X-Tanda-Tangan: sha256=<hex>`), skema yang sama dengan webhook
 * keluar Payoung. `X-Waktu-Kirim` (detik Unix) harus dalam [dukungan.MasukToleransiDetik] dari sekarang supaya
 * tangkapan lama tidak bisa diputar ulang. Rahasia kosong = pintu masuk mati (404), bukan terbuka.
 */
final class VerifikasiTandaTanganDukunganMasuk
{
    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        $rahasia = (string) config('dukungan.MasukRahasia');

        if ($rahasia === '') {
            return GalatApi::Buat('TidakDitemukan', 'Data tidak ditemukan.', 404);
        }

        $waktu = (string) $permintaan->header('X-Waktu-Kirim');
        $tanda = (string) $permintaan->header('X-Tanda-Tangan');

        if (
            preg_match('/^\d{10}$/', $waktu) !== 1
            || abs(time() - (int) $waktu) > (int) config('dukungan.MasukToleransiDetik')
            || ! str_starts_with($tanda, 'sha256=')
            || ! hash_equals(hash_hmac('sha256', $waktu.'.'.$permintaan->getContent(), $rahasia), substr($tanda, 7))
        ) {
            return GalatApi::Buat('TandaTanganTidakSah', 'Tanda tangan pesan tidak sah.', 401);
        }

        return $berikutnya($permintaan);
    }
}
