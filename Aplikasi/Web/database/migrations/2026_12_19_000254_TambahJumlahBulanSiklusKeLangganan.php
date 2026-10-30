<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-249 (lama langganan bebas), tahap expand: jumlah bulan siklus langganan disimpan sebagai angka. Kosong = ikuti
 * `SiklusTagihan` lama (Bulanan = 1, Tahunan = 12), jadi langganan yang sudah ada tidak berubah. Terisi hanya untuk
 * langganan yang dibayar dengan lama selain 1 atau 12 bulan (`SiklusTagihan` = `Kustom`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Langganan', function (Blueprint $tabel): void {
            $tabel->unsignedSmallInteger('JumlahBulanSiklus')->nullable()->after('SiklusTagihan');
        });
    }

    public function down(): void
    {
        Schema::table('Langganan', function (Blueprint $tabel): void {
            $tabel->dropColumn('JumlahBulanSiklus');
        });
    }
};
