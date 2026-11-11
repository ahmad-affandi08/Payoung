#!/usr/bin/env python3
"""Aset listing Google Play untuk Payoung POS dan Payoung Owner.

Bahan:
- Tanda merek & logo dari `Spesifikasi/Merek/Sumber/`.
- Tangkapan layar asli dari widget Flutter:
    cd Aplikasi/Kasir   && flutter test AlatSitus/FotoPlayStore_test.dart
    cd Aplikasi/Pemilik && flutter test AlatSitus/FotoPlayStore_test.dart
  (hasilnya di `AlatSitus/Hasil/PlayStore/`, tidak masuk Git).

Keluaran di `Spesifikasi/Merek/PlayStore/{Kasir,Pemilik}/`:
- `Ikon512.png` (32-bit PNG, 512×512) — sama dengan ikon peluncur.
- `GrafikFitur.png` (1024×500, tanpa alfa).
- `Hp/*.jpg` 1080×2160, `Tablet7/*.jpg` 1920×1200, `Tablet10/*.jpg` 2560×1600 (khusus Kasir).

Jalankan: python3 Spesifikasi/Merek/PlayStore/BuatAsetPlayStore.py
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

AKAR = Path(__file__).resolve().parents[3]
SUMBER = AKAR / "Spesifikasi/Merek/Sumber"
KELUARAN = Path(__file__).resolve().parent
FONT = AKAR / "Paket/SistemDesain/assets/fonts/Lato-Bold.ttf"
FOTO_KASIR = AKAR / "Aplikasi/Kasir/AlatSitus/Hasil/PlayStore"
FOTO_PEMILIK = AKAR / "Aplikasi/Pemilik/AlatSitus/Hasil/PlayStore"

# Palet merek Payoung D-61 (Spesifikasi/Merek/README.md): Brand, BrandGelap, TeksUtama, Aksen Apricot.
BRAND = (59, 91, 93)
BRAND_GELAP = (34, 56, 58)
NAVY = (31, 51, 53)
KUNING = (244, 162, 97)
PUTIH = (255, 255, 255)

# (berkas sumber, judul, keterangan) per tangkapan layar, urut tampil di Play.
KASIR_TABLET = [
    ("Tablet1Jual", "Jual cepat, tetap jalan saat offline", "Katalog per kategori, pindai barcode, keranjang yang rapi"),
    ("Tablet2Bayar", "Bayar tunai cukup dua ketukan", "Tunai, QRIS, kartu, tempo, deposit, atau bagi tagihan"),
    ("Tablet3Berhasil", "Struk langsung tercetak atau terkirim", "Printer Bluetooth, USB, LAN, atau struk digital WhatsApp"),
    ("Tablet4Shift", "Shift yang jelas dari buka sampai tutup", "Omzet, metode bayar, produk terlaris, dan penjualan per jam"),
    ("Tablet5Riwayat", "Riwayat lengkap, retur dalam sekali sentuh", "Cari per nomor atau nominal, cetak ulang, void, tukar barang"),
    ("Tablet6Kas", "Kas laci selalu cocok", "Kas masuk & keluar tercatat, hitung pecahan saat tutup shift"),
]
KASIR_HP = [
    ("Hp1Jual", "Kasir di genggaman", "Jual dari HP Android, tetap jalan saat offline"),
    ("Hp2Shift", "Ringkasan shift real-time", "Omzet, metode bayar, dan terlaris dalam satu layar"),
    ("Hp3Riwayat", "Riwayat & retur dari HP", "Cari transaksi, cetak ulang, void dengan PIN"),
]
PEMILIK_HP = [
    ("PemilikHp1Beranda", "Pantau toko dari mana saja", "Omzet hari ini semua outlet, langsung dari HP"),
    ("PemilikHp2Laporan", "Laporan yang mudah dibaca", "Penjualan per jam, per outlet, dan produk terlaris"),
    ("PemilikHp3Persetujuan", "Setujui dari jauh", "Diskon, void, dan tempo kasir minta izin ke HP Anda"),
    ("PemilikHp4Shift", "Shift & selisih kas", "Lihat shift yang buka dan hasil tutup kasir"),
    ("PemilikHp5Karyawan", "Kehadiran karyawan", "Siapa yang sudah masuk, komisi, dan target bulan ini"),
    ("PemilikHp6Insight", "Insight mingguan", "Saran restock dan tren penjualan setiap Senin"),
]


def Font(ukuran: int, tebal: str = "Bold") -> ImageFont.FreeTypeFont:
    font = ImageFont.truetype(str(FONT), ukuran)
    font.set_variation_by_name(tebal)
    return font


def Gradasi(lebar: int, tinggi: int) -> Image.Image:
    """Latar diagonal BrandGelap → Brand dengan dua lingkaran halus."""
    kecil = Image.new("RGB", (64, 64))
    piksel = kecil.load()
    for y in range(64):
        for x in range(64):
            t = (x + y) / 126
            piksel[x, y] = tuple(round(a + (b - a) * t) for a, b in zip(BRAND_GELAP, BRAND))
    latar = kecil.resize((lebar, tinggi), Image.BICUBIC).convert("RGBA")
    hiasan = Image.new("RGBA", (lebar, tinggi), (0, 0, 0, 0))
    gambar = ImageDraw.Draw(hiasan)
    r = max(lebar, tinggi) // 2
    gambar.ellipse((lebar - r, -r // 2, lebar + r, r + r // 2), fill=(255, 255, 255, 22))
    gambar.ellipse((-r // 2, tinggi - r, r, tinggi + r // 2), fill=(255, 255, 255, 14))
    latar.alpha_composite(hiasan.filter(ImageFilter.GaussianBlur(max(lebar, tinggi) // 60)))
    return latar


def UbahLebar(gambar: Image.Image, lebar: int) -> Image.Image:
    return gambar.resize((lebar, round(gambar.height * lebar / gambar.width)), Image.LANCZOS)


def UbahTinggi(gambar: Image.Image, tinggi: int) -> Image.Image:
    return gambar.resize((round(gambar.width * tinggi / gambar.height), tinggi), Image.LANCZOS)


def Potong(gambar: Image.Image) -> Image.Image:
    return gambar.crop(gambar.getbbox())


def SudutBulat(gambar: Image.Image, jari: int) -> Image.Image:
    topeng = Image.new("L", gambar.size, 0)
    ImageDraw.Draw(topeng).rounded_rectangle((0, 0, gambar.width - 1, gambar.height - 1), radius=jari, fill=255)
    hasil = gambar.convert("RGBA")
    hasil.putalpha(topeng)
    return hasil


def Perangkat(layar: Image.Image, bezel: int, jari: int) -> Image.Image:
    """Bingkai perangkat sederhana (bezel navy) dengan bayangan lembut."""
    lebar, tinggi = layar.width + 2 * bezel, layar.height + 2 * bezel
    pad = bezel * 3
    hasil = Image.new("RGBA", (lebar + 2 * pad, tinggi + 2 * pad), (0, 0, 0, 0))
    bayangan = Image.new("RGBA", hasil.size, (0, 0, 0, 0))
    ImageDraw.Draw(bayangan).rounded_rectangle(
        (pad, pad + bezel, pad + lebar, pad + tinggi + bezel), radius=jari + bezel, fill=(8, 12, 60, 120)
    )
    hasil.alpha_composite(bayangan.filter(ImageFilter.GaussianBlur(bezel * 1.6)))
    ImageDraw.Draw(hasil).rounded_rectangle((pad, pad, pad + lebar, pad + tinggi), radius=jari + bezel, fill=NAVY)
    hasil.alpha_composite(SudutBulat(layar, jari), (pad + bezel, pad + bezel))
    return hasil


def TulisTengah(gambar: ImageDraw.ImageDraw, lebar: int, y: int, teks: str, font, warna) -> int:
    kotak = gambar.textbbox((0, 0), teks, font=font)
    gambar.text(((lebar - (kotak[2] - kotak[0])) // 2 - kotak[0], y - kotak[1]), teks, font=font, fill=warna)
    return y + (kotak[3] - kotak[1])


def BungkusTeks(teks: str, font, lebar_maks: int) -> list[str]:
    baris, kini = [], ""
    for kata in teks.split():
        coba = f"{kini} {kata}".strip()
        if font.getlength(coba) <= lebar_maks:
            kini = coba
        else:
            baris.append(kini)
            kini = kata
    return baris + [kini]


def TangkapanBerjudul(foto: Path, judul: str, keterangan: str, ukuran: tuple[int, int]) -> Image.Image:
    """Tangkapan layar asli di bingkai perangkat dengan judul di atasnya."""
    lebar, tinggi = ukuran
    kanvas = Gradasi(lebar, tinggi)
    gambar = ImageDraw.Draw(kanvas)
    potret = tinggi > lebar
    satuan = lebar if potret else tinggi
    font_judul, font_ket = Font(round(satuan * 0.068)), Font(round(satuan * 0.036), "Regular")
    y = round(tinggi * (0.045 if potret else 0.05))
    for baris in BungkusTeks(judul, font_judul, round(lebar * 0.88)):
        y = TulisTengah(gambar, lebar, y, baris, font_judul, PUTIH) + round(satuan * 0.016)
    y += round(satuan * 0.012)
    for baris in BungkusTeks(keterangan, font_ket, round(lebar * 0.86)):
        y = TulisTengah(gambar, lebar, y, baris, font_ket, (224, 228, 255)) + round(satuan * 0.012)
    y += round(satuan * 0.04)

    layar = Image.open(foto).convert("RGB")
    bezel = round(satuan * 0.018)
    ruang_tinggi = tinggi - y + (0 if potret else -round(satuan * 0.04))
    if potret:
        # Ponsel "masuk" dari bawah: lebar 80%, sisa bawah terpotong tepi kanvas.
        layar = UbahLebar(layar, round(lebar * 0.80))
        bingkai = Perangkat(layar, bezel, round(bezel * 2.4))
    else:
        layar = UbahTinggi(layar, ruang_tinggi - 2 * bezel)
        if layar.width > lebar * 0.9:
            layar = UbahLebar(layar, round(lebar * 0.9))
        bingkai = Perangkat(layar, bezel, round(bezel * 1.2))
    pad = bezel * 3
    kanvas.alpha_composite(bingkai, ((lebar - bingkai.width) // 2, y - pad))
    return kanvas.convert("RGB")


def Ikon512() -> Image.Image:
    tanda = Potong(Image.open(SUMBER / "IkonMerek.png").convert("RGBA"))
    kanvas = Image.new("RGBA", (512, 512), PUTIH + (255,))
    kecil = UbahTinggi(tanda, round(512 * 0.62))
    kanvas.alpha_composite(kecil, ((512 - kecil.width) // 2, (512 - kecil.height) // 2))
    return kanvas


def GrafikFitur(nama_aplikasi: str, sub: str, foto: Path, ponsel: bool) -> Image.Image:
    lebar, tinggi = 1024, 500
    kanvas = Gradasi(lebar, tinggi)
    gambar = ImageDraw.Draw(kanvas)
    logo = UbahLebar(Potong(Image.open(SUMBER / "LogoHorizontalPutih.png").convert("RGBA")), 300)
    kanvas.alpha_composite(logo, (64, 96))
    y = 96 + logo.height + 34
    gambar.text((64, y), nama_aplikasi, font=Font(50), fill=PUTIH)
    y += 66
    for baris in BungkusTeks(sub, Font(26, "Regular"), 430):
        gambar.text((64, y), baris, font=Font(26, "Regular"), fill=(224, 228, 255))
        y += 36
    # Tagline sudah ada di logo; garis kuning (aksen logo) memberi jeda visual.
    gambar.rounded_rectangle((64, y + 22, 136, y + 28), radius=3, fill=KUNING)

    layar = Image.open(foto).convert("RGB")
    if ponsel:
        layar = UbahLebar(layar, 250)
        bingkai = Perangkat(layar, 10, 26)
        kanvas.alpha_composite(bingkai, (lebar - bingkai.width - 40, 46))
    else:
        layar = UbahLebar(layar, 560)
        bingkai = Perangkat(layar, 12, 16)
        kanvas.alpha_composite(bingkai, (lebar - bingkai.width + 150, 60))
    return kanvas.convert("RGB")


def Simpan(gambar: Image.Image, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.suffix == ".jpg":
        gambar.save(path, quality=88, optimize=True, progressive=True)
    else:
        gambar.save(path, optimize=True)
    print(f"  {path.relative_to(AKAR)} {gambar.size[0]}×{gambar.size[1]}")


def BuatSet(folder: str, daftar, sumber: Path, ukuran: tuple[int, int]) -> None:
    for urutan, (berkas, judul, keterangan) in enumerate(daftar, start=1):
        foto = sumber / f"{berkas}.png"
        if not foto.exists():
            raise SystemExit(f"Tangkapan layar belum ada: {foto} (jalankan AlatSitus/FotoPlayStore_test.dart)")
        Simpan(TangkapanBerjudul(foto, judul, keterangan, ukuran), KELUARAN / folder / f"{urutan:02d}{berkas}.jpg")


def main() -> None:
    ikon = Ikon512()
    for aplikasi in ("Kasir", "Pemilik"):
        Simpan(ikon, KELUARAN / aplikasi / "Ikon512.png")

    Simpan(
        GrafikFitur("Payoung POS", "Aplikasi kasir untuk kafe, resto, toko, salon, dan laundry", FOTO_KASIR / "Tablet1Jual.png", False),
        KELUARAN / "Kasir/GrafikFitur.png",
    )
    Simpan(
        GrafikFitur("Payoung Owner", "Pantau omzet, shift, dan karyawan semua outlet dari HP", FOTO_PEMILIK / "PemilikHp1Beranda.png", True),
        KELUARAN / "Pemilik/GrafikFitur.png",
    )

    BuatSet("Kasir/Hp", KASIR_HP, FOTO_KASIR, (1080, 2160))
    BuatSet("Kasir/Tablet10", KASIR_TABLET, FOTO_KASIR, (2560, 1600))
    BuatSet("Kasir/Tablet7", KASIR_TABLET, FOTO_KASIR, (1920, 1200))
    BuatSet("Pemilik/Hp", PEMILIK_HP, FOTO_PEMILIK, (1080, 2160))


if __name__ == "__main__":
    main()
