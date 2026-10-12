<?php

declare(strict_types=1);

namespace App\Domain\Situs\Layanan;

/**
 * Isi awal situs pemasaran (D-21), dipakai sampai konsol menerbitkan halamannya sendiri dan sebagai draf awal saat
 * halaman dibuat di konsol. Teks menerangkan fitur yang benar-benar ada (tanpa testimoni atau angka karangan).
 *
 * D-39 (bersih & meyakinkan): hero terang dengan tangkapan layar asli aplikasi (`Spesimen` Kasir/Pemilik) dan baris
 * centang alasan untuk percaya; keunggulan memakai grid tiga kolom tanpa kartu yatim.
 *
 * Gaya tulisan (D-25): judul menyebut hal yang bisa dibantah, bukan klaim kosong seperti "lengkap" atau "tanpa ribet".
 * Isi kartu adalah satu manfaat konkret, bukan daftar fitur berkoma. Susunan blok dibuat berganti bentuk
 * (`TataLetak` Grid/Daftar/Sorot, `GambarTeks`, `Latar` hero) supaya tidak ada dua grid sejenis berurutan.
 */
final class KontenSitusBawaan
{
    /**
     * Halaman utama situs ditambah satu halaman per sektor usaha (`SektorSitus`, D-91).
     *
     * @return array<string, array{Judul: string, JudulSeo: string, DeskripsiSeo: string, Bagian: list<array<string, mixed>>}>
     */
    public static function AmbilHalaman(): array
    {
        return self::AmbilHalamanUtama() + SektorSitus::AmbilHalaman();
    }

    /**
     * @return array<string, array{Judul: string, JudulSeo: string, DeskripsiSeo: string, Bagian: list<array<string, mixed>>}>
     */
    private static function AmbilHalamanUtama(): array
    {
        $ctaDaftar = [
            'Jenis' => 'Cta',
            'Judul' => 'Coba dulu dengan data toko Anda sendiri',
            'Teks' => 'Daftar gratis, isi produk dari Excel atau tempel dari WhatsApp, lalu mulai jual hari itu juga.',
            'TombolUtama' => ['Label' => 'Coba gratis sekarang', 'Tautan' => '@daftar'],
            'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
        ];

        return [
            'beranda' => [
                'Judul' => 'Beranda',
                'JudulSeo' => 'Payoung | Aplikasi Kasir yang Tetap Jalan Walau Offline',
                'DeskripsiSeo' => 'Aplikasi kasir (POS) untuk kafe, resto, toko, salon, dan laundry: tetap jalan saat offline, stok & HPP otomatis, pajak PPN/PBJT, promo, loyalti, dan laporan keuangan.',
                'Bagian' => [
                    [
                        'Jenis' => 'HeroGeser',
                        'Sorotan' => [
                            [
                                'Label' => 'Kasir offline',
                                'Judul' => 'Kasir tetap mencatat walau internet mati',
                                'Teks' => 'Penjualan, stok, dan struk tetap jalan tanpa sinyal, lalu terkirim sendiri begitu internet kembali.',
                                'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                                'TombolKedua' => ['Label' => 'Jadwalkan demo', 'Tautan' => '/kontak'],
                                'Spesimen' => 'Kasir',
                            ],
                            [
                                'Label' => 'Pembayaran',
                                'Judul' => 'Satu layar bayar untuk semua cara bayar',
                                'Teks' => 'Tunai, QRIS, kartu, transfer, e-wallet, tempo, atau deposit pelanggan, boleh digabung dan dibagi per orang.',
                                'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                                'TombolKedua' => ['Label' => 'Lihat fitur', 'Tautan' => '/fitur'],
                                'Spesimen' => 'KasirBayar',
                            ],
                            [
                                'Label' => 'Shift & laporan',
                                'Judul' => 'Laporan shift siap begitu kasir menutup laci',
                                'Teks' => 'Omzet, metode bayar, produk terlaris, dan penjualan per jam tampil saat itu juga; selisih kas langsung terlihat.',
                                'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                                'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                                'Spesimen' => 'KasirShift',
                            ],
                            [
                                'Label' => 'Aplikasi Pemilik',
                                'Judul' => 'Pantau semua outlet dari HP, tanpa minta laporan',
                                'Teks' => 'Omzet hari ini, laporan, kehadiran karyawan, dan persetujuan diskon atau kas keluar dari jarak jauh.',
                                'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                                'TombolKedua' => ['Label' => 'Unduh aplikasi', 'Tautan' => '@unduh-android'],
                                'Spesimen' => 'PemilikDuo',
                            ],
                            [
                                'Label' => 'Riwayat & retur',
                                'Judul' => 'Salah input? Retur dan tukar barang dalam sekali sentuh',
                                'Teks' => 'Dokumen yang sudah diposting tidak diubah diam-diam. Koreksi lewat retur atau void, stok dan jurnal ikut kembali.',
                                'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                                'TombolKedua' => ['Label' => 'Jadwalkan demo', 'Tautan' => '/kontak'],
                                'Spesimen' => 'KasirRiwayat',
                            ],
                        ],
                        'Poin' => [
                            ['Teks' => 'Tetap jalan tanpa internet'],
                            ['Teks' => 'Android, iPad & Windows'],
                            ['Teks' => 'Printer Bluetooth, USB & Sunmi'],
                        ],
                        'Catatan' => 'Gratis selamanya untuk usaha mikro. Tanpa kartu kredit.',
                    ],
                    [
                        'Jenis' => 'TabUsaha',
                        'Label' => 'Untuk jenis usaha Anda',
                        'Judul' => 'Satu aplikasi, enam cara pakai',
                        'Subjudul' => 'Pilih jenis usaha saat mendaftar; produk, pajak, dan menu ikut disiapkan.',
                        'Item' => [
                            [
                                'Label' => 'Kafe & Resto',
                                'Ikon' => 'Coffee',
                                'Judul' => 'Dari meja ke dapur tanpa kertas tercecer',
                                'Teks' => 'Pelayan mencatat di HP, dapur langsung melihatnya, kasir menagih dengan PBJT dan biaya layanan yang sudah benar.',
                                'Poin' => [
                                    ['Teks' => 'Denah meja berstatus, pisah tagihan, dan gabung meja'],
                                    ['Teks' => 'Tiket dapur per stasiun atau layar dapur (KDS)'],
                                    ['Teks' => 'Tamu memesan dari QR di meja atau kios pesan sendiri'],
                                    ['Teks' => 'Resep memotong stok bahan, termasuk bahan terbuang'],
                                ],
                                'Spesimen' => 'Kasir',
                                'Tombol' => ['Label' => 'Lihat solusi kafe & resto', 'Tautan' => '/solusi/kafe-resto'],
                            ],
                            [
                                'Label' => 'Toko & Retail',
                                'Ikon' => 'Store',
                                'Judul' => 'Selisih stok berhenti jadi tebakan',
                                'Teks' => 'Pindai, jual, terima barang. Kartu stok menunjukkan setiap pergerakan beserta siapa yang mencatatnya.',
                                'Poin' => [
                                    ['Teks' => 'Pemindai barcode tanpa klik, varian, dan beberapa satuan jual'],
                                    ['Teks' => 'Harga member dan grosir mengikuti tier pelanggan'],
                                    ['Teks' => 'Stok di beberapa gudang, opname, dan transfer'],
                                    ['Teks' => 'Batch kedaluwarsa dan nomor seri/IMEI untuk barang bergaransi'],
                                ],
                                'Spesimen' => 'KasirBayar',
                                'Tombol' => ['Label' => 'Lihat solusi toko & retail', 'Tautan' => '/solusi/toko-retail'],
                            ],
                            [
                                'Label' => 'Salon & Jasa',
                                'Ikon' => 'Scissors',
                                'Judul' => 'Komisi dan saldo pelanggan berhenti dihitung tangan',
                                'Teks' => 'Catat siapa yang melayani, terima deposit dan uang muka, lalu komisi serta rekap gaji tersusun sendiri.',
                                'Poin' => [
                                    ['Teks' => 'Komisi menempel ke staf yang melayani'],
                                    ['Teks' => 'Reservasi per staf, termasuk pemesanan online'],
                                    ['Teks' => 'Deposit pelanggan dan paket sesi'],
                                    ['Teks' => 'Jadwal kerja dan absensi karyawan'],
                                ],
                                'Spesimen' => 'PemilikKaryawan',
                                'Tombol' => ['Label' => 'Lihat solusi jasa', 'Tautan' => '/solusi/jasa'],
                            ],
                            [
                                'Label' => 'Laundry',
                                'Ikon' => 'WashingMachine',
                                'Judul' => 'Pelanggan melacak cucian sendiri',
                                'Teks' => 'Nota ber-QR membuka status cucian, dan pelanggan diberi tahu lewat WhatsApp saat cucian siap diambil.',
                                'Poin' => [
                                    ['Teks' => 'Tiket cucian dengan status proses'],
                                    ['Teks' => 'Uang muka dan pelunasan tercatat'],
                                    ['Teks' => 'Nota dan struk ber-QR lacak'],
                                ],
                                'Spesimen' => 'KasirRiwayat',
                                'Tombol' => ['Label' => 'Lihat solusi jasa & laundry', 'Tautan' => '/solusi/jasa'],
                            ],
                            [
                                'Label' => 'Apotek',
                                'Ikon' => 'Pill',
                                'Judul' => 'Obat keras dijual dengan resep dan apoteker',
                                'Teks' => 'Golongan obat tampil di kasir; penyerahan obat keras meminta data resep dan PIN apoteker, tercatat rapi.',
                                'Poin' => [
                                    ['Teks' => 'Resep tersimpan terenkripsi di setiap penjualan'],
                                    ['Teks' => 'Obat racikan dengan komponen yang dipotong per batch'],
                                    ['Teks' => 'Batch & kedaluwarsa dijual lebih dulu yang paling dekat'],
                                    ['Teks' => 'Data siap untuk pelaporan SIPNAP'],
                                ],
                                'Spesimen' => 'KasirShift',
                                'Tombol' => ['Label' => 'Tanya soal apotek lewat WhatsApp', 'Tautan' => '@whatsapp'],
                            ],
                            [
                                'Label' => 'Bengkel',
                                'Ikon' => 'Car',
                                'Judul' => 'Dari perintah kerja sampai tagihan satu alur',
                                'Teks' => 'Catat kendaraan, jasa, dan sparepart dalam perintah kerja; pelanggan menyetujui lewat tautan, lalu ditagih di kasir.',
                                'Poin' => [
                                    ['Teks' => 'Data kendaraan dan riwayat servis per pelanggan'],
                                    ['Teks' => 'Persetujuan pelanggan lewat tautan sebelum dikerjakan'],
                                    ['Teks' => 'Sparepart ber-batch atau bernomor seri ikut ditagih'],
                                    ['Teks' => 'Pengingat servis berikutnya ke pelanggan'],
                                ],
                                'Spesimen' => 'PemilikLaporan',
                                'Tombol' => ['Label' => 'Tanya soal bengkel lewat WhatsApp', 'Tautan' => '@whatsapp'],
                            ],
                        ],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Sorot',
                        'Judul' => 'Enam pekerjaan yang berhenti Anda kerjakan manual',
                        'Item' => [
                            ['Ikon' => 'WifiOff', 'Judul' => 'Kasir jalan tanpa internet', 'Teks' => 'Transaksi, struk, dan laci kas tetap berjalan saat sinyal hilang. Data tersimpan di perangkat lalu terkirim sendiri begitu online, jadi tidak ada penjualan yang hilang dan tidak ada input ulang di akhir hari.'],
                            ['Ikon' => 'Boxes', 'Judul' => 'Stok berkurang sendiri', 'Teks' => 'Setiap penjualan memotong stok. Setiap penerimaan barang memperbarui HPP rata-rata, jadi laba per barang tidak perlu ditaksir.'],
                            ['Ikon' => 'Percent', 'Judul' => 'Pajak ikut tarif yang berlaku', 'Teks' => 'PPN dan PBJT dihitung dari tarif bertanggal, termasuk kalau harga jual Anda sudah termasuk pajak.'],
                            ['Ikon' => 'Tag', 'Judul' => 'Promo berhenti sendiri', 'Teks' => 'Diskon, beli X gratis Y, dan happy hour mengikuti jadwalnya. Kasir tidak menghitung dan tidak lupa mematikan.'],
                            ['Ikon' => 'Users', 'Judul' => 'Riwayat menempel di pelanggan', 'Teks' => 'Poin, harga tier, saldo deposit, dan piutang tersimpan di nama pelanggan, bukan di buku terpisah.'],
                            ['Ikon' => 'Landmark', 'Judul' => 'Jurnal terisi saat transaksi', 'Teks' => 'Laba rugi, neraca, dan arus kas siap kapan pun karena setiap dokumen menulis jurnalnya sendiri.'],
                        ],
                    ],
                    [
                        'Jenis' => 'Langkah',
                        'Label' => 'Mulai',
                        'Judul' => 'Dari daftar sampai transaksi pertama dalam empat langkah',
                        'Subjudul' => 'Panduan awal menyiapkan produk, pajak, dan perangkat. Kalau daftar produk Anda sudah ada di Excel atau WhatsApp, prosesnya hitungan menit.',
                        'Item' => [
                            ['Ikon' => 'Store', 'Judul' => 'Daftar & pilih jenis usaha', 'Teks' => 'Cukup nama usaha dan email. Tidak perlu kartu kredit.'],
                            ['Ikon' => 'Sparkles', 'Judul' => 'Siapkan otomatis', 'Teks' => 'Kategori, pajak, metode bayar, dan menu contoh mengikuti jenis usaha yang dipilih.'],
                            ['Ikon' => 'ClipboardList', 'Judul' => 'Tempel daftar produk', 'Teks' => 'Salin dari Excel atau WhatsApp, periksa, lalu simpan. Harga dan stok awal ikut tercatat.'],
                            ['Ikon' => 'Smartphone', 'Judul' => 'Pasang kasir & mulai jual', 'Teks' => 'Pindai QR aktivasi di aplikasi Kasir, buat PIN kasir, buka shift, dan jual.'],
                        ],
                        'Tombol' => ['Label' => 'Coba gratis sekarang', 'Tautan' => '@daftar'],
                    ],
                    [
                        'Jenis' => 'GambarTeks',
                        'Label' => 'Offline-first',
                        'Judul' => 'Sinyal hilang, penjualan tetap tercatat',
                        'Teks' => 'Transaksi disimpan di perangkat dan dikirim otomatis begitu internet kembali. Nomor struk memakai kode perangkat, jadi tidak bentrok antar kasir, dan kiriman ulang tidak mencatat satu penjualan dua kali.',
                        'Poin' => [
                            ['Teks' => 'Basis data di perangkat kasir terenkripsi'],
                            ['Teks' => 'Status sinkron terlihat di bilah bawah, lengkap dengan yang tertunda'],
                            ['Teks' => 'Struk tercetak atau terkirim digital walau offline'],
                            ['Teks' => 'Perangkat yang hilang bisa dicabut aksesnya dari back-office'],
                        ],
                        'PosisiGambar' => 'Kiri',
                        'Spesimen' => 'Struk',
                    ],
                    [
                        'Jenis' => 'Integrasi',
                        'Label' => 'Terhubung',
                        'Judul' => 'Terhubung ke cara bayar, pesan, dan perangkat yang sudah Anda pakai',
                        'Subjudul' => 'Hanya yang benar-benar tersedia di aplikasi; perangkat yang sudah kami uji ada di halaman Perangkat kompatibel.',
                        'Kelompok' => [
                            ['Ikon' => 'CreditCard', 'Judul' => 'Pembayaran', 'Teks' => 'QRIS lewat DOKU, dan metode lain yang Anda atur sendiri.', 'Item' => [['Nama' => 'QRIS (DOKU)'], ['Nama' => 'Tunai'], ['Nama' => 'Kartu EDC'], ['Nama' => 'Transfer bank'], ['Nama' => 'E-wallet'], ['Nama' => 'Deposit pelanggan'], ['Nama' => 'Tempo']]],
                            ['Ikon' => 'MessageCircle', 'Judul' => 'WhatsApp & email', 'Teks' => 'Struk digital, pengingat piutang, kampanye pelanggan, dan notifikasi status pesanan lewat WhatsApp.', 'Item' => [['Nama' => 'Struk digital'], ['Nama' => 'Pengingat piutang'], ['Nama' => 'Kampanye pelanggan'], ['Nama' => 'Status pesanan']]],
                            ['Ikon' => 'Printer', 'Judul' => 'Printer & perangkat', 'Teks' => 'Cetak struk, tiket dapur, dan buka laci kas dari perangkat yang ada.', 'Item' => [['Nama' => 'Bluetooth'], ['Nama' => 'LAN'], ['Nama' => 'USB'], ['Nama' => 'Sunmi / iMin'], ['Nama' => 'AirPrint & PDF'], ['Nama' => 'Laci kas'], ['Nama' => 'Layar pelanggan'], ['Nama' => 'Pemindai barcode']]],
                            ['Ikon' => 'Truck', 'Judul' => 'Pesan-antar & toko online', 'Teks' => 'Penjualan lewat kanal ojol dicatat dengan harga per kanal; toko online dan kios memakai stok yang sama.', 'Item' => [['Nama' => 'GoFood'], ['Nama' => 'GrabFood'], ['Nama' => 'ShopeeFood'], ['Nama' => 'Toko online'], ['Nama' => 'Self-order QR'], ['Nama' => 'Kios']]],
                        ],
                        'Catatan' => 'Pesanan dari GoFood, GrabFood, dan ShopeeFood dimasukkan manual ke kasir; sambungan otomatis belum tersedia.',
                    ],
                    [
                        'Jenis' => 'GambarTeks',
                        'Label' => 'Satu sistem',
                        'Judul' => 'Kasir, dapur, dan pemilik melihat angka yang sama',
                        'Teks' => 'Aplikasi kasir berjalan di Android, iPad, dan Windows. Pemilik memantau penjualan dari aplikasi Pemilik atau back-office di browser, tanpa perlu meminta laporan ke kasir.',
                        'Poin' => [
                            ['Teks' => 'Printer struk Bluetooth, LAN, USB, dan printer bawaan Sunmi/iMin'],
                            ['Teks' => 'Layar dapur (KDS) dan tiket terpisah per stasiun'],
                            ['Teks' => 'Buka & tutup shift dengan hitung kas; selisih langsung terlihat'],
                            ['Teks' => 'Hak akses per peran; diskon besar minta PIN penyetuju'],
                        ],
                        'PosisiGambar' => 'Kanan',
                        'Spesimen' => 'PemilikDuo',
                        'Tombol' => ['Label' => 'Lihat semua fitur', 'Tautan' => '/fitur'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Daftar',
                        'Label' => 'Keamanan & kepatuhan',
                        'Judul' => 'Angka Anda bisa diperiksa, bukan sekadar dipercaya',
                        'Item' => [
                            ['Ikon' => 'Lock', 'Judul' => 'Dokumen terposting tidak diubah', 'Teks' => 'Koreksi selalu lewat dokumen pembalik (void, retur, penyesuaian), jadi riwayatnya utuh dan bisa diaudit.'],
                            ['Ikon' => 'Percent', 'Judul' => 'Tarif pajak bertanggal berlaku', 'Teks' => 'PPN dan PBJT diambil dari tarif yang berlaku pada tanggal transaksi, bukan angka yang diketik manual.'],
                            ['Ikon' => 'ShieldCheck', 'Judul' => 'Data sensitif terenkripsi', 'Teks' => 'Basis data di perangkat kasir, NPWP/NIK pelanggan, dan data resep apotek disimpan terenkripsi.'],
                            ['Ikon' => 'BadgeCheck', 'Judul' => 'Akses per peran, persetujuan dua pihak', 'Teks' => 'Diskon, void, dan kas keluar di atas batas meminta PIN penyetuju atau persetujuan Pemilik dari HP.'],
                            ['Ikon' => 'Smartphone', 'Judul' => 'Masuk dengan Google', 'Teks' => 'Masuk dengan akun Google atau kode verifikasi dua langkah, dan cabut akses perangkat kapan saja.'],
                            ['Ikon' => 'FileText', 'Judul' => 'Faktur pajak untuk Coretax', 'Teks' => 'Faktur grosir dapat diekspor ke XML Faktur Pajak Keluaran untuk diimpor ke Coretax, lengkap dengan rekap retur.'],
                        ],
                    ],
                    [
                        'Jenis' => 'Statistik',
                        'Judul' => 'Yang bisa kami pastikan hari ini',
                        'Item' => [
                            ['Angka' => '3', 'Keterangan' => 'platform: Android, iOS/iPadOS, dan Windows'],
                            ['Angka' => '5', 'Keterangan' => 'cara menyambung printer: Bluetooth, LAN, USB, Sunmi, sistem'],
                            ['Angka' => '0', 'Keterangan' => 'potongan per transaksi dari Payoung; Anda hanya membayar langganan'],
                        ],
                    ],
                    [
                        'Jenis' => 'Harga',
                        'Judul' => 'Mulai gratis, naik saat outlet bertambah',
                        'Subjudul' => 'Harga per bulan untuk satu usaha. Naik paket kapan saja dari back-office; turun paket lewat tim kami.',
                        'TampilkanTahunan' => true,
                        'PaketDisorot' => 'PRO',
                        'TeksTombol' => 'Mulai',
                    ],
                    [
                        'Jenis' => 'Faq',
                        'Judul' => 'Pertanyaan sebelum daftar',
                        'Item' => [
                            ['Pertanyaan' => 'Apakah Payoung bisa dipakai tanpa internet?', 'Jawaban' => 'Bisa. Aplikasi kasir menyimpan transaksi di perangkat dan mengirimnya otomatis saat internet kembali. Nomor struk memakai kode perangkat supaya tidak bentrok antar kasir.'],
                            ['Pertanyaan' => 'Perangkat apa yang didukung?', 'Jawaban' => 'Android, iPhone/iPad, dan Windows. Printer dan perangkat yang sudah kami uji ada di halaman Perangkat kompatibel.'],
                            ['Pertanyaan' => 'Apakah cocok untuk usaha kecil yang baru mulai?', 'Jawaban' => 'Cocok. Paket Gratis dibuat untuk usaha mikro, dan panduan awal menyiapkan produk, pajak, serta perangkat langkah demi langkah. Kalau bingung, tim kami membantu lewat WhatsApp.'],
                            ['Pertanyaan' => 'Apakah data usaha saya aman?', 'Jawaban' => 'Akses ke data dibatasi per peran dan setiap perubahan penting tercatat. Pemilik usaha bisa mengunduh salinan data lengkap usahanya kapan saja dari menu Pengaturan. Data sensitif seperti basis data kasir dan NPWP/NIK pelanggan disimpan terenkripsi. Kami bertindak sebagai pemroses data sesuai UU Perlindungan Data Pribadi.'],
                            ['Pertanyaan' => 'Bisakah saya memindahkan data produk dari Excel?', 'Jawaban' => 'Bisa. Tempel daftar produk dari Excel atau WhatsApp di panduan awal, periksa hasilnya, lalu simpan. Impor pelanggan juga tersedia dari Excel/CSV.'],
                            ['Pertanyaan' => 'Bagaimana cara berlangganan?', 'Jawaban' => 'Daftar gratis, lalu pilih paket dari menu Langganan di back-office. Tagihan dibayar online lewat halaman pembayaran DOKU, dan paket aktif setelah pembayaran diterima.'],
                            ['Pertanyaan' => 'Apakah saya bisa minta demo dulu?', 'Jawaban' => 'Bisa. Tinggalkan nomor WhatsApp di halaman Kontak, dan tim kami menghubungi dalam 1 hari kerja.'],
                        ],
                    ],
                    $ctaDaftar,
                ],
            ],
            'fitur' => [
                'Judul' => 'Fitur',
                'JudulSeo' => 'Fitur Aplikasi Kasir Payoung',
                'DeskripsiSeo' => 'Fitur Payoung: kasir offline, stok & HPP, pajak, promo, loyalti, deposit, piutang, karyawan & komisi, dapur, self-order QR, dan laporan keuangan.',
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Spesimen' => 'Kasir',
                        'Judul' => 'Mulai dari kasir, hidupkan sisanya saat perlu',
                        'Subjudul' => 'Semua fitur sudah ada di aplikasi yang sama. Anda menyalakannya ketika usaha memang sudah membutuhkannya, bukan sejak hari pertama.',
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Lihat harga', 'Tautan' => '/harga'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Sorot',
                        'Label' => 'Penjualan',
                        'Judul' => 'Kasir yang tidak menahan antrean',
                        'Item' => [
                            ['Ikon' => 'Receipt', 'Judul' => 'Satu layar untuk menjual', 'Teks' => 'Cari produk atau pindai barcode, pilih varian dan tambahan, lalu bayar. Diskon di luar batas kasir minta PIN penyetuju, jadi tidak perlu menelepon pemilik.'],
                            ['Ikon' => 'CreditCard', 'Judul' => 'Bayar dengan apa pun', 'Teks' => 'Tunai, QRIS, EDC, transfer, e-wallet, tempo, deposit — boleh digabung dalam satu transaksi.'],
                            ['Ikon' => 'RefreshCw', 'Judul' => 'Void & retur tetap rapi', 'Teks' => 'Dokumen lama tidak diubah. Koreksi memakai dokumen pembalik, stok dan jurnal ikut kembali sendiri.'],
                            ['Ikon' => 'MessageCircle', 'Judul' => 'Struk tanpa kertas', 'Teks' => 'Kirim struk lewat WhatsApp atau email, atau cetak QR struk di kertas.'],
                            ['Ikon' => 'ChefHat', 'Judul' => 'Pesanan sampai ke dapur', 'Teks' => 'Tiket tercetak per stasiun atau muncul di layar dapur, tanpa kertas pesanan tercecer.'],
                            ['Ikon' => 'QrCode', 'Judul' => 'Tamu pesan dari HP', 'Teks' => 'Self-order lewat QR di meja; pesanan masuk ke kasir dengan perkiraan total.'],
                        ],
                    ],
                    [
                        'Jenis' => 'GambarTeks',
                        'Label' => 'Stok & pembelian',
                        'Judul' => 'Stok di sistem cocok dengan barang di rak',
                        'Teks' => 'Pembelian, penerimaan barang, transfer antar gudang, dan opname memakai dokumen yang sama dengan yang dicatat kasir. Tidak ada dua buku stok yang harus dicocokkan.',
                        'Poin' => [
                            ['Teks' => 'HPP rata-rata bergerak dihitung dari setiap penerimaan barang'],
                            ['Teks' => 'Pesanan pembelian, faktur pemasok, hutang, dan retur pemasok'],
                            ['Teks' => 'Draf pesanan pembelian dibuat sendiri saat stok di bawah minimum'],
                            ['Teks' => 'Bahan terbuang dicatat di kasir, ikut ke ringkasan food cost'],
                        ],
                        'PosisiGambar' => 'Kiri',
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Daftar',
                        'Label' => 'Pelanggan & karyawan',
                        'Judul' => 'Yang bisa berhenti Anda catat di buku tulis',
                        'Item' => [
                            ['Ikon' => 'Gift', 'Judul' => 'Loyalti & promo', 'Teks' => 'Tier member, poin, voucher, kode promo, promo ulang tahun, dan promo per metode bayar.'],
                            ['Ikon' => 'Wallet', 'Judul' => 'Deposit & paket sesi', 'Teks' => 'Pelanggan mengisi saldo atau membeli paket sesi, lalu memakainya di kunjungan berikutnya.'],
                            ['Ikon' => 'HandCoins', 'Judul' => 'Tempo & piutang', 'Teks' => 'Limit kredit per pelanggan, umur piutang, dan pengingat otomatis lewat WhatsApp.'],
                            ['Ikon' => 'Clock', 'Judul' => 'Jadwal & absensi', 'Teks' => 'Absen dengan PIN dan swafoto di perangkat kasir, langsung terhubung ke jadwal kerja.'],
                            ['Ikon' => 'Users', 'Judul' => 'Komisi per staf', 'Teks' => 'Aturan komisi per produk atau kategori, boleh dibagi ke beberapa staf dalam satu transaksi.'],
                            ['Ikon' => 'ClipboardList', 'Judul' => 'Kasbon & rekap gaji', 'Teks' => 'Kasbon dipotong sendiri di rekap gaji bulanan, tanpa hitung ulang.'],
                        ],
                    ],
                    [
                        'Jenis' => 'GambarTeks',
                        'Label' => 'Keuangan',
                        'Spesimen' => 'Jurnal',
                        'Judul' => 'Pembukuan yang tidak menunggu akhir bulan',
                        'Teks' => 'Setiap penjualan, pembelian, dan pembayaran menulis jurnal seimbang di transaksi yang sama. Tutup harian dan tutup bulan hanya mengunci periode, bukan mulai menghitung.',
                        'Poin' => [
                            ['Teks' => 'Laba rugi, neraca, dan arus kas langsung dari jurnal'],
                            ['Teks' => 'Laporan penjualan, pajak, stok, promo, dan shift, bisa diekspor'],
                            ['Teks' => 'Tab anti-fraud: void tunai cepat, buka laci, dan kas kurang diberi skor risiko'],
                            ['Teks' => 'Aplikasi Pemilik untuk memantau semua outlet dari HP'],
                        ],
                        'PosisiGambar' => 'Kanan',
                        'Tombol' => ['Label' => 'Lihat harga paket', 'Tautan' => '/harga'],
                    ],
                    $ctaDaftar,
                ],
            ],
            'harga' => [
                'Judul' => 'Harga',
                'JudulSeo' => 'Harga Paket Aplikasi Kasir Payoung',
                'DeskripsiSeo' => 'Paket Payoung mulai dari gratis. Bandingkan batas outlet, perangkat, pengguna, dan fitur tiap paket.',
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Judul' => 'Harga yang tidak berubah setelah Anda daftar',
                        'Subjudul' => 'Tidak ada biaya pemasangan dan tidak ada potongan per transaksi. Yang Anda bayar hanya langganan bulanan atau tahunan.',
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                    ],
                    [
                        'Jenis' => 'Harga',
                        'Judul' => 'Pilih paket sesuai jumlah outlet',
                        'Subjudul' => 'Bayar tahunan lebih hemat. Naik paket kapan saja; turun paket lewat tim kami.',
                        'TampilkanTahunan' => true,
                        'PaketDisorot' => 'PRO',
                        'TeksTombol' => 'Mulai',
                        'CatatanKaki' => 'Harga belum termasuk add-on (outlet atau perangkat tambahan, kuota WhatsApp, self-order QR).',
                    ],
                    [
                        'Jenis' => 'Faq',
                        'Judul' => 'Tentang langganan',
                        'Item' => [
                            ['Pertanyaan' => 'Apakah ada masa uji coba?', 'Jawaban' => 'Ada. Paket berbayar bisa dicoba gratis selama masa trial. Bila tidak diperpanjang, akun turun ke paket Gratis dan data Anda tetap ada.'],
                            ['Pertanyaan' => 'Bagaimana cara membayar?', 'Jawaban' => 'Buka menu Langganan di back-office dan bayar tagihan secara online lewat halaman pembayaran DOKU. Paket aktif setelah pembayaran diterima.'],
                            ['Pertanyaan' => 'Bisakah pindah paket?', 'Jawaban' => 'Naik paket bisa kapan saja dari menu Langganan, selisih harganya dihitung untuk sisa periode. Untuk turun paket, hubungi tim kami lewat menu Bantuan.'],
                            ['Pertanyaan' => 'Kalau fitur yang saya butuh ada di paket lebih tinggi?', 'Jawaban' => 'Menunya tetap terlihat. Saat diklik, muncul pilihan naik paket atau membeli add-on untuk fitur itu saja.'],
                        ],
                    ],
                    $ctaDaftar,
                ],
            ],
            'solusi/kafe-resto' => [
                'Judul' => 'Kafe & Resto',
                'JudulSeo' => 'Aplikasi Kasir Kafe & Resto | Payoung',
                'DeskripsiSeo' => 'Aplikasi kasir kafe dan resto: denah meja, pesanan ke dapur, self-order QR, pisah tagihan, pajak PBJT dan biaya layanan.',
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Spesimen' => 'Kasir',
                        'Label' => 'Kafe & Resto',
                        'Judul' => 'Dari meja ke dapur tanpa kertas tercecer',
                        'Subjudul' => 'Pelayan mencatat di HP, dapur langsung melihatnya, kasir menagih dengan PBJT dan biaya layanan yang sudah benar.',
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Grid',
                        'Kolom' => '3',
                        'Judul' => 'Yang berubah saat jam makan siang penuh',
                        'Item' => [
                            ['Ikon' => 'UtensilsCrossed', 'Judul' => 'Meja punya status', 'Teks' => 'Terlihat mana yang kosong, mana yang sudah pesan tapi belum bayar. Meja bisa dipindah dan digabung.'],
                            ['Ikon' => 'ChefHat', 'Judul' => 'Dapur tidak menebak', 'Teks' => 'Tiket keluar per stasiun di printer atau layar dapur, lengkap dengan catatan pesanan.'],
                            ['Ikon' => 'QrCode', 'Judul' => 'Tamu pesan sendiri', 'Teks' => 'QR di meja membuka menu di HP tamu, dengan perkiraan total sebelum memesan.'],
                            ['Ikon' => 'Smartphone', 'Judul' => 'Pelayan tanpa shift', 'Teks' => 'Mode Pelayan mencatat pesanan dari HP tanpa harus membuka shift kasir.'],
                            ['Ikon' => 'Percent', 'Judul' => 'PBJT & biaya layanan', 'Teks' => 'Dihitung dari tarif yang berlaku di kota outlet Anda, bukan angka yang diketik manual.'],
                            ['Ikon' => 'Boxes', 'Judul' => 'Bahan ikut berkurang', 'Teks' => 'Resep memotong stok bahan sesuai komposisi menu, termasuk bahan yang terbuang.'],
                        ],
                    ],
                    $ctaDaftar,
                ],
            ],
            'solusi/toko-retail' => [
                'Judul' => 'Toko & Retail',
                'JudulSeo' => 'Aplikasi Kasir Toko & Retail | Payoung',
                'DeskripsiSeo' => 'Aplikasi kasir toko dan minimarket: pemindai barcode, varian, harga grosir & member, stok multi-gudang, pembelian dan hutang pemasok.',
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Spesimen' => 'Struk',
                        'Label' => 'Toko & Retail',
                        'Judul' => 'Selisih stok berhenti jadi tebakan',
                        'Subjudul' => 'Pindai, jual, terima barang. Kartu stok menunjukkan setiap pergerakan beserta siapa yang mencatatnya.',
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Daftar',
                        'Judul' => 'Yang dipakai setiap hari di toko',
                        'Item' => [
                            ['Ikon' => 'ShoppingBasket', 'Judul' => 'Pindai tanpa klik', 'Teks' => 'Pemindai langsung menambah baris. Satu produk boleh punya beberapa satuan jual dan barcode.'],
                            ['Ikon' => 'Tag', 'Judul' => 'Harga bertingkat', 'Teks' => 'Harga member, reseller, dan grosir mengikuti tier pelanggan, tanpa kasir menghitung.'],
                            ['Ikon' => 'Warehouse', 'Judul' => 'Beberapa gudang', 'Teks' => 'Transfer antar gudang, opname, dan penyesuaian dengan riwayat lengkap.'],
                            ['Ikon' => 'Truck', 'Judul' => 'Pembelian & pemasok', 'Teks' => 'Pesanan pembelian, penerimaan, faktur, hutang jatuh tempo, dan retur pemasok.'],
                            ['Ikon' => 'HandCoins', 'Judul' => 'Jual tempo', 'Teks' => 'Limit kredit per pelanggan, umur piutang, dan pelunasan sebagian.'],
                            ['Ikon' => 'ChartLine', 'Judul' => 'Laporan stok', 'Teks' => 'Kartu stok, nilai persediaan, barang terlaris, dan barang yang tidak bergerak.'],
                        ],
                    ],
                    $ctaDaftar,
                ],
            ],
            'solusi/jasa' => [
                'Judul' => 'Salon, Laundry & Jasa',
                'JudulSeo' => 'Aplikasi Kasir Salon, Laundry & Jasa | Payoung',
                'DeskripsiSeo' => 'Aplikasi kasir usaha jasa: komisi per staf, deposit pelanggan, paket sesi, reservasi, tiket laundry, jadwal & absensi karyawan.',
                'Bagian' => [
                    [
                        'Jenis' => 'Hero',
                        'Latar' => 'Terang',
                        'Spesimen' => 'PemilikKaryawan',
                        'Label' => 'Salon, Laundry & Jasa',
                        'Judul' => 'Komisi dan saldo pelanggan berhenti dihitung tangan',
                        'Subjudul' => 'Catat siapa yang melayani, terima deposit dan uang muka, lalu komisi serta rekap gaji tersusun sendiri.',
                        'TombolUtama' => ['Label' => 'Coba gratis', 'Tautan' => '@daftar'],
                        'TombolKedua' => ['Label' => 'Tanya lewat WhatsApp', 'Tautan' => '@whatsapp'],
                    ],
                    [
                        'Jenis' => 'Keunggulan',
                        'TataLetak' => 'Sorot',
                        'Judul' => 'Yang membedakan usaha jasa dari toko',
                        'Item' => [
                            ['Ikon' => 'Users', 'Judul' => 'Komisi menempel ke orang', 'Teks' => 'Aturan komisi per layanan atau kategori, boleh dibagi ke beberapa staf dalam satu transaksi. Angkanya masuk ke rekap gaji bulanan tanpa dihitung ulang, dan kasbon langsung dipotong di sana.'],
                            ['Ikon' => 'Wallet', 'Judul' => 'Deposit & paket sesi', 'Teks' => 'Pelanggan mengisi saldo atau membeli paket sesi; sisa sesi berkurang sendiri tiap kunjungan.'],
                            ['Ikon' => 'Clock', 'Judul' => 'Reservasi per staf', 'Teks' => 'Slot diambil dari jadwal kerja. Pelanggan bisa memesan online dan diingatkan lewat WhatsApp sehari sebelumnya.'],
                            ['Ikon' => 'WashingMachine', 'Judul' => 'Tiket laundry bisa dilacak', 'Teks' => 'Nota ber-QR; pelanggan memeriksa status sendiri dan diberi tahu saat cucian siap diambil.'],
                            ['Ikon' => 'ClipboardList', 'Judul' => 'Uang muka tercatat', 'Teks' => 'Pre-order dengan DP, diambil dan dilunasi kemudian, dengan bukti uang muka tercetak.'],
                            ['Ikon' => 'Smartphone', 'Judul' => 'Absen di perangkat kasir', 'Teks' => 'PIN dan swafoto, terhubung ke jadwal kerja dan target penjualan per staf.'],
                        ],
                    ],
                    $ctaDaftar,
                ],
            ],
            'kontak' => [
                'Judul' => 'Kontak',
                'JudulSeo' => 'Hubungi Payoung',
                'DeskripsiSeo' => 'Hubungi tim Payoung lewat WhatsApp atau email untuk demo, pertanyaan harga, dan bantuan.',
                'Bagian' => [
                    [
                        'Jenis' => 'Kontak',
                        'Judul' => 'Hubungi kami',
                        'Subjudul' => 'Untuk demo, pertanyaan paket, dan bantuan memasang perangkat kasir.',
                    ],
                    [
                        'Jenis' => 'FormulirProspek',
                        'Judul' => 'Minta demo gratis',
                        'Subjudul' => 'Tinggalkan nomor WhatsApp Anda. Tim kami menghubungi dalam 1 hari kerja.',
                        'JenisProspek' => 'Demo',
                        'TeksTombol' => 'Kirim permintaan demo',
                    ],
                    [
                        'Jenis' => 'UnduhAplikasi',
                        'Judul' => 'Unduh aplikasi Payoung',
                        'Subjudul' => 'Aplikasi kasir untuk Android, iPhone/iPad, dan Windows.',
                    ],
                ],
            ],
            'tentang' => [
                'Judul' => 'Tentang kami',
                'JudulSeo' => 'Tentang Payoung',
                'DeskripsiSeo' => 'Payoung adalah aplikasi kasir dan pembukuan untuk usaha di Indonesia.',
                'Bagian' => [
                    [
                        'Jenis' => 'TeksBebas',
                        'Judul' => 'Tentang Payoung',
                        'Isi' => "Payoung dibuat untuk pemilik usaha di Indonesia yang ingin berjualan dengan tenang: kasir tetap jalan saat internet putus, stok dan pajak dihitung benar, dan laporan keuangan tersedia tanpa input ulang.\n\n## Tiga hal yang kami pegang\n- **Dokumen yang sudah diposting tidak pernah kami ubah diam-diam.** Koreksi selalu lewat dokumen pembalik, sehingga riwayatnya bisa diperiksa.\n- **Pajak mengikuti tarif yang berlaku**, bukan angka yang kami tanam di kode.\n- **Data pelanggan Anda dilindungi** sesuai UU Perlindungan Data Pribadi, dan kami bertindak sebagai pemroses data.",
                    ],
                    $ctaDaftar,
                ],
            ],
        ];
    }
}
