<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-18 (D-251), tahap expand: kebutuhan staf minimal per outlet, per hari dalam seminggu, per rentang jam. Dibandingkan
 * dengan jadwal untuk menunjukkan jam yang kekurangan orang (`PenghitungKekuranganStaf`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('KebutuhanStaf', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkKebutuhanStafIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdOutlet')->constrained('Outlet', 'Id', 'FkKebutuhanStafIdOutlet')->restrictOnDelete();
            $tabel->unsignedTinyInteger('HariKe');
            $tabel->char('JamMulai', 5);
            $tabel->char('JamSelesai', 5);
            $tabel->unsignedSmallInteger('Minimal');
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdOutlet', 'HariKe'], 'IdxKebutuhanStafIdTenantIdOutletHariKe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('KebutuhanStaf');
    }
};
