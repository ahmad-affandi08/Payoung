<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Billing;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Snap\Data\PembayaranSnapDibuat;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Integrasi\Snap\GerbangBillingSnap;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Adaptor DOKU untuk `PenyediaTagihanPlatform`: Checkout lewat `GerbangBillingPlatform` dan halaman bayar Payoung
 * (QRIS/VA/DANA/ShopeePay) lewat `GerbangBillingSnap` (DOKU SNAP, D-159). Hanya membungkus; seluruh protokol DOKU tetap
 * di dua kelas itu dan `ProtokolDoku`.
 */
final class AdaptorTagihanDoku implements PenyediaTagihanPlatform
{
    public const KODE = 'Doku';

    public function __construct(
        private readonly GerbangBillingPlatform $checkout,
        private readonly GerbangBillingSnap $snap,
    ) {}

    public function AmbilKode(): string
    {
        return self::KODE;
    }

    public function AmbilLabel(): string
    {
        return 'DOKU';
    }

    public function CekAktif(): bool
    {
        return $this->CekCheckoutAktif() || $this->CekHalamanBayarAktif();
    }

    public function CekCheckoutAktif(): bool
    {
        return $this->checkout->CekAktif();
    }

    public function CekHalamanBayarAktif(): bool
    {
        return $this->snap->CekAktif();
    }

    public function CekAktifUntuk(?string $metodeBayar): bool
    {
        return $metodeBayar !== null ? $this->CekHalamanBayarAktif() : $this->CekCheckoutAktif();
    }

    public function AmbilPilihanHalamanBayar(): array
    {
        return $this->snap->AmbilPilihan();
    }

    public function AmbilMenitKedaluwarsaHalamanBayar(): int
    {
        return GerbangBillingSnap::MENIT_KEDALUWARSA;
    }

    public function BuatCheckout(string $nomorPesanan, Uang $total, string $namaPembayar, string $emailPembayar, string $urlKembali): HasilPembayaranBilling
    {
        return $this->checkout->BuatTransaksi($nomorPesanan, $total, $namaPembayar, $emailPembayar, $urlKembali);
    }

    public function BuatPembayaranHalamanBayar(
        MetodeBayarSnap $metode,
        ?string $kanal,
        string $nomorPesanan,
        Uang $total,
        string $namaPembayar,
        string $emailPembayar,
        string $urlKembali,
        ?string $ipPelanggan = null,
    ): PembayaranSnapDibuat {
        return $this->snap->Buat($metode, $kanal, $nomorPesanan, $total, $namaPembayar, $emailPembayar, $urlKembali, $ipPelanggan);
    }

    public function TanyaStatus(
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

    public function CekTandaTanganSah(Request $permintaan): bool
    {
        return $this->checkout->CekTandaTanganSah($permintaan);
    }

    public function UraiNotifikasi(Request $permintaan): ?NotifikasiBilling
    {
        return $this->checkout->UraiNotifikasi($permintaan);
    }

    public function Konfirmasi(NotifikasiBilling $notifikasi): ?NotifikasiBilling
    {
        return $this->checkout->Konfirmasi($notifikasi);
    }
}
