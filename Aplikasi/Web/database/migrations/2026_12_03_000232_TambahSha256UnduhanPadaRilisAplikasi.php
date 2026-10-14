<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-23 (D-154): `RilisAplikasi.Sha256Unduhan` — SHA-256 (hex 64) berkas di `UrlUnduh`. Pembaruan otomatis Windows di
 * aplikasi kasir hanya menjalankan pemasang yang hasil unduhannya sama dengan checksum ini; kosong = rilis hanya bisa
 * diperbarui manual dari tautan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('RilisAplikasi', function (Blueprint $tabel): void {
            $tabel->char('Sha256Unduhan', 64)->nullable()->after('UrlUnduh');
        });
    }

    public function down(): void
    {
        Schema::table('RilisAplikasi', function (Blueprint $tabel): void {
            $tabel->dropColumn('Sha256Unduhan');
        });
    }
};
