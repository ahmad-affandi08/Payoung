<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\KemampuanGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
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
    /** Lama menunggu penjualan sebelum uang QRIS lunas tanpa penjualan dianggap perlu ditinjau (outbox kasir bisa tertunda). */
    public const MENIT_TUNGGU_PENJUALAN = 120;

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
        // Gerbang ditanya lebih dulu: webhook lunas yang hilang (jaringan, server tenant sedang mati) tidak boleh membuat uang
        // yang sebenarnya sudah masuk tampak "kedaluwarsa" tanpa jurnal.
        foreach (TagihanQris::query()->where('Status', StatusTagihanQris::Menunggu->value)->where('KedaluwarsaPada', '<', $batas)->orderBy('Id')->limit(500)->get() as $tagihan) {
            $status = $this->TanyaGerbang($tagihan);

            if ($status === StatusPembayaranGerbang::Lunas) {
                $berubah += $this->penerap->Terapkan($tagihan->Id, $status, null, 'Rekonsiliasi gerbang')->Status === StatusTagihanQris::Lunas ? 1 : 0;

                continue;
            }

            $berubah += $this->penerap->TandaiKedaluwarsa($tagihan->Id)->Status === StatusTagihanQris::Kedaluwarsa ? 1 : 0;
        }

        $this->TandaiLunasTanpaPenjualan();

        return $berubah;
    }

    /**
     * Uang QRIS POS yang sudah lunas tepat waktu tetapi penjualannya tak kunjung diterima server (aplikasi ditutup,
     * keranjang dibuang, outbox macet) tidak ditandai tinjauan oleh siapa pun: lunasnya normal. Sesudah
     * `MENIT_TUNGGU_PENJUALAN` ia ditandai `PerluTinjauan` supaya muncul di Kotak Tindakan dan bisa ditutup (dikembalikan
     * atau diakui pendapatan lain). Bila penjualannya datang belakangan, penautan tetap berjalan dan butir itu hilang.
     */
    private function TandaiLunasTanpaPenjualan(): void
    {
        TagihanQris::query()
            ->where('Status', StatusTagihanQris::Lunas->value)
            ->where('Sumber', SumberTagihanQris::Pos->value)
            ->whereNull('UuidPenjualan')
            ->where('PerluTinjauan', false)
            ->whereNull('DitutupPada')
            ->where('LunasPada', '<=', CarbonImmutable::now()->subMinutes(self::MENIT_TUNGGU_PENJUALAN))
            ->update([
                'PerluTinjauan' => true,
                'AlasanTinjauan' => 'Uang QRIS sudah masuk tetapi penjualannya belum diterima server. Periksa apakah transaksinya masih tertunda di perangkat kasir.',
                'DiubahPada' => now(),
            ]);
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
