<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-e: jam operasional outlet per hari (JSON `{"Senin": {"Buka": "08:00", "Tutup": "21:00"}, "Minggu": null, ...}`, jam
 * lokal outlet; hari null = libur). Kosong = tidak diatur (dianggap selalu buka). Dipakai toko online: di luar jam,
 * outlet tidak menerima pesanan baru dan halaman toko menunjukkan kapan buka lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->json('JamOperasional')->nullable()->after('PengaturanKasir');
        });
    }

    public function down(): void
    {
        Schema::table('Outlet', function (Blueprint $tabel): void {
            $tabel->dropColumn('JamOperasional');
        });
    }
};
