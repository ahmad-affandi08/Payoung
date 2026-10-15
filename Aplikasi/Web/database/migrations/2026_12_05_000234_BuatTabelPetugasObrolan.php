<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLT-12 (PRD v6.42, D-163): kehadiran petugas dukungan untuk obrolan langsung. Satu baris per anggota tim: `Siap` = ia
 * menekan tombol siap di inbox obrolan, `TerakhirAktifPada` = detak terakhir (inbox yang terbuka berdetak tiap 20 detik).
 * Petugas dianggap online bila siap dan detaknya masih baru. Tabel platform tanpa `IdTenant`, tanpa isi percakapan
 * (percakapan hidup di tiket). Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('PetugasObrolan')) {
            return;
        }

        Schema::create('PetugasObrolan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdPenggunaPengelola')->constrained('PenggunaPengelola', 'Id', 'FkPetugasObrolanIdPenggunaPengelola')->cascadeOnDelete();
            $tabel->boolean('Siap')->default(false);
            $tabel->timestamp('TerakhirAktifPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique('IdPenggunaPengelola', 'UniqPetugasObrolanIdPenggunaPengelola');
            $tabel->index(['Siap', 'TerakhirAktifPada'], 'IdxPetugasObrolanSiapTerakhirAktifPada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PetugasObrolan');
    }
};
