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
            [
                'Slug' => 'membaca-laporan-penjualan',
                'Judul' => 'Membaca laporan penjualan',
                'Ringkasan' => 'Arti angka bersih, void, retur, ABC, skor risiko, food cost, dan waktu saji.',
                'Kategori' => 'Laporan',
                'Konteks' => ['/kelola/laporan/penjualan'],
                'Isi' => "## Angka penjualan\n\n- **Kotor** adalah total penjualan yang sudah diterima server. Penjualan yang di-void tidak dihitung.\n- **Bersih** = kotor dikurangi diskon dan retur, di luar pajak dan biaya layanan. Retur mengurangi penjualan pada tanggal returnya.\n\n## Analisis ABC\n\nProduk diurutkan dari penjualan bersih terbesar. Kelas A menyumbang sekitar 80% penjualan, B sampai 95%, dan C sisanya. Produk tanpa penjualan bersih tidak ditampilkan.\n\n## Anti-fraud\n\nSkor risiko hanya petunjuk untuk diperiksa, bukan bukti. Skornya dibandingkan dengan rata-rata kasir lain pada periode yang sama: void tunai kurang dari 10 menit setelah bayar, rasio void, diskon, atau retur minimal 2 kali rata-rata, buka laci tanpa transaksi, dan kas kurang saat tutup shift.\n\n## Food cost\n\n- **Teoretis** = nilai bahan yang seharusnya terpakai menurut penjualan dan produksi (resep dikali jumlah terjual).\n- **Aktual** = teoretis ditambah bahan terbuang dan selisih stok dari penyesuaian dan opname.\n- Selisih positif berarti ada kerugian bahan. Persentasenya terhadap penjualan bersih periode.\n\n## Waktu saji dapur\n\nDihitung dari tiket dapur yang sudah siap. Total = dikirim ke dapur sampai siap. Tunggu dan masak hanya terisi bila KDS menandai tahap Dimasak. Persentil 90 berarti 9 dari 10 tiket selesai lebih cepat dari angka itu.\n\n## Void item meja\n\nItem pesanan meja yang sudah dikirim ke dapur lalu dibatalkan, paling banyak 200 baris, jadi persempit periode untuk melihat yang lebih lama. Nilainya petunjuk kerugian, bukan angka akuntansi. Dicatat terbuang berarti bahan sudah dikurangi dari stok karena item sempat dibuat.\n\n## Umpan balik pelanggan\n\nPenilaian 1 sampai 5 dari struk digital. Tingkat respons = penilaian masuk dibanding jumlah transaksi. Pelanggan yang tidak membuka struk digital tidak ikut menilai.",
            ],
            [
                'Slug' => 'batas-dan-persetujuan-kasir',
                'Judul' => 'Batas dan persetujuan di kasir',
                'Ringkasan' => 'Aturan retur tanpa struk, diskon, tempo, kas keluar, selisih kas, dan barcode timbangan.',
                'Kategori' => 'Kasir',
                'Konteks' => ['/kelola/kasir/pengaturan'],
                'Isi' => "## Retur tanpa struk\n\nSelalu butuh PIN pemilik atau pengguna berizin Menyetujui retur tanpa struk, dan hanya bisa dikembalikan sebagai tukar barang atau deposit pelanggan, tidak pernah uang tunai. Bila total harian satu outlet melewati batas, returnya tetap tercatat dan masuk Kotak Tindakan untuk dicek. Isi 0 agar setiap retur tanpa struk dicek.\n\n## Pemberitahuan\n\nVoid, retur, dan penjualan berdiskon dengan nilai sama atau di atas batas dikabarkan lewat notifikasi ke pengguna yang boleh melihat laporan penjualan di outlet itu. Tiap pengguna bisa membisukan jenis notifikasinya.\n\n## Diskon\n\nKasir berizin diskon boleh memberi diskon sampai batas kasir. Di atasnya wajib disetujui supervisor dengan PIN sampai batas persetujuan. Hanya Pemilik yang boleh lebih besar.\n\n## Penjualan tempo\n\nButuh PIN penyetuju bila melebihi limit kredit atau pelanggan punya piutang yang lewat jatuh tempo lebih dari batas hari. Isi 0 agar piutang yang lewat sehari pun butuh penyetuju.\n\n## Kas dan laci\n\n- Buka laci tanpa transaksi selalu dicatat beserta alasan dan nama kasir, dan tampil di detail shift.\n- Kas keluar di atas batas butuh PIN supervisor.\n- Selisih kas di atas toleransi butuh alasan dan PIN supervisor saat tutup shift.\n\n## Satu shift untuk semua kasir\n\nCocok untuk kafe kecil. Setiap transaksi dan kas tetap mencatat nama kasirnya. Bila dimatikan, tiap kasir membuka shift sendiri dan hanya kasir itu atau supervisor yang bisa mencatat kas di shiftnya.\n\n## Barang timbangan\n\nIsi barcode produk dengan 7 digit pertama label timbangan (awalan 21 sampai 29 ditambah kode produk 5 digit), misal 2712345. Satuan produknya harus boleh desimal.",
            ],
            [
                'Slug' => 'aturan-kehadiran-karyawan',
                'Judul' => 'Aturan kehadiran dan absensi',
                'Ringkasan' => 'Jam keluar otomatis, lokasi, jadwal, dan pengingat absensi.',
                'Kategori' => 'Karyawan',
                'Konteks' => ['/kelola/karyawan/aturan-kehadiran', '/kelola/karyawan/absensi'],
                'Isi' => "## Absen keluar otomatis\n\nKaryawan yang lupa absen keluar tidak membuat jam kerjanya menyambung ke hari berikutnya. Jam keluar dicatat sesuai jam selesai jadwal, atau pukul 23.59 bila tidak ada jadwal. Absensinya ditandai perlu dicek dan muncul di Kotak Tindakan supaya jam pulangnya bisa Anda koreksi.\n\n## Lokasi\n\nSaat aturan tolak di luar radius dimatikan (bawaan), absen tetap tercatat dan ditandai perlu dicek bila lokasinya jauh dari outlet atau sinyal GPS lemah, supaya karyawan tidak tertolak hanya karena GPS meleset. Saat dinyalakan, absen di luar radius ditolak.\n\n## Jadwal\n\nAturan jadwal berlaku untuk absen dari HP pribadi (ditolak langsung). Absen di aplikasi kasir tetap diterima karena bisa dilakukan offline, tetapi ditandai Di luar jadwal untuk ditinjau.\n\n## Foto dan wajah\n\nWajah selalu dicocokkan dengan wajah terdaftar. Foto bisa dilihat di rekap absensi. Bila penyimpanan foto dimatikan, wajah tetap dicocokkan tetapi hanya skor kemiripannya yang disimpan.\n\n## Pengingat\n\nPengingat dikirim ke nomor HP akun karyawan lewat WhatsApp. Peringatan dikirim ke anggota yang boleh melihat karyawan di outlet itu.",
            ],
            [
                'Slug' => 'jadwal-kerja-karyawan',
                'Judul' => 'Mengisi jadwal kerja',
                'Ringkasan' => 'Cara mengisi jam, shift, dan libur di jadwal mingguan.',
                'Kategori' => 'Karyawan',
                'Konteks' => ['/kelola/karyawan/jadwal'],
                'Isi' => "## Mengisi jam\n\nKetik jam tanpa titik dua (misal 830 menjadi 08:30), atau klik isian untuk memilih. Kosongkan untuk libur.\n\n## Beberapa shift\n\nSatu hari boleh punya beberapa shift. Pasang shift dengan tombol Tambah.\n\n## Shift lewat tengah malam\n\nJam selesai yang lebih kecil dari jam mulai berarti shift berlanjut melewati tengah malam.\n\n## Terlambat\n\nKeterlambatan di rekap absensi dihitung dari jadwal ini.",
            ],
            [
                'Slug' => 'laporan-apotek-sipnap',
                'Judul' => 'Laporan apotek untuk SIPNAP',
                'Ringkasan' => 'Cara menghitung stok akhir psikotropika dan narkotika.',
                'Kategori' => 'Laporan',
                'Konteks' => ['/kelola/laporan/apotek'],
                'Isi' => "## Isi laporan\n\nRekap bulanan psikotropika dan narkotika dari buku stok, dalam satuan dasar. Ini data pendukung, bukan laporan resmi: periksa lalu isikan sendiri ke aplikasi SIPNAP.\n\n## Rumus stok akhir\n\nStok akhir = stok awal + masuk dari pemasok + masuk lain − keluar penjualan − keluar lain. Keluar lain mencakup transfer, penyesuaian, opname, pemusnahan, dan retur ke pemasok.",
            ],
            [
                'Slug' => 'tutup-buku-dan-jurnal',
                'Judul' => 'Tutup buku dan jurnal',
                'Ringkasan' => 'Kunci periode, tutup tahun, dan jurnal umum.',
                'Kategori' => 'Akuntansi',
                'Konteks' => ['/kelola/akuntansi/tutup-buku', '/kelola/akuntansi/jurnal'],
                'Isi' => "## Kunci periode\n\nKunci periode setelah laporan bulan itu selesai. Transaksi back-office bertanggal di periode terkunci akan ditolak. Penjualan kasir offline yang baru terkirim tetap diterima, ditandai untuk ditinjau, dan dibukukan di periode terbuka berikutnya.\n\n## Tutup tahun\n\nSetelah 12 bulan terkunci, tutup tahun buku memindahkan laba atau rugi tahun itu ke Laba Ditahan. Setelah ditutup, kunci bulan-bulannya tidak bisa dibuka lagi.\n\n## Jurnal\n\nJurnal dibuat otomatis dari dokumen seperti penjualan, pembelian, dan stok awal, dan tidak bisa diubah. Koreksi dilakukan dengan membatalkan dokumen sumbernya sehingga terbentuk jurnal pembalik.\n\n## Jurnal umum\n\nUntuk pencatatan yang tidak punya dokumen sendiri, misalnya penyesuaian akhir bulan. Jurnal langsung tercatat dan koreksinya dengan dokumen pembalik. Akun persediaan, piutang, hutang, deposit, dan aset tetap tidak tersedia di sini karena saldonya mengikuti dokumen modulnya.",
            ],
            [
                'Slug' => 'daftar-harga-dan-prioritas',
                'Judul' => 'Daftar harga dan prioritasnya',
                'Ringkasan' => 'Kapan memakai daftar harga dan daftar mana yang dipakai kasir.',
                'Kategori' => 'Produk & harga',
                'Konteks' => ['/kelola/daftar-harga'],
                'Isi' => "## Kapan dipakai\n\nPakai daftar harga kalau harga produk perlu berbeda untuk situasi tertentu, misalnya harga di GoFood, harga anggota, harga satu cabang, atau harga promo bulan ini.\n\n## Daftar mana yang dipakai\n\nProduk yang tidak ada di daftar memakai harga jual biasa. Bila beberapa daftar cocok sekaligus, yang prioritasnya paling besar dipakai.\n\n## Harga bertingkat\n\nHarga bertingkat di formulir produk berlaku otomatis di kasir, misal 1 sampai 11 pcs Rp 5.000 dan mulai 12 pcs Rp 4.500. Harga per outlet, kanal, atau waktu diatur di Daftar harga.",
            ],
            [
                'Slug' => 'salesman-kanvas',
                'Judul' => 'Salesman kanvas',
                'Ringkasan' => 'Alur muat pagi, jual, bongkar sore, dan membaca rekap harian.',
                'Kategori' => 'Grosir',
                'Konteks' => ['/kelola/grosir/kanvas'],
                'Isi' => "## Alur harian\n\nSalesman kanvas membawa barang di kendaraan dan menjual langsung, tunai atau tempo.\n\n1. Muat stok pagi lewat transfer stok ke lokasi bak kendaraan.\n2. Jual lewat aplikasi kasir di outlet kendaraan.\n3. Bongkar sisa sore lewat transfer stok.\n4. Setor kas saat tutup shift.\n\n## Rekap harian\n\nSisa akhir = stok awal + muat − terjual + retur − bongkar ± penyesuaian lain. Terjual sudah dikurangi void pada hari yang sama.\n\n## Kendaraan\n\nKendaraan dibuat sebagai outlet baru bernama Kanvas dan nomor kendaraannya, lengkap dengan lokasi stok Toko (bak kendaraan), dan dihitung dalam batas outlet paket.",
            ],
            [
                'Slug' => 'promo-cara-kerja',
                'Judul' => 'Cara kerja promo',
                'Ringkasan' => 'Syarat promo, poin berlipat, gratis ongkir, dan klaim ke pemasok.',
                'Kategori' => 'Promo',
                'Konteks' => ['/kelola/promo'],
                'Isi' => "## Penerapan\n\nPromo diterapkan otomatis di kasir saat syaratnya terpenuhi (tanggal, hari dan jam, outlet, kanal, tier pelanggan, barang, minimal belanja), termasuk saat kasir offline. Potongan promo mengurangi harga sebelum pajak.\n\n## Syarat pelanggan\n\nBerlaku hanya bila kasir memilih pelanggan. Saat kasir offline, riwayat pelanggan memakai data terakhir di perangkat. Bila ternyata tidak memenuhi syarat, penjualan tetap diterima dan masuk tinjauan.\n\n## Poin berlipat\n\nPoin loyalti transaksi yang memenuhi syarat dikalikan angka pengali (lebih dari 1 sampai 10). Tidak mengubah harga dan bisa berjalan bersama promo potongan. Bila beberapa promo poin berlaku, dipakai pengali terbesar.\n\n## Gratis ongkir\n\nKosongkan batas untuk menggratiskan seluruh ongkir. Isi untuk subsidi sebagian, misal Rp 10.000: ongkir Rp 15.000 menjadi Rp 5.000. Berlaku di checkout toko online saat ada ongkir.\n\n## Pendanaan pemasok\n\nPotongan ke pelanggan tetap tercatat sebagai diskon penjualan. Bila pemasok ikut menanggung, bagiannya menjadi klaim di menu Klaim promo pemasok. Transaksi yang di-void tidak diklaim.",
            ],
            [
                'Slug' => 'harga-otomatis',
                'Judul' => 'Harga otomatis dari harga beli',
                'Ringkasan' => 'Cara harga jual ikut harga beli terakhir dan pengamannya.',
                'Kategori' => 'Produk & harga',
                'Konteks' => ['/kelola/produk/harga-otomatis'],
                'Isi' => "## Cara kerja\n\nSetiap ada penerimaan barang atau belanja stok, harga jual dihitung dari harga beli terakhir ditambah untung, lalu dibulatkan. Hanya harga dasar yang berubah, harga bertingkat dan daftar harga khusus tidak ikut. Setiap perubahan tercatat di riwayat harga.\n\n## Pengaman salah ketik\n\nBila harga akan berubah lebih jauh dari batas, harga tidak langsung dipakai dan menunggu persetujuan Anda. Contoh: harga beli Rp 10.000 salah diketik Rp 100.000 membuat harga jual naik 900%.\n\n## Kalau harga beli turun\n\nPilih harga jual ikut turun, atau tetap supaya aman dari harga beli turun sementara, misalnya karena promo pemasok.",
            ],
            [
                'Slug' => 'laporan-pajak-ppn-pph',
                'Judul' => 'Membaca laporan pajak',
                'Ringkasan' => 'Arti PPN keluaran, PPN masukan, dan PPh yang dipotong pelanggan.',
                'Kategori' => 'Laporan',
                'Konteks' => ['/kelola/laporan/pajak'],
                'Isi' => "## Pajak keluaran\n\nDari penjualan yang diterima server, dikelompokkan per bulan tanggal bisnis. Penjualan yang di-void tidak dihitung. Pajak retur mengurangi pada bulan returnya.\n\n## PPN masukan\n\nPPN pada faktur pembelian (tanggal faktur) dikurangi PPN retur pembelian. PPN masukan yang tidak dapat dikreditkan menjadi beban dan tidak mengurangi PPN keluaran. Selisih = PPN keluaran − PPN masukan yang dikreditkan. Ini angka bantu pembukuan, bukan SPT Masa PPN.\n\n## PPh dipotong pelanggan\n\nPPh 23 dan sejenisnya yang dipotong pelanggan saat membayar invoice jasa. Uangnya tidak masuk kas, jadi nomor bukti potong perlu ditagih dari pelanggan. Rincian per pelunasan, pelanggan, dan status bukti potong ada di halaman rincian.",
            ],
        ];
    }
}
