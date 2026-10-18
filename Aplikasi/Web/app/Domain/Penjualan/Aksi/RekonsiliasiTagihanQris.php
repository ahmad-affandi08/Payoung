<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\KemampuanGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Layanan\PenerapStatusTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Carbon\CarbonImmutable;

/**
 * Audit P0 F-02: menyelesaikan tagihan QRIS `TidakPasti` tenant aktif (dijalankan terjadwal per tenant).
 * - Gerbang yang bisa ditanya dengan `NomorPesanan` (atau tagihan sudah punya `IdReferensi`) ditanya statusnya:
 *   `Lunas`/`Kedaluwarsa`/`Gagal` diterapkan lewat `PenerapStatusTagihanQris` (lunas = uang nyata, ditandai tinjauan).
 * - Lewat `KedaluwarsaPada` + tenggang tanpa kabar lunas = `Kedaluwarsa` (bukan final: webhook lunas tetap diterima);
 *   berlaku juga untuk tagihan `Menunggu` yang tidak pernah dibaca lagi (kedaluwarsa terjadwal, F-08).
 * Galat gerbang tidak menghentikan tagihan lain. Mengembalikan jumlah tagihan yang statusnya berubah.
 */
final class RekonsiliasiTagihanQris
{
    public function __construct(
        private readonly PembuatGerbangPembayaran $pembuatGerbang,
        private readonly PenerapStatusTagihanQris $penerap,
    ) {}

    public function Jalankan(): int
    {
        $berubah = 0;
        $batas = CarbonImmutable::now()->subMinutes(CekStatusTagihanQrisPos::MENIT_TENGGANG);

        foreach (TagihanQris::query()->where('Status', StatusTagihanQris::TidakPasti->value)->orderBy('Id')->limit(500)->get() as $tagihan) {
            TagihanQris::query()->whereKey($tagihan->Id)->increment('PercobaanRekonsiliasi', 1, ['TerakhirDicekPada' => now()]);
            $status = $this->TanyaGerbang($tagihan);

            if ($status !== null && $status !== StatusPembayaranGerbang::Menunggu) {
                $berubah += $this->penerap->Terapkan($tagihan->Id, $status, null, 'Rekonsiliasi gerbang')->Status !== StatusTagihanQris::TidakPasti ? 1 : 0;

                continue;
            }

            if ($tagihan->KedaluwarsaPada->lessThan($batas)) {
                $berubah += $this->penerap->TandaiKedaluwarsa($tagihan->Id)->Status === StatusTagihanQris::Kedaluwarsa ? 1 : 0;
            }
        }

        // F-08: tagihan `Menunggu` yang tidak pernah dibaca lagi (kasir pindah metode, aplikasi ditutup) tetap `Menunggu`
        // selamanya bila kedaluwarsanya hanya diterapkan saat dibaca. Jadwal ini menutupnya; uang yang masuk sesudahnya tetap
        // `Lunas` lewat webhook (bukan status final).
        foreach (TagihanQris::query()->where('Status', StatusTagihanQris::Menunggu->value)->where('KedaluwarsaPada', '<', $batas)->orderBy('Id')->limit(500)->get() as $tagihan) {
            $berubah += $this->penerap->TandaiKedaluwarsa($tagihan->Id)->Status === StatusTagihanQris::Kedaluwarsa ? 1 : 0;
        }

        return $berubah;
    }

    private function TanyaGerbang(TagihanQris $tagihan): ?StatusPembayaranGerbang
    {
        $gerbang = $this->pembuatGerbang->AmbilUntukTagihan($tagihan->Penyedia);

        if ($gerbang === null || ($tagihan->IdReferensi === null && ! $gerbang->CekMendukung(KemampuanGerbang::CekStatusDariNomorPesanan))) {
            return null;
        }

        try {
            return $gerbang->CekStatus($tagihan->NomorPesanan, (string) $tagihan->IdReferensi);
        } catch (GalatGerbang) {
            return null;
        }
    }
}
