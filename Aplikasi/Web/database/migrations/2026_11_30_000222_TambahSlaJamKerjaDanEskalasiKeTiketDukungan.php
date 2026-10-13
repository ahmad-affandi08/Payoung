<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-09: SLA respons pertama dihitung dalam jam kerja (hari libur P-02 dilewati) kecuali tiket mendesak paket berbayar
 * yang dihitung jam kalender (`SlaJamKalender`), dan eskalasi L1 → L2 → Teknis (`TingkatEskalasi`). Tiket yang sudah
 * ada tetap jam kalender (bawaan true), sesuai SLA yang dijanjikan saat dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TiketDukungan', function (Blueprint $tabel): void {
            $tabel->boolean('SlaJamKalender')->default(true)->after('JamSla');
            $tabel->string('TingkatEskalasi', 10)->default('L1')->after('IdPenanggungJawab');
            $tabel->timestamp('DieskalasiPada')->nullable()->after('TingkatEskalasi');
        });
    }

    public function down(): void
    {
        Schema::table('TiketDukungan', function (Blueprint $tabel): void {
            $tabel->dropColumn(['SlaJamKalender', 'TingkatEskalasi', 'DieskalasiPada']);
        });
    }
};
