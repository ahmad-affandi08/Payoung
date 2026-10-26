import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Penjualan/AturanApotek.dart';
import '../../Domain/Penjualan/LayananPenjualan.dart';
import '../../Domain/Penjualan/Racikan.dart';
import '../../Domain/Persediaan/LayananGudang.dart';
import '../Komponen/MasukanUang.dart';

/// Apotek bagian 4 (§9.5): apoteker menyusun obat racikan (puyer, kapsul, salep) dari beberapa obat di katalog
/// perangkat, lalu menambahkannya ke keranjang sebagai satu baris jasa racik. Stok obat komponennya dikurangi server
/// saat penjualan diterima; golongan terkuat komponennya menentukan resep & apoteker di layar Bayar. Bisa offline.
class PanelRacikan extends ConsumerStatefulWidget {
  const PanelRacikan({super.key, required this.saatSelesai});

  final VoidCallback saatSelesai;

  /// Ada obat bergolongan & produk jasa di katalog: tombol racikan ditampilkan.
  static bool CekTersedia(KatalogLokal? katalog) =>
      katalog != null &&
      katalog.produk.any((p) => p.aktif && p.golonganObat != null) &&
      LayananPenjualan.AmbilJasaRacik(katalog).isNotEmpty;

  @override
  ConsumerState<PanelRacikan> createState() => _PanelRacikanState();
}

class _Komponen {
  _Komponen(this.produk, this.satuan) : jumlah = TextEditingController(text: '1');

  final ProdukJual produk;
  SatuanJual? satuan;
  final TextEditingController jumlah;
}

class _PanelRacikanState extends ConsumerState<PanelRacikan> {
  final _nama = TextEditingController();
  final _kemasan = TextEditingController(text: '10');
  final _aturan = TextEditingController();
  final _cari = TextEditingController();
  final _harga = TextEditingController();
  final List<_Komponen> _komponen = [];
  ProdukJual? _jasa;
  bool _hargaDiketik = false;
  String? _galat;

  @override
  void dispose() {
    for (final c in [_nama, _kemasan, _aturan, _cari, _harga, for (final k in _komponen) k.jumlah]) {
      c.dispose();
    }
    super.dispose();
  }

  List<KomponenRacikan> _SusunKomponen({bool ketat = false}) {
    final hasil = <KomponenRacikan>[];
    for (final k in _komponen) {
      Kuantitas? jumlah;
      try {
        jumlah = LayananGudang.BacaJumlah(k.jumlah.text, bolehDesimal: k.satuan?.bolehDesimal ?? true);
      } on GalatKasir {
        if (ketat) {
          rethrow;
        }
      }
      if (jumlah == null) {
        if (ketat) {
          throw GalatKasir('RacikanTidakValid', 'Isi jumlah ${k.produk.nama}.');
        }
        continue;
      }
      hasil.add(
        KomponenRacikan(
          uuidProduk: k.produk.uuid,
          nama: k.produk.nama,
          uuidProdukSatuan: k.satuan?.uuid,
          namaSatuan: k.satuan?.nama,
          jumlah: jumlah,
          golonganObat: k.produk.golonganObat,
        ),
      );
    }
    return hasil;
  }

  /// Harga saran dihitung ulang setiap isian berubah, selama kasir belum mengetik harga sendiri.
  void _PerbaruiHargaSaran() {
    final katalog = ref.read(penyediaKatalog).value;
    final k = ref.read(penyediaKonteksPenjualan).value;
    final jasa = _jasa;
    if (_hargaDiketik || katalog == null || k == null || jasa == null) {
      return;
    }
    final saran = ref
        .read(penyediaLayananPenjualan)
        .HitungHargaSaranRacikan(
          katalog,
          k,
          jasa,
          _SusunKomponen(),
          tierPelanggan: ref.read(penyediaKeranjang).pelanggan?.kodeTier,
        );
    MasukanUang.Isi(_harga, saran.harga);
  }

  void _Tambah(ProdukJual produk) {
    if (_komponen.any((k) => k.produk.uuid == produk.uuid)) {
      setState(() => _galat = '${produk.nama} sudah ada di racikan. Ubah jumlahnya.');
      return;
    }
    setState(() {
      _komponen.add(_Komponen(produk, produk.AmbilSatuanBawaan()));
      _cari.clear();
      _galat = null;
    });
    _PerbaruiHargaSaran();
  }

  void _Simpan() {
    final katalog = ref.read(penyediaKatalog).value;
    final k = ref.read(penyediaKonteksPenjualan).value;
    final jasa = _jasa;
    if (katalog == null || k == null) {
      return;
    }
    try {
      if (jasa == null) {
        throw const GalatKasir('RacikanTidakValid', 'Pilih jasa racik.');
      }
      final racikan = RacikanBaris.Susun(
        nama: _nama.text,
        jumlahKemasan: int.tryParse(_kemasan.text.trim()) ?? 0,
        aturanPakai: _aturan.text,
        komponen: _SusunKomponen(ketat: true),
      );
      final angka = _harga.text.replaceAll(RegExp(r'[^0-9]'), '');
      if (angka.isEmpty) {
        throw const GalatKasir('RacikanTidakValid', 'Isi harga racikan.');
      }
      final layanan = ref.read(penyediaLayananPenjualan);
      final baris = layanan.BuatBarisRacikan(katalog, k, jasa, racikan, Uang.Dari(angka));
      ref.read(penyediaKeranjang.notifier).Ganti(layanan.TambahBaris(ref.read(penyediaKeranjang), baris, katalog, k));
      widget.saatSelesai();
    } on GalatKasir catch (galat) {
      setState(() => _galat = galat.pesan);
    }
  }

  @override
  Widget build(BuildContext context) {
    final warna = TokenWarna.AmbilDari(context);
    final teks = Theme.of(context).textTheme;
    final katalog = ref.watch(penyediaKatalog).value;
    if (katalog == null) {
      return const Padding(padding: EdgeInsets.all(TokenJarak.jarak16), child: Text('Katalog belum dimuat.'));
    }
    final daftarJasa = LayananPenjualan.AmbilJasaRacik(katalog);
    final kata = _cari.text.trim().toLowerCase();
    final saran = kata.isEmpty
        ? const <ProdukJual>[]
        : LayananPenjualan.AmbilBahanRacikan(katalog)
              .where((p) => p.nama.toLowerCase().contains(kata) || (p.sku?.toLowerCase().contains(kata) ?? false))
              .take(8)
              .toList();
    final obat = RacikanBaris(nama: '', jumlahKemasan: 1, komponen: _SusunKomponen()).Obat;

    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          DropdownButtonFormField<String>(
            initialValue: _jasa?.uuid,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Jasa racik', border: OutlineInputBorder()),
            items: [
              for (final j in daftarJasa)
                DropdownMenuItem(
                  value: j.uuid,
                  child: Text(j.nama, maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
            ],
            onChanged: (uuid) {
              setState(() => _jasa = daftarJasa.where((j) => j.uuid == uuid).firstOrNull);
              _PerbaruiHargaSaran();
            },
          ),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _nama,
            maxLength: RacikanBaris.panjangNama,
            decoration: const InputDecoration(
              labelText: 'Nama racikan',
              hintText: 'Contoh: Puyer batuk pilek anak',
              border: OutlineInputBorder(),
            ),
          ),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 140,
                child: TextField(
                  controller: _kemasan,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Jumlah kemasan', border: OutlineInputBorder()),
                ),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              Expanded(
                child: TextField(
                  controller: _aturan,
                  maxLength: RacikanBaris.panjangAturanPakai,
                  decoration: const InputDecoration(
                    labelText: 'Aturan pakai',
                    hintText: '3 x 1 bungkus sesudah makan',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak8),
          Text('Obat untuk satu racikan', style: teks.titleSmall),
          const SizedBox(height: TokenJarak.jarak8),
          TextField(
            controller: _cari,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Cari obat atau bahan',
              prefixIcon: Icon(Icons.search),
              border: OutlineInputBorder(),
            ),
          ),
          for (final p in saran)
            ListTile(
              dense: true,
              title: Text(p.nama),
              subtitle: Text([p.sku, InfoObat.Dari(p)?.Label].whereType<String>().join(' | ')),
              trailing: const Icon(Icons.add),
              onTap: () => _Tambah(p),
            ),
          if (kata.isNotEmpty && saran.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
              child: Text('Tidak ada obat berstok yang cocok.', style: teks.bodySmall),
            ),
          if (_komponen.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: TokenJarak.jarak8),
              child: Text('Belum ada obat di racikan.', style: teks.bodyMedium?.copyWith(color: warna.teksSekunder)),
            ),
          for (var i = 0; i < _komponen.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(_komponen[i].produk.nama, style: teks.bodyMedium),
                        if (InfoObat.Dari(_komponen[i].produk) case final info?)
                          Text(info.Label, style: teks.bodySmall),
                      ],
                    ),
                  ),
                  const SizedBox(width: TokenJarak.jarak8),
                  SizedBox(
                    width: 96,
                    child: TextField(
                      key: ValueKey('JumlahRacikan-${_komponen[i].produk.uuid}'),
                      controller: _komponen[i].jumlah,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      onChanged: (_) {
                        setState(() {});
                        _PerbaruiHargaSaran();
                      },
                      decoration: InputDecoration(
                        labelText: _komponen[i].satuan?.nama ?? 'Jumlah',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Hapus ${_komponen[i].produk.nama}',
                    onPressed: () {
                      setState(() => _komponen.removeAt(i).jumlah.dispose());
                      _PerbaruiHargaSaran();
                    },
                    icon: const Icon(Icons.remove_circle_outline),
                  ),
                ],
              ),
            ),
          if (obat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak12),
              child: Text(
                obat.CekWajibResep
                    ? 'Racikan berisi ${obat.Label.split(',').first.toLowerCase()}: wajib resep dokter'
                          '${obat.CekWajibApoteker ? ' & diserahkan apoteker' : ''}.'
                    : 'Racikan berisi ${obat.Label.toLowerCase()}.',
                style: teks.bodySmall,
              ),
            ),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _harga,
            keyboardType: TextInputType.number,
            inputFormatters: [MasukanUang.pemformat],
            textAlign: TextAlign.right,
            style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
            onChanged: (_) => _hargaDiketik = true,
            decoration: InputDecoration(
              labelText: 'Harga racikan',
              prefixText: 'Rp ',
              helperText: _hargaDiketik ? 'Harga diketik' : 'Saran: jasa racik + harga obat',
              border: const OutlineInputBorder(),
            ),
          ),
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: FilledButton(onPressed: _Simpan, child: const Text('Tambah racikan ke keranjang')),
          ),
        ],
      ),
    );
  }
}
