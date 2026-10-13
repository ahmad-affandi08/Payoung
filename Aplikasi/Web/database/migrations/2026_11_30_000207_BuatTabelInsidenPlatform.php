<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-11 PGL-21 manajemen insiden & halaman status publik: `InsidenPlatform` = satu insiden layanan (tingkat, dampak,
 * komponen terdampak, perkiraan persen tenant aktif terdampak); `PembaruanInsiden` = kronologi append-only. Data
 * platform tanpa `IdTenant`. Insiden yang `DiumumkanPada`-nya terisi tampil di halaman status publik; post-mortem
 * baru tampil publik bila diterbitkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('InsidenPlatform', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('Judul', 120);
            $tabel->string('Tingkat', 10);
            $tabel->string('Status', 15);
            $tabel->text('Dampak');
            $tabel->json('Komponen');
            $tabel->unsignedTinyInteger('PersenTenantTerdampak')->nullable();
            $tabel->timestamp('MulaiPada');
            $tabel->timestamp('DiumumkanPada')->nullable();
            $tabel->timestamp('SelesaiPada')->nullable();
            $tabel->text('PostMortem')->nullable();
            $tabel->timestamp('PostMortemDiterbitkanPada')->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkInsidenPlatformDibuatOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['Status', 'MulaiPada'], 'IdxInsidenPlatformStatusMulai');
            $tabel->index(['DiumumkanPada', 'SelesaiPada'], 'IdxInsidenPlatformDiumumkanSelesai');
        });

        Schema::create('PembaruanInsiden', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdInsiden')->constrained('InsidenPlatform', 'Id', 'FkPembaruanInsidenIdInsiden')->restrictOnDelete();
            $tabel->string('Status', 15);
            $tabel->text('Isi');
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkPembaruanInsidenDibuatOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdInsiden', 'Id'], 'IdxPembaruanInsidenIdInsidenId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PembaruanInsiden');
        Schema::dropIfExists('InsidenPlatform');
    }
};
