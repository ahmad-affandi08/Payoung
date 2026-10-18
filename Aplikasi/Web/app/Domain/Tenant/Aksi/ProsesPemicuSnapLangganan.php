<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifikasi DOKU SNAP untuk pembayaran tagihan langganan (D-159). Isi notifikasi **tidak dipercaya**: ia hanya memberi
 * tahu bahwa ada kabar untuk satu nomor pesanan. Statusnya selalu ditanyakan sendiri ke API status DOKU (permintaan
 * kita bertanda tangan), lalu diproses jalur yang sama dengan Checkout dan rekonsiliasi (`TerimaNotifikasiBillingLangganan`,
 * idempoten). Dengan begitu bentuk tanda tangan atau badan notifikasi yang berbeda dari dugaan tidak bisa melunasi
 * tagihan palsu, dan paling buruk notifikasi diabaikan lalu pembayaran terdeteksi lewat polling halaman bayar atau
 * rekonsiliasi terjadwal.
 *
 * Tenant ditetapkan dari nomor pesanan lalu semua kueri lewat scope `MilikTenant` (pola `TerimaNotifikasiBillingLangganan`).
 * Dibatasi satu pertanyaan per pembayaran tiap `JEDA_DETIK` supaya notifikasi berulang tidak membanjiri DOKU.
 */
final class ProsesPemicuSnapLangganan
{
    public const JEDA_DETIK = 10;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenyediaTagihanPlatform $penyedia,
        private readonly TerimaNotifikasiBillingLangganan $terima,
    ) {}

    /**
     * @return array{Dikenal: bool, Metode: string|null} `Dikenal` = nomor pesanan ini milik pembayaran SNAP yang ada
     */
    public function Jalankan(string $nomorPesanan): array
    {
        $bagian = NomorPesananBilling::Urai($nomorPesanan);

        if ($bagian === null) {
            return ['Dikenal' => false, 'Metode' => null];
        }

        $this->konteks->Atur($bagian['IdTenant']);
        $pembayaran = PembayaranLangganan::query()->where('RefGateway', $nomorPesanan)->first();

        if ($pembayaran === null || $pembayaran->Metode !== MetodePembayaranLangganan::Gateway || $pembayaran->MetodeBayar === null) {
            Log::warning('Notifikasi SNAP tanpa pembayaran yang cocok.', ['NomorPesanan' => $nomorPesanan]);

            return ['Dikenal' => false, 'Metode' => null];
        }

        $hasil = ['Dikenal' => true, 'Metode' => $pembayaran->MetodeBayar];

        if ($pembayaran->Status !== StatusPembayaranLangganan::Menunggu || ! Cache::add("langganan.pemicu-snap.{$pembayaran->Uuid}", 1, self::JEDA_DETIK)) {
            return $hasil;
        }

        try {
            $notifikasi = $this->penyedia->TanyaStatus(
                $nomorPesanan,
                $pembayaran->MetodeBayar,
                $pembayaran->RincianBayar,
                $pembayaran->KedaluwarsaPada,
                $pembayaran->DibuatPada ?? now(),
                $pembayaran->Jumlah,
            );

            if ($notifikasi !== null && $notifikasi->status !== StatusPembayaranGerbang::Menunggu) {
                $this->terima->Jalankan($notifikasi);
            }
        } catch (Throwable $galat) {
            // Dijawab sukses tetap: rekonsiliasi 15 menit dan polling halaman bayar menjadi jaring pengaman.
            report($galat);
        }

        return $hasil;
    }
}
