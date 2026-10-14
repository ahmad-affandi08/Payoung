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
    /**
     * Idempoten dan tanpa sintaks khusus MySQL: versi pertama memakai `GENERATED ALWAYS AS (...) STORED NULL` lewat SQL
     * mentah, yang **ditolak MariaDB** (kesalahan 1064 di produksi) setelah tiga langkah pertama sudah terlanjur
     * berjalan (DDL tidak bisa di-rollback). Migrasi itu tidak pernah selesai di mana pun, jadi isinya diperbaiki di
     * tempat: tiap langkah memeriksa keadaan sekarang supaya aman dijalankan ulang dari keadaan setengah jadi, dan kolom
     * turunan dibuat lewat Blueprint (`storedAs`) seperti migrasi 000149 yang terbukti jalan di MariaDB.
     */
    public function up(): void
    {
        if ($this->AdaIndeks('UniqKendaraanIdTenantNomorPolisiAktif')) {
            Schema::table('Kendaraan', function (Blueprint $tabel): void {
                $tabel->dropUnique('UniqKendaraanIdTenantNomorPolisiAktif');
            });
        }

        if (Schema::hasColumn('Kendaraan', 'KunciNomorPolisiAktif')) {
            Schema::table('Kendaraan', function (Blueprint $tabel): void {
                $tabel->dropColumn('KunciNomorPolisiAktif');
            });
        }

        DB::statement('ALTER TABLE `Kendaraan` MODIFY `NomorPolisi` VARCHAR(40) NULL');

        if (! Schema::hasColumn('Kendaraan', 'Jenis')) {
            DB::statement("ALTER TABLE `Kendaraan` ADD `Jenis` VARCHAR(12) NOT NULL DEFAULT 'Kendaraan' AFTER `IdPelanggan`");
        }

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->string('KunciNomorPolisiAktif', 40)->nullable()->storedAs('IF(`Aktif`, `NomorPolisi`, NULL)');
        });

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciNomorPolisiAktif'], 'UniqKendaraanIdTenantNomorPolisiAktif');

            if (! $this->AdaIndeks('IdxKendaraanIdTenantJenis')) {
                $tabel->index(['IdTenant', 'Jenis'], 'IdxKendaraanIdTenantJenis');
            }
        });
    }

    private function AdaIndeks(string $nama): bool
    {
        foreach (Schema::getIndexes('Kendaraan') as $indeks) {
            if (strcasecmp((string) $indeks['name'], $nama) === 0) {
                return true;
            }
        }

        return false;
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

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->string('KunciNomorPolisiAktif', 20)->nullable()->storedAs('IF(`Aktif`, `NomorPolisi`, NULL)');
        });

        Schema::table('Kendaraan', function (Blueprint $tabel): void {
            $tabel->unique(['IdTenant', 'KunciNomorPolisiAktif'], 'UniqKendaraanIdTenantNomorPolisiAktif');
        });
    }
};
