<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Data;

use App\Domain\Penjualan\Enum\JangkaKuotaTransaksi;
use App\Domain\Penjualan\Enum\StatusKuotaTransaksi;
use Carbon\CarbonImmutable;

/**
 * Ringkasan kuota transaksi penjualan satu tenant pada satu jangka. `Periode` = `Y-m` (bulan kalender WIB) untuk
 * jangka Bulan, atau `Y-m-d` (tanggal bisnis outlet acuan) untuk jangka Hari. `Batas` dan `Sisa` null = tak terbatas.
 * `BerakhirPada` (hanya jangka Hari dengan outlet acuan) = saat hari bisnis itu berakhir: aplikasi Kasir tidak
 * menegakkan data kuota sesudahnya sampai konfigurasi dibaca ulang.
 */
final readonly class DataKuotaTransaksi
{
    public function __construct(
        public JangkaKuotaTransaksi $jangka,
        public string $periode,
        public int $terpakai,
        public ?int $batas,
        public StatusKuotaTransaksi $status,
        public ?CarbonImmutable $berakhirPada = null,
    ) {}

    public function AmbilSisa(): ?int
    {
        return $this->batas === null ? null : max(0, $this->batas - $this->terpakai);
    }

    /** Persen terpakai (0–100, dibulatkan ke atas bila bukan bilangan bulat, maksimal 100); null bila tanpa batas. */
    public function AmbilPersen(): ?int
    {
        if ($this->batas === null) {
            return null;
        }

        return $this->batas === 0 ? 100 : min(100, (int) ceil($this->terpakai * 100 / $this->batas));
    }

    public function CekHabis(): bool
    {
        return $this->status === StatusKuotaTransaksi::Habis;
    }

    /**
     * Bentuk kontrak API/Inertia (key = PascalCase).
     *
     * @return array{Jangka: string, Periode: string, Terpakai: int, Batas: int|null, Sisa: int|null, Persen: int|null, Status: string, LabelStatus: string, BerakhirPada: string|null}
     */
    public function KeLarik(): array
    {
        return [
            'Jangka' => $this->jangka->value,
            'Periode' => $this->periode,
            'Terpakai' => $this->terpakai,
            'Batas' => $this->batas,
            'Sisa' => $this->AmbilSisa(),
            'Persen' => $this->AmbilPersen(),
            'Status' => $this->status->value,
            'LabelStatus' => $this->status->AmbilLabel(),
            'BerakhirPada' => $this->berakhirPada?->utc()->toIso8601ZuluString(),
        ];
    }
}
