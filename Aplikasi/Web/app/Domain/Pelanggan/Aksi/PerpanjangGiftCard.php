<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Kueri\PengaturanGiftCardTenant;
use App\Domain\Pelanggan\Layanan\BukuGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mengubah masa berlaku gift card (CRM-06, D-153, izin `pelanggan.deposit.kelola`): tanggal baru (hari ini sampai
 * [PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI] hari ke depan) atau kosong = tanpa kedaluwarsa. Hanya kartu
 * `Aktif`/`Habis`; kartu hangus tidak dihidupkan lagi (jurnal pengakuannya sudah terbit). Tidak menyentuh saldo.
 * Audit `pelanggan.gift-card-perpanjang`.
 */
final class PerpanjangGiftCard
{
    public function __construct(
        private readonly BukuGiftCard $buku,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(GiftCard $kartu, ?CarbonImmutable $berlakuSampai, int $idPengguna, CarbonImmutable $hariIni): GiftCard
    {
        if ($berlakuSampai !== null && ($berlakuSampai->startOfDay()->lessThan($hariIni->startOfDay()) || $berlakuSampai->startOfDay()->greaterThan($hariIni->startOfDay()->addDays(PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI)))) {
            throw new PelanggaranAturanBisnis('MasaBerlakuTidakValid', 'Tanggal berlaku antara hari ini dan paling lama '.PengaturanGiftCardTenant::MASA_BERLAKU_MAKSIMAL_HARI.' hari lagi.', 'BerlakuSampai');
        }

        return DB::transaction(function () use ($kartu, $berlakuSampai, $idPengguna): GiftCard {
            $terkunci = $this->buku->AmbilKartuTerkunci($kartu->Id);

            if ($terkunci->Status === StatusGiftCard::Hangus || $terkunci->Status === StatusGiftCard::Dibatalkan) {
                throw new PelanggaranAturanBisnis('GiftCardSudahDitutup', "Kartu yang {$terkunci->Status->AmbilLabel()} tidak bisa diperpanjang.", 'BerlakuSampai');
            }

            $lama = $terkunci->BerlakuSampai?->toDateString();
            $terkunci->forceFill(['BerlakuSampai' => $berlakuSampai?->toDateString()])->save();
            $this->audit->Catat('pelanggan.gift-card-perpanjang', $terkunci, ['BerlakuSampai' => $lama], [
                'Kartu' => KodeGiftCard::Samarkan($terkunci->Kode),
                'BerlakuSampai' => $berlakuSampai?->toDateString(),
            ], idPengguna: $idPengguna);

            return $terkunci;
        });
    }
}
