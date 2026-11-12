<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Layanan;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PembayaranHutangAlokasi;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Berapa bagian dari satu dokumen belanja yang berupa bahan baku (D-255 tahap 3). Laporan Pemasukan & Pengeluaran
 * memakainya untuk memecah uang keluar belanja stok menjadi "Belanja bahan baku" dan "Belanja barang dagangan", supaya
 * pemilik FnB melihat berapa uang yang habis untuk bahan.
 *
 * Porsi adalah desimal 0 sampai 1 (6 angka di belakang koma), dihitung dari nilai baris dokumen menurut jenis
 * produknya (`JenisProduk::BahanBaku`). Pembayaran hutang mengikuti komposisi faktur yang dilunasinya, ditimbang
 * menurut jumlah yang dialokasikan. Dokumen tanpa baris dianggap tidak punya bahan baku (porsi 0).
 */
final class PemisahBelanjaBahanBaku
{
    private const SKALA = 6;

    public function __construct(private readonly InfoProdukStok $infoProduk) {}

    /**
     * @param  list<int>  $idPenerimaan
     * @return array<int, string> kunci = Id penerimaan barang
     */
    public function PorsiPenerimaan(array $idPenerimaan): array
    {
        $baris = $idPenerimaan === [] ? collect() : PenerimaanBarangDetail::query()
            ->whereIn('IdPenerimaanBarang', $idPenerimaan)
            ->get(['IdPenerimaanBarang', 'IdProduk', 'Nilai']);

        return $this->Hitung($idPenerimaan, array_values($baris->map(fn (PenerimaanBarangDetail $b): array => [(int) $b->IdPenerimaanBarang, (int) $b->IdProduk, (string) $b->Nilai])->all()));
    }

    /**
     * @param  list<int>  $idFaktur
     * @return array<int, string> kunci = Id faktur pembelian
     */
    public function PorsiFaktur(array $idFaktur): array
    {
        $baris = $idFaktur === [] ? collect() : FakturPembelianDetail::query()
            ->whereIn('IdFakturPembelian', $idFaktur)
            ->get(['IdFakturPembelian', 'IdProduk', 'Subtotal', 'AlokasiOngkir', 'Pajak']);

        // Yang dibayar pemilik per baris = subtotal + ongkir yang dialokasikan + pajak, bukan hanya harga barangnya.
        return $this->Hitung($idFaktur, array_values($baris->map(fn (FakturPembelianDetail $b): array => [
            (int) $b->IdFakturPembelian,
            (int) $b->IdProduk,
            (string) BigDecimal::of((string) $b->Subtotal)->plus((string) $b->AlokasiOngkir)->plus((string) $b->Pajak),
        ])->all()));
    }

    /**
     * @param  list<int>  $idPembayaran
     * @return array<int, string> kunci = Id pembayaran hutang
     */
    public function PorsiPembayaranHutang(array $idPembayaran): array
    {
        $alokasi = PembayaranHutangAlokasi::query()
            ->whereIn('IdPembayaranHutang', $idPembayaran)
            ->get(['IdPembayaranHutang', 'IdFakturPembelian', 'Jumlah']);
        $porsiFaktur = $this->PorsiFaktur(array_values(array_unique($alokasi->map(fn (PembayaranHutangAlokasi $a): int => (int) $a->IdFakturPembelian)->all())));
        $total = [];
        $bahan = [];

        foreach ($alokasi as $a) {
            $jumlah = BigDecimal::of((string) $a->Jumlah);
            $porsi = BigDecimal::of($porsiFaktur[(int) $a->IdFakturPembelian] ?? '0');
            $kunci = (int) $a->IdPembayaranHutang;
            $total[$kunci] = ($total[$kunci] ?? BigDecimal::zero())->plus($jumlah);
            $bahan[$kunci] = ($bahan[$kunci] ?? BigDecimal::zero())->plus($jumlah->multipliedBy($porsi));
        }

        $hasil = [];

        foreach ($idPembayaran as $id) {
            $hasil[$id] = isset($total[$id]) && $total[$id]->isPositive()
                ? (string) $bahan[$id]->dividedBy($total[$id], self::SKALA, RoundingMode::HalfUp)
                : $this->Nol();
        }

        return $hasil;
    }

    /**
     * Belanja stok yang dibayar dari akun tertentu (D-257: akun penampung uang laci), urut tanggal lalu Id, beserta
     * bagian bahan bakunya. Pembayaran yang dibatalkan tidak ikut. Dipakai laporan untuk memecah kas keluar kasir.
     *
     * @param  list<int>  $idAkun
     * @return list<array{IdOutlet: int|null, Total: Uang, Bahan: Uang}>
     */
    public function RincianBelanjaDariAkun(array $idAkun): array
    {
        if ($idAkun === []) {
            return [];
        }

        $pembayaran = PembayaranHutang::query()
            ->where('BelanjaStok', true)
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereIn('IdAkun', $idAkun)
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->get(['Id', 'IdOutlet', 'Jumlah']);
        $porsi = $this->PorsiPembayaranHutang(array_values($pembayaran->map(fn (PembayaranHutang $p): int => (int) $p->Id)->all()));
        $hasil = [];

        foreach ($pembayaran as $p) {
            $total = Uang::Dari((string) $p->Jumlah);
            $bahan = $total->Kali($porsi[(int) $p->Id] ?? '0');
            $hasil[] = [
                'IdOutlet' => $p->IdOutlet === null ? null : (int) $p->IdOutlet,
                'Total' => $total,
                'Bahan' => $bahan->Bandingkan($total) > 0 ? $total : $bahan,
            ];
        }

        return $hasil;
    }

    /**
     * @param  list<int>  $idDokumen
     * @param  list<array{0: int, 1: int, 2: string}>  $baris  [IdDokumen, IdProduk, nilai]
     * @return array<int, string>
     */
    private function Hitung(array $idDokumen, array $baris): array
    {
        $jenis = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (array $b): int => $b[1], $baris))), denganTerhapus: true);
        $total = [];
        $bahan = [];

        foreach ($baris as [$dokumen, $produk, $nilai]) {
            $angka = BigDecimal::of($nilai);
            $total[$dokumen] = ($total[$dokumen] ?? BigDecimal::zero())->plus($angka);

            if (($jenis[$produk] ?? null)?->jenis === JenisProduk::BahanBaku) {
                $bahan[$dokumen] = ($bahan[$dokumen] ?? BigDecimal::zero())->plus($angka);
            }
        }

        $hasil = [];

        foreach ($idDokumen as $id) {
            $hasil[$id] = isset($total[$id], $bahan[$id]) && $total[$id]->isPositive()
                ? (string) $bahan[$id]->dividedBy($total[$id], self::SKALA, RoundingMode::HalfUp)
                : $this->Nol();
        }

        return $hasil;
    }

    private function Nol(): string
    {
        return (string) BigDecimal::zero()->toScale(self::SKALA);
    }
}
