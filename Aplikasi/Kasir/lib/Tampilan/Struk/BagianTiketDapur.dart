import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sistem_desain/SistemDesain.dart';

import '../../Aplikasi/Penyedia.dart';
import '../../Domain/Dapur/LayananTiketDapur.dart';
import '../../Domain/Katalog/KatalogLokal.dart';

/// Tiket dapur penjualan langsung di layar selesai bayar (cetak struk bagian 4c, v1.89): bila outlet punya stasiun
/// dapur dan perangkat ini punya printer dapur, tiket dicetak otomatis sekali per penjualan; tombol cetak ulang
/// (bertanda "CETAK ULANG"). Tanpa printer dapur bagian ini tidak tampil (tiket tetap masuk layar dapur lewat sinkron).
class BagianTiketDapur extends ConsumerStatefulWidget {
  const BagianTiketDapur({super.key, required this.uuidPenjualan, this.namaPelanggan, this.diAtasMerek = false});

  final String uuidPenjualan;
  final String? namaPelanggan;

  /// D-66: layar selesai berlatar warna merek, jadi pesan & tombolnya memakai warna terang.
  final bool diAtasMerek;

  /// Penjualan yang tiketnya sudah dicetak otomatis (layar selesai bisa dibangun ulang).
  static final Set<String> _sudahOtomatis = {};

  @override
  ConsumerState<BagianTiketDapur> createState() => _BagianTiketDapurState();
}

class _BagianTiketDapurState extends ConsumerState<BagianTiketDapur> {
  var _tampil = false;
  var _mencetak = false;
  var _jumlahCetak = 0;
  String? _galat;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _Mulai());
  }

  Future<void> _Mulai() async {
    final konteks = await ref.read(penyediaKonteksPenjualan.future);
    if (!konteks.kirimDapurLangsung || !await ref.read(penyediaLayananTiketDapur).CekAdaPrinter() || !mounted) {
      return;
    }
    setState(() => _tampil = true);
    if (BagianTiketDapur._sudahOtomatis.add(widget.uuidPenjualan)) {
      await _Cetak();
    } else {
      setState(() => _jumlahCetak = 1);
    }
  }

  Future<void> _Cetak() async {
    setState(() {
      _mencetak = true;
      _galat = null;
    });
    List<HasilTiketDapur> hasil;
    try {
      hasil = await ref
          .read(penyediaLayananTiketDapur)
          .CetakPenjualan(
            widget.uuidPenjualan,
            katalog: ref.read(penyediaKatalog).value ?? KatalogLokal.kosong,
            namaPelanggan: widget.namaPelanggan,
            cetakUlang: _jumlahCetak > 0,
          );
    } on Object catch (galat) {
      hasil = const [];
      _galat = '$galat';
    }
    if (!mounted) {
      return;
    }
    final gagal = hasil.where((h) => h.galat != null).toList();
    setState(() {
      _mencetak = false;
      if (gagal.isNotEmpty) {
        _galat = 'Tiket ${gagal.map((g) => g.stasiun.nama).join(', ')} gagal dicetak: ${gagal.first.galat}';
      } else if (_galat == null) {
        _jumlahCetak++;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_tampil) {
      return const SizedBox.shrink();
    }
    final teks = Theme.of(context).textTheme;
    final warna = TokenWarna.AmbilDari(context);
    final pesan = _mencetak
        ? 'Mencetak tiket dapur…'
        : _galat ?? (_jumlahCetak > 0 ? 'Tiket dapur sudah dicetak.' : 'Tiket dapur belum dicetak.');
    return Padding(
      padding: const EdgeInsets.only(top: TokenJarak.jarak12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.diAtasMerek && _galat != null)
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.error_outline, size: TokenJarak.ikonKecil, color: warna.aksen),
                const SizedBox(width: TokenJarak.jarak8),
                Expanded(
                  child: Text(pesan, style: teks.bodySmall?.copyWith(color: warna.permukaan)),
                ),
              ],
            )
          else
            Text(
              pesan,
              style: teks.bodySmall?.copyWith(
                color: _galat != null
                    ? warna.bahaya
                    : (widget.diAtasMerek ? warna.permukaan.withValues(alpha: 0.8) : null),
              ),
            ),
          const SizedBox(height: TokenJarak.jarak8),
          SizedBox(
            height: TokenJarak.targetSentuh,
            child: OutlinedButton.icon(
              onPressed: _mencetak ? null : _Cetak,
              style: widget.diAtasMerek
                  ? OutlinedButton.styleFrom(
                      foregroundColor: warna.permukaan,
                      disabledForegroundColor: warna.permukaan.withValues(alpha: 0.5),
                      side: BorderSide(color: warna.aksen, width: 2),
                    )
                  : null,
              icon: const Icon(Icons.soup_kitchen_outlined),
              label: Text(_jumlahCetak > 0 ? 'Cetak ulang tiket dapur' : 'Cetak tiket dapur'),
            ),
          ),
        ],
      ),
    );
  }
}
