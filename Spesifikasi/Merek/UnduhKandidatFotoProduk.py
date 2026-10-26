"""Mencari kandidat foto produk asli untuk tangkapan layar kasir per sektor (D-212) lewat Openverse, hanya yang berlisensi
CC0 atau domain publik (PDM): aman dipakai komersial tanpa atribusi. Menyimpan kandidat ke folder kerja dan metadata
sumbernya ke `kandidat.json`; pemilihan dilakukan manual dengan melihat lembar kontak, lalu `PilihFotoProduk.py`.

    python3 UnduhKandidatFotoProduk.py <folder-kerja> [2]
"""
import io
import json
import sys
import time
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

from PIL import Image

UA = 'PayoungSitus/1.0 (ipsrs.gemolong@gmail.com)'

QUERI = {
    'KopiSusu': ['iced coffee with milk', 'iced latte'], 'Americano': ['americano coffee cup', 'black coffee cup'],
    'KopiTubruk': ['black coffee glass', 'coffee glass'], 'Cappuccino': ['cappuccino'], 'Matcha': ['matcha latte', 'matcha'],
    'TehLeci': ['iced tea glass', 'iced tea'], 'Cokelat': ['hot chocolate cup', 'hot chocolate'], 'Croissant': ['croissant'],
    'RotiSrikaya': ['toast bread jam', 'toast breakfast'], 'PisangGoreng': ['fried banana', 'banana fritters'],
    'NasiGoreng': ['fried rice', 'nasi goreng'], 'MieGoreng': ['fried noodles', 'noodles plate'],
    'Beras': ['rice sack', 'bag of rice'], 'MinyakGoreng': ['cooking oil bottle', 'vegetable oil bottle'], 'GulaPasir': ['sugar bag', 'white sugar'],
    'TelurAyam': ['eggs carton', 'chicken eggs'], 'AirGalon': ['water gallon', 'water dispenser bottle'], 'SusuKotak': ['milk carton', 'milk box'],
    'TehKotak': ['tea drink carton', 'bottled tea'], 'KopiSachet': ['instant coffee sachet', 'coffee sachet'], 'MiInstan': ['instant noodles package', 'instant noodles'],
    'SabunMandi': ['soap bar', 'bar of soap'], 'SampoBotol': ['shampoo bottle'], 'SnackBungkus': ['potato chips bag', 'snack bag'],
    'PotongRambut': ['hair cutting scissors', 'haircut salon'], 'Creambath': ['hair spa treatment', 'hair treatment cream'], 'WarnaRambut': ['hair dye', 'hair coloring'],
    'Smoothing': ['hair straightening', 'flat iron hair'], 'Hairmask': ['hair mask jar', 'hair care product'], 'Sisir': ['hair dryer', 'blow dry hair'],
    'Manicure': ['manicure', 'nail polish'], 'Pedicure': ['pedicure', 'toenails'], 'FacialWajah': ['facial treatment', 'face mask spa'],
    'PijatRefleksi': ['foot massage', 'reflexology'], 'Spa': ['spa massage stones', 'spa treatment'], 'Waxing': ['waxing legs', 'wax hair removal'],
    'CuciKiloan': ['laundry basket', 'laundry clothes'], 'CuciSetrika': ['ironing clothes', 'iron shirt'], 'Express': ['washing machine', 'laundromat'],
    'Bedcover': ['folded bedding', 'bedsheets folded'], 'Selimut': ['folded blanket', 'blanket'], 'Jas': ['suit jacket', 'blazer'],
    'Kemeja': ['folded shirt', 'dress shirt'], 'Gaun': ['dress hanging', 'evening dress'], 'Karpet': ['carpet rug', 'rug'],
    'Gordyn': ['curtains window', 'curtain'], 'Sepatu': ['sneakers', 'white sneakers'], 'Boneka': ['teddy bear', 'plush toy'],
    'Paracetamol': ['medicine blister pack', 'pills blister'], 'Antasida': ['tablets pills', 'pills'], 'SirupBatuk': ['cough syrup bottle', 'medicine bottle'],
    'ObatMata': ['eye drops', 'eye drop bottle'], 'VitaminC': ['vitamin c', 'vitamin bottle'], 'Minyak': ['essential oil bottle', 'eucalyptus oil'],
    'Masker': ['surgical mask', 'face mask medical'], 'Termometer': ['digital thermometer', 'thermometer'], 'KotakP3K': ['first aid kit', 'first aid box'],
    'Hand': ['hand sanitizer', 'sanitizer bottle'], 'Salep': ['ointment tube', 'cream tube'], 'Antiseptik': ['antiseptic bottle', 'disinfectant bottle'],
    'Servis': ['motorcycle mechanic', 'motorcycle repair'], 'TuneUp': ['engine mechanic', 'car engine repair'], 'OliMesin': ['motor oil bottle', 'engine oil'],
    'OliGardan': ['lubricant oil can', 'oil can'], 'FilterOli': ['oil filter', 'car oil filter'], 'BanLuar': ['motorcycle tire', 'tire'],
    'KampasRem': ['brake pads', 'brake disc'], 'AkiMotor': ['car battery', 'battery'], 'BusiMotor': ['spark plug', 'spark plugs'],
    'LampuDepan': ['headlight', 'motorcycle headlight'], 'WiperMobil': ['windshield wiper', 'car wiper'],
}


# Putaran kedua: kata kunci umum, hanya dari sumber foto stok CC0 terkurasi (kualitas dan kebersihan konten lebih terjaga).
SUMBER_STOK = 'stocksnap,wordpress,rawpixel,nappy,justtakeitfree'
QUERI2 = {k.replace(' ', '_'): [k] for k in [
    'rice', 'cooking oil', 'sugar', 'bottled water', 'milk bottle', 'noodles package', 'bread loaf', 'chips snack', 'soap', 'shampoo',
    'tea', 'coffee beans bag', 'canned food', 'fruit basket', 'vegetables', 'cereal box', 'juice bottle', 'cookies', 'detergent', 'grocery shelf',
    'nail polish', 'scissors comb', 'hair dryer', 'hair brush', 'spa towels', 'spa candle', 'face cream jar', 'hair color', 'makeup brushes', 'perfume bottle', 'lipstick', 'massage oil',
    'laundry basket', 'folded towels', 'washing machine', 'clothes iron', 'shirts hanger', 'pillows bedding', 'laundry detergent', 'sneakers shoes', 'teddy bear', 'curtains', 'carpet', 'suit',
    'pills', 'medicine bottle', 'vitamins', 'syringe', 'first aid kit', 'hand sanitizer', 'thermometer', 'face mask', 'cream tube', 'eye drops', 'herbal medicine', 'bandage',
    'motorcycle', 'motorcycle helmet', 'wrench tools', 'tire', 'car engine', 'car battery', 'mechanic', 'car headlight', 'oil can', 'brake disc', 'toolbox', 'car wash',
]}


def Ambil(url: str) -> bytes:
    permintaan = urllib.request.Request(url, headers={'User-Agent': UA})
    with urllib.request.urlopen(permintaan, timeout=12) as r:
        return r.read()


def Cari(q: str) -> list[dict]:
    isi = {'q': q, 'license': 'cc0,pdm', 'category': 'photograph', 'page_size': 10, 'mature': 'false'}
    if len(sys.argv) > 2 and sys.argv[2] == '2':
        isi['source'] = SUMBER_STOK
    param = urllib.parse.urlencode(isi)
    for _ in range(3):
        try:
            return json.loads(Ambil(f'https://api.openverse.org/v1/images/?{param}'))['results']
        except Exception:  # noqa: BLE001
            time.sleep(1.5)
    return []


def Kerjakan(kerja: Path, nama: str, daftar: list[str]) -> tuple[str, list[dict]]:
    """Kandidat memakai thumbnail Openverse (600 px, cepat dan seragam) bukan berkas asli dari situs sumber."""
    hasil: list[dict] = []
    lihat: set[str] = set()
    for q in daftar:
        for r in Cari(q):
            if r['url'] in lihat or len(hasil) >= 6:
                continue
            lihat.add(r['url'])
            try:
                gambar = Image.open(io.BytesIO(Ambil(r['thumbnail']))).convert('RGB')
            except Exception:  # noqa: BLE001
                continue
            if min(gambar.size) < 300:
                continue
            i = len(hasil)
            gambar.save(kerja / f'{nama}_{i}.jpg', quality=90)
            hasil.append({'i': i, 'url': r['url'], 'landing': r.get('foreign_landing_url'), 'creator': r.get('creator'), 'license': r['license'], 'provider': r.get('provider'), 'title': r.get('title')})
    return nama, hasil


def main() -> None:
    kerja = Path(sys.argv[1])
    kerja.mkdir(parents=True, exist_ok=True)
    meta: dict[str, list[dict]] = {}
    daftar = QUERI2 if len(sys.argv) > 2 and sys.argv[2] == '2' else QUERI
    with ThreadPoolExecutor(max_workers=6) as kolam:
        for nama, hasil in kolam.map(lambda kv: Kerjakan(kerja, *kv), daftar.items()):
            meta[nama] = hasil
            print(nama, len(hasil), flush=True)
    (kerja / ('kandidat2.json' if daftar is QUERI2 else 'kandidat.json')).write_text(json.dumps(meta, indent=1))


if __name__ == '__main__':
    main()
