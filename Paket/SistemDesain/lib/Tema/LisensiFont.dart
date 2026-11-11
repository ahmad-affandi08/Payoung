import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// Mendaftarkan lisensi SIL OFL font Lato ke halaman "Lisensi Pihak Ketiga" (PRD §17.5).
void DaftarkanLisensiFont() {
  LicenseRegistry.addLicense(() async* {
    final teks = await rootBundle.loadString('packages/sistem_desain/assets/fonts/LisensiOFL.txt');
    yield LicenseEntryWithLineBreaks(const ['Lato'], teks);
  });
}
