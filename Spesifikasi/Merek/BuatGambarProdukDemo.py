"""Gambar produk contoh untuk tangkapan layar kasir per sektor di situs pemasaran (D-212): kafe, toko, salon, laundry,
apotek, dan bengkel. Gaya sama dengan ilustrasi keadaan kosong (garis Teal Tinta, isi bidang datar, aksen Apricot) di atas
latar pastel per produk. Semuanya karangan sendiri (bukan foto atau merek orang lain), jadi bebas lisensi.

Menulis SVG lalu PNG 256 px ke `Aplikasi/Kasir/AlatSitus/GambarProduk/`, yang dibaca `FotoSitusSektor_test.dart`.

    python3 BuatGambarProdukDemo.py
"""

import subprocess
from pathlib import Path

AKAR = Path(__file__).resolve().parents[2]
KELUAR = AKAR / 'Aplikasi/Kasir/AlatSitus/GambarProduk'

GARIS = '#1F3335'
G = f'stroke="{GARIS}" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"'
PUTIH = '#FFFFFF'
APRICOT = '#F4A261'
TEAL = '#4F8184'
KRIM = '#F6E7C8'
COKLAT = '#8A5A3C'
COKLAT_TUA = '#5B3A29'
HIJAU = '#7FB77E'
MERAH = '#E76F51'
BIRU = '#6FA8DC'
KUNING = '#F6D365'
UNGU = '#B39DDB'
ABU = '#B8C4C4'


def Rect(x, y, w, h, isi, r=8, garis=True):
    return f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{isi}" {G if garis else ""}/>'


def Circ(cx, cy, r, isi, garis=True):
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{isi}" {G if garis else ""}/>'


def Ell(cx, cy, rx, ry, isi, garis=True):
    return f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="{isi}" {G if garis else ""}/>'


def Path(d, isi='none', garis=True, lebar=None):
    g = G if lebar is None else G.replace('stroke-width="5"', f'stroke-width="{lebar}"')
    return f'<path d="{d}" fill="{isi}" {g if garis else ""}/>'


def Teks(x, y, isi, ukuran=18, warna=GARIS):
    return f'<text x="{x}" y="{y}" font-family="Arial,sans-serif" font-weight="700" font-size="{ukuran}" text-anchor="middle" fill="{warna}">{isi}</text>'


# --- gambar ---------------------------------------------------------------------------------------------------------


def GelasEs(cairan, atas=None, sedotan=APRICOT):
    atas = atas or cairan
    return (
        Path('M112 28 L126 28 L118 84', sedotan)
        + Path('M56 62 L144 62 L130 168 Q129 176 120 176 L80 176 Q71 176 70 168 Z', PUTIH)
        + Path('M60 92 L140 92 L130 168 Q129 176 120 176 L80 176 Q71 176 70 168 Z', cairan)
        + Path('M60 92 L140 92', garis=True)
        + Rect(80, 100, 24, 24, atas, 6)
        + Rect(106, 112, 22, 22, atas, 6)
        + Rect(56, 52, 88, 14, '#FFFFFF', 7)
    )


def Cangkir(isi, uap=True, alas=APRICOT):
    uapan = Path('M80 52 C70 38 92 34 82 18 M104 52 C94 38 116 34 106 18 M128 52 C118 38 140 34 130 18', garis=True, lebar=4) if uap else ''
    return (
        uapan
        + Ell(100, 160, 62, 14, alas)
        + Path('M50 76 H150 V118 Q150 156 100 156 Q50 156 50 118 Z', PUTIH)
        + Path('M150 88 H164 Q176 88 176 104 Q176 120 160 122 H148', 'none')
        + Ell(100, 76, 50, 12, isi)
    )


def Croissant():
    return (
        Path('M24 124 Q28 70 100 58 Q172 70 176 124 Q168 132 156 124 Q150 142 134 134 Q122 150 100 140 Q78 150 66 134 Q50 142 44 124 Q32 132 24 124 Z', '#E9A858')
        + Path('M74 66 Q80 100 70 134 M100 58 V140 M126 66 Q120 100 130 134', garis=True, lebar=4)
        + Path('M84 78 Q100 70 116 78', garis=True, lebar=3)
    )


def RotiBakar():
    return (
        Path('M44 70 Q44 40 74 40 Q88 30 100 40 Q112 30 126 40 Q156 40 156 70 V160 Q156 170 146 170 H54 Q44 170 44 160 Z', '#F0C27B')
        + Path('M58 80 Q58 62 76 62 H124 Q142 62 142 80 V152 H58 Z', KRIM)
        + Path('M70 100 H130 M70 122 H130', MERAH, lebar=7)
    )


def PisangGoreng():
    return (
        Ell(100, 158, 78, 16, PUTIH)
        + Path('M30 140 Q40 96 78 108 L96 138 Q70 164 30 140 Z', '#E2A24C')
        + Path('M82 128 Q94 84 130 90 L146 126 Q120 150 82 128 Z', '#E9B25C')
        + Path('M116 138 Q132 100 166 108 L174 138 Q150 160 116 138 Z', '#E2A24C')
        + Path('M44 126 L70 126 M100 116 L124 112 M134 128 L158 124', KUNING, lebar=6)
    )


def NasiGoreng():
    return (
        Ell(100, 148, 80, 22, PUTIH)
        + Ell(100, 142, 58, 14, '#EFEAD8')
        + Path('M40 134 Q48 70 100 66 Q152 70 160 134 Q100 160 40 134 Z', '#D9923E')
        + Circ(86, 96, 6, MERAH, True)
        + Circ(118, 90, 6, HIJAU, True)
        + Circ(106, 112, 6, MERAH, True)
        + Ell(128, 78, 26, 18, PUTIH)
        + Circ(128, 78, 9, KUNING)
    )


def MieGoreng():
    return (
        Ell(100, 150, 80, 22, PUTIH)
        + Path('M34 128 Q40 74 100 72 Q160 74 166 128 Q100 160 34 128 Z', '#C98A3D')
        + Path('M52 112 Q80 84 104 112 T150 104 M58 126 Q86 100 110 126 T156 122', garis=True, lebar=4)
        + Circ(80, 92, 7, HIJAU) + Circ(124, 88, 7, MERAH) + Circ(106, 100, 6, HIJAU)
    )


def KueSlice(warna=COKLAT_TUA, krim=KRIM):
    return (
        Path('M30 150 L170 150 L170 112 L30 112 Z', warna)
        + Path('M30 112 L170 112 L150 64 L50 64 Z', krim)
        + Path('M50 64 H150 L170 112 H30 Z', krim)
        + Path('M30 112 L170 112', garis=True)
        + Path('M30 150 H170 V160 Q170 168 160 168 H40 Q30 168 30 160 Z', PUTIH)
        + Circ(100, 56, 12, MERAH)
    )


def Bungkus(utama, label, aksen=APRICOT, tulisan=''):
    return (
        Path('M44 40 Q60 28 76 40 Q100 28 124 40 Q140 28 156 40 V160 Q140 172 124 160 Q100 172 76 160 Q60 172 44 160 Z', utama)
        + Rect(58, 70, 84, 58, label, 10)
        + Circ(100, 99, 18, aksen)
        + (Teks(100, 106, tulisan, 18) if tulisan else '')
    )


def Karung(isi, tulisan='BERAS'):
    return (
        Path('M50 40 Q100 20 150 40 L166 168 Q100 182 34 168 Z', isi)
        + Path('M50 40 Q100 56 150 40', garis=True)
        + Rect(62, 78, 76, 50, PUTIH, 8)
        + Teks(100, 112, tulisan, 18)
        + Path('M70 150 H130', garis=True, lebar=4)
    )


def Botol(isi, tutup=APRICOT, label=PUTIH, tulisan='', ukuran=18):
    return (
        Rect(82, 14, 36, 22, tutup, 6)
        + Path('M86 36 H114 V56 Q136 70 136 96 V160 Q136 176 120 176 H80 Q64 176 64 160 V96 Q64 70 86 56 Z', isi)
        + Rect(64, 92, 72, 54, label, 4)
        + (Teks(100, 124, tulisan, ukuran) if tulisan else Circ(100, 119, 12, tutup))
    )


def Kotak(utama, sisi=None, tulisan='', aksen=APRICOT):
    sisi = sisi or utama
    return (
        Path('M40 62 L100 38 L160 62 V148 L100 174 L40 148 Z', utama)
        + Path('M100 38 V174', garis=True)
        + Path('M100 38 L160 62 V148 L100 174 Z', sisi)
        + Rect(52, 90, 40, 34, PUTIH, 4)
        + Circ(72, 107, 8, aksen)
        + (Teks(128, 112, tulisan, 16, GARIS) if tulisan else '')
    )


def KotakSusu(warna=BIRU):
    return (
        Path('M60 56 L100 24 L140 56 Z', PUTIH)
        + Rect(60, 56, 80, 118, PUTIH, 4)
        + Rect(60, 90, 80, 58, warna, 4)
        + Circ(100, 119, 14, PUTIH)
    )


def Galon():
    return (
        Rect(86, 14, 28, 18, TEAL, 5)
        + Path('M76 32 H124 V54 Q150 68 150 100 V164 Q150 178 136 178 H64 Q50 178 50 164 V100 Q50 68 76 54 Z', '#BFE3F0')
        + Path('M60 112 Q100 96 140 112', garis=True, lebar=4)
        + Path('M60 112 V164 H140 V112 Q100 128 60 112 Z', '#8CCBE0')
    )


def Telur():
    return (
        Path('M24 112 H176 L160 170 H40 Z', '#D9C5A0')
        + Ell(58, 100, 17, 22, '#F4E3C3') + Ell(100, 94, 17, 22, '#F4E3C3') + Ell(142, 100, 17, 22, '#F4E3C3')
        + Ell(78, 70, 15, 20, '#F4E3C3') + Ell(122, 70, 15, 20, '#F4E3C3')
    )


def Sabun():
    return (
        Rect(30, 90, 140, 72, '#BDE4DA', 20)
        + Rect(44, 104, 112, 44, '#DDF1EC', 12)
        + Circ(150, 62, 18, '#E8F7F3') + Circ(120, 44, 12, '#E8F7F3') + Circ(172, 38, 10, '#E8F7F3')
    )


def Gunting():
    return (
        Path('M60 40 L118 128 M140 40 L82 128', garis=True, lebar=9)
        + Circ(70, 150, 22, 'none') + Circ(130, 150, 22, 'none')
        + Circ(70, 150, 22, APRICOT) + Circ(130, 150, 22, APRICOT)
        + Circ(70, 150, 8, PUTIH) + Circ(130, 150, 8, PUTIH)
        + Path('M60 40 L118 128 M140 40 L82 128', garis=True, lebar=9)
    )


def Sisir():
    gigi = ''.join(f'<path d="M{34 + i * 12} 100 V150" stroke="{GARIS}" stroke-width="5" stroke-linecap="round"/>' for i in range(12))
    return Rect(26, 66, 148, 40, APRICOT, 12) + gigi + Rect(26, 66, 148, 40, 'none', 12)


def Jar(isi, tutup=TEAL, bunga=False):
    return (
        Rect(52, 36, 96, 28, tutup, 10)
        + Path('M56 64 H144 V150 Q144 172 122 172 H78 Q56 172 56 150 Z', isi)
        + Rect(66, 96, 68, 44, PUTIH, 8)
        + (Circ(100, 118, 9, APRICOT) if not bunga else Path('M100 108 V128 M90 118 H110', garis=True, lebar=4))
    )


def Kuteks(warna=MERAH):
    return (
        Rect(88, 14, 24, 50, GARIS, 6)
        + Rect(62, 62, 76, 112, warna, 20)
        + Rect(76, 96, 48, 40, PUTIH, 8)
        + Path('M70 70 H130', 'none', lebar=3)
    )


def PengeringRambut(warna=APRICOT):
    return (
        Path('M30 72 Q30 40 70 40 H130 Q160 40 160 70 Q160 100 130 100 H70 Q30 100 30 72 Z', warna)
        + Rect(66, 96, 30, 82, TEAL, 10)
        + Rect(150, 52, 26, 40, GARIS, 6)
        + Path('M44 66 H100', garis=True, lebar=4)
    )


def Daun(warna=HIJAU):
    return (
        Path('M40 160 Q30 70 100 40 Q170 70 160 160 Q100 190 40 160 Z', warna)
        + Path('M100 40 V168 M100 80 L66 62 M100 112 L60 92 M100 80 L134 62 M100 112 L140 92', garis=True, lebar=4)
        + Ell(100, 176, 60, 8, '#DCEBE8', False)
    )


def KeranjangBaju():
    return (
        Path('M30 92 H170 L152 170 H48 Z', '#F0D8AE')
        + Path('M44 116 H156 M52 142 H148', garis=True, lebar=4)
        + Path('M44 92 Q44 54 78 58 Q86 40 108 52 Q146 40 152 92 Z', BIRU)
        + Path('M70 60 Q90 70 112 56', garis=True, lebar=4)
    )


def Lipatan(warna=UNGU, aksen=PUTIH):
    return (
        Rect(30, 138, 140, 32, warna, 8) + Rect(36, 106, 128, 32, aksen, 8) + Rect(42, 74, 116, 32, warna, 8) + Rect(48, 42, 104, 32, aksen, 8)
    )


def Hanger(warna=BIRU):
    return (
        Path('M100 26 Q120 26 120 44 Q120 56 100 66', garis=True, lebar=6)
        + Path('M100 66 L22 120 Q14 128 24 130 H176 Q186 128 178 120 Z', 'none')
        + Path('M72 74 Q100 96 128 74 L168 104 L156 172 H44 L32 104 Z', warna)
        + Path('M100 96 V172', garis=True, lebar=4)
        + Circ(100, 122, 4, GARIS) + Circ(100, 144, 4, GARIS)
    )


def Sepatu(warna=MERAH):
    return (
        Path('M24 128 Q24 70 60 62 L84 90 Q130 100 160 118 Q180 126 176 148 Q176 160 160 160 H40 Q24 160 24 148 Z', warna)
        + Path('M24 146 H178', 'none', lebar=5)
        + Rect(24, 148, 154, 14, PUTIH, 6)
        + Path('M72 80 L82 96 M88 86 L98 102 M104 92 L114 108', garis=True, lebar=4)
    )


def Strip(warna=APRICOT):
    butir = ''.join(Circ(42 + i * 34, y, 12, PUTIH) for i in range(4) for y in (74, 126))
    return Rect(24, 40, 152, 120, ABU, 14) + butir + Path('M24 100 H176', garis=True, lebar=3)


def SirupBotol(warna=MERAH):
    return Botol(warna, TEAL, PUTIH, '')


def Tube(warna=TEAL):
    return (
        Path('M60 26 H140 V44 L128 56 H72 L60 44 Z', GARIS)
        + Path('M72 56 H128 L136 150 Q136 162 124 162 H76 Q64 162 64 150 Z', warna)
        + Rect(70, 80, 60, 40, PUTIH, 6)
        + Path('M74 162 H126 V176 H74 Z', PUTIH)
        + Path('M88 100 H112 M100 90 V110', garis=True, lebar=4)
    )


def KotakObat(utama=PUTIH, aksen=MERAH):
    return (
        Rect(30, 56, 140, 100, utama, 12)
        + Rect(30, 56, 140, 28, aksen, 12)
        + Path('M92 100 H108 M100 92 V108', 'none', lebar=0)
        + Rect(86, 98, 28, 44, PUTIH, 4, False)
        + Path('M100 106 V134 M86 120 H114', garis=True, lebar=9)
        + Circ(52, 126, 6, aksen) + Circ(148, 126, 6, aksen)
    )


def Termometer():
    return (
        Rect(56, 80, 120, 28, PUTIH, 14)
        + Circ(52, 94, 18, MERAH)
        + Rect(76, 90, 70, 8, MERAH, 4, False)
        + Path('M140 90 V98 M120 90 V98 M100 90 V98', garis=True, lebar=3)
    )


def Masker():
    return (
        Path('M34 70 Q100 50 166 70 V132 Q100 156 34 132 Z', '#BFE3F0')
        + Path('M34 86 Q100 70 166 86 M34 104 Q100 88 166 104 M34 122 Q100 106 166 122', garis=True, lebar=4)
        + Path('M34 78 Q8 86 14 110 M166 78 Q192 86 186 110', garis=True, lebar=5)
    )


def Kaleng(warna=APRICOT, tulisan='OLI', ukuran=20):
    return (
        Path('M52 52 H148 V160 Q148 176 100 176 Q52 176 52 160 Z', warna)
        + Ell(100, 52, 48, 12, '#FFD9B0')
        + Rect(62, 86, 76, 48, PUTIH, 6)
        + Teks(100, 116, tulisan, ukuran)
        + Path('M124 40 Q150 28 168 40 L150 52', 'none', lebar=5)
    )


def Ban():
    jari = ''.join(
        f'<path d="M100 100 L{100 + 38 * __import__("math").cos(a):.0f} {100 + 38 * __import__("math").sin(a):.0f}" stroke="{GARIS}" stroke-width="5" stroke-linecap="round"/>'
        for a in [i * 3.14159 / 3 for i in range(6)]
    )
    return Circ(100, 100, 74, '#3C4A4B') + Circ(100, 100, 52, ABU) + Circ(100, 100, 14, PUTIH) + jari + Path('M100 26 V40 M174 100 H160 M100 174 V160 M26 100 H40', garis=True, lebar=5)


def Aki():
    return (
        Rect(30, 70, 140, 96, '#335C5E', 12)
        + Rect(46, 52, 30, 20, GARIS, 4) + Rect(124, 52, 30, 20, GARIS, 4)
        + Rect(46, 88, 108, 30, KUNING, 6)
        + Path('M62 104 H78 M70 96 V112', garis=True, lebar=5)
        + Path('M122 104 H142', garis=True, lebar=5)
        + Circ(58, 140, 6, MERAH) + Circ(142, 140, 6, BIRU)
    )


def Busi():
    return (
        Rect(78, 22, 44, 34, PUTIH, 8) + Rect(70, 56, 60, 22, ABU, 4)
        + Path('M78 78 H122 V132 H78 Z', ABU)
        + Path('M78 98 H122 M78 116 H122', garis=True, lebar=4)
        + Path('M92 132 V172 Q92 178 100 178 Q108 178 108 172 V132', ABU)
        + Path('M100 178 V160', garis=True, lebar=4)
    )


def KampasRem():
    return (
        Path('M24 120 Q24 62 100 56 Q176 62 176 120 L160 138 Q100 118 40 138 Z', ABU)
        + Path('M44 112 Q100 94 156 112 L148 128 Q100 112 52 128 Z', COKLAT_TUA)
        + Circ(52, 100, 5, PUTIH) + Circ(148, 100, 5, PUTIH)
    )


def FilterOli():
    return (
        Path('M54 40 H146 V160 Q146 172 134 172 H66 Q54 172 54 160 Z', APRICOT)
        + Path('M54 70 H146 M54 138 H146', garis=True, lebar=5)
        + Rect(70, 82, 60, 44, PUTIH, 6)
        + Teks(100, 112, 'FILTER', 14)
        + Rect(80, 28, 40, 14, GARIS, 4)
    )


def Kunci():
    return (
        Path('M54 148 L130 72 Q118 44 142 30 Q168 22 178 40 L158 52 L164 66 L178 74 Q172 100 148 104 Q136 108 130 100 L72 160 Q60 168 54 160 Q46 154 54 148 Z', ABU)
        + Circ(60, 154, 5, PUTIH)
    )


def Lampu():
    return (
        Path('M74 124 Q54 90 76 56 Q100 24 124 56 Q146 90 126 124 Z', KUNING)
        + Rect(80, 124, 40, 16, ABU, 4) + Rect(84, 140, 32, 14, ABU, 4) + Rect(90, 154, 20, 12, GARIS, 4)
        + Path('M92 100 L100 76 L108 100 Z', 'none', lebar=4)
    )


def Wiper():
    return (
        Path('M24 130 Q100 60 176 130', garis=True, lebar=8)
        + Path('M24 142 Q100 72 176 142', garis=True, lebar=12)
        + Path('M92 80 L108 80 L108 100 L92 100 Z', GARIS)
    )


GAMBAR = {
    # Kafe & resto
    'KopiSusu': (GelasEs('#B07A4A', '#E8CBA7'), '#FBEBD9'),
    'Americano': (Cangkir('#3A2417'), '#F3E6DA'),
    'KopiTubruk': (Cangkir('#5A3A25', True, TEAL), '#E8F1EF'),
    'Cappuccino': (Cangkir('#D9B48A'), '#FBEFE0'),
    'Matcha': (GelasEs('#9CCB8A', '#F4F9EE'), '#EAF4E3'),
    'TehLeci': (GelasEs('#F2B26B', '#FFE2B8'), '#FDEFDC'),
    'Cokelat': (Cangkir('#5B3A29', True, APRICOT), '#F6E3D6'),
    'Croissant': (Croissant(), '#FDF0DC'),
    'RotiSrikaya': (RotiBakar(), '#FBF1DE'),
    'PisangGoreng': (PisangGoreng(), '#FFF1D2'),
    'NasiGoreng': (NasiGoreng(), '#FBEBD3'),
    'MieGoreng': (MieGoreng(), '#F9E6CC'),
    # Toko & retail
    'MiInstan': (Bungkus(MERAH, KUNING, APRICOT, ''), '#FDE7E0'),
    'Beras': (Karung('#EBDDBD', 'BERAS'), '#F7F0DE'),
    'MinyakGoreng': (Botol(KUNING, MERAH, PUTIH, 'MINYAK', 14), '#FFF6D6'),
    'GulaPasir': (Bungkus('#F2F2EE', '#DCEBE8', TEAL, ''), '#EDF3F2'),
    'SabunMandi': (Sabun(), '#E6F4F0'),
    'AirGalon': (Galon(), '#E3F1F7'),
    'SusuKotak': (KotakSusu(BIRU), '#E7F0FA'),
    'KopiSachet': (Bungkus(COKLAT, KRIM, APRICOT, ''), '#F4E9DF'),
    'TehKotak': (KotakSusu(HIJAU), '#E7F3E6'),
    'SampoBotol': (Botol('#B39DDB', TEAL, PUTIH, 'SAMPO', 15), '#EFEAF8'),
    'SnackBungkus': (Bungkus(APRICOT, KRIM, MERAH, ''), '#FDEBDB'),
    'TelurAyam': (Telur(), '#F8F1E4'),
    # Salon & jasa
    'PotongRambut': (Gunting(), '#E8F1EF'),
    'Creambath': (Jar('#F7C6D0', TEAL), '#FBEAEE'),
    'WarnaRambut': (Jar('#B39DDB', APRICOT), '#EFEAF8'),
    'Smoothing': (PengeringRambut(), '#FDEBDB'),
    'Manicure': (Kuteks(MERAH), '#FDE7E0'),
    'Pedicure': (Kuteks(UNGU), '#EFEAF8'),
    'FacialWajah': (Jar('#BDE4DA', TEAL, True), '#E6F4F0'),
    'PijatRefleksi': (Daun(HIJAU), '#E7F3E6'),
    'Spa': (Daun('#9CCB8A') + Circ(150, 60, 14, APRICOT), '#EAF4E3'),
    'Hairmask': (Jar('#F2B26B', TEAL), '#FDEFDC'),
    'Waxing': (Jar('#F6D365', MERAH), '#FFF6D6'),
    'Sisir': (Sisir().replace('y="66"','y="60"'), '#E3F1F7'),
    # Laundry
    'CuciKiloan': (KeranjangBaju(), '#E3F1F7'),
    'CuciSetrika': (Lipatan(BIRU, PUTIH), '#E7F0FA'),
    'Bedcover': (Lipatan(UNGU, '#E6DDF5'), '#EFEAF8'),
    'Selimut': (Lipatan(APRICOT, KRIM), '#FDEBDB'),
    'Jas': (Hanger(TEAL), '#E8F1EF'),
    'Kemeja': (Hanger(BIRU), '#E3F1F7'),
    'Gaun': (Hanger(MERAH), '#FDE7E0'),
    'Sepatu': (Sepatu(MERAH), '#FDE7E0'),
    'Karpet': (Lipatan(MERAH, KRIM), '#FBEAEE'),
    'Gordyn': (Lipatan(HIJAU, '#DDEFD9'), '#E7F3E6'),
    'Boneka': (Circ(100, 100, 56, KRIM) + Circ(72, 52, 20, KRIM) + Circ(128, 52, 20, KRIM) + Circ(82, 96, 6, GARIS) + Circ(118, 96, 6, GARIS) + Path('M90 118 Q100 128 110 118', garis=True), '#FBF1DE'),
    'Express': (Lipatan(TEAL, PUTIH) + Circ(150, 46, 26, APRICOT) + Path('M150 32 V48 L162 54', garis=True, lebar=5), '#E8F1EF'),
    # Apotek
    'Paracetamol': (Strip(), '#EFF3F3'),
    'SirupBatuk': (SirupBotol(MERAH), '#FDE7E0'),
    'Antiseptik': (Botol('#BFE3F0', TEAL, PUTIH, ''), '#E3F1F7'),
    'Salep': (Tube(TEAL), '#E6F4F0'),
    'VitaminC': (Botol(APRICOT, HIJAU, PUTIH, 'C'), '#FDEFDC'),
    'Antasida': (Strip(), '#FBF1DE'),
    'Masker': (Masker(), '#E3F1F7'),
    'Termometer': (Termometer(), '#FDE7E0'),
    'ObatMata': (Botol('#BFE3F0', BIRU, PUTIH, ''), '#E7F0FA'),
    'KotakP3K': (KotakObat(), '#FDE7E0'),
    'Minyak': (Botol('#9CCB8A', HIJAU, PUTIH, ''), '#EAF4E3'),
    'Hand': (Botol(PUTIH, TEAL, '#DDF1EC', ''), '#E6F4F0'),
    # Bengkel
    'OliMesin': (Kaleng(APRICOT, 'OLI'), '#FDEBDB'),
    'OliGardan': (Kaleng(BIRU, 'GARDAN', 14), '#E7F0FA'),
    'BanLuar': (Ban(), '#EFF3F3'),
    'KampasRem': (KampasRem(), '#F3EEE9'),
    'AkiMotor': (Aki(), '#E8F1EF'),
    'BusiMotor': (Busi(), '#EFF3F3'),
    'FilterOli': (FilterOli(), '#FDEBDB'),
    'LampuDepan': (Lampu(), '#FFF6D6'),
    'WiperMobil': (Wiper(), '#E3F1F7'),
    'Servis': (Kunci(), '#EFF3F3'),
    'TuneUp': (Kunci() + Circ(150, 150, 16, APRICOT), '#FDEFDC'),
}


def Bungkus_svg(isi: str, latar: str) -> str:
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="256" height="256">'
        f'<rect width="200" height="200" fill="{latar}"/>'
        f'<ellipse cx="100" cy="182" rx="62" ry="6" fill="{GARIS}" opacity=".10"/>{isi}</svg>'
    )


def main() -> None:
    sumber = KELUAR / 'Sumber'
    sumber.mkdir(parents=True, exist_ok=True)
    for nama, (isi, latar) in GAMBAR.items():
        (sumber / f'{nama}.svg').write_text(Bungkus_svg(isi, latar), encoding='utf-8')
    print(f'{len(GAMBAR)} SVG ditulis ke {sumber}')


if __name__ == '__main__':
    main()
