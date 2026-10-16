<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penagih menolak klaim pembayaran pelanggan (F-21 bagian 4, D-169), dengan alasan wajib (5–255 karakter): uang tidak
 * ditemukan di mutasi bank, jumlah tidak cocok, dan sebagainya. Menolak yang sudah ditolak mengembalikannya; klaim yang
 * sudah diterima tidak bisa ditolak (pembayarannya sudah tercatat, batalkan lewat pelunasan piutang).
 */
final class TolakKonfirmasiPembayaranInvoice
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanWajib, KonfirmasiTidakDitemukan, KonfirmasiSudahDiterima
     */
    public function Jalankan(string $uuidInvoice, string $uuidKonfirmasi, string $alasan, int $idPengguna): KonfirmasiPembayaranInvoice
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Alasan penolakan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuidInvoice, $uuidKonfirmasi, $alasan, $idPengguna): KonfirmasiPembayaranInvoice {
            $invoice = Invoice::query()->where('Uuid', $uuidInvoice)->first()
                ?? throw new PelanggaranAturanBisnis('KonfirmasiTidakDitemukan', 'Invoice tidak ditemukan.', 'Umum', 404);
            $konfirmasi = KonfirmasiPembayaranInvoice::query()->where('Uuid', $uuidKonfirmasi)->where('IdInvoice', $invoice->Id)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('KonfirmasiTidakDitemukan', 'Konfirmasi pembayaran tidak ditemukan.', 'Umum', 404);

            if ($konfirmasi->Status === StatusKonfirmasiPembayaran::Ditolak) {
                return $konfirmasi;
            }

            if ($konfirmasi->Status === StatusKonfirmasiPembayaran::Diterima) {
                throw new PelanggaranAturanBisnis('KonfirmasiSudahDiterima', 'Konfirmasi ini sudah diterima dan pembayarannya tercatat. Batalkan lewat pelunasan piutang bila keliru.', 'Umum', 409);
            }

            $konfirmasi->UbahStatus(StatusKonfirmasiPembayaran::Ditolak);
            $konfirmasi->fill(['AlasanTolak' => $alasan, 'DiputuskanOleh' => $idPengguna, 'DiputuskanPada' => CarbonImmutable::now()])->save();
            $this->audit->Catat('invoice.konfirmasi-tolak', $invoice, nilaiBaru: ['Nomor' => $invoice->Nomor, 'Jumlah' => $konfirmasi->Jumlah, 'Alasan' => $alasan], idPengguna: $idPengguna);

            return $konfirmasi;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
