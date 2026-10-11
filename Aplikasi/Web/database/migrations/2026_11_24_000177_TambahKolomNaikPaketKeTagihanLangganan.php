<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Naik paket di tengah periode (F-19, D-98): tagihan jenis `NaikPaket` menagih selisih harga paket baru dan paket lama
 * secara prorata sampai akhir periode berjalan. Rinciannya disimpan di tagihan supaya halaman tagihan bisa menjelaskan
 * angkanya dan tidak berubah setelah terbit: paket asal, hari yang ditagih, dan hari satu periode.
 * Ketiganya kosong untuk tagihan jenis lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('TagihanLangganan', 'IdPaketAsal')) {
            return;
        }

        Schema::table('TagihanLangganan', function (Blueprint $tabel): void {
            $tabel->foreignId('IdPaketAsal')->nullable()->after('IdHargaPaket')
                ->constrained('Paket', 'Id', 'FkTagihanLanggananIdPaketAsal')->restrictOnDelete();
            $tabel->unsignedSmallInteger('HariDitagih')->nullable()->after('JumlahBulan');
            $tabel->unsignedSmallInteger('HariPeriode')->nullable()->after('HariDitagih');
        });
    }

    public function down(): void
    {
        Schema::table('TagihanLangganan', function (Blueprint $tabel): void {
            $tabel->dropForeign('FkTagihanLanggananIdPaketAsal');
            $tabel->dropColumn(['IdPaketAsal', 'HariDitagih', 'HariPeriode']);
        });
    }
};
