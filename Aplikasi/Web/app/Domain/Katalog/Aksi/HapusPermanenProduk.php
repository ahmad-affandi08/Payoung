<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Database\PenghapusBerantai;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus produk BESERTA SELURUH RIWAYATNYA (keputusan pemilik produk, D-218; mengecualikan BR-03.2 untuk jalur ini).
 * `HapusProduk` biasa tetap menolak produk yang sudah dipakai; jalur ini hanya untuk Pemilik (izin `data.kelola`) dan
 * wajib mengetik ulang nama produk.
 *
 * Yang dihapus: setiap baris yang bergantung pada produk (dan varian-variannya) lewat foreign key, berantai: baris
 * penjualan, pembelian, faktur, retur, transfer, opname, mutasi stok, saldo, batch, nomor seri, resep miliknya, dan
 * seterusnya (`PenghapusBerantai`). Dokumen induknya (nota, faktur, penjualan) TIDAK ikut dihapus, hanya baris produk ini
 * di dalamnya, sehingga total dokumen lama tidak lagi sama dengan jumlah barisnya dan laporan per produk berubah.
 * Jurnal dan jejak audit tidak disentuh (tetap seimbang). Sesudah itu produk dihapus lewat `HapusProduk` (barcode,
 * harga, SKU dilepas, kasir menerima `Dihapus: true`).
 *
 * Penjagaan yang tetap berlaku supaya pembukuan dan produk lain tidak rusak diam-diam:
 * - saldo stok semua lokasi harus nol (kosongkan lewat penyesuaian stok dulu, supaya nilai persediaan di jurnal turun
 *   dengan benar, bukan menggantung);
 * - produk tidak boleh masih dipakai sebagai bahan resep, komponen paket, atau bahan pilihan produk lain (lepaskan dulu).
 *
 * Satu transaksi: gagal di tengah = tidak ada yang terhapus. Tidak bisa dibalik setelah berhasil.
 */
final class HapusPermanenProduk
{
    /** Struktur katalog produk itu sendiri: ditangani `HapusProduk` (jejak penghapusan untuk kasir), bukan riwayat. */
    public const KECUALI = ['Produk', 'ProdukSatuan', 'ProdukBarcode', 'ProdukHarga', 'ProdukGudang', 'RiwayatHarga'];

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PenghapusBerantai $penghapus,
        private readonly HapusProduk $hapusProduk,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * Pratinjau untuk dialog konfirmasi: apa yang akan dihapus dan apa yang menghalangi. Tidak mengubah apa pun.
     *
     * @return array{Total: int, Tabel: int, Rincian: list<array{Tabel: string, Jumlah: int}>, Penghalang: string|null}
     */
    public function Pratinjau(Produk $produk): array
    {
        $ids = $this->AmbilIdTermasukVarian($produk);
        $jumlah = $this->penghapus->Hitung($this->penghapus->Rencanakan('Produk', $ids, self::KECUALI));
        $rincian = [];

        foreach ($jumlah as $tabel => $banyak) {
            $rincian[] = ['Tabel' => $tabel, 'Jumlah' => $banyak];
        }

        return [
            'Total' => array_sum($jumlah),
            'Tabel' => count($jumlah),
            'Rincian' => $rincian,
            'Penghalang' => $this->AmbilPenghalang($ids),
        ];
    }

    /**
     * @return array{Total: int, Tabel: int, Rincian: list<array{Tabel: string, Jumlah: int}>}
     */
    public function Jalankan(Produk $produk, string $konfirmasiNama): array
    {
        return DB::transaction(function () use ($produk, $konfirmasiNama): array {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $produk = Produk::query()->whereKey($produk->Id)->lockForUpdate()->firstOrFail();

            if (trim($konfirmasiNama) !== $produk->Nama) {
                throw new PelanggaranAturanBisnis('KonfirmasiNamaSalah', 'Nama yang diketik tidak sama dengan nama produk ini.', 'KonfirmasiNama');
            }

            $ids = $this->AmbilIdTermasukVarian($produk);
            $penghalang = $this->AmbilPenghalang($ids);

            if ($penghalang !== null) {
                throw new PelanggaranAturanBisnis('HapusPermanenDitolak', $penghalang);
            }

            $lama = ['Sku' => $produk->Sku, 'Nama' => $produk->Nama, 'Jenis' => $produk->Jenis->value];
            $dihapus = $this->penghapus->Terapkan($this->penghapus->Rencanakan('Produk', $ids, self::KECUALI));
            $this->hapusProduk->Jalankan($produk);

            arsort($dihapus);
            $rincian = [];

            foreach ($dihapus as $tabel => $banyak) {
                $rincian[] = ['Tabel' => $tabel, 'Jumlah' => $banyak];
            }

            $total = array_sum($dihapus);
            $this->audit->Catat('produk.hapus-permanen', $produk, $lama, ['Baris' => $total, 'Tabel' => count($dihapus), 'Rincian' => array_slice($rincian, 0, 15)]);

            return ['Total' => $total, 'Tabel' => count($dihapus), 'Rincian' => $rincian];
        });
    }

    /** @return list<int> produk itu sendiri beserta semua variannya (varian yang sudah dihapus lunak pun ikut). */
    private function AmbilIdTermasukVarian(Produk $produk): array
    {
        $anak = Produk::query()->withTrashed()->where('IdInduk', $produk->Id)->pluck('Id')->map(static fn (mixed $id): int => (int) $id)->all();

        return [$produk->Id, ...$anak];
    }

    /**
     * Alasan penolakan (kalimat utuh) atau null bila boleh dihapus.
     *
     * @param  list<int>  $ids
     */
    private function AmbilPenghalang(array $ids): ?string
    {
        if (DB::table('SaldoStok')->whereIn('IdProduk', $ids)->where('JumlahTersedia', '<>', 0)->exists()) {
            return 'Produk ini masih punya stok. Kosongkan dulu lewat Penyesuaian stok (stok keluar), supaya nilai persediaan di pembukuan ikut turun, lalu hapus permanen.';
        }

        $dipakai = [];

        $bahan = DB::table('ResepDetail as d')
            ->join('Resep as r', 'r.Id', '=', 'd.IdResep')
            ->join('Produk as p', 'p.Id', '=', 'r.IdProduk')
            ->whereIn('d.IdProdukBahan', $ids)
            ->whereNotIn('r.IdProduk', $ids)
            ->distinct()
            ->pluck('p.Nama')
            ->all();

        if ($bahan !== []) {
            $dipakai[] = 'bahan resep '.self::DaftarkanNama($bahan);
        }

        $komponen = DB::table('PaketProdukDetail as d')
            ->join('Produk as p', 'p.Id', '=', 'd.IdProdukPaket')
            ->whereIn('d.IdProdukKomponen', $ids)
            ->whereNotIn('d.IdProdukPaket', $ids)
            ->distinct()
            ->pluck('p.Nama')
            ->all();

        if ($komponen !== []) {
            $dipakai[] = 'komponen paket '.self::DaftarkanNama($komponen);
        }

        $pilihan = DB::table('Pilihan')->whereIn('IdProduk', $ids)->distinct()->pluck('Nama')->all();

        if ($pilihan !== []) {
            $dipakai[] = 'bahan pilihan '.self::DaftarkanNama($pilihan);
        }

        return $dipakai === []
            ? null
            : 'Produk ini masih dipakai sebagai '.implode('; ', $dipakai).'. Lepaskan dulu dari sana, lalu hapus permanen.';
    }

    /** @param  array<int, mixed>  $nama */
    private static function DaftarkanNama(array $nama): string
    {
        $rapi = array_map(static fn (mixed $n): string => (string) $n, array_values($nama));
        $tampil = array_slice($rapi, 0, 5);

        return implode(', ', $tampil).(count($rapi) > 5 ? ' dan '.(count($rapi) - 5).' lainnya' : '');
    }
}
