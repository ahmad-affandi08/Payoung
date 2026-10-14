<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Layanan\BukuGiftCard;
use App\Domain\Pelanggan\Layanan\PenutupSaldoGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menghanguskan gift card yang masa berlakunya lewat (CRM-06, D-153) untuk tenant aktif: kartu `Aktif` dengan
 * `BerlakuSampai` sebelum [hariIni] ditutup `Hangus` (sumber `Jadwal`, idempoten per kartu), sisa saldonya diakui
 * sebagai Pendapatan Lain. Kartu `Habis` (saldo nol) hanya ditandai hangus tanpa jurnal. Hasil: jumlah kartu ditutup.
 */
final class HanguskanGiftCardKedaluwarsa
{
    public function __construct(
        private readonly BukuGiftCard $buku,
        private readonly PenutupSaldoGiftCard $penutup,
    ) {}

    public function Jalankan(CarbonImmutable $hariIni): int
    {
        $total = 0;

        GiftCard::query()
            ->whereIn('Status', [StatusGiftCard::Aktif->value, StatusGiftCard::Habis->value])
            ->whereNotNull('BerlakuSampai')
            ->where('BerlakuSampai', '<', $hariIni->toDateString())
            ->orderBy('Id')
            ->pluck('Id')
            ->each(function (int $id) use ($hariIni, &$total): void {
                DB::transaction(function () use ($id, $hariIni, &$total): void {
                    $kartu = $this->buku->AmbilKartuTerkunci($id);

                    if ($kartu->Status !== StatusGiftCard::Aktif && $kartu->Status !== StatusGiftCard::Habis) {
                        return;
                    }

                    $this->penutup->Tutup($kartu, JenisMutasiGiftCard::Hangus, SumberMutasiGiftCard::Jadwal, $hariIni, 'Masa berlaku berakhir '.$kartu->BerlakuSampai?->toDateString(), null);
                    $total++;
                });
            });

        return $total;
    }
}
