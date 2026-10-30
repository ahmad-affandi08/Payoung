<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Data\DataPenjualanPos;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KodeAlasanTinjauan;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\TagihanQris;

/**
 * F-08 BR-08.5: pembayaran `QrisDinamis` di `Penjualan.Buat` membawa `Referensi` = Uuid `TagihanQris`. Di transaksi
 * penerimaan penjualan (kunci baris tagihan):
 * - tagihan dikenal & belum dipakai penjualan lain → ditautkan (`UuidPenjualan`) dan `PenjualanPembayaran.RefEksternal`
 *   = `NomorPesanan` (indeks unik per tenant mencegah pembayaran ganda);
 * - tagihan yang sudah ditutup operator (`DitutupPada`) tidak ditautkan dan menjadi tinjauan `QrisDinamisDipakaiUlang`;
 * - masalah tidak menolak penjualan (offline-first; uang mungkin sudah diterima) tetapi menjadi alasan tinjauan:
 *   `QrisDinamisTidakDikenal` (tanpa referensi / tagihan tidak ada), `QrisDinamisDipakaiUlang` (sudah ditautkan ke
 *   penjualan lain atau dua kali di penjualan ini; tidak ditautkan), `QrisDinamisBelumLunas` (status bukan Lunas saat
 *   diterima; tetap ditautkan agar pelunasan belakangan bisa ditelusuri), `QrisDinamisJumlahBerbeda` (jumlah tagihan ≠
 *   jumlah pembayaran; tetap ditautkan).
 */
final class PenautTagihanQrisPenjualan
{
    /**
     * @param  array<string, MetodePembayaran>  $metode  kunci = Uuid metode
     * @return array{0: array<string, string>, 1: array<string, string>} [kode → alasan tinjauan, Uuid pembayaran → RefEksternal]
     */
    public function Tautkan(DataPenjualanPos $data, array $metode): array
    {
        $masalah = [];
        $refEksternal = [];

        foreach ($data->pembayaran as $bayar) {
            if ($metode[$bayar->uuidMetodePembayaran]->Jenis !== JenisMetodePembayaran::QrisDinamis) {
                continue;
            }

            $referensi = $bayar->referensi === null ? '' : strtoupper(trim($bayar->referensi));
            $tagihan = $referensi === '' ? null : TagihanQris::query()->where('Uuid', $referensi)->where('IsiQr', '!=', '')->lockForUpdate()->first();

            if ($tagihan === null) {
                $masalah[KodeAlasanTinjauan::QrisDinamisTidakDikenal->value][] = "pembayaran {$bayar->jumlah->FormatRupiah()} tanpa tagihan QRIS yang dikenal server";

                continue;
            }

            if (($tagihan->UuidPenjualan !== null && $tagihan->UuidPenjualan !== $data->uuid) || in_array($tagihan->NomorPesanan, $refEksternal, true)) {
                $masalah[KodeAlasanTinjauan::QrisDinamisDipakaiUlang->value][] = "tagihan {$tagihan->NomorPesanan} sudah dipakai penjualan lain";

                continue;
            }

            // Tagihan yang sudah ditutup operator (dikembalikan / diakui pendapatan lain, J-08.2) tidak boleh ditautkan: uang itu
            // sudah dibukukan di sana, menautkannya berarti mengakui uang yang sama sekali lagi lewat penjualan. Penjualannya
            // tetap diterima dan masuk tinjauan; operator membuka kembali penutupan tagihan bila memang itu pembayaran yang benar.
            if ($tagihan->DitutupPada !== null) {
                $masalah[KodeAlasanTinjauan::QrisDinamisDipakaiUlang->value][] = "tagihan {$tagihan->NomorPesanan} sudah ditutup sebagai uang tanpa penjualan, buka kembali penutupannya lebih dulu bila ini pembayaran yang benar";

                continue;
            }

            $tagihan->UuidPenjualan = $data->uuid;
            $tagihan->save();
            $refEksternal[$bayar->uuid] = $tagihan->NomorPesanan;

            if ($tagihan->Status !== StatusTagihanQris::Lunas) {
                $masalah[KodeAlasanTinjauan::QrisDinamisBelumLunas->value][] = "tagihan {$tagihan->NomorPesanan} berstatus {$tagihan->Status->value} saat penjualan diterima";
            }

            if (! Uang::Dari($tagihan->Jumlah)->SamaDengan($bayar->jumlah)) {
                $masalah[KodeAlasanTinjauan::QrisDinamisJumlahBerbeda->value][] = "tagihan {$tagihan->NomorPesanan} ".Uang::Dari($tagihan->Jumlah)->FormatRupiah()." dicatat sebagai pembayaran {$bayar->jumlah->FormatRupiah()}";
            }
        }

        $tinjauan = [];

        foreach ($masalah as $kode => $daftar) {
            $tinjauan[$kode] = "{$kode}: ".implode('; ', $daftar);
        }

        return [$tinjauan, $refEksternal];
    }
}
