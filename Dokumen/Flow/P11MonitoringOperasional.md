<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-11 · Monitoring Operasional

**Tujuan:** Tim Teknis tahu lebih dulu sebelum tenant mengeluh.
**Aktor:** Teknis.

**Dasbor operasional:**
- Detak scheduler (cron terakhir berjalan), umur job antrean tertua, job gagal (lihat detail, coba ulang, buang)
- Error rate & p95 API (POS, Owner, internal), endpoint terlambat
- Perangkat dengan outbox macet > 2 jam (semua tenant), galat sinkron terbanyak
- Webhook keluar gagal, status integrasi (P-05)
- Backup terakhir yang berhasil & hasil uji restore terakhir
- Ukuran database per tenant, tenant dengan beban tertinggi, pemakaian disk/inode hosting
- Crash-free sessions per platform & versi aplikasi

**Alert:** ambang batas per metrik → email/WA/Telegram ke Teknis yang bertugas, dengan tautan ke *runbook*.

**Manajemen insiden:** catat insiden (tingkat, dampak, kronologi), perbarui **halaman status publik** (`status.{{app}}.id`, di-hosting terpisah dari Hostinger agar tetap hidup saat server bermasalah), post-mortem setelah selesai.

**Aturan Bisnis:**
- BR-P11.1 Umur job tertua > 5 menit atau scheduler tidak berdetak > 3 menit = alert kritis (§20.3).
- BR-P11.2 Insiden yang berdampak ke > 10% tenant aktif wajib diumumkan di halaman status dalam 15 menit.

**Lingkup Fase 0 (PGL-20 dasar):** dasbor `/operasional` untuk Teknis & Super Admin (`operasional.lihat`; `operasional.kelola` untuk coba ulang/buang job gagal dan isian backup manual). **Detak scheduler:** perintah `pengelola:detak` tiap menit menyimpan `DetakPenjadwal` lalu memeriksa alert. **Antrean** (tabel `jobs` Laravel): jumlah menunggu/diproses per antrean dan umur job tertua yang sudah waktunya diproses (job tertunda tidak dihitung); worker `queue:work --stop-when-empty` dijalankan scheduler tiap menit (§14.4). **Job gagal** (`failed_jobs`): daftar & detail tanpa isi job terserialisasi (hanya metadata) dan pesan galat yang disaring dari pola rahasia; coba ulang (`queue:retry`) dan buang (wajib alasan) tercatat di log audit. **Backup:** `pengelola:catat-backup` dipanggil skrip backup server (§14.5), ditambah isian manual Teknis (misal hasil uji restore bulanan); lokasi tidak boleh memuat kredensial; catatan append-only. **Alert** (tabel `AlertOperasional`, satu baris per insiden): scheduler tidak berdetak > 3 menit atau belum pernah berdetak (Kritis), job tertua > 5 menit (Kritis), backup berhasil terakhir > 26 jam atau belum ada (Peringatan). Email ke Teknis aktif (bila kosong, Super Admin) dikirim **sekali per insiden**, langsung (bukan lewat antrean), dan dicoba lagi pada pemeriksaan berikutnya bila gagal terkirim; insiden tertutup saat kondisi pulih. Pemeriksaan berjalan dari detak scheduler dan dari `/sehat` (dipanggil uptime monitor eksternal §20.3, paling sering sekali per 30 detik, tidak pernah menggagalkan `/sehat`), sehingga scheduler yang mati tetap terdeteksi. Banner "Masalah operasional" di Platform Pengelola dihitung langsung dari kondisi terkini untuk semua anggota yang masuk. **Kesehatan:** ringkasan status integrasi P-05 (tautan ke halaman integrasi), ukuran database, dan ruang disk storage bila fungsi PHP-nya tersedia di hosting. Selesai v6.21: error rate & p95 API (metrik per menit di store cache `metrik`, alert Peringatan/Kritis), outbox perangkat macet, dan alert WhatsApp untuk insiden Kritis ke nomor yang diatur Teknis (D-140); manajemen insiden dan halaman status publik selesai di v5.95. Masih ditunda: crash-free sessions, endpoint terlambat, galat sinkron terbanyak, Telegram & tautan runbook, webhook keluar gagal per tenant, dan ukuran database per tenant.
