<?php

declare(strict_types=1);

/*
 * F-18 karyawan: disk privat swafoto absensi dan batas ukuran swafoto (JPEG hasil kompres perangkat). Toleransi
 * terlambat/pulang cepat dan ambang lembur kini aturan tenant (`AturanKehadiran`, F-18 bagian 5), bukan config.
 */
return [
    'DiskSwafoto' => 'local',
    'UkuranMaksimalSwafotoKb' => 300,

    // F-18 bagian 4 (D-37) absensi web. Ambang kemiripan kosinus sidik wajah (0–1) sebelum dianggap orang yang sama;
    // dicatat per absen (`KemiripanWajahMasuk/Keluar`) supaya bisa dikalibrasi dengan data nyata. D-188: 0,60 terlalu longgar
    // (±1 dari 80 orang asing lolos); 0,70 ≈ 1 dari 3.000. Naikkan bila rekan yang mirip masih lolos, turunkan hanya
    // bila karyawan sendiri sering ditolak (lihat panel Kalibrasi di rekap absensi dan `Panduan/KalibrasiWajah.md`).
    'AmbangKemiripanWajah' => env('AMBANG_KEMIRIPAN_WAJAH', '0.70'),
    // Foto pendaftaran wajah per karyawan (diambil berturut-turut di halaman Absen).
    'JumlahFotoDaftarWajah' => 3,
    // Akurasi GPS (meter) di atas radius outlet dianggap tidak bisa membuktikan karyawan ada di outlet.
    'BatasAkurasiMinimalMeter' => 50,
];
