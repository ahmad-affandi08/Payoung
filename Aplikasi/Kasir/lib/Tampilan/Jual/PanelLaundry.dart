import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:klien_api/KlienApi.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/GalatKasir.dart';
import '../../Domain/Penjualan/KonteksPenjualan.dart';
import '../../Domain/Penjualan/LayananLaundry.dart';

/// Laundry (§9.9): isian tiket laundry untuk transaksi yang sedang dibuat (bisa offline): layanan reguler/express
/// (perkiraan selesai otomatis), berat kg dan/atau item satuan, parfum, catatan, serta nama & HP pemilik cucian bila
/// belum memilih pelanggan. Tiket ikut tersimpan saat pembayaran selesai; nota tercetak dengan QR lacak.
class PanelLaundry extends ConsumerStatefulWidget {
  const PanelLaundry({super.key, required this.saatSelesai});

  final VoidCallback saatSelesai;

  /// "Sel, 14 Okt 09.25" menurut jam dinding outlet.
  static String FormatWaktu(DateTime waktu, String zona) {
    const hari = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    final w = ZonaWaktuOutlet.KeWaktuOutlet(waktu, zona);
    String Dua(int n) => n.toString().padLeft(2, '0');
    return '${hari[w.weekday - 1]}, ${w.day} ${bulan[w.month - 1]} ${Dua(w.hour)}.${Dua(w.minute)}';
  }

  @override
  ConsumerState<PanelLaundry> createState() => _PanelLaundryState();
}

class _PanelLaundryState extends ConsumerState<PanelLaundry> {
  final _berat = TextEditingController();
  final _catatan = TextEditingController();
  final _nama = TextEditingController();
  final _hp = TextEditingController();
  final _namaItem = TextEditingController();
  String _jenis = 'Reguler';
  String? _parfum;
  final List<({String nama, int jumlah})> _item = [];
  String? _galat;

  @override
  void initState() {
    super.initState();
    final ada = ref.read(penyediaKeranjang).laundry;
    if (ada != null) {
      _jenis = ada.jenisLayanan;
      _berat.text = ada.berat?.toString().replaceAll('.', ',') ?? '';
      _catatan.text = ada.catatan ?? '';
      _nama.text = ada.namaPelanggan ?? '';
      _hp.text = ada.noHp ?? '';
      _parfum = ada.parfum;
      _item.addAll(ada.item);
    }
  }

  @override
  void dispose() {
    for (final c in [_berat, _catatan, _nama, _hp, _namaItem]) {
      c.dispose();
    }
    super.dispose();
  }

  void _TambahItem() {
    final nama = _namaItem.text.trim();
    if (nama.isEmpty) {
      return;
    }
    setState(() {
      final indeks = _item.indexWhere((i) => i.nama.toLowerCase() == nama.toLowerCase());
      if (indeks >= 0) {
        _item[indeks] = (nama: _item[indeks].nama, jumlah: _item[indeks].jumlah + 1);
      } else {
        _item.add((nama: nama, jumlah: 1));
      }
      _namaItem.clear();
    });
  }

  void _Simpan(LaundryPos pengaturan) {
    final keranjang = ref.read(penyediaKeranjang);
    try {
      final tiket = ref
          .read(penyediaLayananLaundry)
          .BuatTiket(
            jenisLayanan: _jenis,
            pengaturan: pengaturan,
            pelanggan: keranjang.pelanggan,
            berat: LayananLaundry.UraiBerat(_berat.text),
            item: _item,
            parfum: _parfum,
            catatan: _catatan.text,
            namaPelanggan: _nama.text,
            noHp: _hp.text,
          );
      ref.read(penyediaKeranjang.notifier).Ganti(keranjang.Salin(laundry: () => tiket));
      widget.saatSelesai();
    } on GalatKasir catch (galat) {
      setState(() => _galat = galat.pesan);
    }
  }

  void _Hapus() {
    final keranjang = ref.read(penyediaKeranjang);
    ref.read(penyediaKeranjang.notifier).Ganti(keranjang.Salin(laundry: () => null));
    widget.saatSelesai();
  }

  @override
  Widget build(BuildContext context) {
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final k = ref.watch(penyediaKonteksPenjualan).value;
    final pengaturan = k?.laundry ?? const LaundryPos();
    final adaWhatsapp = k?.whatsappTersedia ?? false;
    final pelanggan = ref.watch(penyediaKeranjang.select((k) => k.pelanggan));
    final adaTiket = ref.watch(penyediaKeranjang.select((k) => k.laundry != null));
    final estimasi = ref.read(penyediaLayananLaundry).HitungEstimasi(_jenis, pengaturan);
    return Padding(
      padding: const EdgeInsets.all(TokenJarak.jarak16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Layanan', style: teks.titleSmall),
          const SizedBox(height: TokenJarak.jarak8),
          Wrap(
            spacing: TokenJarak.jarak8,
            children: [
              for (final (nilai, label) in [
                ('Reguler', 'Reguler | ${pengaturan.jamReguler} jam'),
                ('Express', 'Express | ${pengaturan.jamExpress} jam'),
              ])
                ChoiceChip(
                  label: Text(label),
                  selected: _jenis == nilai,
                  onSelected: (_) => setState(() => _jenis = nilai),
                ),
            ],
          ),
          const SizedBox(height: TokenJarak.jarak4),
          Text(
            'Perkiraan selesai ${PanelLaundry.FormatWaktu(estimasi, k?.zonaWaktu ?? ZonaWaktuOutlet.bawaan)}',
            style: teks.bodySmall,
          ),
          const SizedBox(height: TokenJarak.jarak16),
          TextField(
            controller: _berat,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Berat (kg)',
              hintText: 'Contoh: 3,5',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: TokenJarak.jarak12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _namaItem,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _TambahItem(),
                  decoration: const InputDecoration(
                    labelText: 'Item satuan (jas, bed cover, ...)',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              const SizedBox(width: TokenJarak.jarak8),
              SizedBox(
                height: TokenJarak.targetSentuh,
                child: OutlinedButton(onPressed: _TambahItem, child: const Text('Tambah item')),
              ),
            ],
          ),
          for (var i = 0; i < _item.length; i++)
            Row(
              children: [
                Expanded(child: Text('${_item[i].nama} ×${_item[i].jumlah}', style: teks.bodyMedium)),
                IconButton(
                  tooltip: 'Kurangi ${_item[i].nama}',
                  onPressed: () => setState(() {
                    final sisa = _item[i].jumlah - 1;
                    if (sisa <= 0) {
                      _item.removeAt(i);
                    } else {
                      _item[i] = (nama: _item[i].nama, jumlah: sisa);
                    }
                  }),
                  icon: const Icon(Icons.remove_circle_outline),
                ),
              ],
            ),
          if (pengaturan.parfum.isNotEmpty) ...[
            const SizedBox(height: TokenJarak.jarak12),
            Text('Parfum', style: teks.titleSmall),
            const SizedBox(height: TokenJarak.jarak8),
            Wrap(
              spacing: TokenJarak.jarak8,
              runSpacing: TokenJarak.jarak8,
              children: [
                for (final p in pengaturan.parfum)
                  ChoiceChip(
                    label: Text(p),
                    selected: _parfum == p,
                    onSelected: (pilih) => setState(() => _parfum = pilih ? p : null),
                  ),
              ],
            ),
          ],
          const SizedBox(height: TokenJarak.jarak12),
          TextField(
            controller: _catatan,
            maxLength: 255,
            decoration: const InputDecoration(
              labelText: 'Catatan (noda, pisahkan warna, ...)',
              border: OutlineInputBorder(),
            ),
          ),
          if (pelanggan == null) ...[
            const SizedBox(height: TokenJarak.jarak8),
            TextField(
              controller: _nama,
              maxLength: 100,
              decoration: const InputDecoration(labelText: 'Nama pemilik cucian', border: OutlineInputBorder()),
            ),
            const SizedBox(height: TokenJarak.jarak8),
            TextField(
              controller: _hp,
              keyboardType: TextInputType.phone,
              maxLength: 20,
              decoration: InputDecoration(
                // D-299: tanpa layanan WhatsApp, isian tetap nomor HP biasa tanpa menyebut kabar lewat WhatsApp.
                labelText: adaWhatsapp ? 'Nomor WhatsApp (untuk kabar cucian siap)' : 'Nomor HP',
                border: const OutlineInputBorder(),
              ),
            ),
          ] else
            Text('Pemilik: ${pelanggan.nama} | ${pelanggan.noHpSamar}', style: teks.bodyMedium),
          if (_galat != null)
            Padding(
              padding: const EdgeInsets.only(top: TokenJarak.jarak8),
              child: Text(_galat!, style: TextStyle(color: warna.bahaya)),
            ),
          const SizedBox(height: TokenJarak.jarak16),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: FilledButton(onPressed: () => _Simpan(pengaturan), child: const Text('Simpan tiket laundry')),
          ),
          if (adaTiket) ...[
            const SizedBox(height: TokenJarak.jarak8),
            SizedBox(
              height: TokenJarak.targetSentuh,
              child: OutlinedButton(onPressed: _Hapus, child: const Text('Hapus tiket laundry')),
            ),
          ],
        ],
      ),
    );
  }
}
