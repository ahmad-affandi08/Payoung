#!/usr/bin/env python3
"""Mengubah tangkapan layar asli aplikasi Payoung menjadi WebP untuk situs pemasaran (`payoung.id`).

Sumbernya keluaran test widget yang merender layar **asli** aplikasi dengan data contoh
(`Aplikasi/Kasir/AlatSitus/FotoPlayStore_test.dart`, `FotoSitusSektor_test.dart` & `Aplikasi/Pemilik/AlatSitus/FotoPlayStore_test.dart`,
hasilnya di `AlatSitus/Hasil/PlayStore/`, tidak masuk Git). Tidak ada gambar karangan atau mockup pesaing di sini.

Pakai:  python3 Spesifikasi/Merek/BuatFotoSitus.py

Tablet disimpan 1600 x 1000 px, ponsel 540 x 1080 px; WebP kualitas 80 agar tiap berkas tetap di bawah ~150 KB.
Berkas hasil di `Aplikasi/Web/resources/js/Aset/Situs/` dirujuk `Komponen/Situs/SpesimenSitus.tsx`.
"""
from pathlib import Path

from PIL import Image

AKAR = Path(__file__).resolve().parents[2]
KASIR = AKAR / 'Aplikasi/Kasir/AlatSitus/Hasil/PlayStore'
SEKTOR = AKAR / 'Aplikasi/Kasir/AlatSitus/Hasil/Sektor'
PEMILIK = AKAR / 'Aplikasi/Pemilik/AlatSitus/Hasil/PlayStore'
TUJUAN = AKAR / 'Aplikasi/Web/resources/js/Aset/Situs'

TABLET = (1600, 1000)
PONSEL = (540, 1080)

DAFTAR = {
    'KasirJual': (KASIR / 'Tablet1Jual.png', TABLET),
    'KasirBayar': (KASIR / 'Tablet2Bayar.png', TABLET),
    'KasirBerhasil': (KASIR / 'Tablet3Berhasil.png', TABLET),
    'KasirShift': (KASIR / 'Tablet4Shift.png', TABLET),
    'KasirRiwayat': (KASIR / 'Tablet5Riwayat.png', TABLET),
    'KasirKas': (KASIR / 'Tablet6Kas.png', TABLET),
    # D-212: layar Jual per sektor usaha dengan gambar produk (`FotoSitusSektor_test.dart`).
    'KasirSektorKafe': (SEKTOR / 'Kafe.png', TABLET),
    'KasirSektorToko': (SEKTOR / 'Toko.png', TABLET),
    'KasirSektorSalon': (SEKTOR / 'Salon.png', TABLET),
    'KasirSektorLaundry': (SEKTOR / 'Laundry.png', TABLET),
    'KasirSektorApotek': (SEKTOR / 'Apotek.png', TABLET),
    'KasirSektorBengkel': (SEKTOR / 'Bengkel.png', TABLET),
    'KasirHpJual': (KASIR / 'Hp1Jual.png', PONSEL),
    'PemilikBeranda': (PEMILIK / 'PemilikHp1Beranda.png', PONSEL),
    'PemilikLaporan': (PEMILIK / 'PemilikHp2Laporan.png', PONSEL),
    'PemilikPersetujuan': (PEMILIK / 'PemilikHp3Persetujuan.png', PONSEL),
    'PemilikShift': (PEMILIK / 'PemilikHp4Shift.png', PONSEL),
    'PemilikKaryawan': (PEMILIK / 'PemilikHp5Karyawan.png', PONSEL),
    'PemilikInsight': (PEMILIK / 'PemilikHp6Insight.png', PONSEL),
}


def main() -> None:
    TUJUAN.mkdir(parents=True, exist_ok=True)
    for nama, (sumber, ukuran) in DAFTAR.items():
        if not sumber.exists():
            print(f'LEWATI {nama}: {sumber} belum dirender (jalankan test AlatSitus di Aplikasi/Kasir & Aplikasi/Pemilik).')
            continue
        gambar = Image.open(sumber).convert('RGB').resize(ukuran, Image.LANCZOS)
        berkas = TUJUAN / f'{nama}.webp'
        gambar.save(berkas, 'WEBP', quality=80, method=6)
        print(f'{berkas.name}: {ukuran[0]}x{ukuran[1]}, {berkas.stat().st_size // 1024} KB')


if __name__ == '__main__':
    main()
