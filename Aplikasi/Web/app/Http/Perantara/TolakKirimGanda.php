<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pengaman klik ganda untuk formulir back-office yang membuat dokumen uang (kasbon, transaksi kas/bank, aset tetap,
 * penerimaan barang, belanja stok). Formulir ini tidak membawa kunci idempotensi klien, jadi dua kiriman identik dari
 * pengguna yang sama dalam beberapa detik (klik ganda, kiriman ulang peramban saat koneksi lambat) membuat dua dokumen,
 * masing-masing dengan jurnal dan nomornya. Kiriman kedua yang isinya sama persis ditolak selama jendela pendek.
 *
 * Bukan jaminan "tepat sekali" (itu tugas kunci idempotensi klien): jendela pendek ini sengaja, supaya mencatat dua
 * dokumen yang memang sama beberapa saat kemudian tetap bisa. Kiriman yang gagal (validasi atau aturan bisnis) tidak
 * menahan perbaikan dan kiriman ulangnya: kuncinya dilepas.
 */
final class TolakKirimGanda
{
    /** Lama kiriman identik dianggap klik ganda. */
    public const DETIK_JENDELA = 8;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $pelaku = $request->user()?->getAuthIdentifier() ?? $request->session()->getId();
        $kunci = 'kirim-ganda:'.$pelaku.':'.hash('sha256', $request->path().'|'.json_encode($request->except(['_token']), JSON_PARTIAL_OUTPUT_ON_ERROR));

        if (! Cache::add($kunci, 1, self::DETIK_JENDELA)) {
            throw new PelanggaranAturanBisnis('KirimGanda', 'Isian yang sama persis baru saja dikirim, jadi tidak dicatat dua kali. Tunggu beberapa detik bila memang ingin mencatat yang sama lagi.', 'Umum', 409);
        }

        try {
            $respons = $next($request);
        } catch (Throwable $galat) {
            Cache::forget($kunci);

            throw $galat;
        }

        // Gagal validasi (dialihkan kembali dengan galat) atau ditolak: perbaikan dan kiriman ulangnya tidak boleh tertahan.
        if ($respons->getStatusCode() >= 400 || ($request->hasSession() && $request->session()->has('errors'))) {
            Cache::forget($kunci);
        }

        return $respons;
    }
}
