import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../Aplikasi/Penyedia.dart';
import '../Data/AksiCepat.dart';
import '../Data/KlienPemilik.dart';
import 'FormatTampilan.dart';

/// Aksi cepat Owner (OWN-07): ubah harga, tandai produk habis ("86"), nyalakan atau matikan promo, dan catat pengeluaran
/// dengan foto nota. Setiap aksi memakai Aksi back-office yang sama di server, jadi hasilnya identik dengan dashboard.
class LayarAksiCepat extends StatelessWidget {
  const LayarAksiCepat({super.key});

  static const _aksi = [
    (Icons.sell_outlined, 'Ubah harga', 'Ganti harga jual satu produk.', _TujuanAksi.harga),
    (
      Icons.do_not_disturb_on_outlined,
      'Tandai habis',
      'Sembunyikan produk yang kehabisan di satu outlet.',
      _TujuanAksi.habis,
    ),
    (Icons.local_offer_outlined, 'Promo', 'Nyalakan atau matikan promo.', _TujuanAksi.promo),
    (
      Icons.receipt_long_outlined,
      'Catat pengeluaran',
      'Catat biaya, lengkap dengan foto nota.',
      _TujuanAksi.pengeluaran,
    ),
  ];

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Aksi cepat')),
    body: ListView(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      children: [
        for (final (ikon, judul, keterangan, tujuan) in _aksi)
          Card(
            margin: const EdgeInsets.only(bottom: TokenJarak.jarak8),
            child: ListTile(
              minTileHeight: TokenJarak.targetSentuh,
              leading: Icon(ikon),
              title: Text(judul),
              subtitle: Text(keterangan),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => unawaited(
                Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => switch (tujuan) {
                      _TujuanAksi.harga => const _LayarUbahHarga(),
                      _TujuanAksi.habis => const _LayarTandaiHabis(),
                      _TujuanAksi.promo => const _LayarPromo(),
                      _TujuanAksi.pengeluaran => const _LayarPengeluaran(),
                    },
                  ),
                ),
              ),
            ),
          ),
      ],
    ),
  );
}

enum _TujuanAksi { harga, habis, promo, pengeluaran }

String _PesanGalat(Object galat) => switch (galat) {
  GalatApi() => galat.pesan,
  GalatJaringan() => 'Tidak tersambung ke server. Periksa internet lalu coba lagi.',
  _ => 'Terjadi galat. Coba lagi.',
};

/// Pencarian produk dengan jeda ketik 350 ms, dipakai Ubah harga dan Tandai habis.
mixin _PencarianProduk<T extends StatefulWidget> on State<T> {
  final TextEditingController kataCari = TextEditingController();
  Timer? _jeda;
  int _urutan = 0;
  List<ProdukAksiCepat>? hasil;
  String? galat;
  bool memuat = false;

  Future<List<ProdukAksiCepat>> Ambil(String kata);

  void CariLagi({bool segera = false}) {
    _jeda?.cancel();
    _jeda = Timer(segera ? Duration.zero : const Duration(milliseconds: 350), () => unawaited(_Muat()));
  }

  Future<void> _Muat() async {
    final urutan = ++_urutan;
    setState(() {
      memuat = true;
      galat = null;
    });
    try {
      final data = await Ambil(kataCari.text);
      if (!mounted || urutan != _urutan) return;
      setState(() {
        hasil = data;
        memuat = false;
      });
    } on Object catch (e) {
      if (!mounted || urutan != _urutan) return;
      setState(() {
        galat = _PesanGalat(e);
        memuat = false;
      });
    }
  }

  @override
  void dispose() {
    _jeda?.cancel();
    kataCari.dispose();
    super.dispose();
  }

  Widget KotakCari(String petunjuk) => Padding(
    padding: const EdgeInsets.all(TokenJarak.jarak16),
    child: TextField(
      controller: kataCari,
      textInputAction: TextInputAction.search,
      decoration: InputDecoration(
        prefixIcon: const Icon(Icons.search),
        labelText: petunjuk,
        border: const OutlineInputBorder(),
      ),
      onChanged: (_) => CariLagi(),
      onSubmitted: (_) => CariLagi(segera: true),
    ),
  );

  Widget KeadaanDaftar({required Widget Function(List<ProdukAksiCepat>) isi}) {
    if (galat != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(TokenJarak.jarak24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(galat!, textAlign: TextAlign.center),
              const SizedBox(height: TokenJarak.jarak12),
              OutlinedButton(onPressed: () => CariLagi(segera: true), child: const Text('Coba lagi')),
            ],
          ),
        ),
      );
    }
    final data = hasil;
    if (data == null) {
      return const Center(child: TandaMuat(ukuran: 48));
    }
    if (data.isEmpty) {
      return const KeadaanKosong(
        ikon: Icons.search_off,
        judul: 'Produk tidak ditemukan.',
        keterangan: 'Coba nama atau SKU lain.',
        ringkas: true,
      );
    }
    return Stack(
      children: [
        isi(data),
        if (memuat) const Positioned(top: 0, left: 0, right: 0, child: LinearProgressIndicator(minHeight: 2)),
      ],
    );
  }
}

class _LayarUbahHarga extends ConsumerStatefulWidget {
  const _LayarUbahHarga();

  @override
  ConsumerState<_LayarUbahHarga> createState() => _LayarUbahHargaState();
}

class _LayarUbahHargaState extends ConsumerState<_LayarUbahHarga> with _PencarianProduk<_LayarUbahHarga> {
  @override
  void initState() {
    super.initState();
    CariLagi(segera: true);
  }

  @override
  Future<List<ProdukAksiCepat>> Ambil(String kata) => ref.read(penyediaKlien).CariProdukAksiCepat(cari: kata);

  Future<void> _Ubah(ProdukAksiCepat produk) async {
    final harga = await showDialog<String>(
      context: context,
      builder: (_) => _DialogHarga(produk: produk),
    );
    if (harga == null || !mounted) return;
    final pesan = ScaffoldMessenger.of(context);
    try {
      final hasilUbah = await ref.read(penyediaKlien).UbahHargaProduk(produk.uuid, harga);
      pesan.showSnackBar(
        SnackBar(
          content: Text(
            hasilUbah.berubah
                ? 'Harga ${hasilUbah.nama} menjadi ${FormatTampilan.Rupiah(hasilUbah.hargaBaru)}.'
                : 'Harga ${hasilUbah.nama} sudah ${FormatTampilan.Rupiah(hasilUbah.hargaBaru)}.',
          ),
        ),
      );
      CariLagi(segera: true);
    } on Object catch (e) {
      pesan.showSnackBar(SnackBar(content: Text(_PesanGalat(e))));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Ubah harga')),
    body: Column(
      children: [
        KotakCari('Cari produk (nama atau SKU)'),
        Expanded(
          child: KeadaanDaftar(
            isi: (data) => ListView.separated(
              itemCount: data.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final p = data[i];
                return ListTile(
                  minTileHeight: TokenJarak.targetSentuh,
                  title: Text(p.nama),
                  subtitle: Text(
                    p.bisaUbahHarga
                        ? (p.sku == null
                              ? 'Harga ${FormatTampilan.Rupiah(p.hargaDasar!)}'
                              : '${p.sku} | ${FormatTampilan.Rupiah(p.hargaDasar!)}')
                        : p.jenis == 'IndukVarian'
                        ? 'Ubah harga per varian di dashboard.'
                        : 'Belum punya harga jual. Atur di dashboard.',
                  ),
                  trailing: p.bisaUbahHarga ? const Icon(Icons.edit_outlined) : null,
                  enabled: p.bisaUbahHarga,
                  onTap: p.bisaUbahHarga ? () => unawaited(_Ubah(p)) : null,
                );
              },
            ),
          ),
        ),
      ],
    ),
  );
}

class _DialogHarga extends StatefulWidget {
  const _DialogHarga({required this.produk});

  final ProdukAksiCepat produk;

  @override
  State<_DialogHarga> createState() => _DialogHargaState();
}

class _DialogHargaState extends State<_DialogHarga> {
  late final TextEditingController _isi = TextEditingController(text: _Angka(widget.produk.hargaDasar ?? ''));

  static String _Angka(String desimal) => desimal.split('.').first;

  @override
  void dispose() {
    _isi.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final angka = _isi.text;
    final sah = angka.isNotEmpty && (int.tryParse(angka) ?? 0) > 0;
    return AlertDialog(
      title: Text(widget.produk.nama),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Harga sekarang ${FormatTampilan.Rupiah(widget.produk.hargaDasar ?? '0')}'),
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _isi,
            autofocus: true,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(12)],
            decoration: InputDecoration(
              labelText: 'Harga baru (Rp)',
              helperText: sah ? FormatTampilan.Rupiah(angka) : 'Isi angka lebih dari 0.',
              border: const OutlineInputBorder(),
            ),
            onChanged: (_) => setState(() {}),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal')),
        FilledButton(onPressed: sah ? () => Navigator.pop(context, angka) : null, child: const Text('Simpan harga')),
      ],
    );
  }
}

/// Outlet yang dipilih untuk aksi cepat: otomatis bila hanya satu, dropdown bila banyak. Null selama belum dipilih.
class _PemilihOutlet extends ConsumerWidget {
  const _PemilihOutlet({required this.dipilih, required this.saatPilih});

  final String? dipilih;
  final ValueChanged<String?> saatPilih;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final outlet = ref.watch(penyediaDasbor).value?.outlet ?? const <OutletRingkas>[];
    if (outlet.isEmpty) {
      return const SizedBox.shrink();
    }
    if (outlet.length == 1) {
      if (dipilih != outlet.first.uuid) {
        WidgetsBinding.instance.addPostFrameCallback((_) => saatPilih(outlet.first.uuid));
      }
      return ListTile(leading: const Icon(Icons.storefront_outlined), title: Text(outlet.first.nama), dense: true);
    }
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: TokenJarak.jarak16),
      child: DropdownButtonFormField<String>(
        initialValue: outlet.any((o) => o.uuid == dipilih) ? dipilih : null,
        isExpanded: true,
        decoration: const InputDecoration(labelText: 'Outlet', border: OutlineInputBorder()),
        items: [for (final o in outlet) DropdownMenuItem(value: o.uuid, child: Text(o.nama))],
        onChanged: saatPilih,
      ),
    );
  }
}

class _LayarTandaiHabis extends ConsumerStatefulWidget {
  const _LayarTandaiHabis();

  @override
  ConsumerState<_LayarTandaiHabis> createState() => _LayarTandaiHabisState();
}

class _LayarTandaiHabisState extends ConsumerState<_LayarTandaiHabis> with _PencarianProduk<_LayarTandaiHabis> {
  String? _outlet;
  final Set<String> _sibuk = {};

  @override
  Future<List<ProdukAksiCepat>> Ambil(String kata) =>
      ref.read(penyediaKlien).CariProdukAksiCepat(cari: kata, outlet: _outlet);

  void _PilihOutlet(String? uuid) {
    if (uuid == _outlet) return;
    setState(() {
      _outlet = uuid;
      hasil = null;
    });
    CariLagi(segera: true);
  }

  Future<void> _Ganti(ProdukAksiCepat produk, bool habis) async {
    final outlet = _outlet;
    if (outlet == null) return;
    final pesan = ScaffoldMessenger.of(context);
    setState(() => _sibuk.add(produk.uuid));
    try {
      final akhir = await ref
          .read(penyediaKlien)
          .TandaiProdukHabis(uuidProduk: produk.uuid, outlet: outlet, habis: habis);
      pesan.showSnackBar(
        SnackBar(content: Text(akhir ? '${produk.nama} ditandai habis.' : '${produk.nama} tersedia lagi.')),
      );
    } on Object catch (e) {
      pesan.showSnackBar(SnackBar(content: Text(_PesanGalat(e))));
    } finally {
      if (mounted) {
        setState(() => _sibuk.remove(produk.uuid));
        CariLagi(segera: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Tandai habis')),
    body: Column(
      children: [
        const SizedBox(height: TokenJarak.jarak12),
        _PemilihOutlet(dipilih: _outlet, saatPilih: _PilihOutlet),
        if (_outlet == null)
          const Expanded(
            child: KeadaanKosong(
              ikon: Icons.storefront_outlined,
              judul: 'Pilih outlet dulu.',
              keterangan: 'Status habis berlaku per outlet.',
              ringkas: true,
            ),
          )
        else ...[
          KotakCari('Cari produk (nama atau SKU)'),
          Expanded(
            child: KeadaanDaftar(
              isi: (data) => ListView.separated(
                itemCount: data.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final p = data[i];
                  return SwitchListTile(
                    title: Text(p.nama),
                    subtitle: Text(
                      !p.bisaTandaiHabis
                          ? 'Tandai habis per varian di dashboard.'
                          : (p.habis ?? false)
                          ? 'Habis'
                          : 'Tersedia',
                    ),
                    value: p.habis ?? false,
                    onChanged: p.bisaTandaiHabis && !_sibuk.contains(p.uuid)
                        ? (nyala) => unawaited(_Ganti(p, nyala))
                        : null,
                  );
                },
              ),
            ),
          ),
        ],
      ],
    ),
  );
}

class _LayarPromo extends ConsumerStatefulWidget {
  const _LayarPromo();

  @override
  ConsumerState<_LayarPromo> createState() => _LayarPromoState();
}

class _LayarPromoState extends ConsumerState<_LayarPromo> {
  List<PromoAksiCepat>? _daftar;
  String? _galat;
  final Set<String> _sibuk = {};

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  Future<void> _Muat() async {
    setState(() => _galat = null);
    try {
      final data = await ref.read(penyediaKlien).AmbilPromoAksiCepat();
      if (mounted) setState(() => _daftar = data);
    } on Object catch (e) {
      if (mounted) setState(() => _galat = _PesanGalat(e));
    }
  }

  Future<void> _Ganti(PromoAksiCepat promo, bool aktif) async {
    final pesan = ScaffoldMessenger.of(context);
    setState(() => _sibuk.add(promo.uuid));
    try {
      await ref.read(penyediaKlien).UbahStatusPromo(promo.uuid, aktif: aktif);
      pesan.showSnackBar(SnackBar(content: Text(aktif ? '${promo.nama} dinyalakan.' : '${promo.nama} dimatikan.')));
    } on Object catch (e) {
      pesan.showSnackBar(SnackBar(content: Text(_PesanGalat(e))));
    } finally {
      if (mounted) {
        setState(() => _sibuk.remove(promo.uuid));
        unawaited(_Muat());
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final daftar = _daftar;
    return Scaffold(
      appBar: AppBar(title: const Text('Promo')),
      body: _galat != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(TokenJarak.jarak24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_galat!, textAlign: TextAlign.center),
                    const SizedBox(height: TokenJarak.jarak12),
                    OutlinedButton(onPressed: _Muat, child: const Text('Coba lagi')),
                  ],
                ),
              ),
            )
          : daftar == null
          ? const Center(child: TandaMuat(ukuran: 48))
          : daftar.isEmpty
          ? const KeadaanKosong(
              ikon: Icons.local_offer_outlined,
              judul: 'Belum ada promo.',
              keterangan: 'Buat promo di dashboard, lalu nyalakan atau matikan dari sini.',
              ringkas: true,
            )
          : RefreshIndicator(
              onRefresh: _Muat,
              child: ListView.separated(
                itemCount: daftar.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final p = daftar[i];
                  return SwitchListTile(
                    title: Text(p.nama),
                    subtitle: Text(p.berakhir ? '${p.kode} | Masa berlaku sudah lewat' : p.kode),
                    value: p.aktif,
                    onChanged: _sibuk.contains(p.uuid) ? null : (nyala) => unawaited(_Ganti(p, nyala)),
                  );
                },
              ),
            ),
    );
  }
}

class _LayarPengeluaran extends ConsumerStatefulWidget {
  const _LayarPengeluaran();

  @override
  ConsumerState<_LayarPengeluaran> createState() => _LayarPengeluaranState();
}

class _LayarPengeluaranState extends ConsumerState<_LayarPengeluaran> {
  OpsiPengeluaran? _opsi;
  String? _galatMuat;
  String? _kategori;
  String? _sumber;
  String? _outlet;
  Uint8List? _foto;
  bool _mengirim = false;
  final _jumlah = TextEditingController();
  final _keterangan = TextEditingController();
  // Satu kunci per formulir: ketuk ganda atau kirim ulang karena sinyal putus tetap menjadi satu pengeluaran.
  late final String _kunci = '${DateTime.now().microsecondsSinceEpoch}-${Random().nextInt(1 << 32)}';

  @override
  void initState() {
    super.initState();
    unawaited(_Muat());
  }

  @override
  void dispose() {
    _jumlah.dispose();
    _keterangan.dispose();
    super.dispose();
  }

  Future<void> _Muat() async {
    setState(() => _galatMuat = null);
    try {
      final opsi = await ref.read(penyediaKlien).AmbilOpsiPengeluaran();
      if (!mounted) return;
      setState(() {
        _opsi = opsi;
        _sumber ??= opsi.sumber.length == 1 ? opsi.sumber.first.uuid : null;
      });
    } on Object catch (e) {
      if (mounted) setState(() => _galatMuat = _PesanGalat(e));
    }
  }

  bool get _bisaKirim => !_mengirim && _kategori != null && _sumber != null && (int.tryParse(_jumlah.text) ?? 0) > 0;

  Future<void> _Foto() async {
    final bytes = await ref.read(penyediaKameraNota).Ambil();
    if (bytes != null && mounted) setState(() => _foto = bytes);
  }

  Future<void> _Kirim() async {
    final pesan = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);
    setState(() => _mengirim = true);
    try {
      final hasil = await ref
          .read(penyediaKlien)
          .CatatPengeluaran(
            uuidKategori: _kategori!,
            uuidAkunSumber: _sumber!,
            jumlah: _jumlah.text,
            keterangan: _keterangan.text,
            outlet: _outlet,
            foto: _foto,
            kunciIdempotensi: _kunci,
          );
      pesan.showSnackBar(
        SnackBar(content: Text('Pengeluaran ${FormatTampilan.Rupiah(hasil.jumlah)} tercatat (${hasil.nomor}).')),
      );
      navigator.pop();
    } on Object catch (e) {
      pesan.showSnackBar(SnackBar(content: Text(_PesanGalat(e))));
      if (mounted) setState(() => _mengirim = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final opsi = _opsi;
    final outlet = ref.watch(penyediaDasbor).value?.outlet ?? const <OutletRingkas>[];
    final kamera = ref.watch(penyediaKameraNota);
    final warna = TokenWarna.AmbilDari(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Catat pengeluaran')),
      body: _galatMuat != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(TokenJarak.jarak24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_galatMuat!, textAlign: TextAlign.center),
                    const SizedBox(height: TokenJarak.jarak12),
                    OutlinedButton(onPressed: _Muat, child: const Text('Coba lagi')),
                  ],
                ),
              ),
            )
          : opsi == null
          ? const Center(child: TandaMuat(ukuran: 48))
          : opsi.kategori.isEmpty || opsi.sumber.isEmpty
          ? const KeadaanKosong(
              ikon: Icons.receipt_long_outlined,
              judul: 'Pengeluaran belum bisa dicatat.',
              keterangan: 'Siapkan kategori biaya dan akun kas di dashboard dulu.',
              ringkas: true,
            )
          : ListView(
              padding: const EdgeInsets.all(TokenJarak.jarak16),
              children: [
                DropdownButtonFormField<String>(
                  initialValue: _kategori,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Untuk apa', border: OutlineInputBorder()),
                  items: [for (final k in opsi.kategori) DropdownMenuItem(value: k.uuid, child: Text(k.nama))],
                  onChanged: (v) => setState(() => _kategori = v),
                ),
                const SizedBox(height: TokenJarak.jarak12),
                TextField(
                  controller: _jumlah,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(12)],
                  decoration: InputDecoration(
                    labelText: 'Jumlah (Rp)',
                    helperText: (int.tryParse(_jumlah.text) ?? 0) > 0 ? FormatTampilan.Rupiah(_jumlah.text) : null,
                    border: const OutlineInputBorder(),
                  ),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: TokenJarak.jarak12),
                DropdownButtonFormField<String>(
                  initialValue: _sumber,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Dibayar dari', border: OutlineInputBorder()),
                  items: [for (final s in opsi.sumber) DropdownMenuItem(value: s.uuid, child: Text(s.nama))],
                  onChanged: (v) => setState(() => _sumber = v),
                ),
                if (outlet.length > 1) ...[
                  const SizedBox(height: TokenJarak.jarak12),
                  DropdownButtonFormField<String?>(
                    initialValue: _outlet,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'Outlet (boleh dikosongkan)',
                      border: OutlineInputBorder(),
                    ),
                    items: [
                      const DropdownMenuItem<String?>(child: Text('Seluruh usaha')),
                      for (final o in outlet) DropdownMenuItem<String?>(value: o.uuid, child: Text(o.nama)),
                    ],
                    onChanged: (v) => setState(() => _outlet = v),
                  ),
                ],
                const SizedBox(height: TokenJarak.jarak12),
                TextField(
                  controller: _keterangan,
                  maxLength: 200,
                  decoration: const InputDecoration(
                    labelText: 'Catatan (boleh dikosongkan)',
                    border: OutlineInputBorder(),
                  ),
                ),
                if (kamera.Tersedia()) ...[
                  const SizedBox(height: TokenJarak.jarak4),
                  if (_foto != null)
                    Row(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(TokenJarak.radiusKontrol),
                          child: Image.memory(
                            _foto!,
                            width: 72,
                            height: 72,
                            fit: BoxFit.cover,
                            semanticLabel: 'Foto nota',
                            errorBuilder: (_, _, _) =>
                                const SizedBox(width: 72, height: 72, child: Icon(Icons.broken_image_outlined)),
                          ),
                        ),
                        const SizedBox(width: TokenJarak.jarak12),
                        Expanded(
                          child: Text('Foto nota terlampir.', style: TextStyle(color: warna.teksSekunder)),
                        ),
                        TextButton(onPressed: () => setState(() => _foto = null), child: const Text('Hapus foto')),
                      ],
                    )
                  else
                    OutlinedButton.icon(
                      onPressed: _mengirim ? null : () => unawaited(_Foto()),
                      icon: const Icon(Icons.photo_camera_outlined),
                      label: const Text('Foto nota'),
                    ),
                ],
                const SizedBox(height: TokenJarak.jarak16),
                FilledButton(
                  onPressed: _bisaKirim ? () => unawaited(_Kirim()) : null,
                  child: Text(_mengirim ? 'Menyimpan…' : 'Simpan pengeluaran'),
                ),
              ],
            ),
    );
  }
}
