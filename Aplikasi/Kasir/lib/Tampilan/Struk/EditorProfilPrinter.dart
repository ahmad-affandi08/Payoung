import 'package:adaptor_perangkat/AdaptorPerangkat.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Struk/PemindaiPrinter.dart';
import '../../Domain/Struk/ProfilPrinter.dart';

/// Isian satu profil printer (PRD v1.79–v1.80, v1.96, dipakai juga printer dapur v1.89): LAN/Wi-Fi (alamat IP & port),
/// Bluetooth (printer yang sudah di-pair; Android & Windows), Bluetooth LE (pindai; iOS, Android, Windows), USB (Android
/// OTG & printer terpasang di Windows), atau printer bawaan POS all-in-one (Android) sesuai
/// [PemindaiPrinter.AmbilJenisDidukung]; lebar kertas; dan untuk printer struk: cetak otomatis & buka laci.
/// Cetak uji memakai isian yang sedang dipilih sebelum disimpan.
class EditorProfilPrinter extends ConsumerStatefulWidget {
  const EditorProfilPrinter({
    super.key,
    required this.profilAwal,
    required this.saatSimpan,
    required this.saatBatal,
    required this.saatCetakUji,
    this.opsiStruk = true,
    this.labelSimpan = 'Simpan printer',
  });

  final ProfilPrinter? profilAwal;
  final Future<void> Function(ProfilPrinter profil) saatSimpan;
  final VoidCallback saatBatal;

  /// Kembalikan pesan galat (null = terkirim).
  final Future<String?> Function(ProfilPrinter profil) saatCetakUji;

  /// Tampilkan pilihan cetak otomatis & buka laci (printer struk).
  final bool opsiStruk;
  final String labelSimpan;

  static String LabelJenis(JenisTransport jenis) => switch (jenis) {
    JenisTransport.Jaringan => 'LAN/Wi-Fi',
    JenisTransport.BluetoothKlasik => 'Bluetooth',
    JenisTransport.Ble => 'Bluetooth LE',
    JenisTransport.Usb => 'USB',
    JenisTransport.SdkVendor => 'Printer bawaan',
    JenisTransport.CetakSistem => 'Printer sistem',
  };

  @override
  ConsumerState<EditorProfilPrinter> createState() => _EditorProfilPrinterState();
}

class _EditorProfilPrinterState extends ConsumerState<EditorProfilPrinter> {
  final _alamat = TextEditingController();
  final _port = TextEditingController(text: '${TransportJaringan.portBawaan}');
  var _jenis = JenisTransport.Jaringan;
  var _lebar = LebarKertas.Mm58;
  var _cetakOtomatis = true;
  var _bukaLaci = true;
  var _umpanAkhir = PerintahEscPos.umpanAkhirBawaan;
  var _sibuk = false;
  var _mencari = false;
  List<PrinterDitemukan>? _hasilCari;
  PrinterDitemukan? _terpilih;
  String? _galatAlamat;
  String? _galatPort;
  String? _galatCari;
  String? _pesan;
  var _pesanGalat = false;

  @override
  void initState() {
    super.initState();
    final profil = widget.profilAwal;
    _jenis = profil?.jenis ?? JenisTransport.Jaringan;
    final jaringan = _jenis == JenisTransport.Jaringan;
    _alamat.text = jaringan ? profil?.alamat ?? '' : '';
    _port.text = '${profil?.port ?? TransportJaringan.portBawaan}';
    _terpilih = profil == null || jaringan
        ? null
        : PrinterDitemukan(jenis: profil.jenis, alamat: profil.alamat, nama: profil.nama ?? profil.alamat);
    _lebar = profil?.lebar ?? (widget.opsiStruk ? LebarKertas.Mm58 : LebarKertas.Mm80);
    _cetakOtomatis = profil?.cetakOtomatis ?? true;
    _bukaLaci = profil?.bukaLaciTunai ?? true;
    _umpanAkhir = profil?.umpanAkhir ?? PerintahEscPos.umpanAkhirBawaan;
  }

  @override
  void dispose() {
    _alamat.dispose();
    _port.dispose();
    super.dispose();
  }

  void _GantiJenis(JenisTransport jenis) => setState(() {
    _jenis = jenis;
    // Printer sistem tidak perlu dicari: printer dipilih di dialog cetak sistem.
    _terpilih = jenis == JenisTransport.CetakSistem ? PrinterDitemukan.sistem : null;
    _hasilCari = null;
    _galatCari = null;
  });

  Future<void> _CariPrinter() async {
    final pemindai = ref.read(penyediaPemindaiPrinter);
    final jenis = _jenis;
    setState(() {
      _mencari = true;
      _galatCari = null;
      _hasilCari = null;
    });
    String? galat;
    List<PrinterDitemukan>? hasil;
    try {
      galat = await pemindai.SiapkanBluetooth(jenis);
      if (galat == null) {
        hasil = await pemindai.CariPrinter(jenis);
      }
    } on GalatPrinter catch (g) {
      galat = g.pesan;
    }
    if (!mounted || jenis != _jenis) {
      return;
    }
    setState(() {
      _mencari = false;
      _galatCari = galat;
      _hasilCari = hasil;
      if (hasil != null && hasil.length == 1) {
        _terpilih = hasil.single;
      }
    });
  }

  ProfilPrinter? _AmbilIsian() {
    if (_jenis == JenisTransport.Jaringan) {
      setState(() {
        _galatAlamat = ProfilPrinter.ValidasiAlamat(_alamat.text);
        _galatPort = ProfilPrinter.ValidasiPort(_port.text);
      });
      if (_galatAlamat != null || _galatPort != null) {
        return null;
      }
    } else if (_terpilih == null) {
      setState(() => _galatCari = 'Pilih printer dulu. Ketuk Cari printer untuk melihat daftarnya.');
      return null;
    }
    final pilihan = _terpilih;
    return ProfilPrinter(
      jenis: _jenis,
      alamat: _jenis == JenisTransport.Jaringan ? _alamat.text.trim() : pilihan!.alamat,
      nama: _jenis == JenisTransport.Jaringan ? null : pilihan!.nama,
      port: _jenis == JenisTransport.Jaringan ? int.parse(_port.text.trim()) : TransportJaringan.portBawaan,
      lebar: _lebar,
      cetakOtomatis: _cetakOtomatis,
      bukaLaciTunai: _bukaLaci && _jenis != JenisTransport.CetakSistem,
      umpanAkhir: _umpanAkhir,
    );
  }

  Future<void> _CetakUji() async {
    final profil = _AmbilIsian();
    if (profil == null) {
      return;
    }
    setState(() {
      _sibuk = true;
      _pesan = null;
    });
    final galat = await widget.saatCetakUji(profil);
    if (!mounted) {
      return;
    }
    setState(() {
      _sibuk = false;
      _pesan = galat ?? 'Halaman uji terkirim. Pastikan semua baris lurus dan tidak terpotong.';
      _pesanGalat = galat != null;
    });
  }

  Future<void> _Simpan() async {
    final profil = _AmbilIsian();
    if (profil != null) {
      await widget.saatSimpan(profil);
    }
  }

  List<Widget> _BangunPilihBluetooth(TextTheme teks, TokenWarna warna) {
    if (_jenis == JenisTransport.CetakSistem) {
      return [
        Text(
          'Struk dibuka di dialog cetak perangkat: pilih printer kantor, AirPrint, atau simpan sebagai PDF. '
          'Cocok sebagai cadangan. Laci kas tidak bisa dibuka lewat printer sistem.',
          style: teks.bodyMedium,
        ),
      ];
    }
    final hasil = _hasilCari;
    final kosong = switch (_jenis) {
      JenisTransport.BluetoothKlasik =>
        'Belum ada printer Bluetooth yang di-pair. Pasangkan printer di pengaturan Bluetooth perangkat '
            '(PIN biasanya 0000 atau 1234), lalu ketuk Cari printer lagi.',
      JenisTransport.Usb =>
        'Tidak ada printer USB yang tersambung. Pasang kabel USB (pakai OTG di tablet/HP), nyalakan printer, '
            'lalu cari lagi. Di Windows, pasang dulu driver printernya.',
      JenisTransport.SdkVendor =>
        'Printer bawaan perangkat ini belum dikenali. Pilih sambungan Bluetooth atau USB, lalu jalankan uji perangkat.',
      _ => 'Tidak ada printer Bluetooth LE di sekitar. Nyalakan printer dan dekatkan ke perangkat, lalu cari lagi.',
    };
    return [
      Text(switch (_jenis) {
        JenisTransport.BluetoothKlasik => 'Printer harus sudah di-pair di pengaturan Bluetooth perangkat.',
        JenisTransport.Usb => 'Printer USB yang tersambung ke perangkat ini.',
        JenisTransport.SdkVendor => 'Printer yang menyatu dengan mesin kasir (Sunmi, iMin, dan merek lain).',
        _ => 'Printer dicari di sekitar selama beberapa detik.',
      }, style: teks.bodySmall),
      const SizedBox(height: TokenJarak.jarak8),
      SizedBox(
        height: TokenJarak.targetSentuh,
        child: OutlinedButton.icon(
          onPressed: _mencari || _sibuk ? null : _CariPrinter,
          icon: Icon(switch (_jenis) {
            JenisTransport.Usb => Icons.usb,
            JenisTransport.SdkVendor => Icons.point_of_sale,
            _ => Icons.bluetooth_searching,
          }),
          label: Text(_mencari ? 'Mencari printer…' : 'Cari printer'),
        ),
      ),
      if (_galatCari != null)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak8),
          child: Text(_galatCari!, style: teks.bodyMedium?.copyWith(color: warna.bahaya)),
        ),
      if (hasil != null && hasil.isEmpty)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak8),
          child: Text(kosong, style: teks.bodyMedium),
        ),
      if (hasil != null && hasil.isNotEmpty) ...[
        const SizedBox(height: TokenJarak.jarak8),
        for (final p in hasil)
          ListTile(
            contentPadding: EdgeInsets.zero,
            minTileHeight: TokenJarak.targetSentuh,
            leading: Icon(
              _terpilih?.alamat == p.alamat ? Icons.radio_button_checked : Icons.radio_button_unchecked,
              color: _terpilih?.alamat == p.alamat ? warna.brand : warna.teksSekunder,
            ),
            title: Text(p.nama),
            subtitle: p.nama == p.alamat ? null : Text(p.alamat, style: teks.bodySmall),
            onTap: () => setState(() {
              _terpilih = p;
              _galatCari = null;
            }),
          ),
      ] else if (_terpilih != null)
        Padding(
          padding: const EdgeInsets.only(top: TokenJarak.jarak8),
          child: Text('Printer terpilih: ${_terpilih!.nama}', style: teks.bodyMedium),
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final jenisDidukung = ref.watch(penyediaPemindaiPrinter).AmbilJenisDidukung();
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);

    Widget Tombol(String label, IconData ikon, VoidCallback? aksi, {bool utama = false}) => SizedBox(
      height: TokenJarak.targetSentuh,
      child: utama
          ? FilledButton.icon(onPressed: _sibuk ? null : aksi, icon: Icon(ikon), label: Text(label))
          : OutlinedButton.icon(onPressed: _sibuk ? null : aksi, icon: Icon(ikon), label: Text(label)),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (jenisDidukung.length > 1) ...[
          Text('Sambungan printer', style: teks.labelLarge),
          const SizedBox(height: TokenJarak.jarak4),
          // Chip bisa turun baris: sampai 5 jenis sambungan tetap muat di layar 360 dp.
          Wrap(
            spacing: TokenJarak.jarak8,
            runSpacing: TokenJarak.jarak8,
            children: [
              for (final j in jenisDidukung)
                ChoiceChip(
                  label: Text(EditorProfilPrinter.LabelJenis(j)),
                  selected: _jenis == j,
                  showCheckmark: false,
                  onSelected: (_) => _GantiJenis(j),
                ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak12),
        ],
        if (_jenis == JenisTransport.Jaringan)
          Wrap(
            spacing: TokenJarak.jarak12,
            runSpacing: TokenJarak.jarak12,
            children: [
              SizedBox(
                width: 240,
                child: TextField(
                  controller: _alamat,
                  keyboardType: TextInputType.url,
                  decoration: InputDecoration(
                    labelText: 'Alamat IP printer',
                    hintText: '192.168.1.50',
                    errorText: _galatAlamat,
                  ),
                ),
              ),
              SizedBox(
                width: 120,
                child: TextField(
                  controller: _port,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(labelText: 'Port', errorText: _galatPort),
                ),
              ),
            ],
          )
        else
          ..._BangunPilihBluetooth(teks, warna),
        const SizedBox(height: TokenJarak.jarak12),
        Text('Lebar kertas', style: teks.labelLarge),
        const SizedBox(height: TokenJarak.jarak4),
        SegmentedButton<LebarKertas>(
          showSelectedIcon: false,
          segments: [for (final l in LebarKertas.values) ButtonSegment(value: l, label: Text(l.label))],
          selected: {_lebar},
          onSelectionChanged: (pilihan) => setState(() => _lebar = pilihan.single),
        ),
        if (_jenis != JenisTransport.CetakSistem) ...[
          const SizedBox(height: TokenJarak.jarak8),
          Text('Kertas kosong di ujung struk: $_umpanAkhir baris', style: teks.labelLarge),
          Slider(
            key: const ValueKey('UmpanAkhir'),
            value: _umpanAkhir.toDouble(),
            max: PerintahEscPos.umpanAkhirMaksimal.toDouble(),
            divisions: PerintahEscPos.umpanAkhirMaksimal,
            label: '$_umpanAkhir',
            onChanged: (nilai) => setState(() => _umpanAkhir = nilai.round()),
          ),
          Text(
            'Kurangi bila kertas di bawah tulisan terakhir terlalu panjang. Naikkan bila tulisan ikut terpotong.',
            style: teks.bodySmall,
          ),
        ],
        const SizedBox(height: TokenJarak.jarak8),
        if (widget.opsiStruk) ...[
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Cetak struk otomatis setelah bayar'),
            value: _cetakOtomatis,
            onChanged: (nilai) => setState(() => _cetakOtomatis = nilai),
          ),
          if (_jenis != JenisTransport.CetakSistem)
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Buka laci kas untuk pembayaran tunai'),
              subtitle: const Text('Laci tersambung ke printer lewat kabel RJ11.'),
              value: _bukaLaci,
              onChanged: (nilai) => setState(() => _bukaLaci = nilai),
            ),
          const SizedBox(height: TokenJarak.jarak8),
        ],
        Wrap(
          spacing: TokenJarak.jarak8,
          runSpacing: TokenJarak.jarak8,
          children: [
            Tombol(widget.labelSimpan, Icons.check, _Simpan, utama: true),
            Tombol('Cetak uji', Icons.print_outlined, _CetakUji),
            Tombol('Batal', Icons.close, widget.saatBatal),
          ],
        ),
        if (_pesan != null)
          Padding(
            padding: const EdgeInsets.only(top: TokenJarak.jarak8),
            child: Text(
              _pesan!,
              style: teks.bodyMedium?.copyWith(color: _pesanGalat ? warna.bahaya : warna.teksSekunder),
            ),
          ),
      ],
    );
  }
}
