<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-07 · Siklus Hidup Tenant

**Tujuan:** Tim melihat kondisi setiap tenant secara utuh dan mengambil tindakan yang tepat dan terkendali.
**Aktor:** Dukungan, Mitra & Penjualan, Keuangan, Super Admin.

**Tampilan 360° tenant:** profil usaha, sektor & versi template, paket & add-on, pemakaian vs batas, outlet & perangkat (platform, versi aplikasi, outbox tertunda), riwayat tagihan & pembayaran, tiket dukungan, riwayat akses dukungan, mitra perujuk, catatan internal, dan **skor kesehatan**.

**State Machine `Langganan.Status`** (sama dengan F-00): `Trial → Aktif → Tertunggak → Ditangguhkan → Berhenti`, cabang `Gratis`. Tenant juga bisa diberi penanda `Uji`, `Demo`, atau `Internal` (dikecualikan dari metrik bisnis & tagihan).

**Tindakan pengelola:**

| Tindakan | Peran | Syarat |
|---|---|---|
| Perpanjang trial | Dukungan, Penjualan | Maks 2 kali, alasan wajib |
| Override batas/fitur sementara | Dukungan, Super Admin | Wajib tanggal berakhir & alasan. Berakhir otomatis |
| Ganti paket manual | Keuangan | Proration otomatis |
| Tangguhkan manual | Super Admin | Alasan wajib (penipuan, penyalahgunaan, permintaan hukum). Owner diberi notifikasi |
| Aktifkan kembali | Keuangan, Super Admin | Tagihan lunas atau keputusan tertulis |
| Catatan internal | Semua peran | Tidak terlihat tenant |
| Permintaan penghapusan data (UU PDP) | Super Admin | Lihat langkah di bawah |

**Skor kesehatan** (dihitung harian): hari aktif 14 hari terakhir, tren transaksi, jumlah fitur utama yang dipakai, perangkat yang lama offline, tiket terbuka, tagihan telat. Hasil: **Sehat / Perlu Perhatian / Berisiko**. Skor memicu tugas otomatis, misalnya tenant baru yang belum bertransaksi 48 jam setelah daftar masuk daftar "hubungi untuk bantuan onboarding".

**Langkah permintaan penghapusan data:** verifikasi identitas Owner → tenant mengunduh export lengkap → masa tunggu 14 hari (bisa dibatalkan) → penghapusan data pribadi & anonimisasi (data transaksi dipertahankan sesuai kewajiban retensi pajak dengan identitas dianonimkan) → bukti penghapusan dikirim ke Owner.

**Aturan Bisnis:**
- BR-P07.1 Pengelola tidak pernah menghapus dokumen transaksi tenant.
- BR-P07.2 Transaksi offline yang dibuat sebelum penangguhan tetap diterima saat sinkron.
- BR-P07.3 Semua tindakan pada tabel di atas tercatat di `LogAuditPengelola` dan terlihat di riwayat tenant.

**Rincian Fase 0 (P-07 dasar; diputuskan agen atas mandat pemilik produk, menunggu konfirmasi):**
- BR-P07.4 **Tangguhkan manual** hanya Super Admin, dari status `Trial`, `Aktif`, `Tertunggak`, atau `Gratis` (melengkapi BR-00.7: transisi Trial/Aktif/Gratis → Ditangguhkan). Wajib kategori (`Penipuan`, `Penyalahgunaan`, `PermintaanHukum`, `Lainnya`) dan catatan. Status sebelum penangguhan disimpan di `Langganan.StatusSebelumDitangguhkan`. Owner menerima email berisi kategori saja, catatan tetap internal; kegagalan kirim email tidak membatalkan penangguhan.
- BR-P07.5 **Aktifkan kembali** oleh Keuangan atau Super Admin dengan keputusan tertulis (alasan wajib). Status yang dipulihkan = status sebelum penangguhan (transisi Ditangguhkan → Trial/Tertunggak ditambahkan); trial yang habis selama ditangguhkan turun ke paket Gratis (BR-00.3); penangguhan tanpa status asal (dari penagihan P-08) dipulihkan ke `Aktif`. Syarat "tagihan lunas" diperiksa otomatis (v1.23): untuk status berbayar, periode masih berjalan → `Aktif`; periode habis tetapi masih dalam masa tenggang → `Tertunggak`; lewat masa tenggang → penangguhan karena tunggakan **tidak bisa** diaktifkan lewat tombol (jalannya pembayaran diterima, BR-P08.9), sedangkan penangguhan manual dicabut menjadi penangguhan karena tunggakan (tetap `Ditangguhkan`, tanpa email "aktif kembali"). `StatusSebelumDitangguhkan` hanya terisi selama penangguhan manual dan dikosongkan otomatis begitu langganan keluar dari `Ditangguhkan`.
- BR-P07.6 **Perpanjang trial** oleh Dukungan, Mitra & Penjualan, atau Super Admin: hanya saat status `Trial`, 1–14 hari per perpanjangan, maksimal 2 kali per tenant. Akhir trial baru dihitung dari akhir trial saat ini (atau dari sekarang bila sudah lewat tetapi belum diproses perintah akhir trial). Setiap perpanjangan dicatat sebagai `OverrideTenant` jenis `Trial`. Tenant yang sudah turun ke Gratis tidak bisa dikembalikan ke Trial.
- BR-P07.7 **Override sementara** oleh Dukungan atau Super Admin: jenis `Batas` (kolom batas paket, angka ≥ 0) atau `Fitur` (kunci fitur katalog), berlaku sampai akhir tanggal pilihan (WIB), paling lama 90 hari. Satu kunci hanya satu override aktif; override bisa dicabut lebih awal dengan alasan. Override yang lewat diabaikan otomatis oleh evaluator fitur (P-04).
- BR-P07.8 **Penanda** `Uji`/`Demo`/`Internal` hanya diubah Super Admin dengan alasan, disimpan di `Tenant.Penanda`.
- BR-P07.9 **Catatan internal** append-only, ditulis semua peran yang boleh melihat tenant (Super Admin, Keuangan, Dukungan, Teknis, Mitra & Penjualan). Konten & Legal dan Analis tidak membuka menu tenant (§19.3).
- BR-P07.10 Data usaha tenant (outlet, gudang, merek) di tampilan 360° dibaca lewat `KonteksPengelola::JalankanLintasTenant`, yang mencatat setiap pembukaan (`tenant.data.akses`) di `LogAuditPengelola`. Log akses baca tidak ditampilkan di riwayat tindakan tenant.
- BR-P07.11 **Penghapusan data dan penutupan akun** (UU PDP, v5.59): hanya edisi SaaS. Owner mengajukan setelah mengunduh ekspor data, dengan kata sandi dan frasa konfirmasi; paling cepat dilaksanakan setelah 14 hari dan bisa dibatalkan Owner sampai saat itu. Hanya Super Admin (izin `tenant.penghapusan-data.kelola`, 2FA baru) yang melaksanakan atau menolak, dengan catatan wajib; slug tenant diketik ulang. Pelaksanaan menghapus data pribadi, kredensial, biometrik, dan berkas pribadi, serta menganonimkan identitas pada data yang dipertahankan; dokumen transaksi dan pembukuan tidak pernah dihapus (BR-P07.1) dan lampiran pembukuan dihapus setelah 10 tahun. Tenant menjadi `Ditutup` (final) dan Owner menerima bukti lewat email.
- Tampilan 360° Fase 0: profil, paket & status langganan, pemakaian vs batas (outlet, pengguna), outlet & gudang, anggota, persetujuan legal, override, catatan, dan riwayat tindakan. Tagihan (P-08), tiket & akses dukungan (P-09), perangkat, mitra (P-12), skor kesehatan, ganti paket manual (butuh proration P-08), dan penghapusan data UU PDP menyusul.
