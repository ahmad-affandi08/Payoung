<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PGL-07 / P-06 template komunikasi: teks pesan platform kepada pemilik tenant per peristiwa (`KodePeristiwa`), kanal
 * (`Email`, `WhatsApp`), dan bahasa (`id`, `en`), berversi seperti dokumen legal: versi terbit tidak pernah diubah atau
 * dihapus, satu draf per kombinasi, perubahan = versi baru. Peristiwa dan variabelnya didefinisikan kode
 * (`PeristiwaKomunikasi`); tanpa versi terbit pengirim memakai teks bawaan kode. `StatusBsp` hanya untuk WhatsApp (catatan
 * persetujuan templat oleh BSP, dicatat manual oleh penyusun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('TemplateKomunikasi', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('KodePeristiwa', 60);
            $tabel->string('Kanal', 12);
            $tabel->string('Bahasa', 2);
            $tabel->unsignedInteger('Versi');
            $tabel->string('Subjek', 150)->nullable();
            $tabel->string('Judul', 150)->nullable();
            $tabel->text('Isi');
            $tabel->string('Status', 12);
            $tabel->string('StatusBsp', 24)->nullable();
            $tabel->string('CatatanBsp', 300)->nullable();
            $tabel->foreignId('IdPenggunaPengelolaPenyusun')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkTemplateKomunikasiIdPenyusun')->nullOnDelete();
            $tabel->foreignId('IdPenggunaPengelolaPenerbit')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkTemplateKomunikasiIdPenerbit')->nullOnDelete();
            $tabel->timestamp('DiterbitkanPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique(['KodePeristiwa', 'Kanal', 'Bahasa', 'Versi'], 'UqTemplateKomunikasiKombinasiVersi');
            $tabel->index(['KodePeristiwa', 'Kanal', 'Bahasa', 'Status'], 'IdxTemplateKomunikasiKombinasiStatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TemplateKomunikasi');
    }
};
