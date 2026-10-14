<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLT-08: preferensi notifikasi per kanal dan per outlet, melengkapi `NotifikasiDibisukan` (jenis dimatikan di semua
 * kanal). `NotifikasiPushDimatikan`: jenis yang tetap muncul di pusat notifikasi web tetapi tidak dikirim sebagai push
 * ke Aplikasi Owner. `NotifikasiOutletDibisukan`: kejadian di outlet ini tidak membuat notifikasi untuk pengguna itu
 * (hanya kabar yang membawa outlet: transaksi besar, pesanan online). Hanya menambah tabel (expand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('NotifikasiPushDimatikan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNotifikasiPushDimatikanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkNotifikasiPushDimatikanIdPengguna')->restrictOnDelete();
            $tabel->string('Jenis', 40);
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdPengguna', 'Jenis'], 'UniqNotifikasiPushDimatikanPenggunaJenis');
        });

        Schema::create('NotifikasiOutletDibisukan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkNotifikasiOutletDibisukanIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkNotifikasiOutletDibisukanIdPengguna')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkNotifikasiOutletDibisukanIdOutlet')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'IdPengguna', 'IdOutlet'], 'UniqNotifikasiOutletDibisukanPenggunaOutlet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NotifikasiOutletDibisukan');
        Schema::dropIfExists('NotifikasiPushDimatikan');
    }
};
