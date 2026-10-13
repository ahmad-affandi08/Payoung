<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-09 · Dukungan & Akses Dukungan

**Tujuan:** Masalah tenant cepat selesai, tanpa mengorbankan privasi dan keamanan data tenant.
**Aktor:** Dukungan (L1/L2), Teknis, Super Admin, Owner tenant.

**Tiket dukungan:**
- Kanal: in-app (tombol Bantuan di back-office & aplikasi), email, WhatsApp. Tiket dari aplikasi otomatis melampirkan konteks: tenant, outlet, perangkat, versi aplikasi, jumlah outbox tertunda, log singkat.
- Prioritas & **SLA per paket** (misal respons pertama: Gratis 2 hari kerja, Starter 1 hari, Pro 8 jam, Bisnis 4 jam).
- Eskalasi L1 → L2 → Teknis.
- **State Machine `TiketDukungan.Status`:** `Baru → Ditangani → MenungguPelanggan → Selesai → Ditutup`. `Selesai` bisa dibuka lagi dalam 7 hari.

**Akses dukungan ("Masuk sebagai tenant"):**
1. Owner memberi izin dari back-office: *"Izinkan tim dukungan mengakses akun saya"* dengan pilihan durasi (1/24/72 jam) dan cakupan (**Baca saja** atau **Baca & Ubah**). Izin juga bisa diberikan melalui tiket.
2. Petugas dukungan membuka sesi akses. Layar menampilkan **banner merah** "Anda mengakses akun {NamaUsaha} sebagai Dukungan".
3. Setiap aksi dalam sesi tercatat di `LogAudit` tenant dengan penanda pelaku pengelola, dan di `LogAuditPengelola`.
4. Owner dapat melihat riwayat akses dan mencabut izin kapan saja.
5. **Akses darurat tanpa izin** hanya untuk Super Admin, dengan alasan insiden keamanan atau permintaan hukum. Owner diberi notifikasi setelahnya.

**Alat bantu dukungan:** cabut/reset perangkat, minta perangkat mengunggah log sinkron, lihat status outbox per perangkat, **bangun ulang `SaldoStok` dari `MutasiStok`**, jalankan ulang ringkasan harian, kirim ulang email/WA, bantu import data.

**Aturan Bisnis:**
- BR-P09.1 Pengelola tidak pernah bisa melihat kata sandi, PIN, atau kredensial integrasi tenant. Data pribadi pelanggan tenant ditampilkan tersamar (masked) secara default.
- BR-P09.2 Aksi ubah dalam sesi dukungan hanya jika cakupan izin "Baca & Ubah".
- BR-P09.3 Alat bantu yang mengubah data (bangun ulang saldo, ringkasan) mencatat hasil sebelum/sesudah.

**AC:**
```gherkin
Given Owner tenant memberi izin akses dukungan "Baca saja" selama 24 jam
When petugas dukungan mencoba mengubah harga produk dalam sesi akses
Then aksi ditolak dengan pesan "Izin akses hanya baca"
And percobaan tersebut tercatat di log audit tenant dan log audit pengelola
```

**Lingkup Fase 0 (PGL-15 dasar):** tiket dari tombol Bantuan back-office (`/kelola/bantuan`): semua anggota aktif tenant boleh membuat, melihat, membalas, dan menandai selesai tiket tenantnya (izin per peran tenant menyusul F-02). Nomor unik platform `TKT-{Tahun WIB}-{6 digit}` berurut per tahun. Prioritas dipilih pelapor (Mendesak/Tinggi/Normal/Rendah) dan bisa diubah tim saat triase. Batas SLA respons pertama = jam kalender dari `config('dukungan.SlaResponsPertamaJam')` per kode paket lalu per prioritas: kolom Normal = janji paket (Gratis 48, Starter 24, Pro 8, Bisnis 4 jam), Mendesak/Tinggi hanya mempercepat paket berbayar, Rendah tidak memperlambat; dihitung ulang saat prioritas diubah selama belum ada respons pertama. Respons pertama = balasan tim pertama yang terlihat tenant. Balasan tim ke tenant: `Baru` → `Ditangani` (atau status pilihan Ditangani/MenungguPelanggan/Selesai), penanggung jawab = pembalas bila belum ada; balasan pelapor saat `MenungguPelanggan` → `Ditangani`; balasan pelapor pada tiket `Selesai` ≤ 7 hari membuka lagi tiket (`Ditangani`), > 7 hari ditolak; tiket `Selesai` > 7 hari ditutup otomatis (harian). Tiket terbuka boleh langsung `Ditutup` oleh tim dengan alasan (duplikat/spam). Catatan internal tim tidak pernah terlihat tenant dan tidak mengubah status/SLA. Lampiran ≤ 3 berkas per pesan, masing-masing ≤ 5 MB (jpg, jpeg, png, webp, pdf, txt, csv), disimpan di disk privat dengan nama acak dan hanya diunduh lewat tiketnya. Email (lewat antrean, setelah commit): tiket baru ke semua anggota Dukungan aktif (bila kosong, Super Admin), balasan tim ke pelapor, balasan/buka ulang pelapor ke penanggung jawab. Tim internal (Dukungan & Super Admin: `dukungan.tiket.lihat`, `dukungan.tiket.tangani`) membaca tiket lintas tenant hanya lewat `KonteksPengelola::JalankanLintasTenant` (tercatat `LogAuditPengelola`); setiap aksi (ambil/tugaskan, balas, catatan internal, ubah status, ubah prioritas, tutup otomatis) tercatat di log audit. Ditunda: tiket via email masuk & WhatsApp. **Selesai v6.22 (D-141):** tombol Bantuan di Aplikasi Kasir & Owner dengan konteks otomatis (outlet, perangkat, versi, outbox tertunda, layar asal, log singkat tersaring), SLA dalam jam kerja dengan hari libur dilewati (tiket Mendesak paket berbayar jam kalender), dan eskalasi L1 → L2 → Teknis otomatis maupun manual. **Akses dukungan berizin dan akses darurat selesai v5.74 (PGL-16 bagian 1, D-132); alat bantu dukungan selesai v5.76 (PGL-16 bagian 2, D-133)**: lihat riwayat versi 5.74 dan 5.76.
