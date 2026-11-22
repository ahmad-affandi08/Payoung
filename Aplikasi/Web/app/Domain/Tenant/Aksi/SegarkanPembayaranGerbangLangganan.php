<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * P-08 (v5.34, D-159): saat pemilik kembali dari halaman bayar DOKU (atau membuka halaman bayar Payoung) ke halaman tagihan, pembayaran gerbang yang masih
 * `Menunggu` (atau yang ditutup "belum pasti", D-305) langsung ditanyakan statusnya ke API status DOKU, tanpa menunggu webhook atau rekonsiliasi 15 menit.
 * Tagihan tetap tidak pernah lunas karena peramban (bisa dipalsukan): hasilnya sumber API status DOKU dan diproses
 * jalur yang sama dengan webhook (`TerimaNotifikasiBillingLangganan`, idempoten). Dibatasi satu pertanyaan per
 * pembayaran tiap `JEDA_DETIK` supaya muat ulang halaman tidak membanjiri DOKU. Galat apa pun tidak menggagalkan
 * halaman (rekonsiliasi terjadwal tetap jaring pengaman).
 */
final class SegarkanPembayaranGerbangLangganan
{
    public const JEDA_DETIK = 15;

    /** Pembayaran yang ditutup sebagai "belum pasti" masih ditanyakan ulang selama ini (hari) sejak dibuat. */
    public const HARI_TANYA_ULANG = 7;

    public function __construct(
        private readonly PenyediaTagihanPlatform $penyedia,
        private readonly TerimaNotifikasiBillingLangganan $terima,
    ) {}

    /** @return bool `true` bila ada pembayaran yang berubah status (halaman perlu dimuat ulang datanya). */
    public function Jalankan(PembayaranLangganan $pembayaran): bool
    {
        if (
            $pembayaran->Metode !== MetodePembayaranLangganan::Gateway
            || ! $this->CekPerluDitanyakan($pembayaran)
            || $pembayaran->RefGateway === null
            || ! $this->penyedia->CekAktifUntuk($pembayaran->MetodeBayar)
            || ! Cache::add("langganan.segarkan.{$pembayaran->Uuid}", 1, self::JEDA_DETIK)
        ) {
            return false;
        }

        try {
            $notifikasi = $this->penyedia->TanyaStatus(
                $pembayaran->RefGateway,
                $pembayaran->MetodeBayar,
                $pembayaran->RincianBayar,
                $pembayaran->KedaluwarsaPada,
                $pembayaran->DibuatPada ?? now(),
                $pembayaran->Jumlah,
            );

            if ($notifikasi === null || $notifikasi->status === StatusPembayaranGerbang::Menunggu) {
                return false;
            }

            return $this->terima->Jalankan($notifikasi);
        } catch (Throwable $galat) {
            report($galat);

            return false;
        }
    }

    /**
     * Pembayaran yang masih `Menunggu`, dan juga yang ditutup `Ditolak` karena hasilnya tak terpastikan (pertanyaan
     * status ke DOKU sendiri yang gagal): uang bisa saja sudah masuk, jadi tetap ditanyakan ulang selama tagihannya
     * masih terbuka. Bila ternyata lunas, `TerimaNotifikasiBillingLangganan` melunasi lewat pembayaran penerus.
     */
    private function CekPerluDitanyakan(PembayaranLangganan $pembayaran): bool
    {
        if ($pembayaran->Status === StatusPembayaranLangganan::Menunggu) {
            return true;
        }

        if (
            $pembayaran->Status !== StatusPembayaranLangganan::Ditolak
            || TagihanLanggananTenant::AmbilKeadaanBayar($pembayaran) !== 'TidakPasti'
            || $pembayaran->DibuatPada === null
            || $pembayaran->DibuatPada->lt(now()->subDays(self::HARI_TANYA_ULANG))
        ) {
            return false;
        }

        return TagihanLangganan::query()->whereKey($pembayaran->IdTagihanLangganan)->first()?->Status->CekTerbuka() === true;
    }
}
