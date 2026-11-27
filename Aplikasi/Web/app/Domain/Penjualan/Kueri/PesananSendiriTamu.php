<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Penjualan\Enum\StatusPesananSendiri;
use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Layanan\PenandaKedaluwarsaPesananSendiri;
use App\Domain\Penjualan\Model\PesananSendiri;
use App\Domain\Penjualan\Model\PesananTerbuka;

/**
 * F-17: status pesanan QR untuk halaman tamu (polling tiap 5 detik). Hanya pesanan meja token yang sama; tidak
 * membuka data staf/perangkat pemroses. `Perkiraan` (PRD v2.06) = estimasi total saat dikirim (null untuk pesanan lama).
 *
 * `Pembayaran` (D-338) dibaca dari pesanan terbuka yang ditautkan saat kasir menerima pesanan: `BelumDibayar`, `Dibayar`
 * (tagihan meja sudah dilunasi di kasir), atau `Dibatalkan`. Null selama pesanan belum diterima kasir. Tagihan yang
 * digabung ke meja lain tetap `BelumDibayar` (tautan tujuan gabung tidak disimpan), jadi halaman tamu mengandalkan
 * batas waktu kartu. Pesanan terbuka yang belum sampai ke server (kasir sedang luring) juga `BelumDibayar`.
 */
final class PesananSendiriTamu
{
    public function __construct(private readonly PenandaKedaluwarsaPesananSendiri $kedaluwarsa) {}

    /**
     * @return array{Uuid: string, Nomor: string, Status: string, Pembayaran: string|null, Baris: list<array<string, mixed>>, Subtotal: string, Perkiraan: array<string, mixed>|null, AlasanTolak: string|null}|null
     */
    public function Ambil(int $idMeja, string $uuid): ?array
    {
        $this->kedaluwarsa->TandaiMeja($idMeja);
        $pesanan = PesananSendiri::query()->where('IdMeja', $idMeja)->where('Uuid', $uuid)->first();

        return $pesanan === null ? null : self::Susun($pesanan);
    }

    /**
     * @return array{Uuid: string, Nomor: string, Status: string, Pembayaran: string|null, Baris: list<array<string, mixed>>, Subtotal: string, Perkiraan: array<string, mixed>|null, AlasanTolak: string|null}
     */
    public static function Susun(PesananSendiri $pesanan): array
    {
        return [
            'Uuid' => $pesanan->Uuid,
            'Nomor' => $pesanan->Nomor,
            'Status' => $pesanan->Status->value,
            'Pembayaran' => self::KeadaanBayar($pesanan),
            'Baris' => $pesanan->Baris,
            'Subtotal' => $pesanan->Subtotal,
            'Perkiraan' => $pesanan->Perkiraan,
            'AlasanTolak' => $pesanan->AlasanTolak,
        ];
    }

    /** @return 'BelumDibayar'|'Dibayar'|'Dibatalkan'|null */
    private static function KeadaanBayar(PesananSendiri $pesanan): ?string
    {
        if ($pesanan->Status !== StatusPesananSendiri::Diterima || $pesanan->UuidPesananTerbuka === null) {
            return null;
        }

        $tagihan = PesananTerbuka::query()->where('Uuid', $pesanan->UuidPesananTerbuka)->first(['Id', 'Status']);

        return match ($tagihan?->Status) {
            StatusPesananTerbuka::Dibayar => 'Dibayar',
            StatusPesananTerbuka::Dibatalkan => 'Dibatalkan',
            default => 'BelumDibayar',
        };
    }
}
