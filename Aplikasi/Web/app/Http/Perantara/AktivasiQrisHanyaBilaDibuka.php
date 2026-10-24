<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use App\Domain\Integrasi\Layanan\InfoGerbangPembayaran;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-190: halaman Aktivasi QRIS lewat DOKU (KYB merchant, D-83) disembunyikan karena DOKU hanya dipakai untuk tagihan
 * langganan; QRIS toko = QRIS statis milik toko yang diberi nominal di kasir (D-189). Tautan lama dialihkan ke Metode
 * pembayaran dan aksi tulisnya tidak dilayani. Diperiksa saat permintaan (bukan saat rute didaftarkan) supaya mudah
 * dibuka kembali lewat `integrasi.AktivasiQrisTampil` dan tetap bisa diuji.
 */
final class AktivasiQrisHanyaBilaDibuka
{
    public function handle(Request $permintaan, Closure $berikutnya): Response
    {
        if (InfoGerbangPembayaran::CekAktivasiQrisTampil()) {
            return $berikutnya($permintaan);
        }

        if ($permintaan->isMethod('GET') || $permintaan->isMethod('HEAD')) {
            return redirect(InfoGerbangPembayaran::TAUTAN_METODE);
        }

        abort(404);
    }
}
