<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-05 (D-131): **jurnal umum manual** `JM/{YYYY}/{MM}/{SEQ4}` oleh Akuntan/Pemilik — dokumen sumber bagi satu `Jurnal`
 * seimbang yang barisnya dipilih sendiri (penyesuaian akrual, koreksi, saldo awal akun neraca, pembagian beban).
 *
 * Dokumen ini append-only (aturan #8): tidak pernah diubah atau dihapus; koreksi hanya lewat dokumen pembalik
 * (`IdJurnalUmumDibalik`) yang jurnalnya mencerminkan jurnal asal. Barisnya tidak disalin ke tabel ini: satu-satunya
 * sumber kebenaran baris adalah `JurnalDetail` milik jurnalnya (ditemukan lewat `Jurnal.JenisSumber/IdSumber`), sama
 * seperti `TransaksiKasBank`. `TotalNilai` hanya ringkasan untuk daftar.
 *
 * Dua penjaga di skema, bukan hanya di kode:
 * - `UuidKlien` unik per tenant: formulir yang terkirim dua kali (klik ganda, koneksi putus lalu ulang) menghasilkan
 *   satu dokumen, bukan dua jurnal kembar.
 * - `IdJurnalUmumDibalik` unik per tenant: satu dokumen hanya bisa dibalik sekali walau dua pembalik dibuat bersamaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('JurnalUmum', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkJurnalUmumIdTenant')->restrictOnDelete();
            $tabel->string('Nomor', 30);
            // Umum / Penyesuaian / Koreksi (JenisJurnalUmum); pembalik mewarisi jenis dokumen asalnya.
            $tabel->string('Jenis', 20);
            $tabel->date('Tanggal');
            // Nomor bukti di luar sistem (kuitansi, memo internal, nomor jurnal akuntan eksternal), opsional.
            $tabel->string('Referensi', 100)->nullable();
            $tabel->string('Keterangan', 255);
            // ULID buatan formulir untuk idempotensi kiriman ganda.
            $tabel->string('UuidKlien', 26);
            $tabel->decimal('TotalNilai', 18, 2);
            $tabel->string('PathLampiran', 255)->nullable();
            $tabel->string('NamaLampiran', 150)->nullable();
            $tabel->string('MimeLampiran', 100)->nullable();
            $tabel->unsignedInteger('UkuranLampiran')->nullable();
            $tabel->unsignedBigInteger('IdJurnalUmumDibalik')->nullable();
            $tabel->foreignId('DibuatOleh')->nullable()->constrained('Pengguna', 'Id', 'FkJurnalUmumDibuatOleh')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->unique(['IdTenant', 'Nomor'], 'UniqJurnalUmumIdTenantNomor');
            $tabel->unique(['IdTenant', 'UuidKlien'], 'UniqJurnalUmumIdTenantUuidKlien');
            $tabel->unique(['IdTenant', 'IdJurnalUmumDibalik'], 'UniqJurnalUmumIdTenantIdJurnalUmumDibalik');
            $tabel->index(['IdTenant', 'Tanggal'], 'IdxJurnalUmumIdTenantTanggal');
            $tabel->foreign('IdJurnalUmumDibalik', 'FkJurnalUmumIdJurnalUmumDibalik')->references('Id')->on('JurnalUmum')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('JurnalUmum');
    }
};
