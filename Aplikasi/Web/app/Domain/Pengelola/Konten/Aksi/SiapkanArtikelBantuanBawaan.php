<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Aksi;

use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;

/**
 * PLT-12: artikel Pusat Bantuan awal supaya pusat bantuan dan tombol Bantuan di back-office tidak kosong saat peluncuran.
 * Dibuat langsung terbit, hanya untuk slug yang belum ada: artikel yang sudah ada (apalagi yang sudah disunting di konsol)
 * tidak pernah ditimpa. Isinya sengaja umum (alur, bukan tangkapan layar) supaya tetap benar saat tampilan berubah.
 */
final class SiapkanArtikelBantuanBawaan
{
    /**
     * @return list<string> slug artikel yang dibuat
     */
    public function Jalankan(): array
    {
        $dibuat = [];

        foreach (self::Daftar() as $urutan => $data) {
            if (ArtikelBantuan::query()->where('Slug', $data['Slug'])->exists()) {
                continue;
            }

            $artikel = new ArtikelBantuan;
            $artikel->forceFill([
                ...$data,
                'UrlVideo' => null,
                'Urutan' => $urutan,
                'Status' => StatusArtikel::Terbit,
                'DiterbitkanPada' => now(),
            ])->save();
            $dibuat[] = $data['Slug'];
        }

        return $dibuat;
    }

    /**
     * @return list<array{Slug: string, Judul: string, Ringkasan: string, Kategori: string, Konteks: list<string>, Isi: string}>
     */
    public static function Daftar(): array
    {
        return [
            [
                'Slug' => 'mulai-memakai-payoung',
                'Judul' => 'Mulai memakai Payoung dalam 5 menit',
                'Ringkasan' => 'Dari daftar sampai transaksi pertama: langkah paling singkat.',
                'Kategori' => 'Mulai cepat',
                'Konteks' => ['/kelola'],
                'Isi' => "## Langkah singkat\n\n1. Selesaikan **panduan awal**: pilih jenis usaha, lalu biarkan Payoung menyiapkan produk contoh, kategori, dan pengaturan dasarnya.\n2. Tambahkan produk Anda (menu Produk). Cukup nama dan harga dulu. Stok bisa menyusul.\n3. Sambungkan perangkat kasir: buat perangkat di menu Perangkat, lalu pindai kode aktivasinya dari aplikasi Kasir.\n4. Buka shift di aplikasi Kasir dengan kas awal, lalu catat penjualan pertama.\n\n## Tips\n\n- Checklist di Beranda menunjukkan langkah yang masih tersisa.\n- Tombol **Bantuan** di kanan atas selalu menampilkan panduan untuk halaman yang sedang Anda buka.",
            ],
            [
                'Slug' => 'cara-menambah-produk',
                'Judul' => 'Cara menambah produk',
                'Ringkasan' => 'Tambah produk bertahap: nama, harga, stok awal, dan barcode.',
                'Kategori' => 'Produk & harga',
                'Konteks' => ['/kelola/produk'],
                'Isi' => "## Tambah satu produk\n\n1. Buka menu **Produk**, lalu tekan **Tambah produk**.\n2. Isi langkah demi langkah. Isian bertanda * wajib.\n3. Di langkah stok, isi **stok sekarang** dan **harga beli** bila barangnya sudah ada di toko. Stok awal langsung tercatat bersama produk.\n4. Simpan. Produk muncul di aplikasi Kasir setelah perangkat tersinkron.\n\n## Banyak produk sekaligus\n\n- Gunakan **Impor** untuk mengunggah produk dari Excel atau CSV.\n- Untuk mengubah harga banyak produk, pilih produknya di daftar lalu pakai aksi massal **Ubah harga**.\n\n## Barcode\n\nBarcode yang Anda ketik otomatis ikut tersimpan saat pindah langkah. Barang tanpa barcode tetap bisa dijual lewat pencarian nama.",
            ],
            [
                'Slug' => 'cetak-label-harga',
                'Judul' => 'Cetak label harga dan barcode',
                'Ringkasan' => 'Cetak stiker harga atau barcode untuk rak dan kemasan.',
                'Kategori' => 'Produk & harga',
                'Konteks' => ['/kelola/produk/label'],
                'Isi' => "## Langkah\n\n1. Pilih produk yang labelnya ingin dicetak, lalu isi **jumlah stiker** per satuan.\n2. Pilih bentuk cetak: lembar A4 berisi banyak stiker, atau label gulung.\n3. Tekan cetak. Jendela cetak peramban terbuka. Pilih printer Anda.\n\n## Barang tanpa barcode\n\nLabelnya hanya berisi harga. Barcode internal hanya dibuat bila Anda memilihnya dengan sadar, supaya tidak bentrok dengan barcode pabrik.",
            ],
            [
                'Slug' => 'tandai-produk-habis',
                'Judul' => 'Menandai produk habis di kasir, toko online, atau self-order',
                'Ringkasan' => 'Sembunyikan produk yang kosong hari ini, per outlet dan per kanal.',
                'Kategori' => 'Produk & harga',
                'Konteks' => ['/kelola/produk'],
                'Isi' => "## Kapan dipakai\n\nBahan atau barang kosong hari ini, tetapi stok di sistem belum berubah.\n\n## Langkah\n\n1. Buka detail produk, lihat panel **Ketersediaan per outlet**.\n2. Tekan **Tandai habis** untuk semua kanal, atau pilih satu kanal saja: kasir, toko online, atau self-order dan kios.\n3. Tekan **Tersedia lagi** bila barangnya sudah ada.\n\n## Yang perlu diketahui\n\n- Produk yang habis disembunyikan dari menu kanal itu di outlet itu.\n- Pesanan yang sudah masuk tidak berubah, dan stok maupun jurnal tidak tersentuh.\n- Kasir juga bisa menandai habis dari layar Jual dengan menahan ubin produk.",
            ],
            [
                'Slug' => 'buka-dan-tutup-shift',
                'Judul' => 'Buka dan tutup shift kasir',
                'Ringkasan' => 'Kas awal, hitung uang di laci, dan membaca selisih.',
                'Kategori' => 'Kasir',
                'Konteks' => ['/kelola/kasir'],
                'Isi' => "## Buka shift\n\nDi aplikasi Kasir, masuk dengan PIN lalu isi **kas awal**: uang yang ada di laci saat mulai. Penjualan baru bisa dicatat setelah shift terbuka.\n\n## Tutup shift\n\n1. Pilih **Tutup shift**.\n2. Hitung uang di laci (bisa per pecahan). Sistem menghitung jumlah yang seharusnya ada dari kas awal, penjualan tunai, kas masuk, dan kas keluar.\n3. Selisih ditampilkan dan dicatat. Shift yang ditutup tidak diubah. Koreksi dilakukan lewat dokumen pembalik.\n\n## Memeriksa dari back-office\n\nMenu **Kasir › Shift** memuat riwayat shift, rincian per metode bayar, dan selisihnya.",
            ],
            [
                'Slug' => 'stok-opname',
                'Judul' => 'Stok opname: menghitung stok fisik',
                'Ringkasan' => 'Hitung fisik, tinjau selisih, lalu setujui agar stok dan jurnal menyesuaikan.',
                'Kategori' => 'Stok',
                'Konteks' => ['/kelola/persediaan/opname'],
                'Isi' => "## Mulai opname\n\n1. Buka **Stok › Opname**, tekan **Mulai stok opname**.\n2. Pilih lokasi stok. Batasi ke satu kategori atau satu rak bila tidak semua barang dihitung.\n3. Centang **hitung buta** bila penghitung tidak boleh melihat jumlah di sistem.\n\n## Menghitung\n\nIsi jumlah fisik tiap baris, atau pindai barcode. Anda boleh menyimpan berkali-kali. Penjualan tetap berjalan selama opname. Selisih dihitung dari jumlah fisik dikurangi saldo saat mulai ditambah mutasi selama opname.\n\n## Menutup\n\nAjukan untuk ditinjau, lalu orang yang berhak menyetujui memeriksa selisihnya. Setelah disetujui, stok dan jurnal selisih tercatat. Opname yang sudah disetujui tidak bisa diubah.",
            ],
            [
                'Slug' => 'rak-gudang',
                'Judul' => 'Rak gudang dan opname per rak',
                'Ringkasan' => 'Bagi gudang menjadi rak agar beberapa orang bisa menghitung berbarengan.',
                'Kategori' => 'Stok',
                'Konteks' => ['/kelola/persediaan/rak'],
                'Isi' => "## Membuat rak\n\n1. Buka **Pengaturan › Rak gudang** lalu **Tambah rak**: pilih lokasi stok, beri kode dan nama.\n2. Buka rak itu dan tempatkan produknya satu per satu, atau satu kategori sekaligus.\n\n## Aturan\n\n- Satu produk hanya berada di satu rak per lokasi stok. Menempatkannya di rak lain memindahkannya.\n- Rak tidak menyimpan stok sendiri. Stok tetap dicatat per lokasi stok.\n- Selama opname rak berjalan, isi rak tidak bisa diubah.\n\n## Opname per rak\n\nSaat memulai opname, pilih rak. Hanya produk di rak itu yang dihitung. Rak berbeda boleh dihitung berbarengan oleh orang berbeda, dan detail opname menampilkan siapa menghitung berapa baris.",
            ],
            [
                'Slug' => 'hubungi-dukungan',
                'Judul' => 'Menghubungi tim dukungan',
                'Ringkasan' => 'Buat tiket bantuan dan pantau jawabannya.',
                'Kategori' => 'Akun & dukungan',
                'Konteks' => ['/kelola/bantuan'],
                'Isi' => "## Membuat tiket\n\n1. Buka tombol **Bantuan** di kanan atas, lalu pilih **Hubungi tim dukungan**, atau buka menu Bantuan.\n2. Jelaskan masalahnya: apa yang Anda lakukan, apa yang terjadi, dan apa yang Anda harapkan. Lampirkan tangkapan layar bila ada.\n3. Kirim. Balasan tim muncul di tiket yang sama.\n\n## Agar cepat ditangani\n\n- Sebutkan outlet dan perangkat yang bermasalah.\n- Jangan menulis kata sandi atau PIN di tiket.\n- Tiket yang selesai bisa ditutup dari halaman tiketnya.",
            ],
        ];
    }
}
