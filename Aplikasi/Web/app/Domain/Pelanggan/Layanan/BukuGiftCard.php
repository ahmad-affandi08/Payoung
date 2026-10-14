<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\JenisMutasiGiftCard;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Enum\SumberMutasiGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\MutasiGiftCard;
use Carbon\CarbonImmutable;

/**
 * Buku gift card (CRM-06, D-153). Dipanggil di dalam transaksi DB pemanggil.
 * - `Catat`: satu baris bertanda; baris kartu dikunci (`FOR UPDATE`) sehingga `SaldoSetelah` dan cache `GiftCard.Saldo`
 *   berurutan walau dua perangkat memakai kartu yang sama bersamaan. Saldo boleh minus (penjualan offline yang sudah
 *   terjadi); pemanggil yang melarang minus memeriksa `AmbilKartuTerkunci` lebih dulu.
 * - Status mengikuti saldo: nol atau kurang = `Habis`, positif = `Aktif`; `Hangus` dan `Dibatalkan` dipasang oleh
 *   jenis mutasinya (`Hangus`, `Kembali`) dan tidak berubah oleh pemakaian.
 * - Idempoten per (Jenis, JenisSumber, IdSumber): pemanggilan ulang mengembalikan baris yang sudah ada.
 */
final class BukuGiftCard
{
    public function Catat(
        int $idGiftCard,
        Uang $jumlah,
        JenisMutasiGiftCard $jenis,
        SumberMutasiGiftCard $sumber,
        ?int $idSumber,
        ?string $nomorSumber,
        CarbonImmutable $tanggal,
        ?string $keterangan = null,
        ?int $idPengguna = null,
        ?int $idAkunKasBank = null,
    ): MutasiGiftCard {
        $ada = $this->CariMutasi($jenis, $sumber, $idSumber);

        if ($ada !== null) {
            return $ada;
        }

        $kartu = $this->AmbilKartuTerkunci($idGiftCard);
        $saldo = Uang::Dari($kartu->Saldo)->Tambah($jumlah);
        $kartu->Saldo = $saldo->KeString();
        $kartu->Status = match (true) {
            $jenis === JenisMutasiGiftCard::Hangus => StatusGiftCard::Hangus,
            $jenis === JenisMutasiGiftCard::Kembali => StatusGiftCard::Dibatalkan,
            $kartu->Status === StatusGiftCard::Hangus, $kartu->Status === StatusGiftCard::Dibatalkan => $kartu->Status,
            $saldo->Bandingkan(Uang::Nol()) > 0 => StatusGiftCard::Aktif,
            default => StatusGiftCard::Habis,
        };
        $kartu->save();

        return MutasiGiftCard::query()->create([
            'IdGiftCard' => $idGiftCard,
            'Jenis' => $jenis,
            'Jumlah' => $jumlah->KeString(),
            'SaldoSetelah' => $saldo->KeString(),
            'JenisSumber' => $sumber,
            'IdSumber' => $idSumber,
            'NomorSumber' => $nomorSumber === null ? null : mb_substr($nomorSumber, 0, 80),
            'Tanggal' => $tanggal->toDateString(),
            'IdAkunKasBank' => $idAkunKasBank,
            'Keterangan' => $keterangan === null ? null : mb_substr($keterangan, 0, 255),
            'IdPengguna' => $idPengguna,
        ]);
    }

    public function AmbilKartuTerkunci(int $idGiftCard): GiftCard
    {
        return GiftCard::query()->whereKey($idGiftCard)->lockForUpdate()->firstOrFail();
    }

    public function CariMutasi(JenisMutasiGiftCard $jenis, SumberMutasiGiftCard $sumber, ?int $idSumber): ?MutasiGiftCard
    {
        return $idSumber === null ? null : MutasiGiftCard::query()
            ->where('Jenis', $jenis->value)
            ->where('JenisSumber', $sumber->value)
            ->where('IdSumber', $idSumber)
            ->first();
    }
}
