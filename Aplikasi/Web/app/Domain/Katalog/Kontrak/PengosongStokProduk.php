<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kontrak;

/**
 * Titik perluasan hapus permanen produk (D-218): menghabiskan sisa stok produk lewat penyesuaian stok biasa supaya
 * nilai persediaan di pembukuan turun dengan benar sebelum riwayat produk dihapus. Katalog tidak mengenal Persediaan;
 * implementasinya (`KosongkanStokProduk`) diikat di `PenyediaPersediaan`.
 */
interface PengosongStokProduk
{
    /**
     * Ringkasan tanpa mengubah apa pun: jumlah lokasi yang masih berstok, nilainya (Rupiah, skala 2), dan alasan
     * penolakan (kalimat utuh) bila stok belum bisa dihabiskan otomatis.
     *
     * @param  list<int>  $idProduk
     * @return array{Lokasi: int, Nilai: string, Penghalang: string|null}
     */
    public function Pratinjau(array $idProduk): array;

    /**
     * Menghabiskan stok semua lokasi, diposting langsung atas nama [$idPengguna].
     *
     * @param  list<int>  $idProduk
     * @return int jumlah dokumen penyesuaian yang diposting
     */
    public function Jalankan(array $idProduk, int $idPengguna, string $namaProduk): int;
}
