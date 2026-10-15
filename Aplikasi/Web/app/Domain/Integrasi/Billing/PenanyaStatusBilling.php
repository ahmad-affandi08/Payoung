<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Billing;

use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Integrasi\Snap\GerbangBillingSnap;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Satu pintu untuk menanyakan status pembayaran tagihan langganan ke gerbang yang benar: DOKU SNAP bila pembayaran
 * dibuat lewat halaman bayar Payoung (`MetodeBayar` terisi), DOKU Checkout bila tidak (D-159). Dipakai halaman tagihan
 * (segarkan), rekonsiliasi terjadwal, dan pemicu webhook SNAP.
 */
final class PenanyaStatusBilling
{
    public function __construct(
        private readonly GerbangBillingPlatform $checkout,
        private readonly GerbangBillingSnap $snap,
    ) {}

    /** Ada gerbang yang aktif untuk menjawab status pembayaran jenis ini? */
    public function CekAktif(?string $metodeBayar = null): bool
    {
        return $metodeBayar !== null ? $this->snap->CekAktif() : $this->checkout->CekAktif();
    }

    public function CekAdaGerbangAktif(): bool
    {
        return $this->checkout->CekAktif() || $this->snap->CekAktif();
    }

    /**
     * @param  array<string, string>|null  $rincian
     * @param  string  $jumlah  total tagihan (string desimal), dipakai e-wallet saat menanyakan status
     */
    public function Tanya(
        string $nomorPesanan,
        ?string $metodeBayar,
        ?array $rincian,
        ?DateTimeInterface $kedaluwarsaPada,
        DateTimeInterface $dibuatPada,
        string $jumlah,
    ): ?NotifikasiBilling {
        $metode = $metodeBayar !== null ? MetodeBayarSnap::tryFrom($metodeBayar) : null;

        if ($metode === null) {
            return $this->checkout->CekStatus($nomorPesanan, $dibuatPada);
        }

        // Tanpa rincian (kode bayar tidak sempat tersimpan) tidak ada yang bisa ditanyakan.
        if ($rincian === null || $rincian === []) {
            return null;
        }

        $batas = $kedaluwarsaPada ?? CarbonImmutable::instance($dibuatPada)->addMinutes(GerbangBillingSnap::MENIT_KEDALUWARSA);

        return $this->snap->CekStatus($nomorPesanan, $metode, $rincian, $batas, $jumlah);
    }
}
