<?php

declare(strict_types=1);

namespace App\Http\Perantara;

use Illuminate\Http\Request;

/**
 * Aturan permintaan pada sesi akses dukungan (PGL-16): apa yang tidak boleh dilihat atau diubah petugas.
 *
 * - BR-P09.2: cakupan "Baca saja" menolak semua metode selain GET/HEAD/OPTIONS.
 * - BR-P09.1: petugas tidak pernah melihat kata sandi, PIN, atau kredensial integrasi. Izin yang terkait sudah dicabut
 *   dari sesi (`IzinTenant::CekDilarangSaatDukungan`); daftar rute di sini menutup halaman yang tidak dijaga izin
 *   (keamanan akun, PIN) dan lapisan kedua untuk halaman kredensial bila suatu hari izinnya dilonggarkan.
 * - Hanya halaman back-office (`/kelola/*`) yang terbuka; halaman akun (ganti kata sandi, pilih tenant, dsb.) tidak.
 */
final class AturanAksesDukungan
{
    /** @var list<string> */
    public const POLA_RUTE_DILARANG = [
        'kelola.keamanan', 'kelola.keamanan.*',
        'kelola.pin', 'kelola.pin.*',
        'kelola.langganan', 'kelola.langganan.*',
        'kelola.pengaturan.whatsapp', 'kelola.pengaturan.whatsapp.*',
        'kelola.pengaturan.integrasi-server', 'kelola.pengaturan.integrasi-server.*',
        'kelola.pengaturan.api', 'kelola.pengaturan.api.*',
        'kelola.pengaturan.webhook', 'kelola.pengaturan.webhook.*',
        'kelola.pengaturan.data-saya', 'kelola.pengaturan.data-saya.*',
        'kelola.pengaturan.akses-dukungan', 'kelola.pengaturan.akses-dukungan.*',
    ];

    public const PESAN_HANYA_BACA = 'Izin akses hanya baca.';

    public const PESAN_DILARANG = 'Halaman ini tidak tersedia pada akses dukungan karena memuat kata sandi, PIN, kredensial, atau data akun Anda.';

    public static function CekMetodeBaca(Request $permintaan): bool
    {
        return in_array($permintaan->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public static function CekRuteDilarang(Request $permintaan): bool
    {
        return $permintaan->routeIs(...self::POLA_RUTE_DILARANG);
    }

    /** Jalur yang boleh dibuka sesi dukungan: back-office dan tombol akhiri sesi. */
    public static function CekJalurDiizinkan(Request $permintaan): bool
    {
        return $permintaan->is('kelola', 'kelola/*') || $permintaan->routeIs('dukungan.akhiri');
    }
}
