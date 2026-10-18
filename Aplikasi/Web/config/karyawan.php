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
    // dicatat per absen (`KemiripanWajahMasuk/Keluar`) supaya bisa dikalibrasi dengan data nyata.
    'AmbangKemiripanWajah' => env('AMBANG_KEMIRIPAN_WAJAH', '0.60'),
    // Foto pendaftaran wajah per karyawan (diambil berturut-turut di halaman Absen).
    'JumlahFotoDaftarWajah' => 3,
    // Akurasi GPS (meter) di atas radius outlet dianggap tidak bisa membuktikan karyawan ada di outlet.
    'BatasAkurasiMinimalMeter' => 50,
];
