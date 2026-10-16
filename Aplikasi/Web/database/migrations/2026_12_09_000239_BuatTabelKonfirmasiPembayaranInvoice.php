<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfirmasi pembayaran dari pelanggan di halaman publik invoice (F-21 bagian 4, D-169): "Saya sudah bayar". Ini **klaim**
 * pelanggan yang menunggu diverifikasi penagih terhadap mutasi bank; ia bukan pembayaran dan tidak membukukan apa pun.
 * Pembayaran tetap lahir dari `SimpanPembayaranPiutang` saat penagih menerimanya (`IdPembayaranPiutang` menautkan).
 *
 * - `NamaPengirim` dan `Catatan` diketik pelanggan tanpa login (data pribadi minimal, diblankan saat penghapusan data).
 * - Status `Menunggu → Diterima | Ditolak`. "Tidak diperlukan lagi" (invoice sudah lunas atau dibatalkan) diturunkan,
 *   bukan disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('KonfirmasiPembayaranInvoice', function (Blueprint $tabel): void {
            $tabel->id('Id');
            $tabel->UuidPublik();
            $tabel->foreignId('IdTenant')->constrained('Tenant', 'Id', 'FkKonfirmasiBayarInvoiceIdTenant')->restrictOnDelete();
            $tabel->foreignId('IdInvoice')->constrained('Invoice', 'Id', 'FkKonfirmasiBayarInvoiceIdInvoice')->restrictOnDelete();
            $tabel->string('Status', 12);
            $tabel->string('NamaPengirim', 100);
            $tabel->date('TanggalBayar');
            $tabel->decimal('Jumlah', 18, 2);
            $tabel->string('Catatan', 300)->nullable();
            $tabel->foreignId('DiputuskanOleh')->nullable()->constrained('Pengguna', 'Id', 'FkKonfirmasiBayarInvoiceDiputuskanOleh')->restrictOnDelete();
            $tabel->timestamp('DiputuskanPada')->nullable();
            $tabel->string('AlasanTolak', 255)->nullable();
            $tabel->foreignId('IdPembayaranPiutang')->nullable()->constrained('PembayaranPiutang', 'Id', 'FkKonfirmasiBayarInvoiceIdPembayaranPiutang')->restrictOnDelete();
            $tabel->WaktuStandar();
            $tabel->index(['IdTenant', 'IdInvoice', 'Status'], 'IdxKonfirmasiBayarInvoiceIdTenantIdInvoiceStatus');
            $tabel->index(['IdTenant', 'Status', 'DibuatPada'], 'IdxKonfirmasiBayarInvoiceIdTenantStatusDibuatPada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('KonfirmasiPembayaranInvoice');
    }
};
