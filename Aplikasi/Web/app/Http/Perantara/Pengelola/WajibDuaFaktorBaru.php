<?php

declare(strict_types=1);

namespace App\Http\Perantara\Pengelola;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-42 (BR-P01.2): aksi berbahaya di konsol (anggota tim, kredensial integrasi, tangguhkan/aktifkan tenant,
 * perangkat tepercaya anggota lain) meminta kode 2FA lagi bila kode terakhir di sesi ini dimasukkan lebih dari
 * `MenitKonfirmasiDuaFaktor` menit lalu. Login lewat perangkat tepercaya tidak dihitung sebagai memasukkan kode.
 * Halaman (GET) diarahkan ke halaman konfirmasi lalu kembali ke halaman asal. Permintaan simpan (non-GET) dari
 * Inertia/JSON dijawab 423 `PerluKonfirmasiDuaFaktor` tanpa mengalihkan halaman: konsol menampilkan dialog kode di tempat
 * lalu mengulang permintaan yang sama, sehingga isian formulir yang sudah diketik tidak hilang.
 */
final class WajibDuaFaktorBaru
{
    public const KODE_GALAT = 'PerluKonfirmasiDuaFaktor';

    public function handle(Request $request, Closure $next): Response
    {
        $sesi = $request->session();
        $kodePada = $sesi->get(SesiPengelola::KODE_DUA_FAKTOR_PADA);
        $batasDetik = (int) config('pengelola.MenitKonfirmasiDuaFaktor') * 60;

        if (is_int($kodePada) && now()->getTimestamp() - $kodePada <= $batasDetik) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ($request->header('X-Inertia') !== null || $request->expectsJson())) {
            return response()->json([
                'Galat' => [
                    'Kode' => self::KODE_GALAT,
                    'Pesan' => 'Aksi ini penting. Masukkan kode dari aplikasi autentikator untuk melanjutkan.',
                ],
            ], 423);
        }

        $sesi->put(
            SesiPengelola::KEMBALI_SETELAH_KONFIRMASI,
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous(route('pengelola.beranda')),
        );

        return redirect()->route('pengelola.dua-faktor.konfirmasi');
    }
}
