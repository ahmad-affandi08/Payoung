<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-210 (konsol tahap 4): siaran pesan dari Payoung ke pemilik tenant terpilih lewat WhatsApp atau email. `PesanSiaran`
 * menyimpan isi, segmen penerima (aturan, bukan daftar), jadwal, dan hitungan hasil; `PenerimaSiaran` menyimpan satu
 * baris per penerima yang ditentukan saat siaran mulai dikirim. Tabel platform: tidak memuat data usaha tenant. Hanya
 * menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PesanSiaran', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->string('Judul', 120);
            $tabel->text('Isi');
            $tabel->string('Kanal', 12);
            $tabel->json('Segmen');
            $tabel->string('Status', 12)->default('Terjadwal');
            $tabel->dateTime('JadwalKirimPada');
            $tabel->dateTime('MulaiKirimPada')->nullable();
            $tabel->dateTime('SelesaiPada')->nullable();
            $tabel->unsignedInteger('JumlahPenerima')->default(0);
            $tabel->unsignedInteger('JumlahTerkirim')->default(0);
            $tabel->unsignedInteger('JumlahGagal')->default(0);
            $tabel->unsignedInteger('JumlahDilewati')->default(0);
            $tabel->unsignedBigInteger('IdPenggunaPengelola');
            $tabel->WaktuStandar();
            $tabel->index(['Status', 'JadwalKirimPada'], 'IdxPesanSiaranStatusJadwal');
        });

        Schema::create('PenerimaSiaran', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdPesanSiaran')->constrained('PesanSiaran', 'Id', 'FkPenerimaSiaranIdPesanSiaran')->cascadeOnDelete();
            $tabel->unsignedBigInteger('IdTenant');
            $tabel->string('Nama', 120);
            $tabel->string('Tujuan', 160);
            $tabel->string('Status', 12)->default('Menunggu');
            $tabel->string('Alasan', 300)->nullable();
            $tabel->dateTime('DikirimPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdPesanSiaran', 'Status'], 'IdxPenerimaSiaranSiaranStatus');
            $tabel->unique(['IdPesanSiaran', 'Tujuan'], 'UniqPenerimaSiaranSiaranTujuan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PenerimaSiaran');
        Schema::dropIfExists('PesanSiaran');
    }
};
