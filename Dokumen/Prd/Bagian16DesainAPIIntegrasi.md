<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

## 16. Desain API & Integrasi

### 16.1 Tiga Lapisan API

| Lapisan | Prefix | Auth | Konsumen | Versi |
|---|---|---|---|---|
| Internal | `/internal/*` | Sesi + CSRF (Sanctum stateful) | Back-office web & web publik {{APP}} (TanStack Query) | Tidak diversi, berubah bersama frontend |
| POS | `/api/pos/v1/*` | **Device token** (Sanctum, abilities per tipe perangkat) + `X-Id-Kasir` + `Idempotency-Key` + `X-Versi-Aplikasi` + `X-Outbox-Tertunda` (opsional, P-10 v1.82) | Aplikasi Flutter | Berversi URL (`v1`) + versi skema sinkron (`X-Skema-Sinkron`). Wajib kompatibel mundur untuk 2 versi minor aplikasi |
| Owner | `/api/pemilik/v1/*` | **User token** (Sanctum, berumur terbatas + refresh) | Aplikasi Owner Flutter | Berversi URL, kompatibel mundur 2 versi minor aplikasi (§17.3.4) |
| Publik | `/api/v1/*` | Sanctum Personal Access Token dengan scope (`produk:baca`, `penjualan:baca`, `stok:tulis`, ...) | Integrasi pihak ketiga | Semantic, deprecation ≥ 6 bulan |

### 16.2 Konvensi

- Key JSON **PascalCase Bahasa Indonesia, sama dengan nama kolom** (§13.7), tanggal ISO-8601 UTC, uang sebagai **string desimal** (`"15000.00"`) agar tidak kehilangan presisi.
- Pagination berbasis cursor untuk list besar. Filter mengikuti gaya `saring[Status]=Lunas&urut=-DibuatPada&sertakan=Detail` (spatie/laravel-query-builder, nama parameter `filter`/`sort`/`include` diganti lewat `config/query-builder.php`).
- Error format seragam:
  ```json
  { "Galat": { "Kode": "StokTidakCukup", "Pesan": "Stok Kopi Susu tidak cukup", "Detail": { "UuidProduk": "...", "Tersedia": "2.0000" } } }
  ```
- Rate limit per token/tenant (misal 120 req/menit untuk paket standar).

### 16.3 Endpoint API POS (Aplikasi Flutter)

| Method | Endpoint | Fungsi |
|---|---|---|
| POST | `/api/pos/v1/perangkat/aktivasi` | Tukar kode aktivasi → device token, kode perangkat (`Perangkat.Kode`), info outlet. Respons F-02b: `TokenPerangkat`, `Perangkat`, `Outlet`, `Tenant`, `Langganan` |
| GET | `/api/pos/v1/konfigurasi-aplikasi` | Versi terbaru, `min_supported_version`, feature flag remote, konfigurasi outlet |
| POST | `/api/pos/v1/kasir/masuk-pin` | Verifikasi PIN kasir online (`UuidPengguna`, `Pin`) → pengguna & izin; kunci 5 menit setelah 5 kali salah per perangkat + pengguna (F-02b) |
| POST | `/api/pos/v1/kasir/ubah-pin` | Kasir mengganti PIN miliknya, online (`UuidPengguna`, `PinLama`, `PinBaru`); PIN lama dengan batas percobaan yang sama dengan masuk-pin, PIN baru wajib kuat dan berbeda (D-302) |
| GET | `/api/pos/v1/katalog?sejak={Kursor}` | Katalog lengkap atau delta (F-03, throttle 30/menit): amplop `Skema`, `Lengkap`, `Kursor`, `WaktuServer`; bagian Kategori, Satuan, KelompokPajak, Produk (`Dihapus`), ProdukSatuan, ProdukBarcode, DaftarHarga, ProdukHarga, KelompokPilihan, Pilihan, ProdukKelompokPilihan, Resep (+Bahan, versi terbaru), PaketProdukDetail, Terhapus. Kursor base64url dengan tumpang tindih 120 detik; kursor > 90 hari = sinkron lengkap; kursor rusak = 422 `KursorTidakValid`. `data-awal`/`perubahan` kelak menyematkan payload ini di kunci `Katalog` |
| GET | `/api/pos/v1/katalog/gambar/{produk}?ukuran=kecil\|besar&versi=` | Gambar produk (privat, throttle 600/menit) |
| GET | `/api/pos/v1/data-awal` | Paket data awal (dapat berupa file JSON terkompresi gzip untuk katalog besar): produk, harga, modifier, pajak, promo aktif, metode bayar, meja, pengaturan, staf + hash PIN, pelanggan yang sering datang (terbatas). F-06 (v1.35): `Pengaturan {BatasKasKeluar, ShiftBersama}`, `KategoriKas`, `Staf [{Uuid, Nama, Pemilik, Izin, PinDiatur, Pin {Garam, Nonce, Sandi}}]`, `PinOffline {Tersedia, Parameter, BatasSalah, MenitKunci}`; katalog tetap lewat `/katalog` |
| GET | `/api/pos/v1/perubahan?sejak={kursor}` | Delta perubahan master sejak cursor (produk/harga/promo/stok ringkas/86/staf) |
| POST | `/api/pos/v1/sinkron/kirim` | Kirim batch outbox (shift, sale, payment, cash movement, void, retur, approval). Respons per item: `accepted` / `duplicate` / `rejected` + alasan |
| POST | `/api/pos/v1/detak` | Status perangkat, versi app, platform, jumlah outbox tertunda, status printer |
| POST | `/api/pos/v1/token-notifikasi` | Daftarkan/perbarui token FCM perangkat |
| GET | `/api/pos/v1/pelanggan/cari?kata=` | Cari pelanggan di server (online) |
| GET | `/api/pos/v1/pelanggan/{uuidPelanggan}/poin` | Saldo poin terkini & aturan tukar sebelum kasir menukar poin (F-16b, wajib online) |
| GET | `/api/pos/v1/promo` | Promo aktif + mode resolusi konflik untuk disimpan perangkat (F-16c, dievaluasi offline) |
| POST | `/api/pos/v1/qris` · `GET /api/pos/v1/qris/{uuid}` · `POST /api/pos/v1/qris/{uuid}/batal` | Buat QRIS dinamis, cek status, batal (v2.05) |
| POST | `/api/pos/v1/penjualan/{uuidPenjualan}/kirim-struk` · `GET /api/pos/v1/pesan-keluar/{uuid}` | Kirim struk digital lewat WhatsApp/email & status (v2.05) |
| POST | `/api/pos/v1/persetujuan/jarak-jauh` | Minta approval jarak jauh (dikirim ke HP supervisor/owner via push) |
| GET | `/api/pos/v1/kds/tiket?stasiun=&sejak=` | Antrean tiket dapur (mode KDS) |
| POST | `/api/pos/v1/gudang/penerimaan-barang`, `/gudang/transfer-stok`, `/gudang/stok-opname` | Operasi gudang dari aplikasi |

**Kontrak API** didokumentasikan otomatis dalam OpenAPI (`Spesifikasi/OpenApi/PosV1.yaml`, dihasilkan Scramble di CI). Model DTO Dart di-generate/diverifikasi dari spesifikasi tersebut. CI gagal jika kontrak berubah tanpa kenaikan versi. *(v2.32, audit F-13: sebelum Scramble dipasang, gerbang minimum = test `tests/Arsitektur/KontrakApiTes.php` terhadap baseline `Spesifikasi/KontrakApi/RuteApi.json`: rute POS/Pemilik yang sudah dirilis tidak boleh dihapus, diganti metode/jalur, atau diganti nama.)*

### 16.4 Webhook Keluar (X7)

Event: `penjualan.selesai`, `penjualan.divoid`, `penjualan.diretur`, `pembayaran.diterima`, `stok.menipis`, `stok.disesuaikan`, `produk.diubah`, `pelanggan.dibuat`, `pesanan-pembelian.disetujui`, `penerimaan-barang.diposting`, `shift.ditutup`.

- Payload ditandatangani HMAC-SHA256 (`X-Tanda-Tangan`), berisi `IdPeristiwa` unik untuk dedup di sisi penerima.
- Retry eksponensial (1m, 5m, 30m, 2j, 12j), dikirim oleh queue via cron.
- Log pengiriman terlihat oleh tenant, tersedia tombol "kirim ulang".
- *(v3.95)* Endpoint tulis pertama: `POST /api/v1/stok/penyesuaian` (cakupan `stok:tulis`, idempoten per `Uuid`).
- *(v3.91)* Terimplementasi juga `produk.diubah`, `pelanggan.dibuat`, `shift.ditutup`, `stok.disesuaikan`, `pesanan-pembelian.disetujui`, `penerimaan-barang.diposting` (peristiwa umum `PeristiwaIntegrasi` dari domain pemilik dokumen; kiriman unik per kejadian lewat `KunciPeristiwa`).
- *(v4.07)* `pembayaran.diterima` = pelunasan piutang diposting atau uang muka pesanan toko online diterima (pembayaran kasir sudah di `penjualan.selesai`); `stok.menipis` = saldo produk di satu lokasi turun melewati stok minimum (sekali per penurunan, dikirim lagi setelah diisi di atas minimum).
- *(v3.84)* Spesifikasi OpenAPI 3.1 API publik & webhook: `public/pengembang/openapi-v1.json`, portal `/pengembang`; kesesuaiannya dengan rute & respons nyata dijaga `SpesifikasiApiPublikTes`.
- *(v3.83, terimplementasi untuk `penjualan.selesai`, `penjualan.divoid`, `penjualan.diretur`; peristiwa lain menyusul.)* Tanda tangan dihitung atas `{X-Waktu-Kirim}.{badan}` dan dikirim sebagai `X-Tanda-Tangan: sha256=<hex>`; header lain `X-Id-Peristiwa`, `X-Peristiwa`. Alamat wajib HTTPS publik (penjaga SSRF saat dibuat & sebelum tiap kiriman, IP dipin). Setelah percobaan ke-6 gagal status Gagal; log disimpan 30 hari.

### 16.5 Integrasi Pihak Ketiga

| Integrasi | Tujuan | Pola | Fase |
|---|---|---|---|
| Payment Gateway (Midtrans / Xendit / DOKU / sejenis, **abstraksi antarmuka `GerbangPembayaran`**) | QRIS dinamis, VA, e-wallet, kartu, refund, billing SaaS | Create charge → webhook (verifikasi signature) + polling fallback | 2 |
| WhatsApp (WA Business API via BSP resmi; abstraksi `KanalPesan`) | Struk, OTP, pengingat piutang/booking, broadcast (dengan opt-in) | Queue + template pesan | 2 |
| Email (SMTP Hostinger / layanan transaksional) | Verifikasi, invoice, laporan terjadwal | Queue | 1 |
| Firebase Cloud Messaging (FCM, termasuk APNs untuk iOS) | Push ke aplikasi POS: approval jarak jauh, order online/self-order baru, stok kritis, pemicu sinkron | HTTP v1 API dari queue Laravel | 2 |
| Printer thermal | Struk, dapur | Lihat §17.6 | 1 |
| Ojol/Marketplace | Menu, stok, order | Tergantung API mitra. Awalnya input manual per channel | 3–4 |
| Software akuntansi eksternal | Export jurnal | CSV/Excel terformat, API di fase lanjut | 3 |
| Coretax/e-Faktur | Faktur pajak | Export format impor. Integrasi via PJAP di fase 4 | 3–4 |
| Ekspedisi | Ongkir & resi | Agregator ongkir | 4 |
