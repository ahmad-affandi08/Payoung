<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Layanan;

use App\Domain\Pengelola\Tagihan\Surel\RefundLanggananDisetujui;
use App\Domain\Pengelola\Tenant\Kueri\PemilikTenant;
use App\Domain\Tenant\Model\RefundLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Email ke Owner setelah refund disetujui dan transaksinya tersimpan; kegagalan email tidak membatalkan refund. */
final class PemberitahuRefundLangganan
{
    public function __construct(private readonly PemilikTenant $pemilik) {}

    public function Kirim(TagihanLangganan $tagihan, RefundLangganan $refund): void
    {
        foreach ($this->pemilik->Ambil($tagihan->IdTenant) as $pemilik) {
            try {
                Mail::to($pemilik['Email'])->queue(new RefundLanggananDisetujui(
                    nama: $pemilik['Nama'],
                    nomorTagihan: $tagihan->Nomor,
                    jumlah: $refund->AmbilJumlah()->FormatRupiah(),
                    catatan: $refund->CatatanPengembalian,
                ));
            } catch (Throwable $galat) {
                Log::warning('Email refund langganan gagal dikirim.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor, 'Galat' => $galat->getMessage()]);
            }
        }
    }
}
