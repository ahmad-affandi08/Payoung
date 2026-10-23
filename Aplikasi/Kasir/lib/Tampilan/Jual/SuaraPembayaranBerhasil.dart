import 'package:audioplayers/audioplayers.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Bunyi singkat tanda pembayaran tersimpan (aset `assets/suara/PembayaranBerhasil.mp3`). Kasir mendengar
/// "berhasil" tanpa harus menatap layar. Pemutaran tidak pernah menahan penyimpanan penjualan: galat pemutar
/// (perangkat tanpa keluaran suara, berkas hilang) diabaikan. Diganti tiruan di test.
class SuaraPembayaranBerhasil {
  const SuaraPembayaranBerhasil();

  static AudioPlayer? _pemutar;

  Future<void> Mainkan() async {
    try {
      final pemutar = _pemutar ??= AudioPlayer(playerId: 'pembayaran-berhasil');
      await pemutar.stop();
      await pemutar.play(AssetSource('suara/PembayaranBerhasil.mp3'));
    } catch (_) {
      // Bunyi hanya pelengkap; gagal memutar tidak boleh mengganggu alur bayar.
    }
  }
}

final penyediaSuaraPembayaran = Provider<SuaraPembayaranBerhasil>((ref) => const SuaraPembayaranBerhasil());
