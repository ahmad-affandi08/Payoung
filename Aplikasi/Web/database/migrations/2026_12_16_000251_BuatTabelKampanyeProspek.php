<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-216: kampanye email Payoung ke calon pelanggan (prospek) di luar tenant. `KontakProspek` menyimpan alamat email
 * beserta sektor usaha dan sumber datanya; yang berhenti berlangganan atau pentalan tidak pernah dikirimi lagi.
 * `KampanyeProspek` menyimpan isi, segmen sektor, dan batas kirim per hari; `PenerimaKampanyeProspek` satu baris per
 * kontak yang ditetapkan saat kampanye dijalankan. Tabel platform: tanpa `IdTenant`. Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('KontakProspek', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('Email', 190);
            $tabel->string('Sektor', 12);
            $tabel->string('Sumber', 160);
            $tabel->string('Status', 12)->default('Aktif');
            $tabel->dateTime('BerhentiPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->unique('Email', 'UniqKontakProspekEmail');
            $tabel->index(['Status', 'Sektor'], 'IdxKontakProspekStatusSektor');
        });

        Schema::create('KampanyeProspek', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('Nama', 120);
            $tabel->string('Subjek', 150);
            $tabel->text('Isi');
            $tabel->json('Segmen');
            $tabel->string('Status', 12)->default('Draf');
            $tabel->unsignedSmallInteger('BatasPerHari')->default(40);
            $tabel->string('AlasanJeda', 200)->nullable();
            $tabel->dateTime('MulaiKirimPada')->nullable();
            $tabel->dateTime('SelesaiPada')->nullable();
            $tabel->unsignedInteger('JumlahPenerima')->default(0);
            $tabel->unsignedInteger('JumlahTerkirim')->default(0);
            $tabel->unsignedInteger('JumlahGagal')->default(0);
            $tabel->unsignedInteger('JumlahDilewati')->default(0);
            $tabel->unsignedBigInteger('IdPenggunaPengelola');
            $tabel->WaktuStandar();
            $tabel->index('Status', 'IdxKampanyeProspekStatus');
        });

        Schema::create('PenerimaKampanyeProspek', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdKampanyeProspek')->constrained('KampanyeProspek', 'Id', 'FkPenerimaKampanyeProspekIdKampanye')->cascadeOnDelete();
            $tabel->foreignId('IdKontakProspek')->constrained('KontakProspek', 'Id', 'FkPenerimaKampanyeProspekIdKontak')->cascadeOnDelete();
            $tabel->string('Status', 12)->default('Menunggu');
            $tabel->string('Alasan', 300)->nullable();
            $tabel->dateTime('DikirimPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdKampanyeProspek', 'Status'], 'IdxPenerimaKampanyeProspekStatus');
            $tabel->unique(['IdKampanyeProspek', 'IdKontakProspek'], 'UniqPenerimaKampanyeProspekKontak');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PenerimaKampanyeProspek');
        Schema::dropIfExists('KampanyeProspek');
        Schema::dropIfExists('KontakProspek');
    }
};
