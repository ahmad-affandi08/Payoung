# Video tutorial Payoung

Motion graphic HTML untuk video "Cara menggunakan". Rencana lengkap per fitur ada di
`RencanaVideoTutorial.md`; folder ini berisi motion yang sudah dibuat.

Berbeda dengan `../VideoPromosi/` (menjual), tutorial **mengajarkan**: akurat terhadap aplikasi
dan aturan bisnis, tenang, dan bisa diikuti sambil mengerjakan.

## Yang sudah ada

| Berkas | Tutorial | Flow | Durasi |
|---|---|---|---|
| `CaraDaftar30Detik.html` | T-MUL-01 · Cara daftar akun Payoung (potongan pendek) | F-00 | 30 dtk |

`CaraDaftar30Detik.html` adalah potongan pendek untuk media sosial & halaman harga.
Versi penuh 90 detik di `RencanaVideoTutorial.md` (§7.1) belum dibuat.

### Alur cerita `CaraDaftar30Detik.html`

| Detik | Langkah | Isi |
|---|---|---|
| 0–3,4 | Pembuka | Judul, "gratis, tanpa kartu kredit", ± 1 menit |
| 3,4–7,85 | 1 · Buka payoung.id | Situs pemasaran, klik **Daftar Gratis** → `dashboard.payoung.id/daftar` (D-20) |
| 7,85–14,6 | 2 · Isi data Anda | Nama Anda, Nama toko/usaha, Email, Nomor WhatsApp |
| 14,6–19,4 | 3 · Kata sandi & paket | Kata sandi, Ulangi kata sandi, "Pro (trial 14 hari)" |
| 19,4–23,6 | 4 · Setujui & daftar | Centang S&K + Kebijakan Privasi + Perjanjian Pemrosesan Data, CAPTCHA, tombol **Daftar** |
| 23,6–27,6 | 5 · Cek berhasil | Tenant, Outlet Utama + gudang, peran Owner, Trial 14 hari → Panduan Awal; banner verifikasi email |
| 27,6–30 | Penutup | Logo + `payoung.id` |

### Kesetiaan pada aplikasi

Urutan dan label bidang diambil dari `Aplikasi/Web/resources/js/Halaman/Autentikasi/Daftar.tsx`.
Aturan yang diperagakan (bukan hanya disebut):

- **BR-00.5** — tombol Daftar **langsung** membentuk tenant dan masuk sebagai Owner; email verifikasi
  dikirim bersamaan dan banner pengingat tampil. Tautan berlaku 24 jam. Video tidak menyuruh
  penonton "menunggu email dulu baru bisa masuk", karena bukan itu yang terjadi.
- **F-00 langkah 3–4 + AC** — Tenant, Pengguna (Owner), outlet "Outlet Utama", gudang default, dan
  Langganan berstatus Trial terbentuk, lalu diarahkan ke Panduan Awal (F-01, D-24).
- **BR-00.3** — trial tanpa kartu kredit; setelah trial habis tenant turun ke paket Gratis, bukan dihapus.
- **BR-00.4** — CAPTCHA (Cloudflare Turnstile) pada formulir.
- **BR-00.11 / D-57** — pilihan "Daftar dengan Google".
- **BR-P06.2** — persetujuan S&K, Kebijakan Privasi, dan Perjanjian Pemrosesan Data.

Lama trial ditampilkan "Pro (trial 14 hari)" mengikuti `Paket.MasaTrialHari` (BR-00.6); kalau paket
bawaan registrasi berubah, perbarui teksnya di adegan langkah 3.

## Suara

Disintesis di halaman dengan Web Audio (tanpa file audio), sekeluarga dengan video promosi:
pad C–G–Am–F 100 bpm, ketukan hanya selama pengisian formulir, bunyi ketikan tidak rata,
denting per bidang selesai, riser sebelum hasil, dan akor penutup yang memudar.

## Memutar

Buka `CaraDaftar30Detik.html` di peramban, lalu klik **Putar dengan suara**.
Spasi = putar/jeda, R = ulang, M = bisu, H = sembunyikan kontrol, panah kiri/kanan = geser 1 detik.

## Merekam ke MP4

Font dibaca dari `../VideoPromosi/Huruf/` dan logo dari `../Sumber/`, jadi rekaman tidak butuh internet.

```bash
npm i -g playwright                 # sekali, jika belum ada
pip install imageio-ffmpeg          # ffmpeg penuh (butuh libx264 + aac)
export FFMPEG=$(python3 -c "import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())")
NODE_PATH=$(npm root -g) node Spesifikasi/Merek/VideoTutorial/RekamVideo.mjs CaraDaftar30Detik.html
```

Argumen: `[berkas.html] [keluaran.mp4] [fps]`. Bawaan: `CaraDaftar30Detik.html` → `CaraDaftar30Detik.mp4`, 30 fps.
Hasilnya 1920×1080, H.264 + AAC, audio dinormalisasi ke −16 LUFS.

> ffmpeg bawaan Playwright (`/opt/pw-browsers/ffmpeg-*`) **tidak bisa dipakai**: build minimal itu
> hanya punya VP8, tanpa libx264 maupun AAC.

Hasil MP4 tidak disimpan di repo; buat ulang dengan perintah di atas.
