<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terima transfer per barang (F-05b, D-105): selain diterima dan susut (rusak), penerima bisa menolak sebagian barang
 * dan mengembalikannya ke lokasi asal. Jumlah dan nilai yang dikembalikan disimpan per baris supaya sisa dalam
 * perjalanan = dikirim − diterima − susut − ditolak tetap bisa dihitung dari baris itu sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('TransferStokDetail', 'JumlahDitolak')) {
            return;
        }

        Schema::table('TransferStokDetail', function (Blueprint $tabel): void {
            $tabel->decimal('JumlahDitolak', 18, 4)->default(0)->after('JumlahSusut');
            $tabel->decimal('NilaiDitolak', 18, 2)->default(0)->after('NilaiSusut');
        });
    }

    public function down(): void
    {
        Schema::table('TransferStokDetail', function (Blueprint $tabel): void {
            $tabel->dropColumn(['JumlahDitolak', 'NilaiDitolak']);
        });
    }
};
