import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart' show KaryawanPos, KunciFlagFitur;
import 'package:mesin_kasir/MesinKasir.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Katalog/KatalogLokal.dart';
import '../../Domain/Penjualan/Keranjang.dart';
import '../../Domain/Penjualan/LayananPenjualan.dart';
import '../../Domain/Sesi/StafLokal.dart';
import '../Komponen/FormatAngka.dart';
import '../Komponen/MasukanUang.dart';
import '../LembarMutasiKas.dart';
import 'PanelDiskon.dart';

/// Panel item keranjang (Rincian F-07c): pilih satuan, jumlah, pilihan wajib/opsional, catatan, dan (saat mengubah
/// baris) diskon item & hapus. Mode tambah bila [baris] null (produk berpilihan), mode ubah bila [baris] terisi.
class PanelItem extends ConsumerStatefulWidget {
  const PanelItem({super.key, required this.produk, required this.kasir, required this.saatSelesai, this.baris});

  /// Null bila produk sudah tidak ada di katalog (baris lama/pesanan tertahan): hanya jumlah, catatan, diskon.
  final ProdukJual? produk;
  final ItemKeranjang? baris;
  final StafLokal kasir;
  final VoidCallback saatSelesai;

  @override
  ConsumerState<PanelItem> createState() => _PanelItemState();
}

class _PanelItemState extends ConsumerState<PanelItem> {
  late final Set<String> _pilihan = {...?widget.baris?.pilihan.map((p) => p.uuid)};
  late SatuanJual? _satuan =
      widget.produk?.satuan.where((s) => s.uuid == widget.baris?.uuidProdukSatuan).firstOrNull ??
      widget.produk?.AmbilSatuanBawaan();
  late final TextEditingController _jumlah = TextEditingController(
    text: FormatAngka.FormatJumlah(widget.baris?.jumlah ?? Kuantitas.DariBulat(1)),
  );
  late final TextEditingController _catatan = TextEditingController(text: widget.baris?.catatan ?? '');
  late DiskonManual? _diskon = widget.baris?.diskon;

  /// F-18: staf yang melayani baris ini (urutan pilih dipertahankan).
  late final List<String> _staf = [...?widget.baris?.staf];

  /// F-05h: nomor seri/IMEI tiap unit (produk bernomor seri). Jumlah baris = banyaknya nomor.
  late final List<String> _nomorSeri = [...?widget.baris?.nomorSeri];
  final TextEditingController _isianSeri = TextEditingController();
  String? _galat;
  String? _galatDiskon;

  /// Harga manual (`pos.harga.timpa`): isian harga satuan baru + alasan. [_hargaDisentuh] membedakan harga yang
  /// benar-benar diubah kasir dari harga yang hanya ditampilkan (harga bisa bergeser sendiri bila jumlah berubah).
  late final TextEditingController _harga = TextEditingController(
    text: widget.baris == null ? '' : MasukanUang.FormatTeks(widget.baris!.hargaSatuan),
  );
  late final TextEditingController _alasanHarga = TextEditingController(text: widget.baris?.alasanHarga ?? '');
  bool _hargaDisentuh = false;
  bool _pulihkanHarga = false;
  String? _galatHarga;

  /// Alasan yang paling sering dipakai; satu ketukan mengisi kolom alasan.
  static const List<String> alasanHargaCepat = ['Barang cacat atau penyok', 'Mendekati kedaluwarsa', 'Tawar menawar'];

  bool get _modeUbah => widget.baris != null;

  bool get _bernomorSeri => widget.produk?.bernomorSeri ?? (widget.baris?.nomorSeri.isNotEmpty ?? false);

  bool get _bolehDesimal => _satuan?.bolehDesimal ?? widget.baris?.bolehDesimal ?? false;

  @override
  void dispose() {
    _jumlah.dispose();
    _catatan.dispose();
    _isianSeri.dispose();
    _harga.dispose();
    _alasanHarga.dispose();
    super.dispose();
  }

  Kuantitas? _AmbilJumlah() {
    if (_bernomorSeri) {
      return Kuantitas.DariBulat(_nomorSeri.length);
    }
    final nilai = FormatAngka.UraiDesimal(_jumlah.text);
    if (nilai == null || nilai.scale > Kuantitas.skala) {
      return null;
    }
    return Kuantitas.DariDesimal(nilai);
  }

  void _Geser(int arah) {
    final sekarang = _AmbilJumlah() ?? Kuantitas.Nol();
    final baru = sekarang.Tambah(Kuantitas.DariBulat(arah));
    if (baru.Bandingkan(Kuantitas.DariBulat(1)) < 0) {
      return;
    }
    setState(() => _jumlah.text = FormatAngka.FormatJumlah(baru));
  }

  /// Tambah satu nomor seri (ketik lalu Enter, atau hasil pindai pemindai keyboard). Nomor yang sama di baris lain
  /// keranjang juga ditolak karena satu unit tidak bisa dijual dua kali.
  void _TambahSeri() {
    final nomor = _isianSeri.text.trim();
    if (nomor.isEmpty) {
      return;
    }
    final kunci = nomor.toUpperCase();
    final uuidProduk = widget.produk?.uuid ?? widget.baris?.uuidProduk;
    final dipakaiBarisLain = ref
        .read(penyediaKeranjang)
        .baris
        .where((b) => b.uuid != widget.baris?.uuid && b.uuidProduk == uuidProduk)
        .any((b) => b.nomorSeri.any((n) => n.toUpperCase() == kunci));
    setState(() {
      if (nomor.length > 100) {
        _galat = 'Nomor seri maksimal 100 karakter.';
      } else if (dipakaiBarisLain || _nomorSeri.any((n) => n.toUpperCase() == kunci)) {
        _galat = 'Nomor seri $nomor sudah ada di keranjang ini.';
      } else {
        _galat = null;
        _nomorSeri.add(nomor);
        _isianSeri.clear();
      }
    });
  }

  List<Widget> _BangunNomorSeri(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    return [
      Text('Nomor seri / IMEI', style: teks.labelLarge),
      const SizedBox(height: TokenJarak.jarak8),
      Row(
        children: [
          Expanded(
            child: TextField(
              controller: _isianSeri,
              autofocus: true,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _TambahSeri(),
              decoration: const InputDecoration(
                labelText: 'Ketik atau pindai nomor seri',
                helperText: 'Satu nomor per unit. Tekan Enter untuk menambah.',
                border: OutlineInputBorder(),
              ),
            ),
          ),
          const SizedBox(width: TokenJarak.jarak8),
          IconButton.filled(
            tooltip: 'Tambah nomor seri',
            onPressed: _TambahSeri,
            icon: const Icon(Icons.add),
            constraints: const BoxConstraints.tightFor(width: 56, height: 56),
          ),
        ],
      ),
      const SizedBox(height: TokenJarak.jarak8),
      if (_nomorSeri.isEmpty)
        Text('Belum ada nomor seri. Tambahkan satu nomor untuk tiap unit.', style: teks.bodySmall)
      else ...[
        Text('${_nomorSeri.length} unit', style: teks.bodySmall),
        const SizedBox(height: TokenJarak.jarak4),
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final n in _nomorSeri)
              InputChip(
                label: Text(n),
                onDeleted: () => setState(() {
                  _galat = null;
                  _nomorSeri.remove(n);
                }),
              ),
          ],
        ),
      ],
    ];
  }

  void _Pilih(KelompokPilihanJual kelompok, PilihanJual pilihan) => setState(() {
    _galat = null;
    if (_pilihan.contains(pilihan.uuid)) {
      _pilihan.remove(pilihan.uuid);
      return;
    }
    if (kelompok.CekSatuSaja()) {
      _pilihan.removeAll(kelompok.pilihan.map((p) => p.uuid));
    }
    _pilihan.add(pilihan.uuid);
  });

  List<PilihanTerpilih> _AmbilPilihan() => [
    for (final k in widget.produk?.kelompokPilihan ?? const <KelompokPilihanJual>[])
      for (final p in k.pilihan)
        if (_pilihan.contains(p.uuid)) PilihanTerpilih(uuid: p.uuid, nama: p.nama, harga: p.harga),
  ];

  Future<void> _Simpan() async {
    final jumlah = _AmbilJumlah();
    if (jumlah == null || jumlah.Bandingkan(Kuantitas.Nol()) <= 0) {
      setState(() => _galat = _bernomorSeri ? 'Isi minimal satu nomor seri.' : 'Isi jumlah lebih dari 0.');
      return;
    }
    final katalog = await ref.read(penyediaKatalog.future);
    final k = await ref.read(penyediaKonteksPenjualan.future);
    final layanan = ref.read(penyediaLayananPenjualan);
    final pengatur = ref.read(penyediaKeranjang.notifier);
    var keranjang = ref.read(penyediaKeranjang);
    try {
      if (!_modeUbah) {
        final baris = layanan.BuatBaris(
          katalog,
          k,
          widget.produk!,
          satuan: _satuan,
          pilihan: _AmbilPilihan(),
          jumlah: jumlah,
          catatan: _catatan.text,
          kanal: LayananPenjualan.AmbilKanal(keranjang),
          tierPelanggan: keranjang.pelanggan?.kodeTier,
          nomorSeri: _nomorSeri,
        );
        pengatur.Ganti(layanan.TambahBaris(keranjang, baris.Salin(staf: List.of(_staf)), katalog, k));
        widget.saatSelesai();
        return;
      }

      final uuid = widget.baris!.uuid;
      final produk = widget.produk;
      if (produk != null) {
        keranjang = layanan.AturPilihan(keranjang, uuid, produk, _AmbilPilihan());
        if (_satuan != null && _satuan!.uuid != widget.baris!.uuidProdukSatuan && !_bernomorSeri) {
          keranjang = layanan.GantiSatuan(keranjang, uuid, _satuan!, katalog, k, jumlahBaru: jumlah);
        }
      }
      if (_bernomorSeri) {
        keranjang = layanan.AturNomorSeri(keranjang, uuid, _nomorSeri, katalog, k);
      }
      keranjang = layanan.UbahJumlah(keranjang, uuid, jumlah, katalog, k);
      keranjang = layanan.AturCatatan(keranjang, uuid, _catatan.text);
      keranjang = await _TerapkanHarga(keranjang, layanan, uuid);
      if (!mounted) {
        return;
      }

      final diskon = _diskon;
      final diskonLama = widget.baris!.diskon;
      final diskonBerubah =
          diskon?.KeJson().toString() != diskonLama?.KeJson().toString() ||
          (diskon?.jumlah == null && jumlah != widget.baris!.jumlah);
      var penyetuju = keranjang.penyetuju;
      if (diskon != null && diskonBerubah) {
        final indeks = keranjang.baris.indexWhere((b) => b.uuid == uuid);
        LayananPenjualan.ValidasiBentukDiskon(layanan.Hitung(keranjang, k).hasil.baris[indeks].bruto, diskon);
        final nilai = layanan.HitungDiskonBaris(keranjang, uuid, diskon, k);
        if (!mounted) {
          return;
        }
        final hasil = await PastikanDiskonDisetujui(
          context,
          dasar: nilai.dasar,
          nilaiDiskon: nilai.diskon,
          kasir: widget.kasir,
          k: k,
          penyetuju: penyetuju,
        );
        if (!hasil.boleh) {
          return;
        }
        penyetuju = hasil.penyetuju;
      }
      keranjang = keranjang.Salin(
        baris: [
          for (final b in keranjang.baris) b.uuid == uuid ? b.Salin(diskon: () => diskon, staf: List.of(_staf)) : b,
        ],
        penyetuju: () => penyetuju,
      );
      pengatur.Ganti(keranjang);
      widget.saatSelesai();
    } on GalatKasir catch (galat) {
      if (mounted) {
        setState(() {
          if (galat.kode.startsWith('Diskon') || galat.kode == 'TanpaIzin') {
            _galatDiskon = galat.pesan;
          } else if (galat.kode.startsWith('Harga') || galat.kode.startsWith('AlasanHarga')) {
            _galatHarga = galat.pesan;
          } else {
            _galat = galat.pesan;
          }
        });
      }
    }
  }

  /// Harga manual hanya untuk baris dari daftar harga di keranjang biasa, dan selama flag `kasir.harga-manual` tidak
  /// dimatikan (kill switch, PGL-18): baris racikan, harga terbuka, dan keranjang
  /// yang menagih dokumen yang sudah disepakati (pesanan meja, pre-order, perintah kerja, tukar barang) tidak ikut.
  bool _CekBolehUbahHarga(Keranjang keranjang, {required bool flagHidup}) =>
      _modeUbah &&
      flagHidup &&
      !widget.baris!.hargaTerbuka &&
      widget.baris!.racikan == null &&
      keranjang.pesananMeja == null &&
      keranjang.praPesan == null &&
      keranjang.perintahKerja == null &&
      keranjang.tukar == null;

  /// Terapkan perubahan harga manual pada [keranjang] lokal (belum disimpan ke penyedia). Kasir tanpa izin
  /// `pos.harga.timpa` memilih penyetuju lewat PIN; dibatalkan = keranjang tidak berubah dan simpan dihentikan lewat
  /// [GalatKasir] `HargaBatal`.
  Future<Keranjang> _TerapkanHarga(Keranjang keranjang, LayananPenjualan layanan, String uuid) async {
    // `read`, bukan `watch`: dipanggil dari aksi simpan, bukan dari `build`.
    final flagHidup = ref.read(penyediaKonfigurasiAplikasi)?.CekFlag(KunciFlagFitur.kasirHargaManual) ?? true;
    if (!_CekBolehUbahHarga(keranjang, flagHidup: flagHidup)) {
      return keranjang;
    }
    if (_pulihkanHarga) {
      return layanan.PulihkanHargaItem(keranjang, uuid);
    }
    final hargaBaru = MasukanUang.AmbilNilai(_harga);
    if (!_hargaDisentuh || hargaBaru == null || hargaBaru.SamaDengan(widget.baris!.hargaSatuan)) {
      return keranjang;
    }
    PenyetujuDiskon? penyetuju;
    if (!widget.kasir.PunyaIzin(IzinKasir.posHargaTimpa)) {
      if (_alasanHarga.text.trim().isEmpty) {
        throw const GalatKasir('AlasanHargaWajib', 'Isi alasan mengubah harga.');
      }
      final staf = await MintaPenyetujuSementara(
        context,
        const DialogPinSupervisor(
          izin: IzinKasir.posHargaTimpa,
          pesan: 'Mengubah harga barang perlu persetujuan. Pilih penyetuju harga.',
          judulDialog: 'Persetujuan ubah harga',
          bolehJarakJauh: false,
        ),
      );
      if (staf == null) {
        throw const GalatKasir('HargaBatal', 'Perubahan harga dibatalkan karena belum disetujui.');
      }
      penyetuju = PenyetujuDiskon(uuid: staf.uuid, nama: staf.nama, pemilik: staf.pemilik);
    }
    return layanan.UbahHargaItem(
      keranjang,
      uuid,
      hargaBaru,
      _alasanHarga.text,
      kasir: widget.kasir,
      penyetuju: penyetuju,
    );
  }

  List<Widget> _BangunHarga(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final baris = widget.baris!;
    final berizin = widget.kasir.PunyaIzin(IzinKasir.posHargaTimpa);
    return [
      const SizedBox(height: TokenJarak.jarak16),
      Text('Harga satuan', style: teks.labelLarge),
      if (!berizin) ...[
        const SizedBox(height: TokenJarak.jarak4),
        Text('Mengubah harga perlu persetujuan supervisor dengan PIN.', style: teks.bodySmall),
      ],
      const SizedBox(height: TokenJarak.jarak8),
      TextField(
        controller: _harga,
        enabled: !_pulihkanHarga,
        keyboardType: TextInputType.number,
        inputFormatters: [MasukanUang.pemformat],
        textAlign: TextAlign.right,
        style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
        onChanged: (_) => setState(() {
          _hargaDisentuh = true;
          _galatHarga = null;
        }),
        decoration: InputDecoration(
          labelText: 'Harga satuan (Rp)',
          prefixText: 'Rp ',
          helperText: baris.hargaAsli == null
              ? 'Harga daftar: ${baris.hargaSatuan.FormatRupiah()}'
              : 'Harga daftar: ${baris.hargaAsli!.FormatRupiah()}',
          errorText: _galatHarga,
          border: const OutlineInputBorder(),
        ),
      ),
      if (_hargaDisentuh && !_pulihkanHarga) ...[
        const SizedBox(height: TokenJarak.jarak8),
        TextField(
          controller: _alasanHarga,
          maxLength: 100,
          decoration: const InputDecoration(labelText: 'Alasan ubah harga', border: OutlineInputBorder()),
        ),
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            for (final a in alasanHargaCepat)
              ActionChip(label: Text(a), onPressed: () => setState(() => _alasanHarga.text = a)),
          ],
        ),
      ],
      if (baris.hargaAsli != null) ...[
        const SizedBox(height: TokenJarak.jarak8),
        SizedBox(
          height: TokenJarak.targetSentuh,
          child: TextButton(
            onPressed: () => setState(() {
              _pulihkanHarga = !_pulihkanHarga;
              _galatHarga = null;
              if (_pulihkanHarga) {
                _harga.text = MasukanUang.FormatTeks(baris.hargaAsli!);
              } else {
                _harga.text = MasukanUang.FormatTeks(baris.hargaSatuan);
              }
            }),
            child: Text(_pulihkanHarga ? 'Batal kembalikan harga' : 'Kembalikan harga daftar'),
          ),
        ),
      ],
    ];
  }

  /// F-18: pilih staf yang melayani (maks. 5, komisi dibagi rata). Tanpa data karyawan = tidak ditampilkan.
  List<Widget> _BangunStaf(BuildContext context) {
    final karyawan = ref.watch(penyediaKaryawanPos).value ?? const <KaryawanPos>[];
    // D-183: komisi staf pelayan untuk jasa dan F&B; retail dan grosir tidak memilih staf per item.
    final sektorCocok =
        ref.watch(penyediaKonteksPenjualan).value?.CekSesuaiSektor(KonteksPenjualan.sektorKomisiStaf) ?? true;
    if (karyawan.isEmpty || !sektorCocok) {
      return const [];
    }
    final teks = Theme.of(context).textTheme;
    return [
      const SizedBox(height: TokenJarak.jarak8),
      Text('Dilayani oleh (opsional)', style: teks.labelLarge),
      const SizedBox(height: TokenJarak.jarak4),
      Wrap(
        spacing: TokenJarak.jarak8,
        runSpacing: TokenJarak.jarak8,
        children: [
          for (final k in karyawan)
            FilterChip(
              label: Text(k.jabatan == null ? k.nama : '${k.nama} | ${k.jabatan}'),
              selected: _staf.contains(k.uuid),
              onSelected: (pilih) => setState(() {
                if (!pilih) {
                  _staf.remove(k.uuid);
                } else if (_staf.length < maksStaf) {
                  _staf.add(k.uuid);
                }
              }),
            ),
        ],
      ),
    ];
  }

  /// Sama dengan server `PencatatKomisiPenjualan::MAKS_STAF_PER_BARIS`.
  static const int maksStaf = 5;

  void _Hapus() {
    final layanan = ref.read(penyediaLayananPenjualan);
    ref.read(penyediaKeranjang.notifier).Ganti(layanan.HapusBaris(ref.read(penyediaKeranjang), widget.baris!.uuid));
    widget.saatSelesai();
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final produk = widget.produk;
    final berizinDiskon = widget.kasir.PunyaIzin(IzinKasir.penjualanDiskonManual);

    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (produk != null && produk.satuan.length > 1) ...[
            Text('Satuan', style: teks.labelLarge),
            const SizedBox(height: TokenJarak.jarak8),
            Wrap(
              spacing: TokenJarak.jarak8,
              runSpacing: TokenJarak.jarak8,
              children: [
                for (final s in produk.satuan)
                  ChoiceChip(
                    label: Text(s.nama),
                    selected: _satuan?.uuid == s.uuid,
                    onSelected: (_) => setState(() => _satuan = s),
                  ),
              ],
            ),
            const SizedBox(height: TokenJarak.jarak16),
          ],
          if (_bernomorSeri)
            ..._BangunNomorSeri(context)
          else ...[
            Text('Jumlah', style: teks.labelLarge),
            const SizedBox(height: TokenJarak.jarak8),
            Row(
              children: [
                IconButton.outlined(
                  tooltip: 'Kurangi jumlah',
                  onPressed: () => _Geser(-1),
                  icon: const Icon(Icons.remove),
                  constraints: const BoxConstraints.tightFor(width: 56, height: 56),
                ),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: TextField(
                    controller: _jumlah,
                    textAlign: TextAlign.center,
                    keyboardType: TextInputType.numberWithOptions(decimal: _bolehDesimal),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(_bolehDesimal ? r'[0-9.,]' : r'[0-9]')),
                      LengthLimitingTextInputFormatter(10),
                    ],
                    style: teks.titleMedium?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
                    decoration: const InputDecoration(border: OutlineInputBorder()),
                  ),
                ),
                const SizedBox(width: TokenJarak.jarak8),
                IconButton.outlined(
                  tooltip: 'Tambah jumlah',
                  onPressed: () => _Geser(1),
                  icon: const Icon(Icons.add),
                  constraints: const BoxConstraints.tightFor(width: 56, height: 56),
                ),
              ],
            ),
          ],
          for (final kelompok in produk?.kelompokPilihan ?? const <KelompokPilihanJual>[]) ...[
            const SizedBox(height: TokenJarak.jarak16),
            Text(
              '${kelompok.nama} | ${kelompok.CekWajib() ? 'wajib' : 'opsional'}'
              '${kelompok.CekSatuSaja()
                  ? ', pilih 1'
                  : kelompok.maksimal != null
                  ? ', maks. ${kelompok.maksimal}'
                  : ''}',
              style: teks.labelLarge,
            ),
            const SizedBox(height: TokenJarak.jarak8),
            Wrap(
              spacing: TokenJarak.jarak8,
              runSpacing: TokenJarak.jarak8,
              children: [
                for (final p in kelompok.pilihan)
                  FilterChip(
                    label: Text(p.harga.BernilaiNol() ? p.nama : '${p.nama} +${p.harga.FormatRupiah()}'),
                    selected: _pilihan.contains(p.uuid),
                    onSelected: (_) => _Pilih(kelompok, p),
                  ),
              ],
            ),
          ],
          const SizedBox(height: TokenJarak.jarak16),
          TextField(
            controller: _catatan,
            maxLength: 255,
            decoration: const InputDecoration(labelText: 'Catatan (opsional)', border: OutlineInputBorder()),
          ),
          ..._BangunStaf(context),
          if (_CekBolehUbahHarga(
            ref.watch(penyediaKeranjang),
            flagHidup: CekFlagKasir(ref, KunciFlagFitur.kasirHargaManual),
          ))
            ..._BangunHarga(context),
          if (_modeUbah) ...[
            const SizedBox(height: TokenJarak.jarak8),
            Text('Diskon item', style: teks.labelLarge),
            if (!berizinDiskon) ...[
              const SizedBox(height: TokenJarak.jarak4),
              Text('Diskon perlu disetujui supervisor dengan PIN.', style: teks.bodySmall),
            ],
            const SizedBox(height: TokenJarak.jarak8),
            IsianDiskon(
              awal: _diskon,
              galat: _galatDiskon,
              saatBerubah: (d) => setState(() {
                _diskon = d;
                _galatDiskon = null;
              }),
            ),
          ],
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: 56,
            child: FilledButton(
              onPressed: _Simpan,
              child: Text(_modeUbah ? 'Simpan perubahan' : 'Tambah ke keranjang'),
            ),
          ),
          if (_modeUbah) ...[
            const SizedBox(height: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: TextButton(
                onPressed: _Hapus,
                style: TextButton.styleFrom(foregroundColor: warna.bahaya),
                child: const Text('Hapus item'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
