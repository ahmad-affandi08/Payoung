"""Ilustrasi jenis usaha (D-178) untuk kartu pilih sektor di panduan awal: satu ilustrasi per kode template sektor
(`FNB-CAF`, `RTL-GEN`, ...). Gaya dan palet sama dengan ilustrasi keadaan kosong (blob sage pucat, garis Teal Tinta,
isi Slate Teal, aksen Apricot) supaya terasa satu keluarga. Menulis SVG ke `IlustrasiSektor/Sumber/`; PNG diekspor
dengan `RenderIlustrasi.cjs` (Chromium) lalu `BuatTurunanIlustrasiSektor.py` membuat WebP kecil untuk web.

    python3 BuatIlustrasiSektor.py
"""

from pathlib import Path

from BuatIlustrasiKasir import APRICOT, BLOB, DASAR, G, GARIS, ILUSTRASI as KASIR, TERANG

KELUAR = Path(__file__).parent / 'IlustrasiSektor' / 'Sumber'

SAGE = '#7FA9A9'
TEAL = '#4F8184'


def R(x: float, y: float, w: float, h: float, r: float = 14, isi: str = 'url(#a)') -> str:
    return f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{isi}" {G}/>'


def C(cx: float, cy: float, r: float, isi: str = '#fff') -> str:
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{isi}" {G}/>'


def P(d: str, isi: str = 'none', lebar: int = 16, garis: str = GARIS) -> str:
    return (
        f'<path d="{d}" fill="{isi}" stroke="{garis}" stroke-width="{lebar}" '
        'stroke-linejoin="round" stroke-linecap="round"/>'
    )


def Uap(x: int, y: int) -> str:
    return P(f'M{x} {y} C{x-34} {y-38} {x+34} {y-62} {x} {y-104}', lebar=14, garis=SAGE)


def Tenda(kiri: int, kanan: int, atas: int, bawah: int, jumlah: int = 6, warna: str = APRICOT) -> str:
    """Tenda bergaris dengan tepi bergelombang (toko, kedai)."""
    lebar_atas = (kanan - kiri - 80) / jumlah
    lebar_bawah = (kanan - kiri) / jumlah
    keluaran = []
    for i in range(jumlah):
        isi = warna if i % 2 == 0 else '#fff'
        xa, xb = kiri + 40 + i * lebar_atas, kiri + i * lebar_bawah
        keluaran.append(
            f'<path d="M{xa:.0f} {atas} H{xa + lebar_atas:.0f} L{xb + lebar_bawah:.0f} {bawah} H{xb:.0f} Z" fill="{isi}" {G}/>'
        )
        keluaran.append(
            f'<path d="M{xb:.0f} {bawah} A{lebar_bawah / 2:.0f} {lebar_bawah / 2:.0f} 0 0 0 {xb + lebar_bawah:.0f} {bawah} Z" fill="{isi}" {G}/>'
        )
    return ''.join(keluaran)


def Toko(lambang: str) -> str:
    """Bangunan toko berteras tenda; `lambang` digambar di jendela (pusat 415, 620)."""
    return (
        R(250, 470, 524, 290, 26, TERANG)
        + R(570, 560, 130, 200, 16, 'url(#b)')
        + C(675, 665, 9, APRICOT)
        + R(300, 540, 232, 150, 16, '#fff')
        + lambang
        + Tenda(230, 794, 360, 470)
    )


ILUSTRASI: dict[str, str] = {}

# ---- Makanan & minuman -------------------------------------------------------------------------------------------
ILUSTRASI['FNB-CAF'] = (
    Uap(440, 335) + Uap(512, 320) + Uap(584, 335)
    + R(346, 395, 332, 56, 24, TERANG)
    + P('M372 451 H652 L614 745 Q610 768 588 768 H436 Q414 768 410 745 Z', 'url(#b)')
    + P('M386 545 H638 L626 660 H398 Z', APRICOT)
    + C(512, 603, 28)
    + f'<g transform="rotate(28 800 690)"><ellipse cx="800" cy="690" rx="50" ry="68" fill="url(#a)" {G}/>'
    f'<path d="M800 628 Q776 690 800 752" fill="none" stroke="#fff" stroke-width="12" stroke-linecap="round"/></g>'
    + f'<g transform="rotate(-24 238 660)"><ellipse cx="238" cy="660" rx="38" ry="52" fill="url(#b)" {G}/>'
    f'<path d="M238 614 Q220 660 238 706" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round"/></g>'
)

ILUSTRASI['FNB-QSR'] = (
    P('M260 440 V745 M764 440 V745', lebar=16)
    + R(230, 590, 564, 160, 26, 'url(#b)')
    + P('M430 565 H594 Q590 615 512 617 Q434 615 430 565 Z', TERANG)
    + Uap(480, 540) + Uap(544, 540)
    + R(262, 636, 110, 64, 16, TERANG)
    + Tenda(210, 814, 330, 440)
    + C(330, 758, 44, 'url(#a)') + C(694, 758, 44, 'url(#a)')
    + C(330, 758, 14, TERANG) + C(694, 758, 14, TERANG)
)

ILUSTRASI['FNB-RST'] = (
    f'<ellipse cx="512" cy="700" rx="290" ry="52" fill="{TERANG}" {G}/>'
    + P('M262 690 C262 480 380 382 512 382 C644 382 762 480 762 690 Z', 'url(#a)')
    + C(512, 352, 32, APRICOT)
    + P('M340 560 C350 500 400 460 460 448', lebar=16, garis='#fff')
    + P('M130 480 V560 Q130 600 164 600 Q198 600 198 560 V480 M164 600 V760 M164 480 V560', lebar=16)
    + P('M880 760 V500 Q830 530 834 640 H880', '#fff')
    + C(240, 330, 12, APRICOT)
)

ILUSTRASI['FNB-BAK'] = (
    f'<ellipse cx="512" cy="752" rx="300" ry="34" fill="{TERANG}" {G}/>'
    + R(290, 560, 444, 170, 36, 'url(#b)')
    + R(340, 430, 344, 140, 32, TERANG)
    + P('M290 585 Q290 548 330 548 H694 Q734 548 734 585 Q700 640 664 590 Q630 650 590 592 Q548 640 512 592 Q470 650 430 592 Q392 640 360 590 Q320 640 290 585 Z', '#fff')
    + P('M340 452 Q340 414 380 414 H644 Q684 414 684 452 Q650 500 616 456 Q580 506 540 458 Q500 500 466 456 Q430 500 396 458 Q364 492 340 452 Z', '#fff')
    + C(512, 384, 38, APRICOT)
    + P('M512 346 Q520 306 556 296', lebar=14)
    + P('M366 666 H398 M470 690 H502 M580 664 H612 M656 692 H688', lebar=14, garis=APRICOT)
)

# ---- Toko & retail -----------------------------------------------------------------------------------------------
ILUSTRASI['RTL-GEN'] = Toko(
    P('M340 600 H490 L470 668 H360 Z', APRICOT) + P('M370 600 Q415 540 460 600', lebar=14) + C(415, 634, 14)
)

ILUSTRASI['RTL-FRS'] = (
    P('M300 470 Q340 380 400 410 Q420 360 480 380', 'url(#a)')
    + P('M560 420 Q600 330 660 380 Q700 340 730 420', 'url(#a)')
    + C(380, 520, 66, APRICOT) + C(512, 500, 70, '#fff') + C(650, 520, 66, APRICOT)
    + P('M512 432 Q514 396 540 380', lebar=14)
    + R(240, 560, 544, 200, 26, 'url(#b)')
    + P('M240 640 H784 M240 710 H784', lebar=12, garis=SAGE)
    + R(226, 535, 572, 56, 22, 'url(#a)')
)

ILUSTRASI['RTL-FSH'] = (
    R(190, 330, 26, 430, 13, 'url(#b)') + R(808, 330, 26, 430, 13, 'url(#b)')
    + R(190, 330, 644, 28, 14, 'url(#a)')
    + P('M350 358 V396 M350 396 L296 440 M350 396 L404 440', lebar=14)
    + P('M290 440 L262 480 L292 510 L310 492 V690 H390 V492 L408 510 L438 480 L410 440 Q350 470 290 440 Z', APRICOT)
    + P('M650 358 V396 M650 396 L610 430 M650 396 L690 430', lebar=14)
    + P('M610 430 Q650 460 690 430 L740 700 H560 Z', 'url(#a)')
    + P('M600 540 H700', lebar=12, garis='#fff')
    + R(450, 640, 70, 120, 14, TERANG)
)

ILUSTRASI['RTL-ELC'] = (
    R(250, 400, 400, 270, 26, '#fff') + R(278, 428, 344, 214, 12, 'url(#b)')
    + P('M222 670 H678 L650 722 H250 Z', TERANG)
    + P('M312 560 L372 500 L430 548 L500 470 L580 540', lebar=16, garis='#fff')
    + C(500, 470, 14, APRICOT)
    + R(640, 470, 160, 286, 32, 'url(#a)') + R(660, 500, 120, 200, 14, '#fff')
    + C(720, 728, 12, APRICOT)
    + P('M690 540 H750 M690 580 H730', lebar=12, garis=SAGE)
)

ILUSTRASI['RTL-BLD'] = (
    R(240, 600, 190, 70, 10, APRICOT) + R(446, 600, 190, 70, 10, TERANG) + R(652, 600, 130, 70, 10, APRICOT)
    + R(240, 686, 100, 70, 10, TERANG) + R(356, 686, 190, 70, 10, APRICOT) + R(562, 686, 220, 70, 10, TERANG)
    + R(340, 514, 190, 70, 10, TERANG) + R(546, 514, 190, 70, 10, APRICOT)
    + f'<g transform="rotate(-32 640 400)">{R(626, 270, 30, 260, 12, TERANG)}{R(560, 250, 160, 70, 16, "url(#b)")}</g>'
    + P('M330 470 Q330 380 410 380 Q490 380 490 470 Z', 'url(#a)')
    + R(310, 462, 200, 34, 14, 'url(#b)')
)

ILUSTRASI['RTL-PHR'] = Toko(
    f'<g transform="rotate(-35 415 615)"><rect x="345" y="577" width="140" height="76" rx="38" fill="#fff" {G}/>'
    f'<path d="M415 577 H447 A38 38 0 0 1 447 653 H415 Z" fill="{APRICOT}" stroke="{GARIS}" stroke-width="16" stroke-linejoin="round"/></g>'
) + P('M512 330 V260 M477 295 H547', lebar=28, garis=APRICOT)

ILUSTRASI['RTL-PET'] = (
    P('M330 360 L350 250 L440 330 Z M694 360 L674 250 L584 330 Z', 'url(#b)')
    + C(512, 480, 200, 'url(#a)')
    + C(436, 460, 36) + C(588, 460, 36)
    + C(444, 466, 16, GARIS) + C(580, 466, 16, GARIS)
    + P('M492 520 H532 L512 548 Z', APRICOT)
    + P('M512 548 V576 M512 576 Q484 600 462 580 M512 576 Q540 600 562 580', lebar=12)
    + P('M380 540 L300 520 M384 566 L304 580 M644 540 L724 520 M640 566 L720 580', lebar=10, garis='#fff')
    + P('M300 700 H724 L694 760 H330 Z', APRICOT)
    + C(780, 380, 14, APRICOT) + C(808, 340, 10, APRICOT)
)

def Tulip(x: int, y: int, isi: str = APRICOT) -> str:
    return P(f'M{x-38} {y} L{x-20} {y-44} L{x} {y-12} L{x+20} {y-44} L{x+38} {y} Q{x+38} {y+58} {x} {y+58} Q{x-38} {y+58} {x-38} {y} Z', isi)


ILUSTRASI['RTL-FLR'] = (
    P('M420 470 Q404 420 372 372 M512 470 V340 M604 470 Q620 420 652 372', lebar=16)
    + P('M430 560 Q380 500 326 540 Q380 592 430 560 Z M594 560 Q644 500 698 540 Q644 592 594 560 Z', 'url(#a)')
    + Tulip(370, 330) + Tulip(512, 290, '#fff') + Tulip(654, 330)
    + P('M390 470 H634 L604 740 Q600 764 576 764 H448 Q424 764 420 740 Z', 'url(#b)')
    + P('M400 540 H624', lebar=12, garis=SAGE)
    + C(512, 630, 26, TERANG)
)

ILUSTRASI['WHS-DST'] = (
    R(190, 400, 430, 300, 24, '#fff')
    + P('M620 480 H730 Q760 480 776 506 L824 584 Q836 604 836 628 V700 H620 Z', 'url(#b)')
    + P('M660 510 H724 L772 584 H660 Z', TERANG)
    + P('M190 620 H620', lebar=16, garis=APRICOT)
    + R(226, 432, 150, 130, 14, TERANG) + P('M301 432 V562', lebar=12, garis=SAGE)
    + R(392, 456, 190, 106, 14, TERANG) + P('M487 456 V562', lebar=12, garis=SAGE)
    + C(330, 720, 56, 'url(#a)') + C(330, 720, 18, TERANG)
    + C(720, 720, 56, 'url(#a)') + C(720, 720, 18, TERANG)
)

# ---- Jasa & layanan ----------------------------------------------------------------------------------------------
ILUSTRASI['SVC-SLN'] = (
    P('M396 270 L512 500 L616 690', lebar=44) + P('M396 270 L512 500 L616 690', lebar=20, garis='#fff')
    + P('M628 270 L512 500 L408 690', lebar=44) + P('M628 270 L512 500 L408 690', lebar=20, garis=SAGE)
    + f'<circle cx="396" cy="740" r="66" fill="none" stroke="{GARIS}" stroke-width="36"/><circle cx="396" cy="740" r="66" fill="none" stroke="{APRICOT}" stroke-width="16"/>'
    + f'<circle cx="628" cy="740" r="66" fill="none" stroke="{GARIS}" stroke-width="36"/><circle cx="628" cy="740" r="66" fill="none" stroke="{APRICOT}" stroke-width="16"/>'
    + C(512, 500, 22, TERANG)
    + R(740, 360, 70, 300, 24, 'url(#b)')
    + P('M740 400 H700 M740 440 H700 M740 480 H700 M740 520 H700 M740 560 H700 M740 600 H700', lebar=14, garis=GARIS)
    + C(230, 350, 12, APRICOT) + C(270, 300, 8, APRICOT)
)

ILUSTRASI['SVC-LDR'] = KASIR['Cucian']

ILUSTRASI['SVC-WRK'] = KASIR['Servis']

ILUSTRASI['SVC-FIT'] = (
    R(210, 450, 60, 230, 24, 'url(#b)') + R(270, 490, 46, 150, 16, 'url(#a)')
    + R(754, 450, 60, 230, 24, 'url(#b)') + R(708, 490, 46, 150, 16, 'url(#a)')
    + R(316, 548, 392, 34, 17, TERANG)
    + P('M380 548 V582 M644 548 V582', lebar=10, garis=SAGE)
    + R(420, 710, 184, 56, 18, 'url(#b)')
    + C(512, 330, 74, APRICOT) + P('M512 280 V330 L552 352', lebar=14, garis='#fff')
)

ILUSTRASI['SVC-CWS'] = (
    P('M228 640 L258 530 Q272 480 326 480 H506 Q556 480 588 516 L630 560 L752 570 Q800 576 800 622 V652 H228 Z', 'url(#a)')
    + P('M304 530 H394 V484 H330 Q312 484 306 504 Z M440 484 V530 H560 L520 492 Q510 484 498 484 Z', TERANG, 12)
    + C(350, 664, 64, 'url(#b)') + C(350, 664, 24, TERANG)
    + C(692, 664, 64, 'url(#b)') + C(692, 664, 24, TERANG)
    + f'<circle cx="300" cy="360" r="62" fill="#fff" fill-opacity=".9" stroke="{GARIS}" stroke-width="12"/>'
    + f'<circle cx="470" cy="300" r="42" fill="#fff" fill-opacity=".9" stroke="{GARIS}" stroke-width="12"/>'
    + f'<circle cx="640" cy="350" r="74" fill="#fff" fill-opacity=".9" stroke="{GARIS}" stroke-width="12"/>'
    + f'<circle cx="780" cy="260" r="34" fill="#fff" fill-opacity=".9" stroke="{GARIS}" stroke-width="12"/>'
    + P('M270 340 Q286 320 308 322 M612 330 Q628 310 652 312', lebar=10, garis=SAGE)
    + P('M200 740 Q232 700 264 740 Q232 772 200 740 Z', APRICOT, 12)
)

ILUSTRASI['SVC-GEN'] = (
    R(340, 270, 344, 200, 12, '#fff')
    + P('M390 330 H634 M390 380 H590 M390 430 H540', lebar=14, garis=SAGE)
    + R(240, 450, 544, 210, 36, 'url(#b)')
    + R(300, 600, 424, 40, 14, '#fff')
    + C(700, 520, 22, APRICOT) + P('M300 520 H560', lebar=14, garis=SAGE)
    + R(330, 640, 364, 130, 12, TERANG)
    + P('M376 690 H640 M376 730 H588', lebar=14, garis='#fff')
)

# ---- Bisnis & perusahaan -----------------------------------------------------------------------------------------
ILUSTRASI['BIZ-JSA'] = (
    f'<g transform="rotate(-6 440 400)">{R(280, 250, 320, 300, 18, "#fff")}</g>'
    + P('M322 440 V400 M382 440 V350 M442 440 V380 M502 440 V310', lebar=34, garis=TEAL)
    + P('M322 440 V400 M502 440 V310', lebar=34, garis=APRICOT)
    + P('M410 468 V430 Q410 408 432 408 H592 Q614 408 614 430 V468', lebar=26)
    + R(240, 470, 544, 290, 40, 'url(#b)')
    + P('M240 590 H784', lebar=14)
    + R(466, 556, 92, 70, 16, APRICOT)
    + C(512, 592, 10, GARIS)
)

ILUSTRASI['BIZ-KTR'] = (
    R(230, 330, 400, 430, 16, '#fff')
    + P('M270 400 H590 M270 460 H590 M270 520 H590 M270 580 H590 M270 640 H590 M270 700 H590', lebar=8, garis=BLOB)
    + P('M320 720 V470 L430 410 L540 470 V720 Z M320 580 H540', lebar=14, garis=TEAL)
    + P('M560 700 Q560 560 690 560 Q820 560 820 700 Z', APRICOT)
    + P('M690 572 V690', lebar=14)
    + R(534, 688, 312, 50, 22, 'url(#b)')
    + P('M610 600 Q640 575 690 575', lebar=12, garis='#fff')
)

ILUSTRASI['BIZ-MFG'] = (
    R(320, 250, 74, 190, 12, 'url(#b)') + R(430, 300, 74, 140, 12, 'url(#b)')
    + C(380, 210, 34, '#fff') + C(430, 170, 44, '#fff') + C(480, 258, 30, '#fff')
    + P('M230 540 V440 L400 520 V440 L570 520 V440 L794 540 V750 H230 Z', 'url(#a)')
    + R(300, 600, 100, 80, 10, '#fff') + R(440, 600, 100, 80, 10, '#fff')
    + P('M230 750 H794', lebar=16)
    + f'<g transform="translate(700 650)"><circle r="88" fill="{APRICOT}" {G}/>'
    + ''.join(f'<rect x="-16" y="-122" width="32" height="44" rx="8" fill="{APRICOT}" {G} transform="rotate({a})"/>' for a in range(0, 360, 45))
    + f'<circle r="46" fill="#fff" {G}/></g>'
)


if __name__ == '__main__':
    KELUAR.mkdir(parents=True, exist_ok=True)
    for kode, isi in ILUSTRASI.items():
        (KELUAR / f'{kode}.svg').write_text(DASAR.replace('{isi}', isi), encoding='utf-8')
    print(f'{len(ILUSTRASI)} ilustrasi ditulis ke {KELUAR}')
