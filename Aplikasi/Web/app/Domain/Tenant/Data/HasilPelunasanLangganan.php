<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Data;

use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use Carbon\CarbonImmutable;

/**
 * Nilai sebelum & sesudah satu pelunasan tagihan langganan (BR-P08.9), dipakai pemanggil untuk mencatat audit.
 * `PelunasTagihanLangganan` sengaja tidak mencatat audit sendiri karena pelakunya berbeda per jalur: verifikator
 * pengelola pada transfer manual, dan gerbang pembayaran pada notifikasi webhook.
 */
final class HasilPelunasanLangganan
{
    /**
     * @param  array{Mulai: CarbonImmutable, Selesai: CarbonImmutable}  $periode
     * @param  array<string, string|int|null>  $langgananLama
     */
    public function __construct(
        public readonly array $periode,
        public readonly array $langgananLama,
        public readonly string $statusTagihanLama,
        public readonly bool $lanjutan,
        public readonly bool $langgananTidakBerubah = false,
    ) {}

    /**
     * Bentuk nilai baru langganan untuk audit; sama di kedua jalur agar riwayatnya bisa dibandingkan.
     *
     * @return array<string, string|int|null>
     */
    public function LanggananBaru(int $idPaket, int $jumlahBulan): array
    {
        // D-49: pelunasan tagihan add-on tidak mengubah paket, siklus, atau periode langganan.
        if ($this->langgananTidakBerubah) {
            return $this->langgananLama;
        }

        return [
            'Status' => StatusLangganan::Aktif->value,
            'IdPaket' => $idPaket,
            'SiklusTagihan' => SiklusTagihan::DariJumlahBulan($jumlahBulan)->value,
            'JumlahBulanSiklus' => $jumlahBulan,
            'PeriodeMulai' => $this->periode['Mulai']->toIso8601ZuluString(),
            'PeriodeSelesai' => $this->periode['Selesai']->toIso8601ZuluString(),
        ];
    }
}
