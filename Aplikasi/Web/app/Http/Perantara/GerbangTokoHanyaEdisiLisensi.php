<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Integrasi\Layanan\InfoGerbangPembayaran;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman & aksi kredensial gerbang pembayaran milik toko (tenant menempel Client ID/Secret DOKU sendiri). D-162
 * menyembunyikannya di SaaS, D-312 membukanya kembali karena DOKU tidak mendukung sub account. Sakelar
 * `integrasi.GerbangTokoTampil` (bawaan `true`): bila dimatikan, halaman dialihkan dan aksi tulisnya tidak dilayani.
 * Diperiksa saat permintaan (bukan saat rute didaftarkan) supaya mudah diganti dan tetap bisa diuji.
 */
final class GerbangTokoHanyaEdisiLisensi
{
    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        if (InfoGerbangPembayaran::CekGerbangTokoTampil()) {
            return $berikutnya($permintaan);
        }

        if ($permintaan->isMethod('GET') || $permintaan->isMethod('HEAD')) {
            return redirect(InfoGerbangPembayaran::AmbilTautan());
        }

        abort(404);
    }
}
