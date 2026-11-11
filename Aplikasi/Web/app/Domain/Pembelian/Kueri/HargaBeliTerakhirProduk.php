<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Harga beli terakhir per produk dalam satuan dasar (D-266): baris penerimaan barang berstatus Diposting yang paling
 * baru (tanggal, lalu nomor dokumen, lalu baris), dari pemasok mana pun. Nilainya **harga beli bersih**: subtotal baris
 * (sudah dipotong diskon baris) dibagi jumlah dasar, tanpa pajak dan tanpa alokasi ongkos kirim. Dengan begitu modal
 * tidak ikut berubah hanya karena ongkir atau PPN. Dibaca lewat kueri ini oleh domain Katalog (aturan #14).
 */
final class HargaBeliTerakhirProduk
{
    /**
     * @param  list<int>  $idProduk
     * @return array<int, array{Modal: string, Tanggal: string, IdPenerimaanBarang: int}> kunci = IdProduk; produk yang belum pernah diterima tidak muncul
     */
    public function Ambil(array $idProduk): array
    {
        $idProduk = array_values(array_unique($idProduk));

        if ($idProduk === []) {
            return [];
        }

        $hasil = [];

        foreach (array_chunk($idProduk, 500) as $potongan) {
            $peringkat = PenerimaanBarangDetail::query()
                ->join('PenerimaanBarang', 'PenerimaanBarang.Id', '=', 'PenerimaanBarangDetail.IdPenerimaanBarang')
                ->whereIn('PenerimaanBarangDetail.IdProduk', $potongan)
                ->where('PenerimaanBarang.Status', StatusDokumenTerposting::Diposting->value)
                ->where('PenerimaanBarangDetail.JumlahDasar', '>', 0)
                ->selectRaw(
                    'PenerimaanBarangDetail.IdProduk AS IdProduk, PenerimaanBarangDetail.Subtotal AS Subtotal, PenerimaanBarangDetail.JumlahDasar AS JumlahDasar, '
                    .'PenerimaanBarang.Tanggal AS Tanggal, PenerimaanBarang.Id AS IdPenerimaanBarang, '
                    .'ROW_NUMBER() OVER (PARTITION BY PenerimaanBarangDetail.IdProduk ORDER BY PenerimaanBarang.Tanggal DESC, PenerimaanBarang.Id DESC, PenerimaanBarangDetail.Id DESC) AS Urut'
                );

            $baris = DB::query()->fromSub($peringkat, 'Peringkat')->where('Urut', 1)->get();

            foreach ($baris as $b) {
                $modal = BigDecimal::of((string) $b->Subtotal)->dividedBy(BigDecimal::of((string) $b->JumlahDasar), 6, RoundingMode::HalfUp);

                $hasil[(int) $b->IdProduk] = [
                    'Modal' => (string) $modal,
                    'Tanggal' => substr((string) $b->Tanggal, 0, 10),
                    'IdPenerimaanBarang' => (int) $b->IdPenerimaanBarang,
                ];
            }
        }

        return $hasil;
    }
}
