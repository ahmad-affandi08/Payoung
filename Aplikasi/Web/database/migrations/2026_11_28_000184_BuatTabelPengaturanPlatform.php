<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-113: pengaturan operasional platform yang diubah Super Admin dari konsol, bukan lewat berkas `.env` di server.
 * Pertama dipakai batas refund tanpa persetujuan kedua (BR-P08.2). Tabel kunci-nilai sederhana milik platform (tanpa
 * `IdTenant`, tanpa `Uuid`): satu baris per kunci, nilai berupa teks yang divalidasi pemakainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PengaturanPlatform', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->string('Kunci', 100)->unique('UniqPengaturanPlatformKunci');
            $tabel->string('Nilai', 500);
            $tabel->foreignId('IdPenggunaPengelolaPengubah')->nullable()
                ->constrained('PenggunaPengelola', 'Id', 'FkPengaturanPlatformIdPengubah')->restrictOnDelete();
            $tabel->WaktuStandar();
        });
    }

    public function down(): void
    {
        // Tidak dibalik: nilai pengaturan yang sudah dipakai keputusan tidak boleh hilang.
    }
};
