<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Billing\NotifikasiBilling;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use App\Domain\Integrasi\Whatsapp\Saldo\BukuSaldoWhatsapp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * D-300: notifikasi atau status gerbang billing untuk top-up saldo WhatsApp (tanda tangan sudah diverifikasi pemanggil,
 * konteks tenant sudah diatur dari nomor pesanan). Idempoten: top-up yang sudah selesai tidak diproses lagi, dan saldo
 * hanya bertambah sekali karena `(TopUp, uuid)` unik di buku. Jumlah yang dibayar harus sama dengan jumlah top-up,
 * bila berbeda tidak dikreditkan dan dicatat untuk ditinjau manual.
 *
 * @return bool true bila notifikasi ini milik top-up (diproses atau sudah pernah diproses), false bila bukan.
 */
final class TerimaNotifikasiTopUpWhatsapp
{
    public function __construct(private readonly BukuSaldoWhatsapp $buku, private readonly PencatatAudit $audit) {}

    public function Jalankan(NotifikasiBilling $notifikasi): bool
    {
        $awal = TopUpSaldoWhatsapp::query()->where('RefGateway', $notifikasi->nomorPesanan)->first();

        if ($awal === null) {
            return false;
        }

        if ($notifikasi->status === StatusPembayaranGerbang::Menunggu) {
            return true;
        }

        DB::transaction(function () use ($awal, $notifikasi): void {
            $topUp = TopUpSaldoWhatsapp::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            // Uang yang masuk setelah top-up ditutup Kedaluwarsa atau Gagal tetap dikreditkan (tidak boleh hilang).
            if ($topUp->Status === StatusTopUpSaldoWhatsapp::Lunas || ($topUp->Status !== StatusTopUpSaldoWhatsapp::Menunggu && $notifikasi->status !== StatusPembayaranGerbang::Lunas)) {
                return;
            }

            if ($notifikasi->status !== StatusPembayaranGerbang::Lunas) {
                $topUp->update(['Status' => $notifikasi->status === StatusPembayaranGerbang::Kedaluwarsa ? StatusTopUpSaldoWhatsapp::Kedaluwarsa : StatusTopUpSaldoWhatsapp::Gagal]);

                return;
            }

            $dibayar = $notifikasi->jumlah === '' ? Uang::Dari($topUp->Jumlah) : Uang::Dari($notifikasi->jumlah);

            if (! $dibayar->SamaDengan(Uang::Dari($topUp->Jumlah))) {
                Log::error('Jumlah top-up saldo WhatsApp tidak sama dengan yang dibayar.', ['NomorPesanan' => $notifikasi->nomorPesanan, 'Jumlah' => $topUp->Jumlah, 'Dibayar' => $notifikasi->jumlah]);

                return;
            }

            $this->buku->TopUp($dibayar, $topUp->Uuid, $topUp->IdPengguna, 'Isi saldo '.$dibayar->FormatRupiah());
            $topUp->update(['Status' => StatusTopUpSaldoWhatsapp::Lunas, 'DibayarPada' => now()]);
            $this->audit->Catat('saldo-whatsapp.top-up-lunas', $topUp, nilaiBaru: ['Jumlah' => $topUp->Jumlah, 'IdTransaksiGerbang' => $notifikasi->idTransaksi], idTenant: $topUp->IdTenant);
        });

        return true;
    }
}
