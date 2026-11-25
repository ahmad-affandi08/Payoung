<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Carbon\CarbonImmutable;

/**
 * Pencarian tagihan QRIS milik outlet perangkat (tenant lewat scope `MilikTenant`; outlet lain/tenant lain = 404
 * `TagihanTidakDitemukan`) dan cek status ke gerbang sebagai cadangan webhook yang terlambat (F-08 BR-08.5).
 */
final class PencariTagihanQris
{
    /** Jeda minimal antar-cek status ke gerbang per tagihan. */
    public const DETIK_JEDA_CEK = 5;

    public function __construct(
        private readonly PembuatGerbangPembayaran $pembuatGerbang,
        private readonly PenerapStatusTagihanQris $penerap,
    ) {}

    public function CariDiOutlet(string $uuid, int $idOutlet): TagihanQris
    {
        $tagihan = TagihanQris::query()->where('Uuid', strtoupper($uuid))->where('IdOutlet', $idOutlet)->first();

        if ($tagihan === null || $tagihan->IsiQr === '') {
            throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan QRIS tidak ditemukan.', 'Uuid', 404);
        }

        return $tagihan;
    }

    /**
     * Tanya gerbang bila tagihan masih `Menunggu`. `paksa` = abaikan jeda (dipakai sebelum membatalkan). Dijatah
     * dengan UPDATE bersyarat atas `TerakhirDicekPada`, sehingga polling bersamaan hanya memanggil gerbang sekali.
     * Galat gerbang diabaikan (status lokal tetap).
     */
    public function CekKeGerbang(TagihanQris $tagihan, bool $paksa = false): TagihanQris
    {
        if ($tagihan->Status !== StatusTagihanQris::Menunggu) {
            return $tagihan;
        }

        $sekarang = CarbonImmutable::now();
        $kueri = TagihanQris::query()->whereKey($tagihan->Id)->where('Status', StatusTagihanQris::Menunggu->value);

        if (! $paksa) {
            $kueri->where(fn ($q) => $q->whereNull('TerakhirDicekPada')->orWhere('TerakhirDicekPada', '<=', $sekarang->subSeconds(self::DETIK_JEDA_CEK)));
        }

        if ($kueri->update(['TerakhirDicekPada' => $sekarang, 'DiubahPada' => $sekarang]) !== 1) {
            return $tagihan->refresh();
        }

        // v2.06: gerbang tenant yang penyedianya sama dengan saat tagihan dibuat; tenant sudah ganti penyedia = tidak bisa
        // dicek (tetap Menunggu sampai webhook masuk atau kedaluwarsa).
        $gerbang = $this->pembuatGerbang->AmbilUntukTagihan($tagihan->Penyedia);

        if ($gerbang === null) {
            return $tagihan->refresh();
        }

        try {
            $hasil = $gerbang->CekStatusDenganJumlah($tagihan->NomorPesanan, (string) $tagihan->IdReferensi);
        } catch (GalatGerbang) {
            return $tagihan->refresh();
        }

        return $hasil->status === StatusPembayaranGerbang::Menunggu
            ? $tagihan->refresh()
            : $this->penerap->Terapkan($tagihan->Id, $hasil->status, $hasil->jumlah, 'Cek status gerbang');
    }
}
