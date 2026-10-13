<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-03 · Template Sektor

**Tujuan:** Setiap sektor usaha (§5.1) punya paket konfigurasi siap pakai yang dipelihara terpusat dan berversi.
**Aktor:** Konten & Legal (isi bisnis), Keuangan (COA & pemetaan akun), Teknis (validasi).
**Pemicu:** Persiapan awal (3 template MVP: RTL-GEN, FNB-CAF, FNB-QSR), penambahan sektor baru, perbaikan template.

**Isi satu template:**
- Modul/fitur aktif (kunci fitur) dan **mode kasir** default
- **COA** (inti + ekstensi sektor) dan **pemetaan akun** untuk semua jenis peristiwa (§11.3)
- Kategori contoh, produk contoh (opsional), satuan, kelompok pajak default
- Pengaturan default: pembulatan, service charge, stok boleh minus, metode HPP
- Stasiun dapur default (F&B), alasan void/penyesuaian default, laporan unggulan di dasbor

**Langkah:**
1. Buat template baru atau **duplikasi** versi terbit untuk membuat versi baru (status `Draf`).
2. Ubah isi melalui editor terstruktur (bukan JSON mentah).
3. **Validasi otomatis:** COA seimbang & tanpa kode ganda, setiap kunci `PemetaanAkun` terisi, kelompok pajak merujuk tarif yang ada di P-02, fitur yang diaktifkan ada di katalog P-04.
4. **Pratinjau sandbox:** sistem membuat tenant uji sementara, menjalankan onboarding F-01 dengan template ini, dan membuat beberapa transaksi contoh untuk memeriksa jurnal & laporan.
5. Terbitkan. Versi sebelumnya menjadi `Usang` untuk tenant baru.
6. (Opsional) **Tawarkan pembaruan** ke tenant yang memakai versi lama. Tenant memilih "Terapkan", dan penerapannya bersifat aditif (BR-01.1).

**State Machine `TemplateSektorVersi.Status`:** `Draf → Terbit → Usang`.

**Aturan Bisnis:**
- BR-P03.1 Tenant menyimpan versi template yang diterapkan. Versi baru **tidak pernah** mengubah data tenant tanpa persetujuan tenant.
- BR-P03.2 Versi yang sedang dipakai tenant tidak bisa dihapus.
- BR-P03.3 Template tidak bisa terbit jika validasi otomatis gagal. Validasi dijalankan ulang saat terbit (data P-02/P-04 bisa berubah sejak draf divalidasi). Aturannya:
  - **COA:** minimal satu akun, kode unik berformat `d-dddd`, digit pertama sesuai tipe (1 Aset, 2 Kewajiban, 3 Ekuitas, 4 Pendapatan, 5 HPP, 6 Beban), dan saldo normal konsisten dengan tipe (Aset/HPP/Beban = Debit, lainnya = Kredit; akun kontra kebalikannya). Inilah arti "COA seimbang" untuk template; keseimbangan Σ debit = Σ kredit diuji pada jurnal (F-13).
  - **Pemetaan akun:** setiap peran akun §11.3 (`PeranAkun`) terisi, merujuk akun yang ada di COA template, dengan tipe yang sesuai perannya.
  - **Kelompok pajak:** merujuk `JenisPajak` P-02 yang ada. Jenis pajak nasional wajib punya minimal satu tarif terbit yang masih berlaku; jenis pajak daerah cukup ada, karena tarifnya dipilih per kota outlet saat F-02. Urutan pajak 1–9 tidak ganda. Dasar pengenaan `SubtotalPlusLayanan` hanya boleh bila pengaturan `BiayaLayananMasukDpp` aktif.
  - **Fitur & satuan:** kunci fitur ada di katalog P-04, kode satuan ada dan aktif di `SatuanStandar`.
  - **Mode kasir & pengaturan:** minimal satu mode kasir dan mode default termasuk di dalamnya; `PembulatanTunai.Kelipatan` bilangan bulat > 0; persen service charge 0–10; daftar berisi teks saja; nama kategori, stasiun dapur, dan alasan tidak ganda.
- BR-P03.4 Satu template hanya punya **satu draf** pada satu waktu. Versi `Terbit` dan `Usang` tidak diubah (koreksi = duplikasi menjadi draf versi baru). Hanya draf yang boleh dihapus; versi terbit/usang tidak pernah dihapus (memenuhi BR-P03.2 tanpa perlu menghitung pemakaian tenant).
- BR-P03.5 Pembagian tugas (§19.3): Konten & Legal mengubah isi bisnis (fitur, mode kasir, kategori, satuan, pengaturan, stasiun dapur, alasan, laporan unggulan); Keuangan mengubah COA, pemetaan akun, dan kelompok pajak; Teknis/Super Admin menerbitkan. Semua peran bisa melihat. Setiap perubahan dicatat di log audit.
- BR-P03.6 Pratinjau sandbox (langkah 4), tawarkan pembaruan ke tenant (langkah 6), pencatatan versi yang diterapkan tenant (BR-P03.1), dan produk contoh dibangun bersama F-01 karena membutuhkan data tenant. **Terpenuhi sejak v6.04:** pratinjau sandbox = `UjiCobaTemplateSektor` (tombol "Uji coba di tenant sementara" di editor template), tawaran pembaruan = `PembaruanTemplateSektor` + Kotak Tindakan `template.pembaruan` + Pengaturan › Pembaruan template usaha.
