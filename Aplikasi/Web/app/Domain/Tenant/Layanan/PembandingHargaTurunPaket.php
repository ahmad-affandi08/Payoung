<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Kueri\HargaPaketBerlaku;
use App\Domain\Tenant\Kueri\TagihanLanggananTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Carbon\CarbonImmutable;

/**
 * Apakah paket tujuan lebih murah daripada paket berjalan untuk siklus langganan (BR-P04.4)? Paket berjalan memakai
 * harga jangkar grandfathering tenant (BR-P04.1), paket tujuan harga berlaku hari ini. Dipakai Aksi pengajuan dan
 * pratinjau di halaman Langganan supaya keduanya sepakat.
 */
final class PembandingHargaTurunPaket
{
    public function __construct(
        private readonly HargaPaketBerlaku $hargaBerlaku,
        private readonly TagihanLanggananTenant $tagihanTenant,
    ) {}

    /**
     * @return array{LebihMurah: bool, HargaBulanan: string|null, HargaTahunan: string|null}|null null bila harga salah satu paket belum tersedia
     */
    public function Bandingkan(Langganan $langganan, Paket $tujuan): ?array
    {
        $sekarang = CarbonImmutable::now();
        $lama = $this->hargaBerlaku->Cari($langganan->IdPaket, $sekarang, $this->tagihanTenant->AmbilMulaiLanggananPaket($langganan->IdPaket));
        $baru = $this->hargaBerlaku->Cari($tujuan->Id, $sekarang, null);

        if ($lama === null || $baru === null) {
            return null;
        }

        $tahunan = $langganan->SiklusTagihan === SiklusTagihan::Tahunan;
        $hargaLama = $tahunan ? $lama->AmbilHargaTahunan() : $lama->AmbilHargaBulanan();
        $hargaBaru = $tahunan ? $baru->AmbilHargaTahunan() : $baru->AmbilHargaBulanan();

        return [
            'LebihMurah' => $hargaBaru->Bandingkan($hargaLama) < 0,
            'HargaBulanan' => $baru->AmbilHargaBulanan()->KeString(),
            'HargaTahunan' => $baru->AmbilHargaTahunan()->KeString(),
        ];
    }
}
