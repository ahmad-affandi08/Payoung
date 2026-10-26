"""Menyusun foto produk asli untuk tangkapan layar kasir per sektor di situs pemasaran (D-212) dari kandidat hasil
`UnduhKandidatFotoProduk.py`: memotong persegi, mengecilkan ke 320 px, dan mencatat sumber tiap foto ke `Sumber.json`.

Semua foto berlisensi CC0 atau domain publik (PDM), yaitu bebas dipakai komersial tanpa atribusi; sumbernya tetap
dicatat supaya mudah ditelusuri dan dicabut bila pemiliknya meminta. Hasil (JPEG kecil) ikut di Git karena kandidat
mentahnya hanya ada di folder kerja; hasil pencarian Openverse bisa berubah dari waktu ke waktu.

    python3 SusunFotoProdukDemo.py <folder-kandidat>
"""
import json
import sys
from pathlib import Path

from PIL import Image

AKAR = Path(__file__).resolve().parents[2]
KELUAR = AKAR / 'Aplikasi/Kasir/AlatSitus/GambarProduk'

# slug: (putaran 1/2, kunci kandidat, indeks, fokus x, fokus y); fokus 0..1 menentukan bagian foto yang dipertahankan.
PILIHAN = {
    # Kafe & resto
    'KopiSusu': (1, 'KopiSusu', 2, .5, .5), 'Americano': (1, 'Americano', 4, .5, .5), 'KopiTubruk': (1, 'KopiTubruk', 4, .5, .5),
    'Cappuccino': (1, 'Cappuccino', 1, .5, .5), 'Matcha': (1, 'Matcha', 1, .5, .5), 'TehLeci': (1, 'TehLeci', 4, .5, .5),
    'Cokelat': (1, 'Cokelat', 2, .5, .5), 'Croissant': (1, 'Croissant', 3, .5, .5), 'RotiSrikaya': (1, 'RotiSrikaya', 0, .5, .5),
    'PisangGoreng': (1, 'PisangGoreng', 1, .5, .5), 'NasiGoreng': (1, 'NasiGoreng', 4, .5, .5), 'MieGoreng': (1, 'MieGoreng', 4, .5, .5),
    # Toko & retail
    'Beras': (1, 'Beras', 0, .5, .5), 'MinyakGoreng': (2, 'cooking_oil', 4, .5, .5), 'GulaPasir': (2, 'sugar', 4, .5, .5),
    'TelurAyam': (1, 'TelurAyam', 2, .5, .5), 'AirGalon': (2, 'bottled_water', 0, .5, .5), 'SusuSegar': (2, 'milk_bottle', 0, .5, .5),
    'KopiBiji': (2, 'coffee_beans_bag', 4, .5, .5), 'RotiTawar': (2, 'bread_loaf', 0, .5, .5), 'SabunBatang': (2, 'soap', 3, .5, .5),
    'SampoBotol': (2, 'shampoo', 0, .5, .5), 'Keripik': (2, 'chips_snack', 1, .5, .5), 'MakananKaleng': (2, 'canned_food', 0, .5, .5),
    # Salon & jasa
    'PotongRambut': (2, 'scissors_comb', 0, .5, .5), 'BlowRambut': (2, 'hair_dryer', 1, .5, .5), 'PewarnaanRambut': (1, 'WarnaRambut', 5, .5, .5),
    'Smoothing': (1, 'Smoothing', 0, .5, .4), 'HairSpa': (2, 'spa_candle', 5, .5, .5), 'Manicure': (1, 'Manicure', 3, .5, .5),
    'NailArt': (2, 'nail_polish', 0, .5, .5), 'FacialWajah': (2, 'spa_towels', 2, .6, .5), 'BodySpa': (2, 'spa_candle', 0, .5, .5),
    'Aromaterapi': (2, 'spa_candle', 2, .5, .5), 'MakeUp': (2, 'makeup_brushes', 4, .5, .5), 'Parfum': (2, 'perfume_bottle', 0, .5, .5),
    # Laundry
    'CuciKiloan': (2, 'washing_machine', 0, .5, .5), 'CuciSetrika': (2, 'clothes_iron', 2, .5, .5), 'Express': (2, 'washing_machine', 2, .5, .5),
    'LipatPakaian': (1, 'Kemeja', 5, .5, .5), 'Bedcover': (2, 'pillows_bedding', 1, .5, .5), 'Kemeja': (2, 'shirts_hanger', 0, .5, .5),
    'Jas': (2, 'suit', 0, .5, .8), 'Karpet': (2, 'carpet', 0, .5, .5), 'Gordyn': (2, 'curtains', 0, .5, .5),
    'Gaun': (1, 'Gaun', 0, .5, .5), 'CuciSepatu': (2, 'sneakers_shoes', 2, .5, .5), 'Boneka': (2, 'teddy_bear', 4, .5, .5),
    # Apotek
    'Paracetamol': (1, 'Paracetamol', 1, .5, .5), 'Antasida': (1, 'Antasida', 4, .5, .5), 'SirupObat': (2, 'medicine_bottle', 2, .5, .5),
    'ObatTetes': (2, 'medicine_bottle', 5, .5, .5), 'VitaminC': (2, 'vitamins', 2, .5, .5), 'VitaminMulti': (2, 'pills', 1, .5, .5),
    'JamuHerbal': (2, 'herbal_medicine', 0, .5, .5), 'MinyakAroma': (1, 'Minyak', 4, .5, .5), 'Masker': (2, 'face_mask', 4, .5, .5),
    'Termometer': (2, 'thermometer', 0, .5, .5), 'Spuit': (2, 'syringe', 1, .5, .5), 'Perban': (2, 'bandage', 3, .5, .5),
    # Bengkel
    'ServisRutin': (2, 'motorcycle', 3, .5, .5), 'ServisMesin': (2, 'car_engine', 3, .5, .5), 'CuciKendaraan': (2, 'car_wash', 1, .5, .5),
    'OliMesin': (2, 'oil_can', 2, .5, .5), 'PelumasSemprot': (2, 'oil_can', 4, .5, .5), 'BanLuar': (2, 'tire', 2, .5, .5),
    'AkiMotor': (2, 'car_battery', 0, .5, .5), 'LampuDepan': (2, 'car_headlight', 1, .5, .5), 'Helm': (2, 'motorcycle_helmet', 0, .5, .5),
    'KunciPas': (2, 'wrench_tools', 0, .5, .5), 'SetKunciSok': (2, 'wrench_tools', 1, .5, .5), 'Obeng': (2, 'toolbox', 4, .5, .5),
}


def main() -> None:
    sumber = Path(sys.argv[1])
    meta = {1: json.loads((sumber / 'kandidat.json').read_text()), 2: json.loads((sumber / 'kandidat2.json').read_text())}
    KELUAR.mkdir(parents=True, exist_ok=True)
    kredit: dict[str, dict] = {}
    for slug, (putaran, kunci, i, fx, fy) in PILIHAN.items():
        gambar = Image.open(sumber / f'{kunci}_{i}.jpg').convert('RGB')
        lebar, tinggi = gambar.size
        sisi = min(lebar, tinggi)
        kiri = round((lebar - sisi) * fx)
        atas = round((tinggi - sisi) * fy)
        gambar.crop((kiri, atas, kiri + sisi, atas + sisi)).resize((320, 320), Image.LANCZOS).save(KELUAR / f'{slug}.jpg', quality=84, optimize=True)
        m = meta[putaran][kunci][i]
        kredit[slug] = {k: m[k] for k in ('title', 'creator', 'license', 'provider', 'landing', 'url')}
    (KELUAR / 'Sumber.json').write_text(json.dumps(kredit, indent=1, ensure_ascii=False) + '\n', encoding='utf-8')
    print(len(PILIHAN), 'foto,', sum(p.stat().st_size for p in KELUAR.glob('*.jpg')) // 1024, 'KB')


if __name__ == '__main__':
    main()
