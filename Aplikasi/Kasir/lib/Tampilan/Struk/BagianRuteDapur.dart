import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Data/BasisData/BasisDataKasir.dart';
import '../../Domain/Dapur/LayananRuteDapur.dart';
import '../../Domain/Dapur/LayananTiketDapur.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';

/// Pilihan daftar yang diatur.
enum _Cakupan { kategori, menu }

/// D-310 (F-10): kasir mengatur ke stasiun dapur/bar mana tiap kategori dan menu dikirim, langsung dari aplikasi kasir.
/// Menu menimpa kategorinya, kategori tanpa pilihan mengikuti kategori induk atau stasiun bawaan. Berlaku untuk semua
/// kasir dan outlet usaha ini, perlu online, dan butuh izin `dapur.rute.atur` (bawaan Kasir). Stasiun checker tidak
/// muncul sebagai tujuan: ia mencetak salinan semua tiket di printer yang dipasangkan ke checker.
class BagianRuteDapur extends ConsumerStatefulWidget {
  const BagianRuteDapur({super.key});

  @override
  ConsumerState<BagianRuteDapur> createState() => _BagianRuteDapurState();
}

class _BagianRuteDapurState extends ConsumerState<BagianRuteDapur> {
  RuteDapur _rute = const RuteDapur();
  var _cakupan = _Cakupan.kategori;
  var _kata = '';
  String? _sibuk;
  String? _pesan;
  var _pesanGalat = false;

  /// Tampilan menu dibatasi supaya daftar ribuan produk tetap ringan. Cari untuk menemukan sisanya.
  static const int _batasMenu = 60;

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    final rute = await RuteDapur.Muat(ref.read(penyediaRepositori));
    if (mounted) {
      setState(() => _rute = rute);
    }
  }

  Future<void> _Atur(String kunci, Future<RuteDapur> Function() kerja, String berhasil) async {
    setState(() {
      _sibuk = kunci;
      _pesan = null;
    });
    try {
      final baru = await kerja();
      if (mounted) {
        setState(() {
          _rute = baru;
          _pesan = berhasil;
          _pesanGalat = false;
        });
      }
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          _pesan = galat.pesan;
          _pesanGalat = true;
        });
      }
    } finally {
      if (mounted) {
        setState(() => _sibuk = null);
      }
    }
  }

  String _NamaStasiun(String? uuid) => uuid == null ? '' : (_rute.CariStasiun(uuid)?.nama ?? 'Stasiun');

  int _Kedalaman(BarisKategori k, Map<String, BarisKategori> peta) {
    var kedalaman = 0;
    var saatIni = k.UuidInduk;
    while (saatIni != null && kedalaman < 5) {
      kedalaman++;
      saatIni = peta[saatIni]?.UuidInduk;
    }
    return kedalaman;
  }

  /// Kategori diurutkan sebagai pohon: induk lalu anak-anaknya.
  List<BarisKategori> _UrutPohon(List<BarisKategori> semua) {
    final anak = <String?, List<BarisKategori>>{};
    for (final k in semua) {
      (anak[k.UuidInduk] ??= []).add(k);
    }
    for (final daftar in anak.values) {
      daftar.sort((a, b) {
        final urut = a.Urutan.compareTo(b.Urutan);
        return urut != 0 ? urut : a.Nama.compareTo(b.Nama);
      });
    }
    final hasil = <BarisKategori>[];
    void Jelajah(String? induk, int langkah) {
      if (langkah > 5) {
        return;
      }
      for (final k in anak[induk] ?? const <BarisKategori>[]) {
        hasil.add(k);
        Jelajah(k.Uuid, langkah + 1);
      }
    }

    Jelajah(null, 0);
    // Kategori yatim (induk tidak ada di katalog) tetap ditampilkan di akhir.
    final sudah = hasil.map((k) => k.Uuid).toSet();
    return [
      ...hasil,
      for (final k in semua)
        if (!sudah.contains(k.Uuid)) k,
    ];
  }

  Widget _PilihStasiun({
    required Key kunci,
    required String? langsung,
    required String ikutLabel,
    required ValueChanged<String?> saatPilih,
    required bool sibuk,
  }) {
    return PopupMenuButton<String>(
      key: kunci,
      enabled: !sibuk,
      tooltip: 'Pilih stasiun',
      onSelected: (nilai) => saatPilih(nilai.isEmpty ? null : nilai),
      itemBuilder: (_) => [
        CheckedPopupMenuItem<String>(value: '', checked: langsung == null, child: Text(ikutLabel)),
        for (final s in _rute.memasak)
          CheckedPopupMenuItem<String>(value: s.uuid, checked: langsung == s.uuid, child: Text(s.nama)),
      ],
      child: Container(
        constraints: const BoxConstraints(minHeight: TokenJarak.targetSentuh, minWidth: 140),
        padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak12),
        decoration: BoxDecoration(
          border: Border.all(color: TokenWarna.AmbilDari(context).garis),
          borderRadius: BorderRadius.circular(TokenJarak.jarak8),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            if (sibuk)
              const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
            else
              Flexible(
                child: Text(langsung == null ? 'Ikut aturan' : _NamaStasiun(langsung), overflow: TextOverflow.ellipsis),
              ),
            const SizedBox(width: TokenJarak.jarak4),
            const Icon(Icons.arrow_drop_down),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final kasir = ref.watch(penyediaSesi).kasir;
    final katalog = ref.watch(penyediaKatalog).value ?? KatalogLokal.kosong;
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final keterangan = teks.bodySmall?.copyWith(color: warna.teksSekunder);

    if (kasir == null || !LayananRuteDapur.CekBoleh(kasir)) {
      return Text(
        'Kasir ini tidak punya izin mengatur stasiun dapur. Minta pemilik atau manajer menambahkan izin '
        '"Mengatur stasiun dapur" pada perannya, atau atur dari back-office.',
        key: const ValueKey('RuteDapurTanpaIzin'),
        style: keterangan,
      );
    }
    if (_rute.memasak.isEmpty) {
      return Text(
        'Belum ada stasiun dapur atau bar. Tambahkan di back-office menu Meja & dapur, lalu perbarui data kasir.',
        key: const ValueKey('RuteDapurKosong'),
        style: keterangan,
      );
    }

    final induk = {for (final k in katalog.kategori) k.Uuid: k.UuidInduk};
    final petaKategori = {for (final k in katalog.kategori) k.Uuid: k};
    final layanan = ref.read(penyediaLayananRuteDapur);

    final tombolCakupan = SegmentedButton<_Cakupan>(
      key: const ValueKey('CakupanRuteDapur'),
      segments: const [
        ButtonSegment(value: _Cakupan.kategori, label: Text('Per kategori')),
        ButtonSegment(value: _Cakupan.menu, label: Text('Per menu')),
      ],
      selected: {_cakupan},
      onSelectionChanged: (pilihan) => setState(() => _cakupan = pilihan.first),
    );

    final baris = <Widget>[];
    if (_cakupan == _Cakupan.kategori) {
      for (final k in _UrutPohon(katalog.kategori)) {
        final langsung = _rute.kategori[k.Uuid];
        final efektif = _rute.AmbilStasiun(k.Uuid, induk);
        final dasar = langsung == null ? 'Ikut aturan (${efektif?.nama ?? 'tanpa stasiun'})' : null;
        baris.add(
          _BarisRute(
            kunci: ValueKey('RuteKategori-${k.Uuid}'),
            indentasi: _Kedalaman(k, petaKategori),
            nama: k.Nama,
            keterangan: dasar ?? 'Dikirim ke ${_NamaStasiun(langsung)}',
            aksi: _PilihStasiun(
              kunci: ValueKey('PilihRuteKategori-${k.Uuid}'),
              langsung: langsung,
              ikutLabel: 'Ikut aturan induk atau bawaan',
              sibuk: _sibuk == 'k-${k.Uuid}',
              saatPilih: (uuid) => unawaited(
                _Atur(
                  'k-${k.Uuid}',
                  () => layanan.AturKategori(k.Uuid, uuidStasiun: uuid, kasir: kasir),
                  uuid == null ? '${k.Nama} mengikuti aturan induk.' : '${k.Nama} dikirim ke ${_NamaStasiun(uuid)}.',
                ),
              ),
            ),
          ),
        );
      }
    } else {
      final kata = _kata.trim().toLowerCase();
      final cocok = [
        for (final p in katalog.produk)
          if (p.uuidInduk == null &&
              p.jenis != JenisProdukKasir.jasa &&
              (kata.isEmpty || p.nama.toLowerCase().contains(kata)))
            p,
      ];
      for (final p in cocok.take(_batasMenu)) {
        final langsung = _rute.produk[p.uuid];
        final efektif = _rute.AmbilStasiun(p.uuidKategori, induk, uuidProduk: p.uuid);
        baris.add(
          _BarisRute(
            kunci: ValueKey('RuteProduk-${p.uuid}'),
            indentasi: 0,
            nama: p.nama,
            keterangan: langsung == null
                ? 'Ikut kategori (${efektif?.nama ?? 'tanpa stasiun'})'
                : 'Dikirim ke ${_NamaStasiun(langsung)}',
            aksi: _PilihStasiun(
              kunci: ValueKey('PilihRuteProduk-${p.uuid}'),
              langsung: langsung,
              ikutLabel: 'Ikut kategori',
              sibuk: _sibuk == 'p-${p.uuid}',
              saatPilih: (uuid) => unawaited(
                _Atur(
                  'p-${p.uuid}',
                  () => layanan.AturProduk(p.uuid, uuidStasiun: uuid, kasir: kasir),
                  uuid == null ? '${p.nama} mengikuti kategorinya.' : '${p.nama} dikirim ke ${_NamaStasiun(uuid)}.',
                ),
              ),
            ),
          ),
        );
      }
      if (cocok.length > _batasMenu) {
        baris.add(
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(
              'Menampilkan $_batasMenu dari ${cocok.length} menu. Ketik nama menu untuk mempersempit.',
              style: keterangan,
            ),
          ),
        );
      }
      if (cocok.isEmpty) {
        baris.add(Text('Tidak ada menu yang cocok.', key: const ValueKey('RuteMenuKosong'), style: keterangan));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Pilih ke stasiun mana kategori atau menu dikirim. Menu mengalahkan kategorinya. Berlaku untuk semua kasir dan '
          'outlet usaha ini, dan butuh internet.',
          style: keterangan,
        ),
        const SizedBox(height: TokenJarak.jarak8),
        tombolCakupan,
        if (_cakupan == _Cakupan.menu) ...[
          const SizedBox(height: TokenJarak.jarak8),
          TextField(
            key: const ValueKey('CariMenuRute'),
            decoration: const InputDecoration(labelText: 'Cari menu', prefixIcon: Icon(Icons.search)),
            onChanged: (nilai) => setState(() => _kata = nilai),
          ),
        ],
        if (_pesan case final pesan?) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Text(
            pesan,
            key: const ValueKey('PesanRuteDapur'),
            style: teks.bodyMedium?.copyWith(color: _pesanGalat ? warna.bahaya : warna.sukses),
          ),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        ...baris,
      ],
    );
  }
}

class _BarisRute extends StatelessWidget {
  const _BarisRute({
    required this.kunci,
    required this.indentasi,
    required this.nama,
    required this.keterangan,
    required this.aksi,
  });

  final Key kunci;
  final int indentasi;
  final String nama;
  final String keterangan;
  final Widget aksi;

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    return Padding(
      key: kunci,
      padding: EdgeInsets.only(left: indentasi * TokenJarak.jarak16, top: TokenJarak.jarak4, bottom: TokenJarak.jarak4),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  nama,
                  style: teks.bodyMedium?.copyWith(fontWeight: FontWeight.w700),
                  overflow: TextOverflow.ellipsis,
                ),
                Text(keterangan, style: teks.bodySmall?.copyWith(color: warna.teksSekunder)),
              ],
            ),
          ),
          const SizedBox(width: TokenJarak.jarak8),
          aksi,
        ],
      ),
    );
  }
}
