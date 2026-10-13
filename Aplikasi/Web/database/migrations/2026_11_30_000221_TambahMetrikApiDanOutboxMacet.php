<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observabilitas P-11 (PGL-20): metrik API per menit (error rate & p95 dari histogram latensi), awal outbox tertunda
 * perangkat (outbox macet > 2 jam), dan penanda WhatsApp alert terkirim. Semua tabel/kolom bersifat platform: tidak
 * memuat data usaha tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MetrikApi', function (Blueprint $tabel): void {
            $tabel->id('Id');
            // Kelompok API: Pos, Pemilik, ApiPublik.
            $tabel->string('Kelompok', 20);
            $tabel->timestamp('Menit');
            $tabel->unsignedInteger('JumlahPermintaan')->default(0);
            $tabel->unsignedInteger('JumlahGalatServer')->default(0);
            $tabel->unsignedBigInteger('TotalDurasiMs')->default(0);
            // Histogram latensi (batas atas inklusif): p95 dibaca dari sini tanpa menyimpan tiap permintaan.
            $tabel->unsignedInteger('Hingga100Ms')->default(0);
            $tabel->unsignedInteger('Hingga250Ms')->default(0);
            $tabel->unsignedInteger('Hingga400Ms')->default(0);
            $tabel->unsignedInteger('Hingga800Ms')->default(0);
            $tabel->unsignedInteger('Hingga1600Ms')->default(0);
            $tabel->unsignedInteger('Lebih1600Ms')->default(0);
            $tabel->WaktuStandar();
            $tabel->unique(['Kelompok', 'Menit'], 'UniqMetrikApiKelompokMenit');
            $tabel->index('Menit', 'IdxMetrikApiMenit');
        });

        Schema::table('Perangkat', function (Blueprint $tabel): void {
            // Sejak kapan outbox perangkat tidak kosong (null = kosong); dipakai alert outbox macet.
            $tabel->timestamp('OutboxTertundaSejak')->nullable()->after('JumlahOutboxTertunda');
        });

        Schema::table('AlertOperasional', function (Blueprint $tabel): void {
            $tabel->timestamp('WaTerkirimPada')->nullable()->after('EmailTerkirimPada');
        });
    }

    public function down(): void
    {
        Schema::table('AlertOperasional', function (Blueprint $tabel): void {
            $tabel->dropColumn('WaTerkirimPada');
        });
        Schema::table('Perangkat', function (Blueprint $tabel): void {
            $tabel->dropColumn('OutboxTertundaSejak');
        });
        Schema::dropIfExists('MetrikApi');
    }
};
