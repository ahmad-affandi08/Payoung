<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PGL-16 bagian 2 (P-09, BR-P09.3, D-133): **alat bantu dukungan** di konsol.
 *
 * - `HasilAlatDukungan`: satu baris setiap alat bantu yang dijalankan petugas atas izin akses dukungan yang berlaku,
 *   memuat keadaan `Sebelum` dan `Sesudah` (angka dan pengenal saja, tanpa data pribadi) supaya Owner dan tim bisa
 *   menelusuri apa yang berubah. Append-only. Alat yang hanya membaca tidak menulis baris di sini (cukup
 *   `LogAuditPengelola`).
 * - `Perangkat.MintaLogPada` / `LogDiunggahPada`: permintaan unggah log dari konsol; aplikasi kasir membacanya dari
 *   `konfigurasi-aplikasi` lalu mengunggah log lokalnya yang sudah tersaring ke `LogPerangkatDiunggah`.
 * - `LogPerangkatDiunggah`: isi log yang diunggah perangkat atas permintaan dukungan (teks sudah disaring dari data
 *   pribadi di perangkat dan server), disimpan 30 hari lalu dihapus `dukungan:bersihkan-log-perangkat`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HasilAlatDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkHasilAlatDukunganIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdAksesDukungan')->constrained('AksesDukungan', 'Id', 'FkHasilAlatDukunganIdAksesDukungan')->restrictOnDelete();
            $tabel->foreignId('IdPenggunaPengelola')->constrained('PenggunaPengelola', 'Id', 'FkHasilAlatDukunganIdPenggunaPengelola')->restrictOnDelete();
            // Salinan nama petugas supaya sisi tenant tidak perlu membaca data pengelola.
            $tabel->string('NamaPetugas', 150);
            $tabel->string('Alat', 40);
            // Berhasil / TanpaPerubahan / Gagal.
            $tabel->string('Status', 15);
            $tabel->string('Ringkasan', 300);
            $tabel->json('Parameter')->nullable();
            $tabel->json('Sebelum')->nullable();
            $tabel->json('Sesudah')->nullable();
            $tabel->timestamp('DibuatPada');
            $tabel->index(['IdTenant', 'DibuatPada'], 'IdxHasilAlatDukunganIdTenantDibuatPada');
            $tabel->index(['IdTenant', 'IdAksesDukungan'], 'IdxHasilAlatDukunganIdTenantIdAksesDukungan');
        });

        Schema::table('Perangkat', function (Blueprint $tabel): void {
            $tabel->timestamp('MintaLogPada')->nullable()->after('JumlahOutboxTertunda');
            $tabel->timestamp('LogDiunggahPada')->nullable()->after('MintaLogPada');
        });

        Schema::create('LogPerangkatDiunggah', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkLogPerangkatDiunggahIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPerangkat')->constrained('Perangkat', 'Id', 'FkLogPerangkatDiunggahIdPerangkat')->restrictOnDelete();
            $tabel->string('VersiAplikasi', 30)->nullable();
            $tabel->unsignedInteger('JumlahBaris');
            $tabel->mediumText('Isi');
            $tabel->timestamp('DiunggahPada');
            $tabel->index(['IdTenant', 'IdPerangkat', 'DiunggahPada'], 'IdxLogPerangkatDiunggahIdTenantIdPerangkatDiunggahPada');
            $tabel->index('DiunggahPada', 'IdxLogPerangkatDiunggahDiunggahPada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LogPerangkatDiunggah');
        Schema::table('Perangkat', function (Blueprint $tabel): void {
            $tabel->dropColumn(['MintaLogPada', 'LogDiunggahPada']);
        });
        Schema::dropIfExists('HasilAlatDukungan');
    }
};
