"""Ilustrasi Aplikasi Owner (D-231): payung menadah koin, toko, papan persetujuan, kotak stok, lonceng, avatar, dan empat
ilustrasi mini laporan. Flat, tanpa gradien, hanya warna turunan palet merek (netral untuk semua sektor usaha).
Ditulis sebagai SVG lalu diekspor PNG transparan 3x lewat Chrome ke `Paket/SistemDesain/assets/ilustrasi/owner/`.

    python3 BuatIlustrasiOwner.py
"""

import re

T, TD, TL, TT = "#3B5B5D", "#22383A", "#CFE0D8", "#E4ECE8"
A, AD, AL, AP = "#F4A261", "#E08A44", "#FBD9B8", "#FDF0E2"
G, GD, R = "#6AA77A", "#4E8A5E", "#E4694F"
W = "#FFFFFF"
KULIT, RAMBUT = "#F0C4A0", "#2B3F41"
KAYU, KAYU2 = "#E9B27A", "#D79B5F"


def _svg(vb, ukuran, isi, kelas=""):
    w, h = [float(x) for x in vb.split()[2:]]
    lebar = ukuran
    tinggi = round(ukuran * h / w)
    return f'<svg class="{kelas}" width="{lebar}" height="{tinggi}" viewBox="{vb}" fill="none" xmlns="http://www.w3.org/2000/svg">{isi}</svg>'


def koin(cx, cy, r=12):
    return (
        f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{A}"/><circle cx="{cx}" cy="{cy}" r="{r}" stroke="{AD}" stroke-width="2"/>'
        f'<circle cx="{cx}" cy="{cy}" r="{r*0.58:.1f}" stroke="{AL}" stroke-width="2"/>'
        f'<rect x="{cx - r*0.22:.1f}" y="{cy - r*0.3:.1f}" width="{r*0.44:.1f}" height="{r*0.6:.1f}" rx="1.5" fill="{AL}"/>'
    )


def kilau(cx, cy, s=6, warna=A):
    return f'<path d="M{cx} {cy-s} Q{cx} {cy} {cx+s} {cy} Q{cx} {cy} {cx} {cy+s} Q{cx} {cy} {cx-s} {cy} Q{cx} {cy} {cx} {cy-s}Z" fill="{warna}"/>'


def payung(ukuran=200, koin_jatuh=True, gagang=TD):
    kanopi = "M20 100 A80 80 0 0 1 180 100 Q160 126 140 100 Q120 126 100 100 Q80 126 60 100 Q40 126 20 100 Z"
    isi = (
        f'<defs><clipPath id="kp"><path d="{kanopi}"/></clipPath></defs>'
        f'<path d="{kanopi}" fill="{T}"/>'
        f'<g clip-path="url(#kp)">'
        f'<path d="M100 18 Q66 52 60 112 L100 112 Z" fill="{A}"/>'
        f'<path d="M100 18 Q134 52 140 112 L210 112 L210 18 Z" fill="{A}"/>'
        f'<path d="M100 18 Q80 50 60 106 M100 18 L100 106 M100 18 Q120 50 140 106" stroke="{TD}" stroke-opacity="0.28" stroke-width="2" stroke-linecap="round"/>'
        f'<path d="M34 60 Q44 40 62 32" stroke="{W}" stroke-opacity="0.45" stroke-width="5" stroke-linecap="round"/>'
        f'</g>'
        f'<path d="M100 100 V166 Q100 186 82 186 Q66 186 66 170" stroke="{gagang}" stroke-width="7" stroke-linecap="round"/>'
        f'<circle cx="100" cy="16" r="5" fill="{gagang}"/>'
    )
    if koin_jatuh:
        isi += koin(44, 150, 11) + koin(158, 138, 9) + koin(132, 176, 8) + kilau(26, 112, 5, AL) + kilau(176, 170, 6, AL)
    return _svg("0 0 200 200", ukuran, isi)


def toko(ukuran=240):
    belang = ""
    for i in range(8):
        x = 34 + i * 21.5
        warna = A if i % 2 == 0 else AP
        belang += f'<path d="M{x} 70 h21.5 v14 a10.75 10.75 0 0 1 -21.5 0 z" fill="{warna}"/>'
    isi = (
        f'<ellipse cx="120" cy="166" rx="104" ry="8" fill="{TL}" fill-opacity="0.7"/>'
        f'<rect x="40" y="72" width="160" height="92" rx="6" fill="{W}" stroke="{TL}" stroke-width="2"/>'
        f'<rect x="34" y="42" width="172" height="28" rx="9" fill="{TD}"/>'
        f'<rect x="96" y="54" width="48" height="5" rx="2.5" fill="{AL}"/>'
        f"{belang}"
        f'<rect x="100" y="112" width="40" height="52" rx="7" fill="{T}"/><rect x="106" y="118" width="28" height="22" rx="5" fill="{TL}"/><circle cx="131" cy="148" r="2.6" fill="{AL}"/>'
        f'<rect x="54" y="108" width="36" height="36" rx="7" fill="{TL}"/>'
        f'<rect x="58" y="128" width="28" height="12" rx="3" fill="{KAYU}"/><circle cx="66" cy="124" r="5" fill="{R}"/><circle cx="76" cy="123" r="5.5" fill="{R}"/><circle cx="84" cy="125" r="4" fill="{A}"/>'
        f'<rect x="150" y="108" width="36" height="36" rx="7" fill="{TL}"/>'
        f'<path d="M158 144 v-14 a10 10 0 0 1 20 0 v14z" fill="{G}"/><path d="M168 144 v-18" stroke="{GD}" stroke-width="2"/>'
        f'<rect x="40" y="146" width="12" height="18" rx="3" fill="{AD}"/><path d="M46 146 C36 136 38 126 46 122 C54 126 56 136 46 146Z" fill="{G}"/>'
        f'<rect x="190" y="146" width="12" height="18" rx="3" fill="{AD}"/><path d="M196 146 C186 138 188 128 196 124 C204 128 206 138 196 146Z" fill="{GD}"/>'
        f"{kilau(214, 52, 7, A)}{kilau(22, 96, 5, AL)}"
    )
    return _svg("0 34 240 146", ukuran, isi)


def papan_setuju(ukuran=200):
    isi = (
        f'<circle cx="100" cy="90" r="72" fill="{TT}"/>'
        f'<rect x="58" y="30" width="84" height="120" rx="13" fill="{W}"/>'
        f'<rect x="80" y="21" width="40" height="19" rx="7" fill="{T}"/><circle cx="100" cy="30" r="3.2" fill="{AL}"/>'
        f'<circle cx="75" cy="64" r="8" fill="{G}"/><path d="M71.5 64 l2.8 2.8 l5-5.6" stroke="{W}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        f'<rect x="90" y="61" width="40" height="6" rx="3" fill="{TL}"/>'
        f'<circle cx="75" cy="88" r="8" fill="{G}"/><path d="M71.5 88 l2.8 2.8 l5-5.6" stroke="{W}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        f'<rect x="90" y="85" width="34" height="6" rx="3" fill="{TL}"/>'
        f'<circle cx="75" cy="112" r="7" stroke="{AL}" stroke-width="3"/><rect x="90" y="109" width="38" height="6" rx="3" fill="{TL}"/>'
        f'<circle cx="142" cy="122" r="26" fill="{A}"/><circle cx="142" cy="122" r="26" stroke="{AD}" stroke-width="3"/>'
        f'<path d="M130 122 l9 9 l16-18" stroke="{W}" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>'
        f"{kilau(40, 50, 7, A)}{kilau(166, 40, 5, AL)}{kilau(30, 120, 4, TL)}{koin(36, 140, 8)}"
    )
    return _svg("0 0 200 170", ukuran, isi)


def kotak_stok(ukuran=220):
    def kardus(x, y, w=84, h=56):
        return (
            f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{KAYU}"/>'
            f'<rect x="{x}" y="{y}" width="{w}" height="14" rx="8" fill="{KAYU2}"/>'
            f'<rect x="{x + w/2 - 7}" y="{y}" width="14" height="{h}" fill="{AL}" fill-opacity="0.9"/>'
            f'<rect x="{x + 10}" y="{y + 28}" width="22" height="16" rx="3" fill="{W}"/>'
            f'<rect x="{x + 14}" y="{y + 33}" width="14" height="3" rx="1.5" fill="{TL}"/><rect x="{x + 14}" y="{y + 38}" width="9" height="3" rx="1.5" fill="{TL}"/>'
        )

    isi = (
        f'<ellipse cx="110" cy="158" rx="100" ry="8" fill="{TL}" fill-opacity="0.7"/>'
        f"{kardus(18, 96)}{kardus(114, 96)}{kardus(66, 42)}"
        f'<circle cx="190" cy="62" r="13" fill="{G}"/><path d="M184 62 l4.5 4.5 l8 -9" stroke="{W}" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>'
        f"{kilau(204, 112, 7, A)}{kilau(12, 74, 5, AL)}"
    )
    return _svg("0 30 220 140", ukuran, isi)


def lonceng(ukuran=180):
    isi = (
        f'<circle cx="100" cy="88" r="70" fill="{TT}"/>'
        f'<path d="M100 40 C72 40 60 62 60 88 V110 L48 126 H152 L140 110 V88 C140 62 128 40 100 40Z" fill="{T}"/>'
        f'<circle cx="100" cy="34" r="7" fill="{TD}"/><path d="M86 134 a14 14 0 0 0 28 0z" fill="{A}"/>'
        f'<path d="M74 70 C74 58 84 52 92 52" stroke="{W}" stroke-opacity="0.35" stroke-width="5" stroke-linecap="round"/>'
        f'<circle cx="142" cy="58" r="20" fill="{G}"/><path d="M133 58 l7 7 l12-14" stroke="{W}" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>'
        f"{kilau(46, 56, 6, A)}{kilau(166, 112, 5, AL)}{kilau(40, 120, 4, TL)}"
    )
    return _svg("0 0 200 170", ukuran, isi)


def avatar(ukuran=48):
    isi = (
        f'<circle cx="40" cy="40" r="40" fill="{TL}"/>'
        f'<path d="M10 80 C10 60 24 54 40 54 S70 60 70 80Z" fill="{T}"/>'
        f'<path d="M33 54 L40 64 L47 54Z" fill="{W}"/>'
        f'<rect x="35" y="44" width="10" height="12" rx="4" fill="#E3B089"/>'
        f'<circle cx="40" cy="34" r="15" fill="{KULIT}"/>'
        f'<path d="M24 33 C23 18 32 13 41 13 S58 19 56 33 C52 25 47 23 41 23 S28 25 24 33Z" fill="{RAMBUT}"/>'
        f'<circle cx="34.5" cy="36" r="1.6" fill="{RAMBUT}"/><circle cx="45.5" cy="36" r="1.6" fill="{RAMBUT}"/>'
        f'<path d="M36 42 Q40 45.5 44 42" stroke="{AD}" stroke-width="2" stroke-linecap="round"/>'
    )
    return _svg("0 0 80 80", ukuran, isi)


# ilustrasi mini 64x64 untuk ubin laporan
def mini_penjualan(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M14 8 H50 V54 L45 50 L40 54 L35 50 L30 54 L25 50 L20 54 L14 50Z" fill="{W}"/>'
        f'<rect x="21" y="18" width="22" height="4" rx="2" fill="{TL}"/><rect x="21" y="27" width="16" height="4" rx="2" fill="{TL}"/><rect x="21" y="36" width="20" height="4" rx="2" fill="{TL}"/>'
        f"{koin(46, 44, 11)}")


def mini_laba(u=56):
    return _svg("0 0 64 64", u,
        f'<rect x="10" y="38" width="10" height="16" rx="3" fill="{TL}"/><rect x="26" y="28" width="10" height="26" rx="3" fill="{TL}"/><rect x="42" y="16" width="10" height="38" rx="3" fill="{T}"/>'
        f'<path d="M10 30 L26 20 L34 25 L52 8" stroke="{A}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><path d="M42 8 H52 V18" stroke="{A}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>')


def mini_kas(u=56):
    return _svg("0 0 64 64", u,
        f'<rect x="6" y="20" width="44" height="26" rx="5" fill="{G}"/><rect x="14" y="14" width="44" height="26" rx="5" fill="{W}"/>'
        f'<circle cx="36" cy="27" r="7" fill="{TL}"/><rect x="33.5" y="22" width="5" height="10" rx="2" fill="{T}"/>'
        f'<circle cx="21" cy="27" r="2.4" fill="{TL}"/><circle cx="51" cy="27" r="2.4" fill="{TL}"/>'
        f"{koin(48, 46, 10)}")


def mini_waspada(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M32 6 L54 14 V32 C54 45 45 54 32 59 C19 54 10 45 10 32 V14Z" fill="{W}"/>'
        f'<path d="M32 6 L54 14 V32 C54 45 45 54 32 59Z" fill="{TL}" fill-opacity="0.45"/>'
        f'<rect x="29" y="20" width="6" height="19" rx="3" fill="{R}"/><circle cx="32" cy="46" r="3.6" fill="{R}"/>')


# --- ikon ilustrasi 64x64 untuk aksi cepat, menu Lainnya, dan ubin angka Beranda (D-234) ---------------------------------
def ikon_harga(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M8 32 L32 8 H55 V31 L31 55 Z" fill="{A}" stroke="{AD}" stroke-width="3" stroke-linejoin="round"/>'
        f'<circle cx="45" cy="19" r="4.5" fill="{W}"/>'
        f'<rect x="21" y="30" width="19" height="4.5" rx="2.2" fill="{AL}" transform="rotate(-45 30 32)"/>'
        f'<rect x="25" y="38" width="13" height="4.5" rx="2.2" fill="{AL}" transform="rotate(-45 31 40)"/>'
        f"{kilau(14, 14, 5, AL)}")


def ikon_habis(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M8 22 L32 12 L56 22 V46 L32 56 L8 46Z" fill="{KAYU}"/>'
        f'<path d="M8 22 L32 32 L56 22" stroke="{KAYU2}" stroke-width="3" stroke-linejoin="round"/>'
        f'<path d="M32 32 V56" stroke="{KAYU2}" stroke-width="3"/>'
        f'<circle cx="46" cy="44" r="13" fill="{R}"/><circle cx="46" cy="44" r="13" stroke="{W}" stroke-width="3"/>'
        f'<rect x="38.5" y="41.5" width="15" height="5" rx="2.5" fill="{W}"/>')


def ikon_promo(u=56):
    return _svg("0 0 64 64", u,
        f'<circle cx="32" cy="32" r="24" fill="{G}"/><circle cx="32" cy="32" r="24" stroke="{GD}" stroke-width="3"/>'
        f'<circle cx="32" cy="32" r="17" stroke="{W}" stroke-opacity="0.5" stroke-width="2" stroke-dasharray="3 4"/>'
        f'<circle cx="24" cy="24" r="4.6" fill="{W}"/><circle cx="40" cy="40" r="4.6" fill="{W}"/>'
        f'<path d="M41 22 L23 42" stroke="{W}" stroke-width="5" stroke-linecap="round"/>'
        f"{kilau(54, 12, 6, A)}{kilau(10, 52, 4, AL)}")


def ikon_pengeluaran(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M12 6 H44 V54 L39 50 L34 54 L29 50 L24 54 L19 50 L12 54Z" fill="{W}" stroke="{TL}" stroke-width="2.5" stroke-linejoin="round"/>'
        f'<rect x="19" y="15" width="18" height="4" rx="2" fill="{TL}"/><rect x="19" y="24" width="12" height="4" rx="2" fill="{TL}"/><rect x="19" y="33" width="16" height="4" rx="2" fill="{TL}"/>'
        f'<circle cx="46" cy="44" r="13" fill="{R}"/><circle cx="46" cy="44" r="13" stroke="{W}" stroke-width="3"/>'
        f'<path d="M46 37 V50 M40.5 45 L46 50.5 L51.5 45" stroke="{W}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>')


def ikon_kunci(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M32 5 L54 13 V32 C54 45 45 54 32 59 C19 54 10 45 10 32 V13Z" fill="{G}"/>'
        f'<path d="M32 5 L54 13 V32 C54 45 45 54 32 59Z" fill="{GD}" fill-opacity="0.35"/>'
        f'<path d="M24 29 V25 A8 8 0 0 1 40 25 V29" stroke="{W}" stroke-width="4.5" stroke-linecap="round"/>'
        f'<rect x="20" y="28" width="24" height="18" rx="5" fill="{W}"/>'
        f'<circle cx="32" cy="35.5" r="3" fill="{GD}"/><rect x="30.7" y="36" width="2.6" height="6" rx="1.3" fill="{GD}"/>')


def ikon_bantuan(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M12 10 H46 A8 8 0 0 1 54 18 V36 A8 8 0 0 1 46 44 H30 L20 54 V44 H12 A8 8 0 0 1 4 36 V18 A8 8 0 0 1 12 10Z" fill="{T}"/>'
        f'<path d="M24 24 Q24 17 29 17 Q35 17 35 23 Q35 27 30 30 Q28.5 31.2 28.5 34" stroke="{W}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
        f'<circle cx="28.5" cy="39.5" r="2.7" fill="{W}"/>'
        f'<circle cx="50" cy="48" r="9" fill="{A}"/><circle cx="50" cy="48" r="9" stroke="{W}" stroke-width="2.5"/>'
        f'<circle cx="46.5" cy="48" r="1.6" fill="{W}"/><circle cx="50" cy="48" r="1.6" fill="{W}"/><circle cx="53.5" cy="48" r="1.6" fill="{W}"/>')


def ikon_keluar(u=56):
    return _svg("0 0 64 64", u,
        f'<rect x="8" y="8" width="32" height="48" rx="5" fill="{TL}"/>'
        f'<path d="M14 14 H34 V52 H14Z" fill="{KAYU}"/><path d="M14 14 H34 V52 H14Z" stroke="{KAYU2}" stroke-width="3" stroke-linejoin="round"/>'
        f'<circle cx="29" cy="33" r="2.4" fill="{KAYU2}"/>'
        f'<path d="M34 32 H56 M47 22 L57 32 L47 42" stroke="{R}" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>')


def ikon_transaksi(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M12 6 H48 V56 L42.5 51.5 L37 56 L31 51.5 L25 56 L19.5 51.5 L12 56Z" fill="{W}" stroke="{TL}" stroke-width="2.5" stroke-linejoin="round"/>'
        f'<rect x="19" y="15" width="22" height="4.5" rx="2.2" fill="{TL}"/><rect x="19" y="25" width="15" height="4.5" rx="2.2" fill="{TL}"/><rect x="19" y="35" width="19" height="4.5" rx="2.2" fill="{TL}"/>'
        f'<circle cx="46" cy="44" r="12" fill="{G}"/><circle cx="46" cy="44" r="12" stroke="{W}" stroke-width="3"/>'
        f'<path d="M40.5 44.5 L44.5 48.5 L52 40" stroke="{W}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>')


def ikon_rata_rata(u=56):
    return _svg("0 0 64 64", u,
        f'<path d="M20 30 Q32 4 44 30" stroke="{AD}" stroke-width="4.5" stroke-linecap="round"/>'
        f'<circle cx="25" cy="27" r="6.5" fill="{R}"/><circle cx="39" cy="26" r="6.5" fill="{G}"/>'
        f'<path d="M7 29 H57 L50 54 H14Z" fill="{A}" stroke="{AD}" stroke-width="3" stroke-linejoin="round"/>'
        f'<path d="M22 35 L24 49 M32 35 V49 M42 35 L40 49" stroke="{AL}" stroke-width="3.5" stroke-linecap="round"/>'
        f"{kilau(55, 12, 5, AL)}")


def ikon_laba(u=56):
    return _svg("0 0 64 64", u,
        f'{koin(32, 11, 8)}'
        f'<path d="M17 24 L22 14 L29 22Z" fill="{AD}"/>'
        f'<rect x="19" y="45" width="8" height="12" rx="3" fill="{AD}"/><rect x="41" y="45" width="8" height="12" rx="3" fill="{AD}"/>'
        f'<ellipse cx="33" cy="37" rx="23" ry="17" fill="{A}"/><ellipse cx="33" cy="37" rx="23" ry="17" stroke="{AD}" stroke-width="3"/>'
        f'<ellipse cx="53" cy="39" rx="7" ry="6" fill="{AL}" stroke="{AD}" stroke-width="2.5"/>'
        f'<circle cx="51" cy="39" r="1.3" fill="{AD}"/><circle cx="55" cy="39" r="1.3" fill="{AD}"/>'
        f'<circle cx="44" cy="30" r="2.6" fill="{TD}"/>'
        f'<path d="M24 28 Q33 24 40 28" stroke="{AD}" stroke-width="3" stroke-linecap="round"/>'
        f'<path d="M10 33 Q5 31 6 26" stroke="{AD}" stroke-width="3" stroke-linecap="round"/>')


# --- ekspor ---------------------------------------------------------------------------------------------------------
import os
import shutil
import subprocess
import tempfile
from pathlib import Path


def _peramban() -> str:
    """Chrome dari PATH, atau Chromium Playwright (`/opt/pw-browsers/chromium`) di lingkungan agen; bisa ditimpa `CHROME`."""
    return os.environ.get("CHROME") or shutil.which("google-chrome") or "/opt/pw-browsers/chromium"

SET = {
    "Payung": (lambda: payung(280), 280),
    "Toko": (lambda: toko(260), 260),
    "PapanSetuju": (lambda: papan_setuju(240), 240),
    "KotakStok": (lambda: kotak_stok(240), 240),
    "Lonceng": (lambda: lonceng(220), 220),
    "Avatar": (lambda: avatar(96), 96),
    "MiniPenjualan": (lambda: mini_penjualan(64), 64),
    "MiniLaba": (lambda: mini_laba(64), 64),
    "MiniKas": (lambda: mini_kas(64), 64),
    "MiniWaspada": (lambda: mini_waspada(64), 64),
    "IkonHarga": (lambda: ikon_harga(64), 64),
    "IkonHabis": (lambda: ikon_habis(64), 64),
    "IkonPromo": (lambda: ikon_promo(64), 64),
    "IkonPengeluaran": (lambda: ikon_pengeluaran(64), 64),
    "IkonKunci": (lambda: ikon_kunci(64), 64),
    "IkonBantuan": (lambda: ikon_bantuan(64), 64),
    "IkonKeluar": (lambda: ikon_keluar(64), 64),
    "IkonTransaksi": (lambda: ikon_transaksi(64), 64),
    "IkonRataRata": (lambda: ikon_rata_rata(64), 64),
    "IkonLaba": (lambda: ikon_laba(64), 64),
}


def Ekspor(keluar: Path) -> None:
    keluar.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        for nama, (buat, lebar) in SET.items():
            svg = buat()
            tinggi = int(re.search(r'height="(\d+)"', svg).group(1))
            html = Path(tmp) / f"{nama}.html"
            html.write_text(
                f"<!doctype html><html><head><meta charset=utf-8><style>html,body{{margin:0;background:transparent}}</style></head><body>{svg}</body></html>"
            )
            subprocess.run(
                [
                    _peramban(), "--headless=new", "--no-sandbox", "--disable-gpu", "--hide-scrollbars",
                    "--default-background-color=00000000", "--force-device-scale-factor=3",
                    f"--window-size={lebar},{tinggi}", f"--screenshot={keluar / (nama + '.png')}", html.as_uri(),
                ],
                check=True, capture_output=True,
            )
            print("ok", nama)


if __name__ == "__main__":
    Ekspor(Path(__file__).resolve().parents[2] / "Paket" / "SistemDesain" / "assets" / "ilustrasi" / "owner")
