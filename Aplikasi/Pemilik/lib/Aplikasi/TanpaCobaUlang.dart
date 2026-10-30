/// Riverpod 3 mencoba ulang provider yang gagal secara otomatis (sampai 10 kali, jeda melebar) dan selama itu
/// statusnya tetap "memuat". Akibatnya galat server, misalnya 403 `FiturTidakAktif` pada laporan Anti-fraud yang
/// belum termasuk di paket, tidak pernah sampai ke layar: pengguna hanya melihat tanda muat berputar lama sekali.
///
/// Semua layar data di aplikasi ini sudah punya keadaan galat dengan tombol "Coba lagi", jadi percobaan ulang diam-diam
/// dimatikan. Dipasang di `ProviderScope(retry: ...)`.
Duration? TanpaCobaUlangOtomatis(int jumlahPercobaan, Object galat) => null;
