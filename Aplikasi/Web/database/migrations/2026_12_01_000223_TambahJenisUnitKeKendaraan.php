<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servis non-kendaraan (§9.6 elektronik, §9.10 work order, D-142): master `Kendaraan` kini menampung "unit servis"
 * pelanggan berjenis `Kendaraan` (bawaan, perilaku lama) atau `Perangkat` (HP, laptop, televisi, ...). Untuk Perangkat,
 * `NomorPolisi` memuat IMEI / nomor seri dan **boleh kosong** (pelanggan tidak selalu tahu), sehingga kolom dilonggarkan
 * dan diperlebar ke 40 karakter beserta kolom turunan keunikannya. Hanya mengembangkan skema (expand): baris lama tetap
 * Kendaraan dan tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->dropUnique('UniqKendaraanIdTenantNomorPolisiAktif');
        });

        DB::statement('ALTER TABLE `Kendaraan` DROP COLUMN `KunciNomorPolisiAktif`');
        DB::statement('ALTER TABLE `Kendaraan` MODIFY `NomorPolisi` VARCHAR(40) NULL');
        DB::statement("ALTER TABLE `Kendaraan` ADD `Jenis` VARCHAR(12) NOT NULL DEFAULT 'Kendaraan' AFTER `IdPelanggan`");
        DB::statement('ALTER TABLE `Kendaraan` ADD `KunciNomorPolisiAktif` VARCHAR(40) GENERATED ALWAYS AS (IF(`Aktif`, `NomorPolisi`, NULL)) STORED NULL');

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciNomorPolisiAktif'], 'UniqKendaraanIdTenantNomorPolisiAktif');
            $tabel->index(['IdTenant', 'Jenis'], 'IdxKendaraanIdTenantJenis');
        });
    }

    public function down(): void
    {
        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->dropIndex('IdxKendaraanIdTenantJenis');
            $tabel->dropUnique('UniqKendaraanIdTenantNomorPolisiAktif');
        });

        DB::statement('ALTER TABLE `Kendaraan` DROP COLUMN `KunciNomorPolisiAktif`');
        DB::statement('ALTER TABLE `Kendaraan` DROP COLUMN `Jenis`');
        DB::statement("UPDATE `Kendaraan` SET `NomorPolisi` = CONCAT('TANPA-', `Id`) WHERE `NomorPolisi` IS NULL");
        DB::statement('ALTER TABLE `Kendaraan` MODIFY `NomorPolisi` VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE `Kendaraan` ADD `KunciNomorPolisiAktif` VARCHAR(20) GENERATED ALWAYS AS (IF(`Aktif`, `NomorPolisi`, NULL)) STORED NULL');

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciNomorPolisiAktif'], 'UniqKendaraanIdTenantNomorPolisiAktif');
        });
    }
};
