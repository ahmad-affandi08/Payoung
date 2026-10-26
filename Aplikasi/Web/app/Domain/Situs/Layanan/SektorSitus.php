<?php

declare(strict_types=1);

namespace App\Domain\Situs\Layanan;

/**
 * Halaman situs pemasaran per sektor usaha (D-91) dan pengelompokannya untuk menu "Solusi" di kepala situs.
 *
 * Satu halaman per template sektor (`TemplateSektorAwal.json`) supaya pemilik toko sayur menemukan halaman "aplikasi kasir
 * toko sayur & sembako" dan bukan halaman umum, dan supaya menu Solusi punya tujuan yang berbeda untuk tiap barisnya.
 * Halaman memakai jalur `solusi/{slug}` dan ikut mekanisme halaman bawaan (`KontenSitusBawaan`): tampil tanpa baris di
 * database, dan halaman bernama sama yang dibuat di konsol menggantikannya (sehingga bisa disunting tanpa kode).
 *
 * Teks hanya menyebut fitur yang benar-benar ada di template sektor dan di PRD; tidak ada angka atau testimoni karangan.
 * Sektor yang templatenya dinonaktifkan (D-47) atau halamannya dimatikan di konsol tidak muncul di menu
 * (`MenuSolusiSitus`).
 */
final class SektorSitus
{
    /**
     * Kelompok menu Solusi: kunci = slug halaman kelompok (`solusi/{kunci}`), urutan = urutan kolom di menu.
     *
     * @var array<string, array{Judul: string, Ikon: string, Prefiks: list<string>}>
     */
    public const KELOMPOK = [
        'kafe-resto' => ['Judul' => 'Kafe & Resto', 'Ikon' => 'Coffee', 'Prefiks' => ['FNB']],
        'toko-retail' => ['Judul' => 'Toko & Retail', 'Ikon' => 'Store', 'Prefiks' => ['RTL', 'WHS']],
        'jasa' => ['Judul' => 'Salon, Laundry & Jasa', 'Ikon' => 'Scissors', 'Prefiks' => ['SVC']],
        // D-167: perusahaan yang menagih tanpa kasir (invoice, kontrak, proyek, produksi).
        'perusahaan' => ['Judul' => 'Perusahaan & Jasa Profesional', 'Ikon' => 'Building', 'Prefiks' => ['BIZ']],
    ];

    /**
     * Tiga kelompok datar yang dulu tersimpan di menu situs lama (sebelum dropdown Solusi, D-91). Kelompok yang ditambah
     * sesudahnya (D-167) tidak ikut: pengaturan lama tidak pernah memuatnya, jadi tidak boleh dijadikan syarat pelipatan.
     */
    public const KELOMPOK_MENU_LAMA = ['kafe-resto', 'toko-retail', 'jasa'];

    /**
     * Kunci kelompok untuk kode template sektor (awalan kode: FNB, RTL, WHS, SVC, BIZ).
     */
    public static function AmbilKelompokUntukKode(string $kode): ?string
    {
        $awalan = strtok($kode, '-');

        foreach (self::KELOMPOK as $kunci => $kelompok) {
            if (in_array($awalan, $kelompok['Prefiks'], true)) {
                return $kunci;
            }
        }

        return null;
    }

    /**
     * Daftar sektor untuk menu (belum disaring template aktif).
     *
     * @return list<array{Kode: string, Slug: string, Kelompok: string, Nama: string, Ringkas: string, Ikon: string}>
     */
    public static function AmbilDaftar(): array
    {
        return array_map(static fn (array $s): array => [
            'Kode' => $s['Kode'],
            'Slug' => 'solusi/'.$s['Slug'],
            'Kelompok' => (string) self::AmbilKelompokUntukKode($s['Kode']),
            'Nama' => $s['Nama'],
            'Ringkas' => $s['Ringkas'],
            'Ikon' => $s['Ikon'],
        ], self::Definisi());
    }

    /**
     * @return array<string, array{Judul: string, JudulSeo: string, DeskripsiSeo: string, Bagian: list<array<string, mixed>>}>
     */
    public static function AmbilHalaman(): array
    {
        $hasil = [];

        foreach (self::Definisi() as $s) {
            $kelompok = self::KELOMPOK[self::AmbilKelompokUntukKode($s['Kode'])]['Judul'] ?? 'Solusi';

            $hasil['solusi/'.$s['Slug']] = [
                'Judul' => $s['Nama'],
                'JudulSeo' => ($s['JudulSeo'] ?? 'Aplikasi Kasir '.($s['NamaSeo'] ?? $s['Nama'])).' | Payoung',
                'DeskripsiSeo' => $s['Seo'],
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Spesimen' => $s['Spesimen'],
                        'Label' => $kelompok,
                        'Judul' => $s['Judul'],
                        'Subjudul' => $s['Sub'],
                        'Poin' => array_map(static fn (string $p): array => ['Teks' => $p], $s['Poin']),
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Grid',
                        'Kolom' => '2',
                        'Judul' => $s['JudulFitur'],
                        'Item' => array_map(static fn (array $i): array => ['Ikon' => $i[0], 'Judul' => $i[1], 'Teks' => $i[2]], $s['Item']),
                    ],
                    [
                        'Jenis' => 'Faq',
                        'Judul' => 'Pertanyaan dari pemilik '.mb_strtolower($s['Nama']),
                        'Item' => array_map(static fn (array $f): array => ['Pertanyaan' => $f[0], 'Jawaban' => $f[1]], $s['Faq']),
                    ],
                    [
                        'Jenis' => 'Cta',
                        'Judul' => 'Coba dulu dengan data usaha Anda sendiri',
                        'Teks' => $s['Cta'] ?? 'Daftar gratis, pilih sektor "'.$s['Nama'].'" di panduan awal, lalu produk, pajak, dan perangkat kasir disiapkan langkah demi langkah.',
                        'TombolUtama' => ['Label' => 'Coba gratis sekarang', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                    ],
                ],
            ];
        }

        return $hasil;
    }

    /**
     * @return list<array{Kode: string, Slug: string, Nama: string, NamaSeo?: string, JudulSeo?: string, Cta?: string, Ringkas: string, Ikon: string, Spesimen: string, Judul: string, Sub: string, Poin: list<string>, JudulFitur: string, Item: list<array{0: string, 1: string, 2: string}>, Faq: list<array{0: string, 1: string}>, Seo: string}>
     */
    private static function Definisi(): array
    {
        $offline = ['Apakah bisa dipakai kalau internet mati?', 'Bisa. Transaksi disimpan di perangkat dan dikirim otomatis saat internet kembali. Nomor struk memakai kode perangkat sehingga tidak bentrok antar kasir.'];

        return [
            [
                'Kode' => 'FNB-CAF', 'Slug' => 'kafe-kedai-kopi', 'Nama' => 'Kafe & kedai kopi', 'NamaSeo' => 'Kafe & Kedai Kopi',
                'Ringkas' => 'Mode cepat & meja, modifier, bar & dapur', 'Ikon' => 'Coffee', 'Spesimen' => 'Kasir',
                'Judul' => 'Pesanan masuk ke bar sebelum kasir selesai menagih',
                'Sub' => 'Pilih ukuran, gula, es, dan tambahan sekali ketuk. Tiket keluar di printer atau layar bar, dan bahan berkurang sesuai resep.',
                'Poin' => ['Mode cepat & mode meja', 'Tiket bar & dapur terpisah', 'Tetap jalan saat offline'],
                'JudulFitur' => 'Yang berubah saat antrean kopi panjang',
                'Item' => [
                    ['Coffee', 'Modifier tanpa catatan tulisan tangan', 'Ukuran, gula, es, dan topping jadi pilihan terstruktur yang ikut tercetak di tiket, jadi barista tidak menebak.'],
                    ['ChefHat', 'Tiket per stasiun', 'Bar dan dapur menerima tiket masing-masing di printer atau layar dapur (KDS).'],
                    ['UtensilsCrossed', 'Cepat atau per meja', 'Ketuk-bayar untuk antrean di kasir, atau pesanan terbuka per meja untuk tamu yang duduk lama.'],
                    ['Boxes', 'Bahan ikut resep', 'Biji kopi, susu, dan sirup berkurang sesuai resep tiap minuman. Bahan yang terbuang dicatat terpisah.'],
                    ['QrCode', 'Nomor antrean & layar panggil', 'Nomor urut harian tercetak di struk dan tiket, dan layar panggil menampilkan pesanan yang siap.'],
                    ['Percent', 'PBJT & biaya layanan', 'Dihitung dari tarif yang berlaku untuk outlet Anda, bukan angka yang diketik manual.'],
                ],
                'Faq' => [$offline, ['Apakah ada self-order lewat QR di meja?', 'Ada. Tamu memindai QR meja, memilih menu dengan perkiraan total, lalu membayar di kasir. Ketersediaan fitur mengikuti paket langganan. Lihat halaman Harga.']],
                'Seo' => 'Aplikasi kasir kafe dan kedai kopi: modifier ukuran-gula-es, tiket bar & dapur, mode cepat dan meja, stok bahan dari resep, PBJT, tetap jalan offline.',
            ],
            [
                'Kode' => 'FNB-QSR', 'Slug' => 'cepat-saji-kaki-lima', 'Nama' => 'Cepat saji & kaki lima', 'NamaSeo' => 'Warung Makan, Cepat Saji & Kaki Lima',
                'Ringkas' => 'Tombol besar, nomor antrean, ojol', 'Ikon' => 'UtensilsCrossed', 'Spesimen' => 'KasirBayar',
                'Judul' => 'Jam makan siang tidak lagi antre di kasir',
                'Sub' => 'Tombol produk besar yang cepat ditekan, nomor antrean tercetak, dan pesanan GoFood, GrabFood, atau ShopeeFood dicatat dengan harga kanalnya sendiri.',
                'Poin' => ['Tombol produk besar', 'Nomor antrean & layar panggil', 'Harga per kanal ojol'],
                'JudulFitur' => 'Dibuat untuk penjualan yang bergerak cepat',
                'Item' => [
                    ['Zap', 'Dua ketukan sampai bayar', 'Pilih menu, tekan Uang pas atau ketik nominal, selesai. Pemindaian QRIS menutup layar berhasil sendiri.'],
                    ['Clock', 'Nomor antrean urut harian', 'Setiap pesanan bernomor di struk dan tiket dapur, dengan layar panggil untuk pesanan yang siap.'],
                    ['Truck', 'Makan di tempat, bawa pulang, antar', 'Jenis pesanan dipilih di keranjang, dan struk menunjukkannya.'],
                    ['Tag', 'Harga berbeda per kanal ojol', 'Daftar harga khusus GoFood, GrabFood, dan ShopeeFood, dengan pencairan dana yang bisa direkonsiliasi.'],
                    ['QrCode', 'Kios pesan sendiri', 'Layar sentuh untuk pembeli memesan sendiri dan mendapat nomor antrean, lalu membayar di kasir atau QRIS.'],
                    ['WifiOff', 'Tetap jual saat sinyal hilang', 'Transaksi menunggu di perangkat dan terkirim sendiri saat sinyal kembali.'],
                ],
                'Faq' => [['Apakah cocok untuk warung dengan satu kasir?', 'Cocok. Paket Gratis dibuat untuk usaha kecil, dan panduan awal menyiapkan menu dari daftar yang Anda tempel dari WhatsApp atau Excel.'], ['Apakah bisa mencatat penjualan ojol?', 'Bisa. Pilih kanal di keranjang, harga mengikuti daftar harga kanal itu, dan dana yang belum cair tercatat sebagai piutang pencairan sampai Anda mencocokkannya.']],
                'Seo' => 'Aplikasi kasir warung makan, cepat saji, dan kaki lima: tombol produk besar, nomor antrean, harga GoFood/GrabFood/ShopeeFood, kios pesan sendiri, offline.',
            ],
            [
                'Kode' => 'FNB-RST', 'Slug' => 'restoran-rumah-makan', 'Nama' => 'Restoran & rumah makan', 'NamaSeo' => 'Restoran & Rumah Makan',
                'Ringkas' => 'Denah meja, dapur & bar, pisah tagihan', 'Ikon' => 'UtensilsCrossed', 'Spesimen' => 'Kasir',
                'Judul' => 'Dari meja ke dapur tanpa kertas tercecer',
                'Sub' => 'Pelayan mencatat di HP, dapur langsung melihatnya, kasir menagih dengan PBJT dan biaya layanan yang sudah benar, termasuk pisah tagihan dan gabung meja.',
                'Poin' => ['Denah meja berstatus', 'Pelayan tanpa buka shift', 'Pisah tagihan & gabung meja'],
                'JudulFitur' => 'Yang dibutuhkan restoran saat ramai',
                'Item' => [
                    ['UtensilsCrossed', 'Meja punya status', 'Terlihat meja kosong, sudah pesan, minta bill, dan perlu dibersihkan. Meja bisa dipindah dan digabung.'],
                    ['ChefHat', 'Dapur dan bar tidak menebak', 'Tiket keluar per stasiun di printer atau layar dapur, lengkap dengan catatan pesanan.'],
                    ['Clock', 'Kursus pembuka, utama, penutup', 'Tahan hidangan utama sampai pembuka selesai, lalu kirim ke dapur dengan satu ketukan.'],
                    ['Smartphone', 'Mode Pelayan', 'Pelayan mencatat pesanan dari HP tanpa membuka shift kasir. Izinnya terpisah dari kasir.'],
                    ['Receipt', 'Pisah dan bagi tagihan', 'Pisah per item, bagi rata per orang, atau per nominal. Tagihan sementara dicetak sebelum membayar.'],
                    ['Percent', 'PBJT & service charge', 'Tarif diambil dari data pajak yang berlaku di kota outlet, bukan angka tertanam.'],
                ],
                'Faq' => [$offline, ['Apakah bisa dipakai di banyak outlet?', 'Bisa. Stok, harga, dan laporan dikelola per outlet dalam satu akun, dan pemilik memantau semuanya dari Aplikasi Pemilik.']],
                'Seo' => 'Aplikasi kasir restoran dan rumah makan: denah meja, pesanan ke dapur, pisah tagihan, gabung meja, mode pelayan, PBJT dan service charge, laporan per outlet.',
            ],
            [
                'Kode' => 'FNB-BAK', 'Slug' => 'bakery-kue', 'Nama' => 'Bakery & toko kue', 'NamaSeo' => 'Bakery & Toko Kue',
                'Ringkas' => 'Produksi harian, pre-order, kedaluwarsa', 'Ikon' => 'Gift', 'Spesimen' => 'KasirRiwayat',
                'Judul' => 'Kue hari ini habis, kue kemarin tidak terjual diam-diam',
                'Sub' => 'Catat produksi harian, terima pre-order kue dengan uang muka, dan pantau tanggal kedaluwarsa supaya yang terjual selalu yang paling dulu masuk.',
                'Poin' => ['Order produksi & resep', 'Pre-order dengan uang muka', 'Kedaluwarsa per batch'],
                'JudulFitur' => 'Dari oven sampai etalase',
                'Item' => [
                    ['Boxes', 'Produksi memotong bahan', 'Order produksi mengurangi tepung, mentega, dan telur sesuai resep, lalu menambah stok kue jadi dengan HPP yang benar.'],
                    ['ClipboardList', 'Pre-order dengan DP', 'Kue pesanan dicatat dengan uang muka, diambil dan dilunasi kemudian, dengan bukti uang muka tercetak.'],
                    ['Clock', 'Kedaluwarsa dijaga', 'Penjualan mengambil dari batch yang paling dulu kedaluwarsa, dan barang yang hampir habis masa simpan muncul di Kotak Tindakan.'],
                    ['Package', 'Bahan terbuang tercatat', 'Adonan gagal atau kue yang tidak laku dicatat sebagai susut, sehingga food cost terlihat jujur.'],
                    ['Globe', 'Katalog pesanan online', 'Pembeli memesan dari halaman toko Anda dan membayar di muka lewat QRIS.'],
                    ['ChartLine', 'Laporan yang terbaca', 'Kue terlaris, susut, dan margin per produk tanpa menyalin ke Excel.'],
                ],
                'Faq' => [['Apakah resep dan HPP dihitung otomatis?', 'Ya. HPP kue jadi = jumlah biaya bahan + overhead produksi yang Anda isi, dan berubah mengikuti harga beli bahan terakhir.'], ['Bagaimana jika pesanan kue dibatalkan?', 'Uang muka bisa dikembalikan atau dinyatakan hangus sesuai kebijakan Anda, dan keduanya tercatat di jurnal.']],
                'Seo' => 'Aplikasi kasir bakery dan toko kue: order produksi dengan resep, pre-order dengan uang muka, kedaluwarsa per batch, bahan terbuang, pesanan online dan QRIS.',
            ],
            [
                'Kode' => 'RTL-GEN', 'Slug' => 'toko-kelontong-minimarket', 'Nama' => 'Toko kelontong & minimarket', 'NamaSeo' => 'Toko Kelontong & Minimarket',
                'Ringkas' => 'Scan barcode, multi-satuan, stok minimum', 'Ikon' => 'ShoppingBasket', 'Spesimen' => 'Struk',
                'Judul' => 'Selisih stok berhenti jadi tebakan',
                'Sub' => 'Pindai, jual, terima barang. Satu produk boleh punya beberapa satuan dan barcode, dan kartu stok menunjukkan setiap pergerakan beserta siapa yang mencatatnya.',
                'Poin' => ['Pindai tanpa klik tambahan', 'Satuan dus, pak, dan pcs', 'Saran restock dari penjualan'],
                'JudulFitur' => 'Yang dipakai setiap hari di toko',
                'Item' => [
                    ['ShoppingBasket', 'Pindai langsung masuk', 'Pemindai langsung menambah baris keranjang, dan mode Cek harga menampilkan harga tanpa menambahnya ke keranjang.'],
                    ['Package', 'Beberapa satuan jual', 'Jual per pcs, pak, atau dus dari satu produk, dengan harga dan barcode per satuan.'],
                    ['Boxes', 'Stok minimum & saran restock', 'Barang di bawah batas minimum menjadi draf pesanan pembelian otomatis, dan laporan saran restock menghitungnya dari kecepatan jual.'],
                    ['Tag', 'Harga bertingkat', 'Harga member, reseller, dan grosir mengikuti tier pelanggan, tanpa kasir menghitung.'],
                    ['HandCoins', 'Jual tempo', 'Limit kredit per pelanggan, umur piutang, dan pelunasan sebagian, lengkap dengan pengingat lewat WhatsApp.'],
                    ['Truck', 'Pembelian & hutang pemasok', 'Pesanan pembelian, penerimaan barang, faktur, dan hutang jatuh tempo dalam satu tempat.'],
                ],
                'Faq' => [['Apakah saya bisa memindahkan daftar produk dari Excel?', 'Bisa. Tempel dari Excel atau WhatsApp di panduan awal, periksa hasilnya, lalu simpan. Barcode yang sudah ada tetap dipakai.'], $offline],
                'Seo' => 'Aplikasi kasir toko kelontong dan minimarket: pemindai barcode, multi satuan, stok minimum, harga bertingkat, jual tempo, pembelian dan hutang pemasok.',
            ],
            [
                'Kode' => 'RTL-FRS', 'Slug' => 'toko-sayur-sembako', 'Nama' => 'Toko sayur, buah & sembako', 'NamaSeo' => 'Toko Sayur, Buah & Sembako',
                'Ringkas' => 'Timbang per kg, ikat/karung, susut sayur', 'Ikon' => 'ShoppingBasket', 'Spesimen' => 'KasirHp',
                'Judul' => 'Sayur layu tercatat sebagai susut, bukan hilang tanpa jejak',
                'Sub' => 'Jual per kilo, per ikat, atau per karung, dengan harga yang bisa berubah tiap pagi. Barang layu dan busuk dicatat sebagai susut, jadi untung rugi terlihat apa adanya.',
                'Poin' => ['Timbang per kg & barcode timbangan', 'Satuan ikat, karung, dan kilo', 'Susut sayur layu tercatat'],
                'JudulFitur' => 'Disesuaikan untuk pasar dan kios',
                'Item' => [
                    ['ShoppingBasket', 'Timbang lalu jual', 'Jumlah desimal untuk kilo dan ons, dan barcode timbangan (EAN-13) langsung terbaca sebagai berat atau harga.'],
                    ['Package', 'Beli karung, jual kiloan', 'Beli per karung atau ikat dari pemasok, jual per kilo atau per ikat, dan stok mengikuti konversi satuannya.'],
                    ['Tag', 'Harga berubah, struk tetap benar', 'Ubah harga puluhan barang sekaligus tiap pagi, dan simpan harga terbuka untuk barang yang ditawar.'],
                    ['Boxes', 'Susut dicatat terpisah', 'Sayur layu, buah busuk, dan barang kedaluwarsa masuk laporan susut sehingga margin tidak terlihat lebih bagus dari kenyataan.'],
                    ['Percent', 'Tanpa pajak bila Anda non-PKP', 'Untuk usaha non-PKP, pajak tidak ditanyakan lagi saat Anda menyiapkan toko.'],
                    ['HandCoins', 'Bon langganan', 'Catat pembelian tempo pelanggan tetap atau warung, lengkap dengan umur piutang dan pelunasannya.'],
                ],
                'Faq' => [['Apakah bisa dipakai di HP saja tanpa komputer?', 'Bisa. Aplikasi kasir berjalan di HP atau tablet Android, dan printer Bluetooth kecil cukup untuk struk.'], ['Barang yang tidak punya barcode bagaimana?', 'Cukup ketuk produknya di layar atau ketik namanya. Foto dan kategori membantu mencari lebih cepat, dan barcode bisa ditambahkan kemudian.']],
                'Seo' => 'Aplikasi kasir toko sayur, buah, dan sembako: timbang per kilo, satuan ikat dan karung, catat susut sayur layu, harga berubah harian, bon langganan, offline.',
            ],
            [
                'Kode' => 'RTL-FSH', 'Slug' => 'fashion-aksesoris', 'Nama' => 'Fashion & aksesoris', 'NamaSeo' => 'Toko Fashion & Aksesoris',
                'Ringkas' => 'Varian ukuran × warna, tukar barang', 'Ikon' => 'Shirt', 'Spesimen' => 'KasirBayar',
                'Judul' => 'Ukuran M merah tinggal berapa, terlihat tanpa membuka gudang',
                'Sub' => 'Satu model, banyak varian ukuran dan warna, dengan stok per varian. Pelanggan menukar barang di kasir, dan poin loyalti terkumpul sendiri.',
                'Poin' => ['Varian ukuran × warna', 'Tukar barang di kasir', 'Poin & tier pelanggan'],
                'JudulFitur' => 'Untuk butik dan distro',
                'Item' => [
                    ['Shirt', 'Varian ukuran dan warna', 'Satu produk dengan tabel varian. Harga dan barcode bisa diisi sama untuk semua atau berbeda per varian.'],
                    ['RefreshCw', 'Tukar barang', 'Retur dari struk, pilih pengganti, dan selisih harga dihitung. Lebih murah dikembalikan tunai, lebih mahal ditagih.'],
                    ['Star', 'Loyalti pelanggan', 'Poin, tier, dan harga khusus anggota. Poin bisa ditukar sebagai diskon di kasir.'],
                    ['Gift', 'Promo & voucher', 'Diskon otomatis, promo metode bayar, dan voucher berkode, semuanya dihitung konsisten di kasir dan server.'],
                    ['Warehouse', 'Stok antar outlet', 'Transfer barang antar toko dan opname per varian dengan riwayat lengkap.'],
                    ['Globe', 'Toko online', 'Katalog dan pesanan online memakai stok yang sama dengan toko fisik.'],
                ],
                'Faq' => [['Apakah stok toko fisik dan online terpisah?', 'Tidak. Pesanan online mencadangkan stok toko yang sama, sehingga barang tidak terjual ganda.'], $offline],
                'Seo' => 'Aplikasi kasir toko fashion dan aksesoris: varian ukuran dan warna, tukar barang, poin loyalti, voucher dan promo, stok antar outlet, toko online.',
            ],
            [
                'Kode' => 'RTL-ELC', 'Slug' => 'elektronik-gadget', 'Nama' => 'Elektronik & gadget', 'NamaSeo' => 'Toko Elektronik & Gadget',
                'Ringkas' => 'Nomor seri/IMEI, garansi di struk', 'Ikon' => 'Smartphone', 'Spesimen' => 'Struk',
                'Judul' => 'IMEI dan garansi tercatat di setiap struk',
                'Sub' => 'Setiap unit punya nomor serinya sendiri. Penjualan, retur, dan klaim garansi tinggal dicari dari nomor seri, tanpa membuka buku catatan.',
                'Poin' => ['Nomor seri & IMEI per unit', 'Garansi tercetak di struk', 'Riwayat nomor seri'],
                'JudulFitur' => 'Untuk toko HP, laptop, dan perangkat',
                'Item' => [
                    ['Smartphone', 'Nomor seri per unit', 'Setiap penjualan memilih nomor seri yang dijual, dan server memastikan nomor itu memang tersedia.'],
                    ['ShieldCheck', 'Garansi otomatis', 'Masa garansi per produk tercetak di struk bersama nomor serinya, dan tampil di struk digital.'],
                    ['ClipboardList', 'Riwayat satu nomor seri', 'Cari IMEI untuk melihat kapan masuk, siapa pembelinya, dan sisa garansinya.'],
                    ['RefreshCw', 'Retur mengembalikan nomor', 'Unit yang diretur kembali tersedia dengan nomor serinya yang sama.'],
                    ['Wallet', 'QRIS dan non-tunai', 'Pembayaran QRIS dinamis dan metode non-tunai dicatat sebagai piutang pencairan sampai dananya masuk.'],
                    ['Truck', 'Pembelian dari distributor', 'Penerimaan barang dengan nomor seri, faktur, dan hutang jatuh tempo.'],
                ],
                'Faq' => [['Apakah bisa mencatat jasa servis?', 'Bisa dijual sebagai item layanan di kasir. Untuk perintah kerja lengkap dengan persetujuan pelanggan, lihat solusi Bengkel.'], ['Apakah nomor seri wajib untuk semua produk?', 'Tidak. Hanya produk yang Anda tandai bernomor seri. Produk lain dijual seperti biasa.']],
                'Seo' => 'Aplikasi kasir toko elektronik dan gadget: nomor seri dan IMEI per unit, garansi di struk, riwayat nomor seri, retur, QRIS, pembelian dari distributor.',
            ],
            [
                'Kode' => 'RTL-BLD', 'Slug' => 'bahan-bangunan', 'Nama' => 'Bahan bangunan', 'NamaSeo' => 'Toko Bahan Bangunan',
                'Ringkas' => 'Satuan desimal, tempo kontraktor', 'Ikon' => 'Building', 'Spesimen' => 'Jurnal',
                'Judul' => 'Harga semen naik pagi ini, semua daftar harga ikut berubah',
                'Sub' => 'Jual per meter, per kilo, atau per batang dengan angka desimal. Pelanggan kontraktor boleh tempo dengan limit kredit, dan tagihannya tersusun per pelanggan.',
                'Poin' => ['Satuan desimal & multi-satuan', 'Tempo dan limit kontraktor', 'Ubah harga massal'],
                'JudulFitur' => 'Untuk toko besi dan material',
                'Item' => [
                    ['Package', 'Satuan desimal', 'Meter, kilo, lembar, dan batang dengan pecahan, tanpa pembulatan yang menggeser stok.'],
                    ['Tag', 'Harga sering berubah', 'Daftar harga per level pelanggan dan ubah harga puluhan barang sekaligus.'],
                    ['HandCoins', 'Tempo untuk kontraktor', 'Limit kredit, umur piutang, nota tagihan per pelanggan, dan pelunasan sebagian.'],
                    ['Truck', 'Pengiriman', 'Ongkir dicatat terpisah dari harga barang dan masuk jurnal sebagai pendapatan pengiriman.'],
                    ['Warehouse', 'Stok gudang & toko', 'Transfer antar gudang dan opname dengan riwayat lengkap.'],
                    ['FileText', 'Mode grosir', 'Katalog berbentuk daftar dengan pengali jumlah cepat, cocok untuk pesanan besar.'],
                ],
                'Faq' => [['Apakah faktur dan surat jalan tersedia?', 'Ya, lewat modul Grosir: pesanan, surat jalan, dan faktur dengan cetak A4. Surat jalan tidak menampilkan harga.'], $offline],
                'Seo' => 'Aplikasi kasir toko bahan bangunan: satuan desimal, harga per level pelanggan, tempo dan limit kredit kontraktor, ongkir, transfer gudang, mode grosir.',
            ],
            [
                'Kode' => 'RTL-PHR', 'Slug' => 'apotek-toko-obat', 'Nama' => 'Apotek & toko obat', 'NamaSeo' => 'Apotek & Toko Obat',
                'Ringkas' => 'Golongan obat, resep, FEFO, racikan', 'Ikon' => 'Pill', 'Spesimen' => 'KasirBayar',
                'Judul' => 'Obat keras tidak keluar tanpa resep dan PIN apoteker',
                'Sub' => 'Setiap obat punya golongan. Obat keras meminta data resep dan PIN apoteker sebelum diserahkan, dan stok dijual dari batch yang paling dulu kedaluwarsa.',
                'Poin' => ['Golongan obat per produk', 'Resep & PIN apoteker', 'Kedaluwarsa FEFO'],
                'JudulFitur' => 'Untuk apotek dan toko obat berizin',
                'Item' => [
                    ['Pill', 'Golongan obat', 'Setiap produk obat bisa diberi golongan, misalnya bebas, obat wajib apotek, atau keras, dan tampil sebagai lencana di kasir.'],
                    ['ShieldCheck', 'Penyerahan obat keras', 'Dialog data resep dan PIN apoteker wajib sebelum bayar. Data resep disimpan terenkripsi.'],
                    ['Clock', 'Batch & kedaluwarsa (FEFO)', 'Penjualan mengambil batch terdepan, dengan peringatan bila hampir atau sudah lewat masa simpan.'],
                    ['Boxes', 'Racikan', 'Jasa racik dengan komponen obat yang dipotong dari stok, embalase, dan tuslah.'],
                    ['FileText', 'Data pendukung SIPNAP', 'Laporan apotek menyusun data penjualan obat tertentu sebagai bahan pelaporan Anda.'],
                    ['Truck', 'Pembelian & faktur pemasok', 'Penerimaan barang dengan nomor batch dan tanggal kedaluwarsa, lalu hutang jatuh tempo.'],
                ],
                'Faq' => [['Apakah aplikasi ini mengurus izin apotek saya?', 'Tidak. Payoung membantu pencatatan dan kepatuhan operasional, sedangkan izin dan pelaporan resmi tetap tanggung jawab apotek dan apoteker penanggung jawab.'], ['Siapa yang boleh menyerahkan obat keras?', 'Hanya pengguna berperan Apoteker yang memasukkan PIN-nya. Kasir biasa tidak bisa melewatinya.']],
                'Seo' => 'Aplikasi kasir apotek dan toko obat: golongan obat, resep dan PIN apoteker untuk obat keras, batch dan kedaluwarsa FEFO, racikan, data pendukung SIPNAP.',
            ],
            [
                'Kode' => 'RTL-PET', 'Slug' => 'pet-shop-grooming', 'Nama' => 'Pet shop & grooming', 'NamaSeo' => 'Pet Shop & Grooming',
                'Ringkas' => 'Pakan, aksesoris, grooming, loyalti', 'Ikon' => 'Heart', 'Spesimen' => 'KasirRiwayat',
                'Judul' => 'Pakan, aksesoris, dan grooming dalam satu struk',
                'Sub' => 'Jual pakan kiloan dan kemasan, catat layanan grooming dan penitipan, dan beri poin untuk pelanggan tetap, dengan tanggal kedaluwarsa pakan yang tidak terlewat.',
                'Poin' => ['Pakan kiloan & kemasan', 'Grooming dan penitipan', 'Kedaluwarsa pakan'],
                'JudulFitur' => 'Untuk toko perlengkapan hewan',
                'Item' => [
                    ['Package', 'Pakan kiloan dan kemasan', 'Beli karung, jual eceran per kilo. Satuan dan stok mengikuti konversinya.'],
                    ['Scissors', 'Layanan di struk yang sama', 'Grooming dan penitipan dijual sebagai layanan bersama barang, dengan staf pelaksana tercatat.'],
                    ['Clock', 'Kedaluwarsa terpantau', 'Pakan dan obat hewan dijual dari batch terdepan, dengan daftar yang hampir kedaluwarsa.'],
                    ['Star', 'Loyalti pelanggan', 'Poin dan tier untuk pelanggan setia, dengan data pelanggan yang bisa dihubungi lewat WhatsApp.'],
                    ['Tag', 'Promo musiman', 'Diskon otomatis dan voucher berkode untuk hari tertentu atau pembelian paket.'],
                    ['Truck', 'Pembelian & pemasok', 'Pesanan pembelian, penerimaan, dan hutang pemasok pakan.'],
                ],
                'Faq' => [['Apakah bisa menjual paket grooming?', 'Bisa. Paket sesi dijual sekali, lalu sisa sesinya berkurang tiap kunjungan.'], $offline],
                'Seo' => 'Aplikasi kasir pet shop dan grooming: pakan kiloan, layanan grooming dan penitipan, kedaluwarsa pakan, poin loyalti, paket sesi, stok dan pemasok.',
            ],
            [
                'Kode' => 'RTL-FLR', 'Slug' => 'florist-toko-hadiah', 'Nama' => 'Florist & toko hadiah', 'NamaSeo' => 'Florist & Toko Hadiah',
                'Ringkas' => 'Buket dirakit, pre-order, antar kota', 'Ikon' => 'Gift', 'Spesimen' => 'KasirRiwayat',
                'Judul' => 'Pesanan buket Valentine tidak lagi bertumpuk di catatan HP',
                'Sub' => 'Terima pesanan buket dengan uang muka, rakit dari bunga dan aksesori di stok, dan antar dalam kota dengan ongkir yang tercatat jelas.',
                'Poin' => ['Pre-order dengan uang muka', 'Buket dirakit dari bahan', 'Ongkir antar kota'],
                'JudulFitur' => 'Dari pesanan sampai pengantaran',
                'Item' => [
                    ['ClipboardList', 'Pre-order dengan DP', 'Catat pesanan, tanggal ambil, dan uang muka. Lunasi saat pesanan diambil.'],
                    ['Boxes', 'Buket dirakit sesuai pesanan', 'Perakitan memotong bunga dan aksesori dari stok dan menambah stok buket jadi dengan HPP yang benar.'],
                    ['Truck', 'Antar dalam kota', 'Ongkir dicatat per pesanan, bisa gratis ongkir lewat promo, dan kurir mengonfirmasi lewat tautan tanpa akun.'],
                    ['Tag', 'Harga mengikuti musim', 'Ubah harga banyak produk sekaligus menjelang hari besar.'],
                    ['Star', 'Pelanggan tetap', 'Poin, data pelanggan, dan promo ulang tahun untuk pelanggan yang sering memesan.'],
                    ['Globe', 'Katalog online', 'Pembeli memesan dari halaman toko Anda dan membayar di muka lewat QRIS.'],
                ],
                'Faq' => [['Bagaimana jika bunga layu sebelum terjual?', 'Catat sebagai bahan terbuang. Nilainya masuk ke laporan susut sehingga margin tidak terlihat lebih tinggi dari kenyataan.'], $offline],
                'Seo' => 'Aplikasi kasir florist dan toko hadiah: pre-order buket dengan uang muka, perakitan dari bahan, ongkir antar dalam kota, bunga layu sebagai susut, katalog online.',
            ],
            [
                'Kode' => 'WHS-DST', 'Slug' => 'grosir-distributor', 'Nama' => 'Grosir & distributor', 'NamaSeo' => 'Grosir & Distributor',
                'Ringkas' => 'Pesanan, surat jalan, faktur, salesman', 'Ikon' => 'Warehouse', 'Spesimen' => 'Jurnal',
                'Judul' => 'Dari pesanan salesman sampai faktur bulanan tanpa ketik ulang',
                'Sub' => 'Pesanan grosir, surat jalan, dan faktur gabungan per pelanggan, dengan limit kredit yang menahan pesanan sebelum piutang membengkak.',
                'Poin' => ['Pesanan, surat jalan, faktur', 'Harga per level pelanggan', 'Salesman & kanvas'],
                'JudulFitur' => 'Untuk grosir sembako dan distributor',
                'Item' => [
                    ['FileText', 'Satu alur dokumen', 'Pesanan grosir menjadi surat jalan saat barang keluar, lalu satu faktur gabungan per pelanggan per bulan.'],
                    ['HandCoins', 'Limit kredit menjaga piutang', 'Limit kredit dicek saat pesanan dibuat, dan umur piutang terlihat per pelanggan.'],
                    ['Tag', 'Harga per level', 'Daftar harga untuk agen, reseller, dan grosir yang diterapkan otomatis sesuai pelanggan.'],
                    ['Users', 'Modul salesman', 'Salesman mengambil pesanan di lapangan secara offline, mencatat kunjungan dengan lokasi, dan menjual dari mobil kanvas.'],
                    ['Warehouse', 'Beberapa gudang', 'Transfer antar gudang dan opname, dengan stok kendaraan kanvas dipisahkan.'],
                    ['Receipt', 'Cetak A4 yang rapi', 'Surat jalan, faktur, dan nota kredit dicetak A4. Surat jalan sengaja tanpa harga.'],
                ],
                'Faq' => [['Apakah retur dan nota kredit ada?', 'Ada. Retur grosir mengembalikan stok pada HPP asal dan menerbitkan nota kredit, baik untuk faktur yang sudah terbit maupun belum.'], ['Apakah mendukung faktur pajak?', 'Rekap faktur pajak keluaran bisa diekspor dalam format XML Coretax dari laporan pajak. Format ini perlu Anda uji di aplikasi resmi sebelum dipakai melapor.']],
                'Seo' => 'Aplikasi kasir dan back-office grosir dan distributor: pesanan grosir, surat jalan, faktur gabungan, limit kredit, harga per level, salesman, kanvas, cetak A4.',
            ],
            [
                'Kode' => 'SVC-SLN', 'Slug' => 'salon-barbershop-spa', 'Nama' => 'Salon, barbershop & spa', 'NamaSeo' => 'Salon, Barbershop & Spa',
                'Ringkas' => 'Reservasi per staf, komisi, paket sesi', 'Ikon' => 'Scissors', 'Spesimen' => 'PemilikKaryawan',
                'Judul' => 'Komisi dan sisa paket pelanggan berhenti dihitung tangan',
                'Sub' => 'Catat siapa yang melayani, terima reservasi per staf, jual paket sesi, dan komisi tersusun sendiri ke rekap gaji bulanan.',
                'Poin' => ['Reservasi per staf', 'Paket sesi & deposit', 'Komisi otomatis'],
                'JudulFitur' => 'Untuk usaha perawatan dan kecantikan',
                'Item' => [
                    ['Clock', 'Reservasi sesuai jadwal staf', 'Slot diambil dari jadwal kerja. Pelanggan bisa memesan online dan diingatkan lewat WhatsApp sehari sebelumnya.'],
                    ['Users', 'Komisi menempel ke orang', 'Aturan komisi per layanan, boleh dibagi ke beberapa staf, dan masuk ke rekap gaji tanpa dihitung ulang.'],
                    ['Wallet', 'Paket sesi dan deposit', 'Pelanggan membeli paket atau mengisi saldo. Sisa sesi berkurang sendiri tiap kunjungan.'],
                    ['Smartphone', 'Absen di perangkat kasir', 'PIN dan swafoto, terhubung ke jadwal kerja dan target penjualan per staf.'],
                    ['Star', 'Tier dan poin pelanggan', 'Tier pelanggan dan poin yang bisa ditukar menjadi diskon.'],
                    ['Boxes', 'Stok produk perawatan', 'Produk retail dan bahan pakai habis dikelola di stok yang sama.'],
                ],
                'Faq' => [['Apakah bisa ada beberapa kapster atau terapis?', 'Bisa. Setiap staf punya jadwal dan komisinya sendiri, dan reservasi dipilih per staf.'], ['Bagaimana mengurangi pelanggan yang lupa datang?', 'Pelanggan yang memesan reservasi diingatkan lewat WhatsApp sehari sebelumnya, dan reservasi tercatat per staf supaya jadwalnya tidak bentrok.']],
                'Seo' => 'Aplikasi kasir salon, barbershop, dan spa: reservasi per staf, komisi otomatis, paket sesi, deposit pelanggan, jadwal dan absensi karyawan, pengingat WhatsApp.',
            ],
            [
                'Kode' => 'SVC-LDR', 'Slug' => 'laundry', 'Nama' => 'Laundry', 'NamaSeo' => 'Laundry',
                'Ringkas' => 'Tiket laundry, status proses, QR lacak', 'Ikon' => 'WashingMachine', 'Spesimen' => 'Struk',
                'Judul' => 'Pelanggan tahu cucian siap tanpa menelepon',
                'Sub' => 'Setiap cucian punya tiket dengan estimasi selesai. Status berpindah dari diterima sampai siap diambil, dan pelanggan diberi tahu lewat WhatsApp.',
                'Poin' => ['Tiket & nota ber-QR lacak', 'WhatsApp saat siap diambil', 'Deposit pelanggan'],
                'JudulFitur' => 'Untuk laundry kiloan dan satuan',
                'Item' => [
                    ['WashingMachine', 'Tiket laundry dari kasir', 'Berat, jenis layanan, dan estimasi selesai dicatat saat menerima cucian, lengkap dengan label.'],
                    ['QrCode', 'Pelanggan bisa melacak sendiri', 'Nota ber-QR membuka halaman status, jadi pelanggan tidak perlu bertanya ke kasir.'],
                    ['MessageCircle', 'WhatsApp saat siap', 'Pesan otomatis terkirim ketika status berubah menjadi siap diambil.'],
                    ['Wallet', 'Deposit pelanggan', 'Pelanggan langganan mengisi saldo dan membayar dari saldo itu, dengan bukti isi saldo tercetak.'],
                    ['ClipboardList', 'Layanan kiloan dan satuan', 'Harga per kilo untuk cucian reguler dan per potong untuk jas, bed cover, atau karpet.'],
                    ['ChartLine', 'Laporan yang jelas', 'Omzet per layanan, cucian belum diambil, dan penjualan per kasir.'],
                ],
                'Faq' => [['Bagaimana jika cucian tidak diambil?', 'Tiket yang berstatus siap tetapi belum diambil terlihat di halaman Laundry, sehingga bisa diingatkan.'], $offline],
                'Seo' => 'Aplikasi kasir laundry kiloan dan satuan: tiket laundry, status proses, QR lacak untuk pelanggan, WhatsApp saat siap diambil, deposit pelanggan, offline.',
            ],
            [
                'Kode' => 'SVC-WRK', 'Slug' => 'bengkel', 'Nama' => 'Bengkel motor & mobil', 'NamaSeo' => 'Bengkel Motor & Mobil',
                'Ringkas' => 'Perintah kerja, persetujuan WA, riwayat', 'Ikon' => 'Car', 'Spesimen' => 'PemilikPersetujuan',
                'Judul' => 'Estimasi servis disetujui pelanggan sebelum mekanik mulai bekerja',
                'Sub' => 'Catat keluhan, diagnosis, dan estimasi jasa serta sparepart. Pelanggan menyetujui lewat tautan WhatsApp, dan servis berikutnya diingatkan otomatis.',
                'Poin' => ['Perintah kerja & persetujuan WA', 'Mekanik per jasa & komisi', 'Pengingat servis berkala'],
                'JudulFitur' => 'Untuk bengkel motor dan mobil',
                'Item' => [
                    ['ClipboardList', 'Perintah kerja lengkap', 'Keluhan, diagnosis, jasa, dan sparepart dalam satu dokumen yang bisa ditagih langsung di kasir.'],
                    ['MessageCircle', 'Persetujuan lewat WhatsApp', 'Pelanggan membuka tautan, memeriksa estimasi, lalu menyetujui atau menolak tanpa menelepon.'],
                    ['Car', 'Riwayat per kendaraan', 'Setiap plat nomor punya riwayat servis, kilometer, dan sparepart yang pernah diganti.'],
                    ['Users', 'Mekanik per jasa', 'Komisi mekanik dihitung dari jasa yang ia kerjakan dan masuk ke rekap gaji.'],
                    ['Boxes', 'Sparepart ber-batch dan bernomor seri', 'Sparepart dipotong dari stok dan batch yang tepat, dan nomor seri ikut tercatat di struk.'],
                    ['Bell', 'Pengingat servis', 'Pelanggan diingatkan untuk servis berkala lewat WhatsApp.'],
                ],
                'Faq' => [['Apakah pelanggan wajib punya aplikasi?', 'Tidak. Persetujuan lewat tautan di WhatsApp yang dibuka di peramban HP.'], $offline],
                'Seo' => 'Aplikasi kasir bengkel motor dan mobil: perintah kerja, estimasi disetujui lewat WhatsApp, riwayat servis per kendaraan, komisi mekanik, pengingat servis.',
            ],
            [
                'Kode' => 'SVC-FIT', 'Slug' => 'gym-kursus', 'Nama' => 'Gym, kebugaran & kursus', 'NamaSeo' => 'Gym, Kebugaran & Kursus',
                'Ringkas' => 'Tier anggota, paket sesi, komisi pelatih', 'Ikon' => 'Heart', 'Spesimen' => 'PemilikKaryawan',
                'Judul' => 'Sisa sesi member terlihat sebelum ia bertanya',
                'Sub' => 'Jual paket sesi dan tiket harian, lengkap dengan tier dan poin anggota. Sisa sesi berkurang sendiri tiap kunjungan, dan komisi pelatih tersusun dari sesi yang benar-benar dipakai.',
                'Poin' => ['Paket sesi & tier anggota', 'Tiket harian', 'Komisi pelatih'],
                'JudulFitur' => 'Untuk pusat kebugaran dan tempat kursus',
                'Item' => [
                    ['Wallet', 'Paket sesi', 'Jual paket sekali, pakai sesi di kasir tiap kedatangan, dan sisa sesi yang hangus diurus otomatis.'],
                    ['Clock', 'Jadwal kelas dan reservasi', 'Sesi dengan pelatih dipesan lewat slot per staf, lengkap dengan pengingat WhatsApp.'],
                    ['Users', 'Komisi pelatih', 'Aturan komisi per layanan dan per pelatih yang masuk ke rekap gaji bulanan.'],
                    ['Star', 'Loyalti anggota', 'Poin, tier, dan promo ulang tahun untuk member yang tetap datang.'],
                    ['Receipt', 'Tiket harian', 'Jual tiket sekali masuk dengan struk seperti penjualan biasa.'],
                    ['Smartphone', 'Absensi pelatih', 'Pelatih absen di perangkat kasir atau lewat HP dengan lokasi dan wajah.'],
                ],
                'Faq' => [['Bagaimana pendapatan dari paket dibukukan?', 'Penjualan paket dicatat sebagai pendapatan diterima dimuka, lalu diakui bertahap saat sesi dipakai atau hangus.'], $offline],
                'Seo' => 'Aplikasi kasir gym, kebugaran, dan kursus: paket sesi, tier anggota, tiket harian, reservasi kelas, komisi pelatih, loyalti anggota, absensi pelatih.',
            ],
            [
                'Kode' => 'SVC-CWS', 'Slug' => 'cuci-kendaraan', 'Nama' => 'Cuci kendaraan', 'NamaSeo' => 'Cuci Motor & Mobil',
                'Ringkas' => 'Layanan per jenis kendaraan, crew', 'Ikon' => 'Car', 'Spesimen' => 'KasirBayar',
                'Judul' => 'Harga cuci mobil dan motor tidak lagi ditanyakan ulang',
                'Sub' => 'Layanan dan harga per jenis kendaraan, komisi untuk crew yang mengerjakan, dan paket cuci untuk pelanggan langganan.',
                'Poin' => ['Harga per jenis kendaraan', 'Komisi crew', 'Paket cuci langganan'],
                'JudulFitur' => 'Untuk cuci motor, mobil, dan detailing',
                'Item' => [
                    ['Car', 'Layanan per jenis kendaraan', 'Cuci, steam, poles, dan detailing dengan harga untuk motor, mobil kecil, dan SUV.'],
                    ['Users', 'Komisi crew', 'Crew yang mengerjakan dicatat di struk, dan komisinya terhitung sendiri.'],
                    ['Wallet', 'Paket cuci', 'Pelanggan membeli paket beberapa kali cuci dan memakainya dari kasir.'],
                    ['Star', 'Loyalti', 'Poin untuk pelanggan tetap, ditukar menjadi diskon.'],
                    ['Zap', 'Kasir cepat', 'Pilih jenis kendaraan dan layanan dalam dua ketukan, bayar tunai atau QRIS.'],
                    ['Boxes', 'Stok sabun dan bahan', 'Bahan habis pakai dikelola dengan stok minimum dan saran pembelian.'],
                ],
                'Faq' => [['Apakah bisa mencatat plat nomor?', 'Bisa dicantumkan sebagai nama pemesan di keranjang dan muncul di struk.'], $offline],
                'Seo' => 'Aplikasi kasir cuci motor dan mobil: layanan per jenis kendaraan, komisi crew, paket cuci langganan, loyalti, QRIS, tetap jalan saat offline.',
            ],
            [
                'Kode' => 'SVC-GEN', 'Slug' => 'jasa-umum', 'Nama' => 'Jasa umum & percetakan', 'NamaSeo' => 'Jasa, Percetakan & Fotokopi',
                'Ringkas' => 'Pesanan kustom, uang muka, harga terbuka', 'Ikon' => 'Printer', 'Spesimen' => 'KasirRiwayat',
                'Judul' => 'Pesanan khusus tercatat lengkap dengan uang mukanya',
                'Sub' => 'Fotokopi, cetak, dan rental dengan harga yang kadang ditentukan saat itu juga. Pesanan khusus dicatat dengan uang muka dan status pengerjaan.',
                'Poin' => ['Harga terbuka untuk pesanan khusus', 'Uang muka & status pengerjaan', 'Laporan harian'],
                'JudulFitur' => 'Untuk fotokopi, percetakan, dan rental',
                'Item' => [
                    ['Tag', 'Harga terbuka', 'Ketik harga saat itu juga untuk pesanan yang tidak punya harga tetap, lengkap dengan catatan.'],
                    ['ClipboardList', 'Pesanan dengan DP', 'Catat pesanan cetak atau rental dengan uang muka, lalu lunasi saat diambil.'],
                    ['Receipt', 'Struk dan tagihan', 'Struk cetak atau digital, dan nota tagihan untuk pelanggan yang membayar belakangan.'],
                    ['HandCoins', 'Bayar belakangan', 'Pelanggan korporasi boleh tempo dengan limit kredit dan pengingat piutang.'],
                    ['Boxes', 'Stok kertas dan tinta', 'Bahan habis pakai dengan stok minimum dan penyesuaian saat opname.'],
                    ['ChartLine', 'Laporan harian', 'Omzet, kas masuk keluar, dan tutup shift yang selisihnya terlihat.'],
                ],
                'Faq' => [['Apakah cocok untuk usaha sangat kecil?', 'Cocok. Paket Gratis cukup untuk satu kasir, dan Anda bisa naik paket kapan saja dari back-office.'], $offline],
                'Seo' => 'Aplikasi kasir fotokopi, percetakan, rental, dan jasa umum: harga terbuka, pesanan dengan uang muka, tagihan tempo, stok bahan, laporan harian.',
            ],
            [
                'Kode' => 'BIZ-JSA', 'Slug' => 'jasa-profesional-agensi', 'Nama' => 'Jasa profesional & agensi', 'JudulSeo' => 'Aplikasi Invoice & Penagihan untuk Jasa Profesional',
                'Ringkas' => 'Invoice, retainer berulang, PPh dipotong', 'Ikon' => 'FileText', 'Spesimen' => 'Jurnal',
                'Judul' => 'Tagihan terkirim hari ini, pembayarannya terlihat besok',
                'Sub' => 'Buat invoice profesional, kirim lewat WhatsApp dengan tautan yang bisa dibuka pelanggan, dan lihat siapa yang sudah membuka, membayar, atau terlambat. Tanpa aplikasi kasir.',
                'Poin' => ['Invoice, uang muka, dan retainer berulang', 'Tautan invoice & kirim WhatsApp', 'PPh 23 dipotong pelanggan tercatat'],
                'JudulFitur' => 'Untuk konsultan, agensi, software house, dan event organizer',
                'Item' => [
                    ['FileText', 'Invoice yang rapi', 'Nomor otomatis, PPN dihitung dari tarif yang berlaku, terbilang rupiah, dan PDF siap cetak atau kirim.'],
                    ['MessageCircle', 'Kirim dan lacak', 'Kirim invoice lewat WhatsApp dan lihat kapan pelanggan membukanya, tanpa mengejar lewat chat satu per satu.'],
                    ['RefreshCw', 'Retainer berulang', 'Tagihan bulanan terbit sendiri pada tanggalnya, dengan perihal yang mengikuti bulan berjalan.'],
                    ['Wallet', 'Uang muka (DP)', 'Minta DP sebelum pekerjaan dimulai, lalu potong otomatis dari invoice akhir tanpa PPN terhitung dua kali.'],
                    ['Percent', 'PPh dipotong pelanggan', 'Catat PPh 23 yang dipotong beserta nomor bukti potongnya saat pembayaran diterima.'],
                    ['ChartLine', 'Piutang dan arus kas', 'Umur piutang, invoice lewat tempo, dan pengingat otomatis yang sopan sebelum dan sesudah jatuh tempo.'],
                ],
                'Faq' => [['Apakah perlu aplikasi kasir atau printer struk?', 'Tidak. Sektor ini dibuat untuk menagih lewat invoice. Menu kasir tidak ditampilkan, dan Anda bisa menambahnya kemudian bila membuka toko.'], ['Apakah pelanggan perlu punya akun?', 'Tidak. Pelanggan membuka invoice dari tautan, tanpa mendaftar. Tautannya rahasia dan tidak bisa ditebak dari nomor invoice.']],
                'Seo' => 'Aplikasi invoice dan penagihan untuk konsultan, agensi, dan jasa profesional: invoice PPN, uang muka, retainer berulang, kirim WhatsApp, PPh 23, dan pengingat piutang.',
                'Cta' => 'Daftar gratis, pilih sektor "Jasa profesional & agensi" di panduan awal, lalu pelanggan, pajak, dan invoice pertama disiapkan langkah demi langkah.',
            ],
            [
                'Kode' => 'BIZ-KTR', 'Slug' => 'kontraktor-proyek', 'Nama' => 'Kontraktor & proyek', 'JudulSeo' => 'Aplikasi Invoice Proyek untuk Kontraktor dan Arsitek',
                'Ringkas' => 'Invoice bertermin, uang muka, PO material', 'Ikon' => 'Building', 'Spesimen' => 'Jurnal',
                'Judul' => 'Termin proyek tertagih tepat waktu, uang muka tidak terlupa',
                'Sub' => 'Tagih proyek dalam beberapa termin: uang muka dulu, lalu pelunasan dengan potongan DP. PPN dan PPh yang dipotong pemberi kerja tercatat rapi.',
                'Poin' => ['Uang muka dan invoice akhir', 'PPN dan PPh dipotong tercatat', 'Pesanan pembelian material'],
                'JudulFitur' => 'Untuk kontraktor, interior, arsitek, dan vendor proyek',
                'Item' => [
                    ['Wallet', 'Termin bertahap', 'Terbitkan invoice DP, lalu invoice akhir yang otomatis memotong DP yang sudah dibayar.'],
                    ['FileText', 'Invoice per proyek', 'Cantumkan nomor kontrak atau PO pemberi kerja, perihal proyek, dan cara pembayaran di tiap invoice.'],
                    ['Percent', 'PPN dan PPh jasa konstruksi', 'PPN dari tarif yang berlaku. Tarif PPh yang dipotong diisi sesuai bukti potong, bukan ditebak sistem.'],
                    ['ClipboardList', 'Pesanan pembelian material', 'Beli material dari pemasok dengan pesanan pembelian, penerimaan, dan hutang jatuh tempo.'],
                    ['HandCoins', 'Piutang proyek terpantau', 'Lihat termin mana yang belum dibayar, yang lewat tempo, dan kirim pengingat lewat WhatsApp.'],
                    ['Calculator', 'Pembukuan otomatis', 'Pendapatan, PPN, uang muka, dan piutang masuk ke jurnal tanpa input ganda.'],
                ],
                'Faq' => [['Apakah bisa menagih proyek dengan beberapa termin?', 'Bisa. Buat invoice uang muka untuk tiap termin awal, lalu invoice akhir yang memotongnya. Keduanya terkait dan tidak menghitung PPN dua kali.'], ['Bagaimana dengan retensi proyek?', 'Retensi belum punya fitur khusus, sementara dapat dicatat sebagai invoice terpisah yang diterbitkan setelah masa pemeliharaan.']],
                'Seo' => 'Aplikasi invoice proyek untuk kontraktor, interior, dan arsitek: uang muka dan termin, PPN, PPh dipotong pemberi kerja, pesanan pembelian material, piutang proyek.',
                'Cta' => 'Daftar gratis, pilih sektor "Kontraktor & proyek" di panduan awal, lalu pemberi kerja, pajak, dan invoice termin pertama disiapkan langkah demi langkah.',
            ],
            [
                'Kode' => 'BIZ-MFG', 'Slug' => 'manufaktur-produksi', 'Nama' => 'Manufaktur & produksi', 'JudulSeo' => 'Aplikasi Stok, Produksi, dan Invoice untuk Pabrik',
                'Ringkas' => 'Bahan baku, produksi, pesanan & invoice', 'Ikon' => 'Warehouse', 'Spesimen' => 'Jurnal',
                'Judul' => 'Dari bahan baku sampai invoice distributor dalam satu catatan',
                'Sub' => 'Catat bahan baku, produksi barang jadi dengan HPP yang benar, lalu jual ke distributor lewat pesanan, surat jalan, dan invoice. Tanpa kasir.',
                'Poin' => ['Bahan baku dan produksi dengan HPP', 'Pesanan, surat jalan, dan invoice', 'Piutang distributor terpantau'],
                'JudulFitur' => 'Untuk pabrik kecil-menengah, konveksi, maklon, dan makanan kemasan',
                'Item' => [
                    ['Boxes', 'Produksi memotong bahan', 'Order produksi mengurangi bahan baku sesuai resep dan menambah barang jadi dengan HPP dari bahan ditambah overhead.'],
                    ['Warehouse', 'Beberapa gudang', 'Bahan baku, barang setengah jadi, dan barang jadi di gudang berbeda, dengan transfer dan opname.'],
                    ['Truck', 'Pesanan, surat jalan, invoice', 'Pesanan distributor dikirim bertahap. Pendapatan dan HPP diakui saat surat jalan, lalu difakturkan.'],
                    ['Tag', 'Harga per level pelanggan', 'Harga distributor, agen, dan eceran mengikuti daftar harga tanpa menghitung manual.'],
                    ['ClipboardList', 'Pesanan pembelian bahan', 'Beli bahan baku dengan pesanan pembelian, penerimaan barang, dan hutang pemasok.'],
                    ['FileText', 'Invoice jasa maklon', 'Jasa seperti maklon atau desain ditagih langsung lewat invoice, tanpa melewati stok.'],
                ],
                'Faq' => [['Apakah perlu aplikasi kasir?', 'Tidak. Penjualan ke distributor dicatat dari back-office lewat pesanan dan surat jalan. Menu kasir tidak ditampilkan.'], ['Apakah resep produksi dan HPP dihitung otomatis?', 'Ya. HPP barang jadi dihitung dari biaya bahan yang dipakai ditambah overhead yang Anda isi di order produksi.']],
                'Seo' => 'Aplikasi stok, produksi, dan invoice untuk pabrik, konveksi, dan maklon: bahan baku, order produksi dengan HPP, pesanan dan surat jalan, invoice distributor, piutang.',
                'Cta' => 'Daftar gratis, pilih sektor "Manufaktur & produksi" di panduan awal, lalu bahan baku, gudang, dan pelanggan distributor disiapkan langkah demi langkah.',
            ],
        ];
    }
}
