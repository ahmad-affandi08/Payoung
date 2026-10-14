<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;

/**
 * Cek gift card untuk kasir sebelum membayar (CRM-06, D-153, wajib online §18.4): kode yang diketik atau dipindai
 * dicari di tenant perangkat. Kode yang salah dan kode yang tidak ada sama-sama `GiftCardTidakDitemukan` (404) supaya
 * kasir tidak bisa menebak kartu; jawabannya hanya memuat saldo dan masa berlakunya, tidak nama pemegang atau pembeli.
 * Cek hanya membaca: pemotongan saldo terjadi saat penjualan diterima server.
 */
final class CekGiftCardPos
{
    public function __construct(
        private readonly PengaturanGiftCardTenant $pengaturan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
    ) {}

    /**
     * @return array{Kode: string, Saldo: string, Status: string, BerlakuSampai: string|null, BisaDipakai: bool, Alasan: string|null}
     *
     * @throws PelanggaranAturanBisnis FiturTidakAktif (403), GiftCardTidakDitemukan (404)
     */
    public function Ambil(string $kode): array
    {
        if (! $this->pengaturan->CekBerlaku()) {
            throw new PelanggaranAturanBisnis('FiturTidakAktif', 'Gift card belum aktif di paket usaha ini.', 'Kode', 403);
        }

        $normal = KodeGiftCard::Normalisasi($kode);
        $kartu = $normal === '' ? null : GiftCard::query()->where('Kode', $normal)->first();

        if ($kartu === null) {
            throw new PelanggaranAturanBisnis('GiftCardTidakDitemukan', 'Kode gift card tidak ditemukan. Periksa lagi kodenya.', 'Kode', 404);
        }

        $hariIni = $this->tanggalBisnis->Hitung(null)->toDateString();
        $saldo = Uang::Dari($kartu->Saldo);
        $alasan = match (true) {
            $kartu->Status === StatusGiftCard::Hangus => 'Kartu ini sudah hangus.',
            $kartu->Status === StatusGiftCard::Dibatalkan => 'Kartu ini sudah dibatalkan.',
            $kartu->BerlakuSampai !== null && $kartu->BerlakuSampai->toDateString() < $hariIni => 'Masa berlaku kartu ini sudah berakhir pada '.$kartu->BerlakuSampai->toDateString().'.',
            $saldo->Bandingkan(Uang::Nol()) <= 0 => 'Saldo kartu ini sudah habis.',
            default => null,
        };

        return [
            'Kode' => $kartu->Kode,
            'Saldo' => $saldo->KeString(),
            'Status' => $kartu->Status->value,
            'BerlakuSampai' => $kartu->BerlakuSampai?->toDateString(),
            'BisaDipakai' => $alasan === null,
            'Alasan' => $alasan,
        ];
    }
}
