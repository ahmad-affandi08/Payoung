#!/usr/bin/env python3
"""Membuat WebP kecil untuk web dari PNG ilustrasi jenis usaha (D-178).

Urutan: `BuatIlustrasiSektor.py` (SVG) -> `RenderIlustrasi.cjs` (PNG 1024) -> skrip ini.

    pip install pillow
    python3 Spesifikasi/Merek/BuatIlustrasiSektor.py
    (cd Spesifikasi/Merek/IlustrasiSektor/Sumber && NODE_PATH=<node_modules> node ../../RenderIlustrasi.cjs *.svg)
    python3 Spesifikasi/Merek/BuatTurunanIlustrasiSektor.py

Hasil (ditimpa): `Aplikasi/Web/resources/js/Aset/Sektor/{KodeTemplate PascalCase}.webp` (`FNB-CAF` -> `FnbCaf.webp`, sesuai konvensi nama berkas), 320 px persegi, latar transparan.
Dipakai `Komponen/PanduanAwal/KartuSektor.tsx` lewat `Pustaka/TampilanSektor.ts` (tampil 88–112 px CSS).
"""

from pathlib import Path

from PIL import Image

AKAR = Path(__file__).resolve().parents[2]
SUMBER = AKAR / 'Spesifikasi/Merek/IlustrasiSektor/Sumber'
KELUAR = AKAR / 'Aplikasi/Web/resources/js/Aset/Sektor'
UKURAN = 320


def NamaBerkas(kode: str) -> str:
    """`FNB-CAF` -> `FnbCaf` (nama berkas PascalCase, tanpa tanda hubung)."""
    return ''.join(bagian[0] + bagian[1:].lower() for bagian in kode.split('-'))


def Jalankan() -> None:
    KELUAR.mkdir(parents=True, exist_ok=True)
    jumlah = 0
    for png in sorted(SUMBER.glob('*.png')):
        gambar = Image.open(png).convert('RGBA').resize((UKURAN, UKURAN), Image.LANCZOS)
        gambar.save(KELUAR / f'{NamaBerkas(png.stem)}.webp', 'WEBP', quality=88, method=6)
        jumlah += 1
    print(f'{jumlah} ilustrasi sektor ditulis ke {KELUAR}')


if __name__ == '__main__':
    Jalankan()
