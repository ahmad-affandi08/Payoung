<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-10 · Rilis Aplikasi & Flag Fitur

**Tujuan:** Versi baru Aplikasi POS dan Aplikasi Owner sampai ke pengguna dengan aman, bertahap, dan bisa dihentikan bila bermasalah.
**Aktor:** Teknis, Super Admin.

**Langkah rilis:**
1. CI mengunggah build dan membuat catatan `RilisAplikasi` (status `Draf`).
2. Uji internal di kanal `Beta` (perangkat lab & tenant uji).
3. **Rollout bertahap** 10% → 50% → 100% (Play Store staged rollout. Windows lewat `RilisAplikasi.PersenRollout`).
4. Pantau crash-free sessions, galat sinkron, dan tiket terkait versi.
5. Bila bermasalah: **hentikan rollout** dan matikan fitur bermasalah lewat flag (*kill switch*) tanpa menunggu review store.
6. Naikkan `VersiMinimum` bila diperlukan (§14.6).

**Flag fitur:** cakupan global, per paket, per tenant, atau persentase tenant. Dipakai untuk peluncuran bertahap fitur baru dan kill switch.

**Pengumuman & pemeliharaan:** banner in-app per segmen (paket, sektor, platform, versi), jadwal pemeliharaan, catatan rilis ("Yang baru").

**Aturan Bisnis:**
- BR-P10.1 Menaikkan `VersiMinimum` wajib diumumkan ≥ 7 hari sebelumnya, kecuali perbaikan keamanan.
- BR-P10.2 Sebelum menaikkan `VersiMinimum`, sistem menampilkan jumlah perangkat di versi lama yang masih punya outbox tertunda. Perangkat tersebut tetap diizinkan mengirim outbox (§14.6).
- BR-P10.3 Setiap perubahan flag produksi tercatat di audit, dengan alasan.

**Rincian P-10 (v1.82; rincian diputuskan agen atas mandat D-12):**
- **Rilis aplikasi** `/rilis` di Platform Pengelola (lihat `rilis.lihat`, ubah `rilis.kelola`; peran Teknis & Super Admin). Model platform `RilisAplikasi` (tanpa `MilikTenant`, di `Domain/Tenant`, aksi di `Domain/Pengelola/Rilis`). Langkah: catat draf (versi `MAJOR.MINOR.PATCH`, unik per aplikasi/platform/kanal; draf bisa diubah, rilis terbit tidak) → terbitkan: kanal **Beta** langsung ke semua perangkat tenant berpenanda Uji/Internal, kanal **Stabil** ke perangkat yang embernya (`crc32(Perangkat.Uuid) mod 100`) di bawah `PersenRollout` → ubah persen bertahap → **hentikan** (alasan wajib; perangkat yang belum memasang tidak ditawari lagi). Pencatatan otomatis dari CI belum ada; draf dicatat manual di halaman ini. Audit `rilis.draf.simpan`, `rilis.terbit`, `rilis.rollout.ubah`, `rilis.hentikan`.
- **Versi minimum:** diambil dari rilis Stabil yang aktif (menu "Jadikan versi minimum"), berlaku mulai tanggal yang dipilih. BR-P10.1: paling cepat 7 hari dari hari ini (WIB), kecuali ditandai perbaikan keamanan (boleh hari ini, berlaku segera). BR-P10.2: dialog menampilkan jumlah perangkat aktif di platform itu yang masih di bawah versi tersebut dan berapa yang masih punya outbox tertunda; angka yang sama dicatat di audit `rilis.versi-minimum.atur`. Versi minimum yang belum berlaku bisa dibatalkan (`rilis.versi-minimum.batal`). Versi minimal efektif = `VersiMinimum` tertinggi yang sudah berlaku; rilis yang kemudian dihentikan tetap mempertahankan versi minimum yang sudah berlaku.
- **`konfigurasi-aplikasi`** kini menghitung `VersiTerbaru`/`VersiMinimal`/`TautanUnduh` per perangkat dari `RilisAplikasi` (cadangan `config/aplikasi.php` bila belum ada rilis), menambah `Aplikasi.CatatanRilis`, dan mengisi `FlagFitur` (kunci → hidup/mati) untuk tenant perangkat. Perubahan aditif, kompatibel mundur.
- **Header `X-Outbox-Tertunda`** (baru, opsional): aplikasi POS mengirim jumlah outbox tertunda di setiap permintaan ber-token; server menyimpannya di `Perangkat.JumlahOutboxTertunda` (header absen = nilai lama dipertahankan). Perangkat di bawah versi minimal tetap dilayani API dan boleh mengirim outbox.
- **Flag fitur** `/flag-fitur` (lihat `rilis.lihat`, ubah `flag-fitur.kelola`): aturan per (kunci, cakupan, objek). Kunci berformat D-06, boleh kunci katalog fitur maupun flag aplikasi. Evaluasi per tenant: **Global mati = kill switch** (mengalahkan semua aturan) → aturan Tenant → aturan Paket (paket langganan) → Persentase (hidup bila `crc32(kunci|IdTenant) mod 100` < Persen, selain itu mati) → Global hidup; kunci tanpa aturan tidak dikirim dan dianggap hidup. `EvaluatorFitur` memakai hasil ini (fitur paket yang flag-nya mati = tidak aktif). Setiap simpan/hapus wajib alasan 10–500 karakter dan diaudit (`flag-fitur.simpan`, `flag-fitur.hapus`, BR-P10.3).
- **Aplikasi kasir:** membaca `konfigurasi-aplikasi` saat data disegarkan dan setelah putaran sinkron yang tersambung (paling sering tiap 15 menit). Versi baru → bilah status "Versi X tersedia". Di bawah versi minimal → bilah status "Wajib perbarui aplikasi" dan layar Jual & Meja diganti panel "Perbarui aplikasi" (versi tujuan, tautan unduh yang bisa disalin, catatan "Yang baru", jumlah transaksi belum terkirim dengan anjuran menunggu sampai terkirim sebelum memperbarui); sinkron outbox tetap berjalan. Flag fitur dibaca aplikasi lewat `KonfigurasiAplikasi.CekFlag` dan dihormati sebagai kill switch (v6.20, D-139): `kasir.harga-manual`, `kasir.retur-tanpa-struk`, `kasir.pindai-kamera`, `pemilik.insight`, `pemilik.karyawan`, `pemilik.persetujuan-dokumen`. Aplikasi Owner juga membaca versi terbaru/minimal lewat `GET /api/pemilik/v1/konfigurasi-aplikasi` dan menampilkan layar wajib perbarui.
- **v3.45:** pengumuman & banner pemeliharaan per segmen (PGL-19) di back-office & kasir, dikelola di tab Pengumuman konsol.
- **v3.47:** pengumuman yang sama tampil di Beranda Aplikasi Pemilik (`GET /api/pemilik/v1/pengumuman`, platform sasaran `Pemilik`).
- **Belum:** pemantauan crash-free sessions per versi (langkah 4), pencatatan rilis otomatis dari CI, pembaruan dalam aplikasi (Play in-app update) dan pemasangan installer Windows.
