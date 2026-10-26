import 'dart:typed_data';

import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:test/test.dart';

void main() {
  group('TataLetakStruk', () {
    test('58 mm = 32 kolom: tengah, dua kolom, garis, dan pecah kata di spasi', () {
      final teks = TataLetakStruk.KeTeks(
        const DokumenStruk([
          BarisTeks('Kopi Senja', rata: RataStruk.Tengah, tebal: true),
          BarisGaris(),
          BarisDuaKolom('Kopi Susu Gula Aren Ukuran Besar Sekali', '36.000'),
          BarisTeks('Barang yang sudah dibeli bisa ditukar dalam 7 hari'),
          BarisTeks('Kanan', rata: RataStruk.Kanan),
        ]),
        LebarKertas.Mm58,
      );
      expect(teks, [
        '           Kopi Senja',
        '-' * 32,
        'Kopi Susu Gula Aren',
        'Ukuran Besar Sekali       36.000',
        'Barang yang sudah dibeli bisa',
        'ditukar dalam 7 hari',
        '                           Kanan',
      ]);
      expect(teks.every((b) => b.length <= 32), isTrue);
    });

    test('80 mm = 48 kolom. Teks besar memakai setengah kolom', () {
      final teks = TataLetakStruk.KeTeks(
        const DokumenStruk([
          BarisDuaKolom('TOTAL', 'Rp 56.000'),
          BarisTeks('LUNAS', rata: RataStruk.Tengah, besar: true),
        ]),
        LebarKertas.Mm80,
      );
      expect(teks[0], hasLength(48));
      expect(teks[0], startsWith('TOTAL'));
      expect(teks[0], endsWith('Rp 56.000'));
      expect(teks[1], '         LUNAS');
    });

    test('huruf beraksen & tanda kutip pintar dirapikan ke ASCII. Karakter lain jadi "?"', () {
      expect(TataLetakStruk.RapikanTeks('Café “Senja” – 2×'), 'Cafe "Senja" - 2x');
      expect(TataLetakStruk.RapikanTeks('Kopi ☕'), 'Kopi ?');
      expect(TataLetakStruk.RapikanTeks('−Rp 2.000'), '-Rp 2.000');
    });
  });

  group('GambarMonokrom', () {
    test('RGBA → 1 bit: hitam pekat = 1, putih & transparan = 0, diperkecil ke lebar maksimal', () {
      final rgba = Uint8List(4 * 4 * 4);
      for (var i = 0; i < 16; i++) {
        final hitam = i < 8; // dua baris atas hitam
        rgba.setAll(i * 4, hitam ? [0, 0, 0, 255] : [0, 0, 0, 0]);
      }
      final gambar = GambarMonokrom.DariRgba(4, 4, rgba);
      expect(gambar.titik, [1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0]);
      final kecil = GambarMonokrom.DariRgba(4, 4, rgba, lebarMaksimal: 2);
      expect((kecil.lebar, kecil.tinggi), (2, 2));
      expect(kecil.titik, [1, 1, 0, 0]);
    });

    test('KeJson/DariJson bolak-balik. Data rusak → null', () {
      final gambar = GambarMonokrom(3, 3, Uint8List.fromList([1, 0, 1, 0, 1, 0, 1, 1, 1]));
      final kembali = GambarMonokrom.DariJson(gambar.KeJson())!;
      expect((kembali.lebar, kembali.tinggi), (3, 3));
      expect(kembali.titik, gambar.titik);
      expect(GambarMonokrom.DariJson({'Lebar': 3, 'Tinggi': 3, 'Titik': 'AA=='}), isNull);
      expect(GambarMonokrom.DariJson('rusak'), isNull);
    });
  });
}
