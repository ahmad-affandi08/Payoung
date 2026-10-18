# Ilustrasi jenis usaha (D-178)

Satu ilustrasi per kode template sektor (22 buah: `FNB-*`, `RTL-*`, `WHS-DST`, `SVC-*`, `BIZ-*`), dipakai kartu pilih jenis
usaha di panduan awal (`Komponen/PanduanAwal/KartuSektor.tsx`). Gaya dan palet sama dengan ilustrasi keadaan kosong:
blob sage pucat, garis Teal Tinta, isi Slate Teal, aksen Apricot. `SVC-LDR` dan `SVC-WRK` memakai ulang Cucian dan Servis
dari set aplikasi Kasir.

- `Sumber/*.svg`: sumber vektor, dibuat `../BuatIlustrasiSektor.py`. PNG tidak disimpan (bisa dibuat ulang).
- Hasil untuk web: `Aplikasi/Web/resources/js/Aset/Sektor/{Kode PascalCase}.webp` (`FNB-CAF` -> `FnbCaf.webp`; 320 px, transparan),
  dibaca `Pustaka/TampilanSektor.ts` lewat nama berkas.

Membuat ulang:

```bash
python3 Spesifikasi/Merek/BuatIlustrasiSektor.py
(cd Spesifikasi/Merek/IlustrasiSektor/Sumber && NODE_PATH=<node_modules> node ../../RenderIlustrasi.cjs *.svg)   # per berkas bila Chromium crash
python3 Spesifikasi/Merek/BuatTurunanIlustrasiSektor.py
rm Spesifikasi/Merek/IlustrasiSektor/Sumber/*.png
```

Kode template baru tanpa ilustrasi sendiri memakai ilustrasi awalannya (`FNB-RST`, `RTL-GEN`, `WHS-DST`, `SVC-GEN`,
`BIZ-JSA`); tambahkan SVG-nya di `BuatIlustrasiSektor.py` bila ingin yang khusus.
