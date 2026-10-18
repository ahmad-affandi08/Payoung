<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran\Adaptor;

use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\GerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\HasilRefund;
use App\Domain\Integrasi\GerbangPembayaran\KemampuanGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanRefund;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Dasar adaptor gerbang: pengaturan & kredensial dari konfigurasi aktif P-05, mode Sandbox/Produksi, HTTP dengan
 * batas waktu, dan pesan galat yang disaring dari kredensial.
 */
abstract class AdaptorDasar implements GerbangPembayaran
{
    /**
     * @param  array<string, string|int>  $pengaturan
     * @param  array<string, string>  $kredensial
     */
    public function __construct(protected readonly array $pengaturan, protected readonly array $kredensial) {}

    protected function CekSandbox(): bool
    {
        return ($this->pengaturan['Mode'] ?? 'Sandbox') !== 'Produksi';
    }

    protected function Pengaturan(string $kunci): string
    {
        return trim((string) ($this->pengaturan[$kunci] ?? ''));
    }

    protected function Kredensial(string $kunci): string
    {
        return $this->kredensial[$kunci] ?? '';
    }

    protected function Http(): PendingRequest
    {
        return Http::timeout(15)->acceptJson();
    }

    /**
     * @param  callable(): Response  $kirim
     */
    protected function Kirim(callable $kirim): Response
    {
        try {
            return $kirim();
        } catch (ConnectionException) {
            // Waktu habis/koneksi putus: permintaan mungkin sudah sampai dan diproses gerbang.
            throw new GalatGerbang('Gerbang pembayaran tidak bisa dihubungi. Coba lagi sebentar lagi.', tidakPasti: true);
        }
    }

    protected function Saring(string $pesan): string
    {
        foreach ($this->kredensial as $rahasia) {
            if ($rahasia !== '') {
                $pesan = str_replace($rahasia, '••••', $pesan);
            }
        }

        return mb_substr($pesan, 0, 300);
    }

    protected function GagalRespons(Response $respons, string $pesanPenyedia): GalatGerbang
    {
        return new GalatGerbang(
            $this->Saring("Gerbang menolak permintaan (HTTP {$respons->status()}): {$pesanPenyedia}"),
            tidakPasti: self::CekTidakPasti($respons),
        );
    }

    public function CekDapatCekDariNomorPesanan(): bool
    {
        return false;
    }

    /** Bawaan: QRIS dinamis, ditambah cek dari nomor pesanan bila adaptor menyatakannya. Adaptor yang bisa lebih menimpa ini. */
    public function AmbilKemampuan(): array
    {
        return $this->CekDapatCekDariNomorPesanan()
            ? [KemampuanGerbang::QrisDinamis, KemampuanGerbang::CekStatusDariNomorPesanan]
            : [KemampuanGerbang::QrisDinamis];
    }

    public function CekMendukung(KemampuanGerbang $kemampuan): bool
    {
        return in_array($kemampuan, $this->AmbilKemampuan(), true);
    }

    /** Bawaan: tidak didukung. Adaptor yang menyatakan `KemampuanGerbang::Refund` wajib menimpa ini. */
    public function Refund(PermintaanRefund $permintaan): HasilRefund
    {
        throw new GalatGerbang('Pengembalian dana lewat gerbang ini belum didukung. Kembalikan dana secara manual.');
    }

    /** Hanya 4xx yang jelas menolak (bukan 408/409/425/429) yang pasti tidak membuat apa pun di gerbang. */
    private static function CekTidakPasti(Response $respons): bool
    {
        return $respons->status() < 400 || $respons->serverError() || in_array($respons->status(), [408, 409, 425, 429], true);
    }
}
