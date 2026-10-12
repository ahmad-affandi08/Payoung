<?php

declare(strict_types=1);

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * Jadwal tugas. Di Hostinger dijalankan oleh cron `* * * * * php artisan schedule:run` (PRD §14).
 */

// Audit kinerja skala besar: `withoutOverlapping(menit)` memberi batas kedaluwarsa kunci (bawaan 24 jam). Bila proses mati
// di tengah jalan (OOM, dibunuh hosting), tugas pulih sendiri setelah batas itu, bukan terkunci sehari penuh.
// `runInBackground()`: tanpa ini `schedule:run` menjalankan tugas satu per satu dan menunggu masing-masing (pekerja antrean
// saja sampai 50 detik), sehingga tugas lain di menit yang sama tertahan; kunci `withoutOverlapping` tetap mencegah tumpang tindih.
//
// D-35: tugas milik Platform Pengelola & tagihan langganan hanya dijadwalkan di edisi SaaS. Di edisi Lisensi tugasnya
// tetap terdaftar sebagai perintah (bisa dijalankan manual) tetapi tidak pernah berjalan sendiri.
$jadwalSaas = static fn (string $perintah): Event => Schedule::command($perintah)->when(static fn (): bool => ! EdisiAplikasi::CekLisensi());

// BR-P02.4: pengingat hari libur tahun berikutnya (aktif mulai 1 November sampai terbit).
$jadwalSaas('pengelola:ingatkan-hari-libur')->dailyAt('08:00')->timezone('Asia/Jakarta');

// BR-P05.3: uji koneksi integrasi aktif setiap jam; alert ke Teknis saat baru gagal.
$jadwalSaas('pengelola:uji-integrasi')->hourly()->withoutOverlapping(120)->runInBackground();

// BR-00.3: trial yang berakhir turun ke paket Gratis.
$jadwalSaas('tenant:akhiri-trial')->hourly()->withoutOverlapping(120)->runInBackground();

// P-08: tagihan lewat jatuh tempo, langganan Tertunggak lalu Ditangguhkan setelah masa tenggang.
$jadwalSaas('tagihan:proses-tunggakan')->hourly()->withoutOverlapping(120)->runInBackground();

// P-08 (v4.06): pembayaran langganan gerbang tanpa notifikasi webhook ditanyakan statusnya ke gerbang.
$jadwalSaas('tagihan:rekonsiliasi-gerbang')->everyFifteenMinutes()->withoutOverlapping(30)->runInBackground();
// Pembayaran yang masih Menunggu lewat jendela 7 hari ditanyakan sekali sehari; yang tidak dibayar ditutup supaya tagihannya bebas.
$jadwalSaas('tagihan:rekonsiliasi-gerbang --arsip')->dailyAt('03:10')->timezone('Asia/Jakarta')->withoutOverlapping(60)->runInBackground();

// DOKU Partner (KYB): jalur cadangan webhook, status pendaftaran merchant yang masih ditinjau dibaca tiap 30 menit;
// foto KYC sementara yang tersisa lebih dari 24 jam dihapus tiap jam. Hanya edisi SaaS (butuh akun Partner Payoung).
$jadwalSaas('pembayaran:segarkan-pendaftaran-merchant')->everyThirtyMinutes()->withoutOverlapping(30)->runInBackground();
$jadwalSaas('pembayaran:bersihkan-berkas-pendaftaran')->hourly()->withoutOverlapping(60)->runInBackground();
// BR-P04.4: terapkan turun paket yang sudah waktunya (ke Gratis saat periode berakhir, ke paket lebih murah setelah perpanjangan lunas).
$jadwalSaas('langganan:terapkan-turun-paket')->hourly()->withoutOverlapping(60)->runInBackground();
// Audit E5: hapus berkas ekspor data usaha yang kedaluwarsa.
$jadwalSaas('ekspor-data:bersihkan')->dailyAt('03:30')->withoutOverlapping(60)->runInBackground();
// Audit E2: ukur ulang penyimpanan berkas tenant (batas paket) tiap dini hari.
$jadwalSaas('penyimpanan:ukur')->dailyAt('03:10')->withoutOverlapping(120)->runInBackground();

// P-08 (v4.04): tagihan perpanjangan otomatis H-7 + pengingat H-7/H-3/H0/H+3 ke Owner, di jam kerja.
$jadwalSaas('tagihan:terbitkan-perpanjangan')->dailyAt('08:20')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// BR-P06.5: pengumuman versi materiil dokumen legal ke Owner selama masa pengumuman (sekali per versi per pengguna).
// v1.98 HCL: daftar kompatibilitas perangkat dari hasil Wizard Uji Perangkat.
$jadwalSaas('pengelola:segarkan-kompatibilitas')->dailyAt('02:30')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

$jadwalSaas('tenant:umumkan-dokumen-legal')->dailyAt('09:00')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// P-11 BR-P11.1: detak scheduler tiap menit + pemeriksaan alert operasional (scheduler, antrean, backup).
$jadwalSaas('pengelola:detak')->everyMinute()->withoutOverlapping(10);

// P-11 (§14.4): worker antrean database dijalankan scheduler tiap menit di Hostinger (tanpa proses daemon).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(10)->runInBackground();

// X7 bagian 2 (PRD §16.4): coba ulang webhook keluar yang jatuh tempo (1m, 5m, 30m, 2j, 12j) + retensi log 30 hari.
Schedule::command('integrasi:kirim-webhook')->everyMinute()->withoutOverlapping(10)->runInBackground();

// P-09: tiket selesai yang tidak dibuka lagi dalam 7 hari ditutup otomatis.
$jadwalSaas('pengelola:tutup-tiket-selesai')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// F-05a (DesainF05a C.9): pemeriksaan malam SaldoStok = Σ MutasiStok, rantai mutasi, lapisan FIFO, batch & seri (keluar 1 bila berbeda).
Schedule::command('persediaan:bangun-ulang-saldo --periksa')->dailyAt('02:30')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// Audit PAY-P1-02: jaring pendeteksi rujukan foreign key lintas tenant (harus nol), tiap Senin dini hari.
Schedule::command('tenant:periksa-silang')->weeklyOn(1, '03:15')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// F-14a: bangun ulang ringkasan penjualan harian H-1 & H-2 (penjualan offline terlambat, job antrean gagal).
Schedule::command('laporan:bangun-ulang-ringkasan')->dailyAt('02:45')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// F-16b: poin kedaluwarsa dihanguskan (FIFO) lalu tier pelanggan dievaluasi dari belanja N bulan terakhir.
Schedule::command('pelanggan:proses-loyalti')->dailyAt('03:00')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// D-23 D: draf PO otomatis untuk stok di bawah minimum, siap diperiksa pagi hari (setelah saldo stok dibangun ulang).
Schedule::command('pembelian:draf-po-otomatis')->dailyAt('05:30')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// D-23 D: transaksi kas & bank berulang (sewa, listrik, internet) yang jatuh tempo dicatat otomatis.
Schedule::command('akuntansi:jalankan-jadwal-kas-bank')->dailyAt('05:45')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// FIN-10 (v3.38): penyusutan aset tetap sampai bulan lalu (idempoten; menyusul bulan yang tertinggal).
Schedule::command('akuntansi:susutkan-aset-tetap')->dailyAt('05:50')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// D-23 D: tutup harian otomatis untuk hari yang aman ditutup (setelah jam tutup buku bawaan 04.00).
Schedule::command('kasir:tutup-harian-otomatis')->dailyAt('06:15')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// D-23 D: ringkasan pagi Kotak Tindakan lewat WhatsApp (D-33) (setelah otomatisasi pagi di atas).
Schedule::command('tindakan:kirim-ringkasan-harian')->dailyAt('07:00')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// X6 (v3.79): insight penjualan minggu lalu ke pemilik tiap Senin pagi (setelah ringkasan Kotak Tindakan).
Schedule::command('laporan:kirim-insight-mingguan')->weeklyOn(1, '07:15')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// OWN-03: push kondisi operasional penting; idempoten per pengguna, jenis, dan tanggal bisnis.
Schedule::command('tindakan:buat-notifikasi-operasional')->hourlyAt(10)->withoutOverlapping(120)->runInBackground();

// D-23 D: pengingat piutang jatuh tempo ke pelanggan (jam wajar, bila tenant mengaktifkannya).
Schedule::command('pelanggan:kirim-pengingat-piutang')->dailyAt('09:00')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// F-18 bagian 5 (D-44): pengingat shift & peringatan terlambat/belum masuk lewat WhatsApp (bila aturan kehadiran aktif).
Schedule::command('karyawan:kirim-notifikasi-kehadiran')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();

// CRM-07: mulai kampanye pesan pelanggan yang jadwalnya sudah tiba (pengiriman bertahap & jam tenang di tugasnya).
Schedule::command('pelanggan:jalankan-kampanye-terjadwal')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();

// Bengkel (§9.10): pengingat servis berkala H-3 lewat WhatsApp (jam kerja pagi, sekali per tanggal servis).
Schedule::command('bengkel:kirim-pengingat-servis')->dailyAt('09:10')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// F-07 mode service: pengingat reservasi H-1 lewat WhatsApp (jendela 20–28 jam, tiap jam).
Schedule::command('reservasi:kirim-pengingat')->hourlyAt(5)->withoutOverlapping(120)->runInBackground();

// Situs pemasaran bagian B: retensi data prospek (UU PDP).
$jadwalSaas('situs:bersihkan-prospek')->dailyAt('03:30')->timezone('Asia/Jakarta')->withoutOverlapping(240)->runInBackground();

// D-63: halaman situs yang jadwal terbitnya sudah tiba.
$jadwalSaas('situs:terbitkan-terjadwal')->everyMinute()->withoutOverlapping(10)->runInBackground();

// Audit P0 F-02: tagihan QRIS dinamis yang hasil pembuatannya di gerbang tidak pasti direkonsiliasi (bukan dihapus).
Schedule::command('penjualan:rekonsiliasi-qris')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();

// F-17 toko online: pesanan yang tidak pernah dikonfirmasi staf dihanguskan sesuai batas waktu toko.
Schedule::command('pesanan-online:kedaluwarsa')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
