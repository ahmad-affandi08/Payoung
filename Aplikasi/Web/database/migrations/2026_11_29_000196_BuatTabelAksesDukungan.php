<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PGL-16 (P-09, D-132): **akses dukungan berizin** — petugas platform masuk ke back-office tenant lewat sesi terbatas.
 *
 * - `AksesDukungan`: izin akses satu tenant. `Berizin` = Owner memberi izin (cakupan BacaSaja/BacaUbah, 1/24/72 jam,
 *   `IdPenggunaPengelola` kosong = tim dukungan mana pun); `Darurat` = Super Admin tanpa izin Owner (alasan insiden
 *   keamanan / permintaan hukum, Owner diberi tahu). Dicabut Owner kapan saja (`DicabutPada`).
 * - `PenggunaDukungan`: identitas teknis tiap petugas di sisi tenant (`Pengguna` tanpa kata sandi yang bisa dipakai
 *   dan tanpa keanggotaan tenant). Bukan akun Owner: dokumen yang disentuh petugas tercatat atas nama identitas ini,
 *   dan hak aksesnya hanya ada selama sesi yang sah (`AksesPengguna`).
 * - `SesiAksesDukungan`: satu kali masuk petugas. Token tukar sekali pakai berumur pendek (disimpan sebagai hash)
 *   menghubungkan konsol (domain pengelola) dengan back-office (domain tenant) yang cookie-nya terpisah.
 * - `AktivitasAksesDukungan`: jejak setiap permintaan dalam sesi (metode, rute, status, ditolak atau tidak) yang
 *   dibaca Owner pada riwayat akses. Append-only.
 * - `LogAudit.IdPenggunaPengelola`: penanda pelaku pengelola pada audit tenant (kolom tanpa FK: tabel ini besar dan
 *   append-only; keutuhan dijaga `PencatatAudit`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PenggunaDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdPenggunaPengelola')->constrained('PenggunaPengelola', 'Id', 'FkPenggunaDukunganIdPenggunaPengelola')->restrictOnDelete();
            $tabel->foreignId('IdPengguna')->constrained('Pengguna', 'Id', 'FkPenggunaDukunganIdPengguna')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique('IdPenggunaPengelola', 'UniqPenggunaDukunganIdPenggunaPengelola');
            $tabel->unique('IdPengguna', 'UniqPenggunaDukunganIdPengguna');
        });

        Schema::create('AksesDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAksesDukunganIdTenant')->restrictOnDelete();
            // Berizin / Darurat.
            $tabel->string('Jenis', 10);
            // Darurat: Super Admin yang membuka. Berizin: kosong (tim dukungan).
            $tabel->foreignId('IdPenggunaPengelola')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkAksesDukunganIdPenggunaPengelola')->restrictOnDelete();
            $tabel->foreignId('DiizinkanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkAksesDukunganDiizinkanOleh')->restrictOnDelete();
            $tabel->string('NamaPemberi', 150)->nullable();
            $tabel->foreignId('IdTiketDukungan')->nullable()->constrained('TiketDukungan', 'Id', 'FkAksesDukunganIdTiketDukungan')->restrictOnDelete();
            // BacaSaja / BacaUbah.
            $tabel->string('Cakupan', 10);
            // Data pribadi pelanggan tersamar kecuali Owner membukanya (BR-P09.1).
            $tabel->boolean('DataPribadiTerbuka')->default(false);
            $tabel->string('Alasan', 500)->nullable();
            // InsidenKeamanan / PermintaanHukum (hanya Darurat).
            $tabel->string('KategoriDarurat', 20)->nullable();
            $tabel->timestamp('MulaiPada');
            $tabel->timestamp('BerakhirPada');
            $tabel->timestamp('DicabutPada')->nullable();
            $tabel->foreignId('DicabutOleh')->nullable()->constrained('Pengguna', 'Id', 'FkAksesDukunganDicabutOleh')->restrictOnDelete();
            $tabel->foreignId('DicabutOlehPengelola')->nullable()->constrained('PenggunaPengelola', 'Id', 'FkAksesDukunganDicabutOlehPengelola')->restrictOnDelete();
            $tabel->timestamp('PemilikDiberitahuPada')->nullable();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'BerakhirPada'], 'IdxAksesDukunganIdTenantBerakhirPada');
            $tabel->index(['IdTenant', 'Jenis', 'DibuatPada'], 'IdxAksesDukunganIdTenantJenisDibuatPada');
        });

        Schema::create('SesiAksesDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkSesiAksesDukunganIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdAksesDukungan')->constrained('AksesDukungan', 'Id', 'FkSesiAksesDukunganIdAksesDukungan')->restrictOnDelete();
            $tabel->foreignId('IdPenggunaPengelola')->constrained('PenggunaPengelola', 'Id', 'FkSesiAksesDukunganIdPenggunaPengelola')->restrictOnDelete();
            // Salinan nama petugas supaya sisi tenant tidak perlu membaca data pengelola.
            $tabel->string('NamaPetugas', 150);
            $tabel->char('HashToken', 64);
            $tabel->timestamp('TokenKedaluwarsaPada');
            $tabel->timestamp('TokenDipakaiPada')->nullable();
            $tabel->timestamp('MulaiPada')->nullable();
            $tabel->timestamp('BerakhirPada');
            $tabel->timestamp('DiakhiriPada')->nullable();
            // Selesai / Dicabut / Kedaluwarsa / Digantikan.
            $tabel->string('AlasanAkhir', 12)->nullable();
            $tabel->string('Ip', 45)->nullable();
            $tabel->string('AgenPengguna', 500)->nullable();
            $tabel->WaktuStandar();
            $tabel->unique('HashToken', 'UniqSesiAksesDukunganHashToken');
            $tabel->index(['IdTenant', 'IdAksesDukungan'], 'IdxSesiAksesDukunganIdTenantIdAksesDukungan');
            $tabel->index(['IdPenggunaPengelola', 'DiakhiriPada'], 'IdxSesiAksesDukunganIdPenggunaPengelolaDiakhiriPada');
        });

        Schema::create('AktivitasAksesDukungan', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkAktivitasAksesDukunganIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdSesiAksesDukungan')->constrained('SesiAksesDukungan', 'Id', 'FkAktivitasAksesDukunganIdSesiAksesDukungan')->restrictOnDelete();
            $tabel->string('Metode', 7);
            $tabel->string('Rute', 150)->nullable();
            // Jalur tanpa query string (query bisa memuat kata kunci pencarian berisi data pribadi).
            $tabel->string('Jalur', 255);
            $tabel->unsignedSmallInteger('StatusHttp');
            $tabel->boolean('Ditolak')->default(false);
            $tabel->string('Alasan', 200)->nullable();
            $tabel->timestamp('DibuatPada');
            $tabel->index(['IdTenant', 'IdSesiAksesDukungan'], 'IdxAktivitasAksesDukunganIdTenantIdSesiAksesDukungan');
            $tabel->index(['IdTenant', 'DibuatPada'], 'IdxAktivitasAksesDukunganIdTenantDibuatPada');
        });

        Schema::table('LogAudit', function (Blueprint $tabel): void {
            $tabel->unsignedBigInteger('IdPenggunaPengelola')->nullable()->after('IdPerangkat');
        });
    }

    public function down(): void
    {
        Schema::table('LogAudit', function (Blueprint $tabel): void {
            $tabel->dropColumn('IdPenggunaPengelola');
        });
        Schema::dropIfExists('AktivitasAksesDukungan');
        Schema::dropIfExists('SesiAksesDukungan');
        Schema::dropIfExists('AksesDukungan');
        Schema::dropIfExists('PenggunaDukungan');
    }
};
