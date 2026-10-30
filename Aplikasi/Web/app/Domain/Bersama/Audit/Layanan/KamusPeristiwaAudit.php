<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Audit\Layanan;

/**
 * Kamus peristiwa log audit (tenant: `LogAudit.Peristiwa`; pengelola: `LogAuditPengelola.Aksi`) ke kalimat yang bisa
 * dibaca orang. Kunci peristiwa tetap `objek.aksi` untuk kode, pencarian, dan laporan; layar log audit menampilkan
 * kalimatnya dan kunci aslinya hanya sebagai keterangan kecil.
 *
 * Peristiwa baru wajib menambah barisnya di sini: `KamusPeristiwaAuditTes` memindai kode dan gagal bila ada kunci yang
 * belum punya kalimat. Kunci yang belum terdaftar (misal peristiwa dinamis) tetap terbaca lewat `Humanisasi`, tetapi
 * hasilnya hanya cadangan.
 */
final class KamusPeristiwaAudit
{
    /** @var array<string, string> */
    public const LABEL = [
        'absensi.dicek' => 'Absensi yang perlu dicek ditandai sudah diperiksa',
        'absensi.keluar-otomatis' => 'Absensi yang lupa keluar ditutup otomatis sesuai jam selesai jadwal',
        'absensi.koreksi' => 'Absensi dikoreksi',
        'absensi.koreksi.diminta' => 'Karyawan meminta koreksi absensi ke manajer',
        'absensi.koreksi.ditutup' => 'Permintaan koreksi absensi ditutup',
        'absensi.tambah-manual' => 'Absensi dicatat manual',
        'absensi.web.di-luar-radius' => 'Absen dari HP ditolak: di luar radius outlet',
        'absensi.web.ditolak-jadwal' => 'Absen dari HP ditolak: di luar jadwal kerja',
        'absensi.web.qr-salah' => 'Absen dari HP ditolak: kode QR salah',
        'absensi.web.wajah-tidak-cocok' => 'Absen dari HP ditolak: wajah tidak cocok',

        'akun.dua-faktor-aktif' => 'Verifikasi dua langkah diaktifkan',
        'akun.dua-faktor-nonaktif' => 'Verifikasi dua langkah dinonaktifkan',
        'akun.email-diganti' => 'Email akun diganti',
        'akun.google-lepas' => 'Tautan akun Google dilepas',
        'akun.google-tautkan' => 'Akun Google ditautkan',
        'akun.hapus' => 'Akun perkiraan dihapus',
        'akun.kata-sandi-atur-ulang' => 'Kata sandi akun diatur ulang',
        'akun.pemetaan.hapus' => 'Pemetaan akun dihapus',
        'akun.pemetaan.lengkapi-otomatis' => 'Pemetaan akun dilengkapi otomatis',
        'akun.pemetaan.ubah' => 'Pemetaan akun diubah',
        'akun.status' => 'Status akun perkiraan diubah',
        'akun.tambah' => 'Akun perkiraan ditambahkan',
        'akun.tambah-template' => 'Akun perkiraan ditambahkan dari template',
        'akun.ubah' => 'Akun perkiraan diubah',

        'akuntansi.periode.buka' => 'Periode pembukuan dibuka kembali',
        'akuntansi.periode.kunci' => 'Periode pembukuan dikunci',
        'akuntansi.tahun.tutup' => 'Tahun pembukuan ditutup',

        'anggaran.salin' => 'Anggaran disalin',
        'anggaran.simpan' => 'Anggaran disimpan',

        'area-meja.buat' => 'Area meja dibuat',
        'area-meja.ubah' => 'Area meja diubah',

        'armada.buat' => 'Armada dibuat',
        'armada.ubah' => 'Armada diubah',

        'aset-tetap.batal' => 'Aset tetap dibatalkan',
        'aset-tetap.catat' => 'Aset tetap dicatat',
        'aset-tetap.lepas' => 'Aset tetap dilepas',

        'aturan-kehadiran.simpan' => 'Aturan kehadiran disimpan',

        'aturan-komisi.tambah' => 'Aturan komisi ditambahkan',
        'aturan-komisi.ubah' => 'Aturan komisi diubah',

        'bahan-terbuang.batalkan' => 'Bahan terbuang dibatalkan',
        'bahan-terbuang.catat' => 'Bahan terbuang dicatat',

        'belanja-stok.simpan' => 'Belanja stok disimpan',

        'bengkel.perintah-kerja-status' => 'Status perintah kerja bengkel diubah',
        'bengkel.persetujuan-minta' => 'Persetujuan servis diminta ke pelanggan',
        'bengkel.servis-berikutnya' => 'Jadwal servis berikutnya diatur',

        'biaya-tambahan.batalkan' => 'Biaya tambahan dibatalkan',
        'biaya-tambahan.posting' => 'Biaya tambahan dicatat',

        'cuti-karyawan.batal' => 'Cuti karyawan dibatalkan',
        'cuti-karyawan.catat' => 'Cuti karyawan dicatat',

        'daftar-harga.buat' => 'Daftar harga dibuat',
        'daftar-harga.harga.ubah' => 'Harga di daftar harga diubah',
        'daftar-harga.ubah' => 'Daftar harga diubah',

        'data.ekspor-minta' => 'Ekspor data usaha diminta',
        'data.ekspor-siap' => 'Ekspor data usaha siap diunduh',
        'data.ekspor-unduh' => 'Ekspor data usaha diunduh',
        'data.penghapusan-ajukan' => 'Penghapusan data usaha diajukan',
        'data.penghapusan-batal' => 'Penghapusan data usaha dibatalkan',

        'denah-meja.simpan' => 'Denah meja disimpan',

        'deposit.isi' => 'Deposit pelanggan diisi',
        'deposit.isi-batal' => 'Isi deposit dibatalkan',

        'dukungan.akses.beri' => 'Izin akses dukungan diberikan',
        'dukungan.akses.cabut' => 'Izin akses dukungan dicabut',
        'dukungan.akses.darurat' => 'Akses darurat dukungan dibuka',
        'dukungan.aksi.ditolak' => 'Aksi petugas dukungan ditolak (izin hanya baca)',
        'dukungan.alat' => 'Alat bantu dukungan dijalankan',
        'dukungan.sesi.berakhir' => 'Sesi akses dukungan berakhir',
        'dukungan.sesi.mulai' => 'Sesi akses dukungan dimulai',
        'dukungan.sesi.siapkan' => 'Sesi akses dukungan disiapkan',
        'dukungan.tiket.balas' => 'Tiket dukungan dibalas',
        'dukungan.tiket.catatan-internal' => 'Catatan internal tiket dukungan ditulis',
        'dukungan.tiket.eskalasi' => 'Tiket dukungan dieskalasi',
        'dukungan.tiket.eskalasi-otomatis' => 'Tiket dukungan dieskalasi otomatis (lewat batas SLA)',
        'dukungan.tiket.tugaskan' => 'Tiket dukungan ditugaskan',
        'dukungan.tiket.tutup-otomatis' => 'Tiket dukungan ditutup otomatis',
        'dukungan.tiket.ubah-prioritas' => 'Prioritas tiket dukungan diubah',
        'dukungan.tiket.ubah-status' => 'Status tiket dukungan diubah',

        'faktur-pembelian.batalkan' => 'Faktur pembelian dibatalkan',
        'faktur-pembelian.posting' => 'Faktur pembelian dicatat',

        'flag-fitur.hapus' => 'Flag fitur dihapus',
        'flag-fitur.simpan' => 'Flag fitur disimpan',

        'gerbang-pembayaran.uji' => 'Koneksi gerbang pembayaran diuji',

        'giro.cair' => 'Giro dicairkan',
        'giro.tolak' => 'Giro ditolak',

        'grosir.faktur-batalkan' => 'Faktur grosir dibatalkan',
        'grosir.faktur-buat' => 'Faktur grosir dibuat',
        'grosir.faktur-nomor-pajak' => 'Nomor faktur pajak grosir diisi',
        'grosir.pengiriman-ubah' => 'Pengiriman surat jalan grosir diubah',
        'grosir.pesanan-batalkan' => 'Pesanan grosir dibatalkan',
        'grosir.pesanan-konfirmasi' => 'Pesanan grosir dikonfirmasi',
        'grosir.pesanan-salesman' => 'Pesanan grosir dari salesman diterima',
        'grosir.retur-batalkan' => 'Retur grosir dibatalkan',
        'grosir.retur-buat' => 'Retur grosir dibuat',
        'grosir.surat-jalan-batalkan' => 'Surat jalan grosir dibatalkan',
        'grosir.surat-jalan-kirim' => 'Surat jalan grosir dikirim',

        'gudang.buat' => 'Gudang dibuat',
        'gudang.ubah' => 'Gudang diubah',

        'insiden.buka' => 'Insiden dibuka',
        'insiden.perbarui' => 'Insiden diperbarui',
        'insiden.post-mortem' => 'Ulasan akhir insiden ditulis',
        'insiden.umumkan' => 'Insiden diumumkan',

        'integrasi.server.nonaktifkan' => 'Integrasi server dinonaktifkan',
        'integrasi.server.simpan' => 'Integrasi server disimpan',
        'integrasi.server.uji' => 'Integrasi server diuji',
        'integrasi.token-api.buat' => 'Token API dibuat',
        'integrasi.token-api.cabut' => 'Token API dicabut',
        'integrasi.uji' => 'Koneksi integrasi diuji',
        'integrasi.webhook.buat' => 'Webhook dibuat',
        'integrasi.webhook.hapus' => 'Webhook dihapus',
        'integrasi.webhook.ubah-status' => 'Webhook diaktifkan atau dinonaktifkan',

        'invoice-berulang.buat' => 'Templat invoice berulang dibuat',
        'invoice-berulang.hentikan' => 'Templat invoice berulang dihentikan',
        'invoice-berulang.jeda' => 'Templat invoice berulang dijeda',
        'invoice-berulang.lanjut' => 'Templat invoice berulang dilanjutkan',
        'invoice-berulang.terbit' => 'Invoice diterbitkan dari templat berulang',
        'invoice-berulang.ubah' => 'Templat invoice berulang diubah',
        'invoice.batal' => 'Invoice dibatalkan',
        'invoice.buat' => 'Draf invoice dibuat',
        'invoice.kirim' => 'Invoice dikirim ke pelanggan',
        'invoice.konfirmasi-bayar' => 'Pelanggan mengonfirmasi sudah membayar invoice',
        'invoice.konfirmasi-terima' => 'Konfirmasi pembayaran pelanggan diterima',
        'invoice.konfirmasi-tolak' => 'Konfirmasi pembayaran pelanggan ditolak',
        'invoice.terbit' => 'Invoice diterbitkan',
        'invoice.ubah' => 'Draf invoice diubah',

        'jadwal-kerja.salin' => 'Jadwal kerja disalin',
        'jadwal-kerja.simpan' => 'Jadwal kerja disimpan',

        'jurnal.posting' => 'Jurnal dicatat',

        'jurnal-umum.balik' => 'Jurnal umum dibalik',
        'jurnal-umum.simpan' => 'Jurnal umum disimpan',

        'kampanye-pesan.batalkan' => 'Kampanye pesan dibatalkan',
        'kampanye-pesan.jadwalkan' => 'Kampanye pesan dijadwalkan',
        'kampanye-pesan.jalankan' => 'Kampanye pesan dijalankan',
        'kampanye-pesan.simpan' => 'Kampanye pesan disimpan',

        'karyawan.tambah' => 'Karyawan ditambahkan',
        'karyawan.tautan-absen.buat' => 'Tautan absen karyawan dibuat',
        'karyawan.tautan-absen.cabut' => 'Tautan absen karyawan dicabut',
        'karyawan.ubah' => 'Karyawan diubah',
        'karyawan.wajah.daftar' => 'Wajah karyawan didaftarkan',
        'karyawan.wajah.hapus' => 'Wajah karyawan dihapus',

        'kas.kategori.simpan' => 'Kategori kas disimpan',
        'kas.kategori.status' => 'Status kategori kas diubah',
        'kas.mutasi.catat' => 'Kas masuk/keluar dicatat',

        'kas-bank.balik' => 'Transaksi kas/bank dibalik',
        'kas-bank.jadwal-buat' => 'Jadwal transaksi kas/bank berulang dibuat',
        'kas-bank.jadwal-ubah' => 'Jadwal transaksi kas/bank berulang diubah',
        'kas-bank.simpan' => 'Transaksi kas/bank disimpan',

        'kasbon.batal' => 'Kasbon dibatalkan',
        'kasbon.catat' => 'Kasbon dicatat',
        'kasbon.lunasi' => 'Kasbon dilunasi',

        'kasir.barcode-timbangan.ubah' => 'Pengaturan barcode timbangan diubah',
        'kasir.masuk-pin' => 'Kasir masuk dengan PIN',
        'kasir.pengaturan.ubah' => 'Pengaturan kasir diubah',
        'kasir.pin.terkunci' => 'PIN kasir terkunci (salah berulang)',
        'kasir.tutup-harian' => 'Penjualan harian ditutup',

        'katalog.addon.terapkan-rilis' => 'Add-on katalog diterapkan dari rilis',
        'katalog.harga.ajukan' => 'Harga paket diajukan',
        'katalog.harga.akhiri' => 'Harga paket diakhiri',
        'katalog.harga.terapkan-rilis' => 'Harga paket diterapkan dari rilis',
        'katalog.harga.terbit' => 'Harga paket diterbitkan',
        'katalog.harga.tolak' => 'Harga paket ditolak',
        'katalog.paket-sesi-simpan' => 'Paket sesi disimpan',

        'kategori.buat' => 'Kategori produk dibuat',
        'kategori.hapus' => 'Kategori produk dihapus',
        'kategori.tambah-template' => 'Kategori produk ditambahkan dari template',

        'keamanan.perangkat-tepercaya.cabut' => 'Perangkat tepercaya dicabut',
        'keamanan.perangkat-tepercaya.cabut-semua' => 'Semua perangkat tepercaya dicabut',
        'keamanan.perangkat-tepercaya.tambah' => 'Perangkat tepercaya ditambahkan',

        'kelompok-pajak.buat' => 'Kelompok pajak dibuat',
        'kelompok-pajak.tambah-template' => 'Kelompok pajak ditambahkan dari template',
        'kelompok-pajak.ubah' => 'Kelompok pajak diubah',

        'kelompok-pilihan.buat' => 'Kelompok pilihan dibuat',
        'kelompok-pilihan.hapus' => 'Kelompok pilihan dihapus',
        'kelompok-pilihan.ubah' => 'Kelompok pilihan diubah',

        'kompatibilitas.tandai' => 'Kompatibilitas perangkat ditandai',

        'komunikasi.templat.hapus-draf' => 'Draf templat pesan dihapus',
        'komunikasi.templat.status-bsp' => 'Status persetujuan templat WhatsApp diubah',
        'komunikasi.templat.terbitkan' => 'Templat pesan diterbitkan',

        'konsinyasi.catat' => 'Barang konsinyasi dicatat',
        'konsinyasi.setor' => 'Setoran konsinyasi dicatat',
        'konsinyasi.setor.batalkan' => 'Setoran konsinyasi dibatalkan',

        'laci.buka' => 'Laci kasir dibuka manual',

        'langganan.bukti-transfer-unggah' => 'Bukti transfer langganan diunggah',
        'langganan.kapasitas-dibatasi' => 'Kapasitas langganan dibatasi',
        'langganan.pembayaran-gerbang-gagal' => 'Pembayaran langganan lewat gerbang gagal',
        'langganan.pembayaran-gerbang-lunas' => 'Pembayaran langganan lewat gerbang lunas',
        'langganan.pembayaran-gerbang-mulai' => 'Pembayaran langganan lewat gerbang dimulai',
        'langganan.pindah-gratis' => 'Langganan dipindah ke paket Gratis',
        'langganan.status.otomatis' => 'Status langganan berubah otomatis',
        'langganan.tagihan-addon' => 'Tagihan add-on diterbitkan',
        'langganan.tagihan-batal' => 'Tagihan langganan dibatalkan',
        'langganan.tagihan-lunas-kupon' => 'Tagihan langganan lunas dengan kupon',
        'langganan.tagihan-naik-paket' => 'Tagihan naik paket diterbitkan',
        'langganan.trial-berakhir' => 'Masa coba langganan berakhir',
        'langganan.turun-paket-ajukan' => 'Turun paket diajukan',
        'langganan.turun-paket-batal' => 'Turun paket dibatalkan',
        'langganan.turun-paket-berlaku' => 'Turun paket berlaku',

        'laporan.insight-mingguan.ubah' => 'Pengaturan insight mingguan diubah',

        'laundry.pengaturan' => 'Pengaturan laundry diubah',
        'laundry.status' => 'Status cucian diubah',

        'legal.draf.buat' => 'Draf dokumen legal dibuat',
        'legal.draf.hapus' => 'Draf dokumen legal dihapus',
        'legal.setujui' => 'Dokumen legal disetujui',
        'legal.terbitkan' => 'Dokumen legal diterbitkan',

        'loyalti.pengaturan' => 'Pengaturan loyalti diubah',

        'meja.buat' => 'Meja dibuat',
        'meja.token-pesan-sendiri.buat-ulang' => 'Kode QR pesan sendiri meja dibuat ulang',
        'meja.ubah' => 'Meja diubah',

        'merchant-pembayaran.ajukan' => 'Pendaftaran merchant pembayaran diajukan',
        'merchant-pembayaran.batal' => 'Pendaftaran merchant pembayaran dibatalkan',
        'merchant-pembayaran.simpan' => 'Draf pendaftaran merchant pembayaran disimpan',
        'merchant-pembayaran.status' => 'Status pendaftaran merchant pembayaran diubah',
        'merchant-pembayaran.terkirim' => 'Pendaftaran merchant pembayaran terkirim ke DOKU',

        'merek.buat' => 'Merek dibuat',
        'merek.hapus' => 'Merek dihapus',
        'merek.ubah' => 'Merek diubah',

        'metode-pembayaran.batas-hari-menunggu' => 'Batas hari menunggu pencairan metode pembayaran diubah',
        'metode-pembayaran.buat' => 'Metode pembayaran dibuat',
        'metode-pembayaran.teks-qris' => 'Nominal otomatis QRIS statis diubah',

        'mitra.komisi.batal' => 'Komisi mitra dibatalkan',
        'mitra.pencairan' => 'Komisi mitra dicairkan',

        'mutasi-bank.abaikan' => 'Mutasi bank diabaikan',
        'mutasi-bank.batal' => 'Mutasi bank dibatalkan',
        'mutasi-bank.cocok' => 'Mutasi bank dicocokkan',
        'mutasi-bank.cocok-otomatis' => 'Mutasi bank dicocokkan otomatis',
        'mutasi-bank.impor' => 'Mutasi bank diimpor',

        'komunikasi.siaran.batal' => 'Siaran dibatalkan',
        'komunikasi.siaran.buat' => 'Siaran dijadwalkan',

        'nota-debit.batalkan' => 'Nota debit dibatalkan',
        'nota-debit.posting' => 'Nota debit dicatat',

        'operasional.alert.nomor-wa.atur' => 'Nomor WhatsApp alert operasional diatur',
        'operasional.backup.catat' => 'Pencadangan basis data dicatat',
        'operasional.jadwal.jalankan' => 'Jadwal otomatis dijalankan manual',
        'operasional.job-gagal.buang' => 'Tugas gagal dibuang',
        'operasional.job-gagal.coba-ulang' => 'Tugas gagal dicoba ulang',
        'operasional.runbook.atur' => 'Tautan panduan penanganan alert diatur',

        'order-produksi.batalkan' => 'Order produksi dibatalkan',
        'order-produksi.buat' => 'Order produksi dibuat',
        'order-produksi.posting' => 'Order produksi dicatat',
        'order-produksi.ubah' => 'Order produksi diubah',

        'outlet.buat' => 'Outlet dibuat',
        'outlet.fitur.konfigurasi-template' => 'Konfigurasi fitur outlet dari template diterapkan',
        'outlet.fitur.lepas-template' => 'Fitur sisa jenis usaha lama dilepas dari outlet',
        'outlet.fitur.tambah-template' => 'Fitur outlet ditambahkan dari template',
        'outlet.jam-operasional.ubah' => 'Jam operasional outlet diubah',
        'outlet.jenis-pesanan.ubah' => 'Jenis pesanan outlet diubah',
        'outlet.kios.buat-ulang' => 'Kode QR kios outlet dibuat ulang',
        'outlet.kios.ubah' => 'Kios outlet diubah',
        'outlet.kunci-kode' => 'Kode outlet dikunci',
        'outlet.lokasi-absensi.ubah' => 'Lokasi absensi outlet diubah',
        'outlet.pajak.ubah' => 'Pajak outlet diubah',
        'outlet.pesan-sendiri.ubah' => 'Pesan sendiri outlet diubah',
        'outlet.template.ubah' => 'Template usaha outlet diubah',
        'outlet.ubah' => 'Outlet diubah',
        'outlet.wajib-qr-absensi.ubah' => 'Kewajiban QR layar untuk absen outlet diubah',

        'panduan-awal.lewati' => 'Panduan awal dilewati',
        'panduan-awal.selesai' => 'Panduan awal diselesaikan',

        'pelanggan.berhenti-pemasaran' => 'Pelanggan berhenti menerima pesan promosi',
        'pelanggan.daftar-online' => 'Pelanggan mendaftar lewat toko online',
        'pelanggan.deposit-sesuaikan' => 'Saldo deposit pelanggan disesuaikan',
        'pelanggan.deposit-tarik' => 'Deposit pelanggan ditarik',
        'pelanggan.ekspor' => 'Pelanggan diekspor',
        'pelanggan.gift-card-hangus' => 'Gift card dihanguskan',
        'pelanggan.gift-card-jual' => 'Gift card dijual',
        'pelanggan.gift-card-kembali' => 'Gift card dikembalikan',
        'pelanggan.gift-card-perpanjang' => 'Gift card diperpanjang',
        'pelanggan.impor' => 'Pelanggan diimpor',
        'pelanggan.poin-sesuaikan' => 'Poin pelanggan disesuaikan',
        'pelanggan.sesi-pakai' => 'Sesi paket pelanggan dipakai',
        'pelanggan.sesi-pakai-batal' => 'Pemakaian sesi paket dibatalkan',
        'pelanggan.sesi-tutup' => 'Sisa sesi paket pelanggan ditutup',
        'pelanggan.tambah' => 'Pelanggan ditambahkan',
        'pelanggan.tier' => 'Tier pelanggan diubah',
        'pelanggan.tier-otomatis' => 'Tier pelanggan naik/turun otomatis',
        'pelanggan.ubah' => 'Pelanggan diubah',
        'pelanggan.ubah-online' => 'Data pelanggan diubah lewat toko online',

        'pelunasan-piutang.batalkan' => 'Pelunasan piutang dibatalkan',
        'pelunasan-piutang.bukti-potong' => 'Nomor bukti potong PPh pelunasan dicatat',
        'pelunasan-piutang.posting' => 'Pelunasan piutang dicatat',

        'pemasok.hapus' => 'Pemasok dihapus',
        'pemasok.impor' => 'Pemasok diimpor',
        'pemasok.tambah' => 'Pemasok ditambahkan',
        'pemasok.ubah' => 'Pemasok diubah',

        'pembayaran-hutang.batalkan' => 'Pembayaran hutang dibatalkan',
        'pembayaran-hutang.kompensasi' => 'Pembayaran hutang dikompensasi',
        'pembayaran-hutang.posting' => 'Pembayaran hutang dicatat',

        'pembelian.pengaturan.ubah' => 'Pengaturan pembelian diubah',

        'pemilik.keluar' => 'Pemilik keluar dari Aplikasi Owner',
        'pemilik.masuk' => 'Pemilik masuk ke Aplikasi Owner',

        'pencairan.batalkan' => 'Pencairan dibatalkan',
        'pencairan.buat' => 'Pencairan dana non-tunai dicatat',

        'penerimaan-barang.batalkan' => 'Penerimaan barang dibatalkan',
        'penerimaan-barang.posting' => 'Penerimaan barang dicatat',

        'penawaran.batal' => 'Penawaran dibatalkan',
        'penawaran.buat' => 'Draf penawaran dibuat',
        'penawaran.diinvoicekan' => 'Penawaran dijadikan invoice',
        'penawaran.diputuskan' => 'Keputusan penawaran dicatat',
        'penawaran.kirim' => 'Penawaran dikirim ke pelanggan',
        'penawaran.terbit' => 'Penawaran diterbitkan',
        'penawaran.ubah' => 'Draf penawaran diubah',

        'pengguna.ganti-kata-sandi' => 'Kata sandi pengguna diganti',
        'pengguna.pin.atur' => 'PIN kasir pengguna diatur',
        'pengguna.pin.atur-ulang' => 'PIN kasir pengguna diatur ulang',
        'pengguna.tambah' => 'Pengguna ditambahkan',
        'pengguna.terima-undangan' => 'Undangan pengguna diterima',
        'pengguna.ubah-akses' => 'Akses pengguna diubah',
        'pengguna.undang' => 'Pengguna diundang',
        'pengguna.undangan-batal' => 'Undangan pengguna dibatalkan',

        'pengiriman.status' => 'Status pengiriman diubah',

        'pengumuman.cabut' => 'Pengumuman dicabut',
        'pengumuman.simpan' => 'Pengumuman disimpan',
        'pengumuman.terbit' => 'Pengumuman diterbitkan',

        'penjualan.faktur-pajak.batal' => 'Faktur pajak penjualan dibatalkan',
        'penjualan.faktur-pajak.nomor' => 'Nomor faktur pajak penjualan diisi',
        'penjualan.faktur-pajak.terbit' => 'Faktur pajak penjualan diterbitkan',
        'penjualan.retur' => 'Retur penjualan dibuat',
        'penjualan.retur-tanpa-struk' => 'Retur tanpa struk dibuat',
        'penjualan.terima' => 'Penjualan dari kasir diterima server',
        'penjualan.void' => 'Penjualan dibatalkan (void)',

        'penyesuaian-stok.ajukan' => 'Penyesuaian stok diajukan',
        'penyesuaian-stok.batal' => 'Penyesuaian stok dibatalkan',
        'penyesuaian-stok.buat' => 'Penyesuaian stok dibuat',
        'penyesuaian-stok.posting' => 'Penyesuaian stok dicatat',
        'penyesuaian-stok.setujui' => 'Penyesuaian stok disetujui',
        'penyesuaian-stok.tolak' => 'Penyesuaian stok ditolak',
        'penyesuaian-stok.ubah' => 'Penyesuaian stok diubah',

        'peran.buat' => 'Peran dibuat',
        'peran.hapus' => 'Peran dihapus',
        'peran.ubah' => 'Peran diubah',

        'perangkat.aktivasi' => 'Perangkat diaktifkan',
        'perangkat.buat' => 'Perangkat dibuat',
        'perangkat.cabut' => 'Perangkat dicabut',
        'perangkat.kode-aktivasi.buat' => 'Kode aktivasi perangkat dibuat',
        'perangkat.profil-hardware' => 'Profil hardware perangkat dicatat',
        'perangkat.ubah' => 'Perangkat diubah',

        'peristiwa' => 'Peristiwa audit',

        'permintaan-pembelian.ajukan' => 'Permintaan pembelian diajukan',
        'permintaan-pembelian.batalkan' => 'Permintaan pembelian dibatalkan',
        'permintaan-pembelian.jadikan-po' => 'Permintaan pembelian dijadikan pesanan pembelian',
        'permintaan-pembelian.simpan' => 'Permintaan pembelian disimpan',

        'permintaan-stok.ajukan' => 'Permintaan stok diajukan',
        'permintaan-stok.pemenuhan' => 'Permintaan stok dipenuhi',

        'persediaan.pengaturan.ubah' => 'Pengaturan persediaan diubah',
        'persediaan.saldo.bangun-ulang' => 'Saldo stok dibangun ulang dari buku stok',

        'persetujuan-jarak-jauh.ajukan' => 'Persetujuan jarak jauh diajukan ke Owner',

        'pesan-sendiri.terima' => 'Pesanan pesan-sendiri diterima kasir',
        'pesan-sendiri.tolak' => 'Pesanan pesan-sendiri ditolak kasir',

        'pesanan-online.dibayar' => 'Pesanan online dibayar',
        'pesanan-online.kedaluwarsa' => 'Pesanan online kedaluwarsa',
        'pesanan-online.kembalikan-uang' => 'Uang pesanan online dikembalikan',
        'pesanan-online.status' => 'Status pesanan online diubah',

        'pesanan-pembelian.ajukan' => 'Pesanan pembelian diajukan',
        'pesanan-pembelian.kirim-whatsapp' => 'Pesanan pembelian dikirim lewat WhatsApp',
        'pesanan-pembelian.simpan' => 'Pesanan pembelian disimpan',

        'pesanan-penjualan.siap' => 'Pesanan penjualan siap diambil',
        'pesanan-penjualan.terima' => 'Pesanan penjualan diterima',

        'pesanan-terbuka.batal' => 'Pesanan terbuka dibatalkan',
        'pesanan-terbuka.pindah-item' => 'Item pesanan terbuka dipindahkan',
        'pesanan-terbuka.pindah-meja' => 'Pesanan terbuka dipindah meja',
        'pesanan-terbuka.void-item' => 'Item pesanan terbuka dibatalkan (void)',

        'piutang.hapus-buku' => 'Piutang dihapus buku',
        'piutang.hapus-buku.batalkan' => 'Hapus buku piutang dibatalkan',
        'piutang.pengingat.pengaturan' => 'Pengaturan pengingat piutang diubah',

        'produk.arsipkan' => 'Produk diarsipkan',
        'produk.barcode.buat' => 'Barcode internal produk dibuat',
        'produk.barcode.hapus' => 'Barcode produk dihapus',
        'produk.barcode.tambah' => 'Barcode produk ditambahkan',
        'produk.batas-stok.ubah' => 'Batas stok produk diubah',
        'produk.ekspor' => 'Produk diekspor',
        'produk.gambar.hapus' => 'Gambar produk dihapus',
        'produk.gambar.ubah' => 'Gambar produk diubah',
        'produk.hapus' => 'Produk dihapus',
        'produk.hapus-permanen' => 'Produk dihapus permanen beserta riwayatnya',
        'produk.harga.ubah' => 'Harga produk diubah',
        'produk.impor.batalkan' => 'Impor produk dibatalkan',
        'produk.impor.lanjutkan' => 'Impor produk dilanjutkan',
        'produk.impor.pemetaan' => 'Pemetaan kolom impor produk disimpan',
        'produk.impor.selesai' => 'Impor produk selesai',
        'produk.impor.terapkan' => 'Impor produk diterapkan',
        'produk.impor.unggah' => 'Impor produk diunggah',
        'produk.komponen.ubah' => 'Komponen produk diubah',
        'produk.pilihan.ubah' => 'Pilihan tambahan produk diubah',
        'produk.pulihkan' => 'Produk dipulihkan',
        'produk.resep.buat-versi' => 'Versi resep produk dibuat',
        'produk.satuan.tambah' => 'Satuan produk ditambahkan',
        'produk.ubah' => 'Produk diubah',
        'produk.varian.generasi' => 'Varian produk dibuat otomatis',
        'produk.varian.tambah' => 'Varian produk ditambahkan',

        'promo.klaim-pemasok.terima' => 'Klaim promo ke pemasok diterima',
        'promo.pengaturan' => 'Pengaturan promo diubah',

        'prospek.kampanye.buat' => 'Kampanye prospek dibuat',
        'prospek.kampanye.status' => 'Status kampanye prospek diubah',
        'prospek.kampanye.uji' => 'Email uji kampanye prospek dikirim',
        'prospek.kontak.hapus' => 'Kontak prospek dihapus',
        'prospek.kontak.henti' => 'Kontak prospek dihentikan',
        'prospek.kontak.impor' => 'Kontak prospek diimpor',

        'rak-gudang.buat' => 'Rak gudang dibuat',
        'rak-gudang.lepas-produk' => 'Produk dilepas dari rak gudang',
        'rak-gudang.tetapkan-produk' => 'Produk ditetapkan ke rak gudang',
        'rak-gudang.ubah' => 'Rak gudang diubah',

        'referensi.hari-libur.ajukan' => 'Hari libur referensi diajukan',
        'referensi.hari-libur.ajukan-batal' => 'Pengajuan hari libur dibatalkan',
        'referensi.hari-libur.hapus-draf' => 'Draf hari libur dihapus',
        'referensi.tarif-pajak.ajukan' => 'Tarif pajak referensi diajukan',
        'referensi.tarif-pajak.akhiri' => 'Tarif pajak diakhiri',
        'referensi.tarif-pajak.setujui' => 'Tarif pajak referensi disetujui',
        'referensi.tarif-pajak.terbit' => 'Tarif pajak referensi diterbitkan',
        'referensi.tarif-pajak.tolak' => 'Tarif pajak referensi ditolak',
        'referensi.wilayah.impor' => 'Data wilayah diimpor',

        'rekap-gaji.bayar' => 'Rekap gaji dibayar',
        'rekap-gaji.buat' => 'Rekap gaji dibuat',
        'rekap-gaji.hapus' => 'Rekap gaji dihapus',
        'rekap-gaji.ubah' => 'Rekap gaji diubah',

        'reservasi.buat' => 'Reservasi dibuat',
        'reservasi.jadwal-ulang' => 'Reservasi dijadwalkan ulang',
        'reservasi.pengaturan' => 'Pengaturan reservasi diubah',
        'reservasi.status' => 'Status reservasi diubah',

        'reservasi-meja.buat' => 'Reservasi meja dibuat',
        'reservasi-meja.dp-catat' => 'Uang muka reservasi meja dicatat',
        'reservasi-meja.dp-selesai' => 'Uang muka reservasi meja diselesaikan',
        'reservasi-meja.status' => 'Status reservasi meja diubah',
        'reservasi-meja.ubah' => 'Reservasi meja diubah',

        'retur-pembelian.batalkan' => 'Retur pembelian dibatalkan',
        'retur-pembelian.posting' => 'Retur pembelian dicatat',

        'rilis.draf.simpan' => 'Draf rilis disimpan',
        'rilis.hentikan' => 'Rilis dihentikan',
        'rilis.rollout.ubah' => 'Persentase peluncuran rilis diubah',
        'rilis.terbit' => 'Rilis diterbitkan',
        'rilis.versi-minimum.atur' => 'Versi minimum aplikasi diatur',
        'rilis.versi-minimum.batal' => 'Versi minimum aplikasi dibatalkan',

        'satuan.buat' => 'Satuan dibuat',
        'satuan.hapus' => 'Satuan dihapus',
        'satuan.tambah-template' => 'Satuan ditambahkan dari template',

        'sektor.ganti' => 'Jenis usaha outlet diganti',

        'sesi.dua-faktor.aktifkan' => 'Masuk dengan verifikasi dua langkah',
        'sesi.keluar' => 'Pengguna keluar',
        'sesi.masuk' => 'Pengguna masuk',
        'sesi.masuk-perangkat-tepercaya' => 'Pengguna masuk dari perangkat tepercaya',
        'sesi.pilih-tenant' => 'Pengguna memilih usaha',

        'shift.buka' => 'Shift dibuka',
        'shift.buka-ulang' => 'Shift dibuka ulang',
        'shift.tutup' => 'Shift ditutup',
        'shift.tutup-paksa' => 'Shift ditutup paksa',

        'situs.artikel.hapus' => 'Artikel situs dihapus',
        'situs.bantuan.hapus' => 'Bantuan situs dihapus',
        'situs.gambar.hapus' => 'Gambar situs dihapus',
        'situs.gambar.ubah' => 'Gambar situs diubah',
        'situs.gambar.unggah' => 'Gambar situs diunggah',
        'situs.halaman.gandakan' => 'Halaman situs digandakan',
        'situs.halaman.hapus' => 'Halaman situs dihapus',
        'situs.halaman.pulihkan' => 'Halaman situs dipulihkan',
        'situs.halaman.terbitkan' => 'Halaman situs diterbitkan',
        'situs.pengaturan.ubah' => 'Pengaturan situs diubah',
        'situs.prospek.ubah' => 'Prospek situs diubah',

        'stasiun-dapur.buat' => 'Stasiun dapur dibuat',
        'stasiun-dapur.tambah-template' => 'Stasiun dapur ditambahkan dari template',
        'stasiun-dapur.ubah' => 'Stasiun dapur diubah',

        'stok-awal.batalkan' => 'Stok awal dibatalkan',
        'stok-awal.buang' => 'Stok awal dibuang',
        'stok-awal.buat' => 'Stok awal dibuat',
        'stok-awal.impor.batalkan' => 'Impor stok awal dibatalkan',
        'stok-awal.impor.pemetaan' => 'Pemetaan kolom impor stok awal disimpan',
        'stok-awal.impor.terapkan' => 'Impor stok awal diterapkan',
        'stok-awal.impor.unggah' => 'Impor stok awal diunggah',
        'stok-awal.posting' => 'Stok awal dicatat',
        'stok-awal.posting-diajukan' => 'Catat stok awal diajukan untuk persetujuan',
        'stok-awal.posting-gagal' => 'Catat stok awal gagal',
        'stok-awal.ubah' => 'Stok awal diubah',

        'stok-opname.ajukan' => 'Stok opname diajukan',
        'stok-opname.batal' => 'Stok opname dibatalkan',
        'stok-opname.hitung' => 'Stok opname dihitung',
        'stok-opname.kembalikan' => 'Stok opname dikembalikan ke penghitungan',
        'stok-opname.mulai' => 'Stok opname dimulai',
        'stok-opname.setujui' => 'Stok opname disetujui',

        'struk.pengaturan.ubah' => 'Pengaturan struk diubah',

        'tagihan.addon-kedaluwarsa' => 'Tagihan add-on kedaluwarsa',
        'tagihan.bukti.lihat' => 'Bukti pembayaran tagihan dilihat',
        'tagihan.hapus-buku' => 'Tagihan dihapus buku',
        'tagihan.jatuh-tempo' => 'Tagihan jatuh tempo',
        'tagihan.lama-langganan.ubah' => 'Aturan lama langganan platform diubah',
        'tagihan.manual.terbit' => 'Tagihan manual diterbitkan',
        'tagihan.pembayaran.catat-langsung' => 'Pembayaran tagihan dicatat langsung',
        'tagihan.pembayaran.terima' => 'Pembayaran tagihan diterima',
        'tagihan.pembayaran.tolak' => 'Pembayaran tagihan ditolak',
        'tagihan.pkp.ubah' => 'Status PKP platform diubah',
        'tagihan.refund.ajukan' => 'Pengembalian dana tagihan diajukan',
        'tagihan.refund.batas-ubah' => 'Batas persetujuan pengembalian dana diubah',
        'tagihan.refund.setuju' => 'Pengembalian dana tagihan disetujui',
        'tagihan.refund.tolak' => 'Pengembalian dana tagihan ditolak',
        'tagihan.rekening-tujuan.ubah' => 'Rekening tujuan transfer diubah',

        'tagihan-qris.batal' => 'Tagihan qris dibatalkan',
        'tagihan-qris.buat' => 'Tagihan qris dibuat',
        'tagihan-qris.buat-online' => 'Tagihan QRIS dibuat (toko online)',
        'tagihan-qris.buka-kembali' => 'Tagihan QRIS dibuka kembali',
        'tagihan-qris.jumlah-berbeda' => 'Tagihan QRIS dibayar dengan jumlah berbeda',
        'tagihan-qris.status' => 'Status tagihan QRIS diubah',
        'tagihan-qris.tidak-pasti' => 'Tagihan QRIS belum pasti (menunggu pengecekan)',
        'tagihan-qris.tutup' => 'Tagihan qris ditutup',

        'target-penjualan.hapus' => 'Target penjualan dihapus',
        'target-penjualan.simpan' => 'Target penjualan disimpan',

        'template.buat' => 'Template dibuat',
        'template.draf.hapus' => 'Draf template dihapus',
        'template.hapus' => 'Template dihapus',
        'template.terbitkan' => 'Template diterbitkan',
        'template.terbitkan-rilis' => 'Template sektor diterbitkan dari rilis',
        'template.versi.duplikasi' => 'Versi template diduplikasi',

        'template-sektor.pembaruan.terapkan' => 'Pembaruan template sektor diterapkan',
        'template-sektor.terapkan' => 'Template sektor diterapkan',
        'template-sektor.uji-coba' => 'Template sektor diuji coba',

        'tenant.aktifkan' => 'Tenant diaktifkan',
        'tenant.catatan.tulis' => 'Catatan tenant ditulis',
        'tenant.daftar' => 'Tenant didaftarkan',
        'tenant.daftar.ekspor' => 'Daftar tenant diekspor',
        'tenant.data.akses' => 'Data tenant diakses petugas Payoung',
        'tenant.hapus-permanen' => 'Tenant uji dihapus permanen',
        'tenant.merchant.penampung-qris' => 'Penampung dana QRIS tenant diatur',
        'tenant.merchant.segarkan' => 'Status merchant tenant disegarkan',
        'tenant.override.buat' => 'Override tenant dibuat',
        'tenant.override.cabut' => 'Override tenant dicabut',
        'tenant.penanda.ubah' => 'Penanda tenant diubah',
        'tenant.sektor.ganti' => 'Daftar jenis usaha tenant diperbarui',
        'tenant.pengaturan.lengkapi' => 'Pengaturan tenant dilengkapi',
        'tenant.penghapusan-data-laksana' => 'Penghapusan data tenant dilaksanakan',
        'tenant.penghapusan-data-tolak' => 'Penghapusan data tenant ditolak',
        'tenant.profil.ubah' => 'Profil tenant diubah',
        'tenant.tangguhkan' => 'Tenant ditangguhkan',
        'tenant.trial.perpanjang' => 'Masa coba tenant diperpanjang',
        'tenant.tugas.selesai' => 'Tugas tenant diselesaikan',

        'tier-pelanggan.tambah' => 'Tier pelanggan ditambahkan',
        'tier-pelanggan.ubah' => 'Tier pelanggan diubah',

        'tim.anggota.buat-super-admin' => 'Super Admin dibuat',
        'tim.anggota.ganti-kata-sandi' => 'Kata sandi anggota tim diganti',
        'tim.anggota.nonaktifkan' => 'Anggota tim dinonaktifkan',
        'tim.anggota.tambah' => 'Anggota tim ditambahkan',
        'tim.anggota.terima-undangan' => 'Undangan anggota tim diterima',
        'tim.anggota.undang' => 'Anggota tim diundang',
        'tim.anggota.undangan-batal' => 'Undangan anggota tim dibatalkan',
        'tim.batasan-ip.kosongkan-server' => 'Batasan IP konsol dikosongkan lewat server',
        'tim.batasan-ip.ubah' => 'Batasan ip tim diubah',
        'tim.peran.tetapkan' => 'Peran anggota tim ditetapkan',

        'tindakan.ringkasan-whatsapp.ubah' => 'Pengaturan ringkasan pagi WhatsApp diubah',
        'tindakan.tinjau' => 'Tindakan ditinjau',

        'toko-online.pengaturan' => 'Pengaturan toko online diubah',

        'transfer-stok.batal' => 'Transfer stok dibatalkan',
        'transfer-stok.buat' => 'Transfer stok dibuat',
        'transfer-stok.kirim' => 'Transfer stok dikirim',
        'transfer-stok.terima' => 'Transfer stok diterima',
        'transfer-stok.tutup' => 'Transfer stok ditutup',
        'transfer-stok.ubah' => 'Transfer stok diubah',

        'unduhan.hapus' => 'Unduhan dihapus',
        'unduhan.minta' => 'Ekspor besar diminta',
        'unduhan.unduh' => 'Berkas unduhan diunduh',

        'voucher.tambah' => 'Voucher ditambahkan',

        'whatsapp-tenant.uji' => 'WhatsApp tenant diuji',
    ];

    public static function Label(string $kunci): string
    {
        return self::LABEL[$kunci] ?? self::Humanisasi($kunci);
    }

    public static function CekDikenal(string $kunci): bool
    {
        return isset(self::LABEL[$kunci]);
    }

    /**
     * Kunci terdaftar yang kalimatnya memuat [kata] (tanpa membedakan huruf besar/kecil), untuk pencarian log audit:
     * orang mengetik "outlet diubah", bukan "outlet.ubah".
     *
     * @return list<string>
     */
    public static function CariKunci(string $kata): array
    {
        $kata = mb_strtolower(trim($kata));

        if ($kata === '') {
            return [];
        }

        $hasil = [];

        foreach (self::LABEL as $kunci => $kalimat) {
            if (str_contains(mb_strtolower($kalimat), $kata)) {
                $hasil[] = $kunci;
            }
        }

        return $hasil;
    }

    /** Cadangan untuk kunci yang belum terdaftar: segmen dibalik ke urutan kalimat, `-` menjadi spasi. */
    public static function Humanisasi(string $kunci): string
    {
        $kata = array_map(static fn (string $segmen): string => str_replace('-', ' ', $segmen), array_reverse(explode('.', $kunci)));
        $kalimat = trim(implode(' ', $kata));

        return $kalimat === '' ? $kunci : mb_strtoupper(mb_substr($kalimat, 0, 1)).mb_substr($kalimat, 1);
    }

    /**
     * Nama objek yang dibaca orang dari nama kelas model ("TiketDukungan" menjadi "Tiket dukungan"), lengkap dengan
     * nomornya bila ada ("Tiket dukungan #12").
     */
    public static function LabelObjek(?string $jenisObjek, ?int $idObjek): ?string
    {
        if ($jenisObjek === null || $jenisObjek === '') {
            return null;
        }

        $kata = mb_strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $jenisObjek));
        $nama = mb_strtoupper(mb_substr($kata, 0, 1)).mb_substr($kata, 1);

        return $idObjek === null ? $nama : "{$nama} #{$idObjek}";
    }
}
