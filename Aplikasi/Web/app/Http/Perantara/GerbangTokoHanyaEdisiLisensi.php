<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Integrasi\Layanan\InfoGerbangPembayaran;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-162: halaman & aksi kredensial gerbang pembayaran milik toko (tenant menempel Client ID/Secret DOKU sendiri) hanya
 * hidup di edisi Lisensi, yang tidak punya akun Partner Payoung. Di edisi SaaS tenant memakai Aktivasi QRIS (foto KTP):
 * halaman dialihkan ke sana dan aksi tulisnya tidak dilayani. Diperiksa saat permintaan (bukan saat rute didaftarkan)
 * supaya mudah dibuka kembali dan tetap bisa diuji.
 */
final class GerbangTokoHanyaEdisiLisensi
{
    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        if (InfoGerbangPembayaran::CekGerbangTokoTampil()) {
            return $berikutnya($permintaan);
        }

        if ($permintaan->isMethod('GET') || $permintaan->isMethod('HEAD')) {
            return to_route('kelola.pembayaran.aktivasi-qris');
        }

        abort(404);
    }
}
