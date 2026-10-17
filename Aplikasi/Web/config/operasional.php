<?php

declare(strict_types=1);

/*
 * Monitoring operasional (P-11, PGL-20, §20.3). Kunci PascalCase (D-05).
 */
return [
    // BR-P11.1: scheduler tidak berdetak lebih dari 3 menit = alert kritis.
    'BatasDetakPenjadwalMenit' => 3,

    // BR-P11.1: umur job antrean tertua lebih dari 5 menit = alert kritis.
    'BatasUmurTugasTertuaMenit' => 5,

    // §14.5: backup harian (RPO ≤ 24 jam) + toleransi 2 jam.
    'BatasUmurBackupJam' => 26,

    // PGL-20: metrik API per menit disimpan sekian hari lalu dipangkas.
    'RetensiMetrikHari' => 7,

    // PGL-20: jendela pemeriksaan alert error rate & latensi (menit terakhir) dan jumlah permintaan minimum supaya
    // sedikit permintaan tidak membuat persentase menyesatkan.
    'JendelaMetrikMenit' => 5,
    // v6.60: 30 terlalu rendah untuk lalu lintas kecil (2 galat dari 33 permintaan sudah memicu alert kritis).
    'MinimumPermintaanMetrik' => 100,

    // v6.60: alert dari metrik lalu lintas API (error rate, latensi POS) baru terbuka setelah kondisi buruk terus
    // teramati selama ini (menit), dan baru tertutup setelah terus sehat selama ini. Mencegah insiden yang membuka-menutup
    // (satu email "Kritis" per bukaan) saat persentase naik-turun di sekitar ambang. Alert scheduler/antrean/backup/outbox
    // tidak memakai ini.
    'KonfirmasiMetrikMenit' => 2,
    'PulihMetrikMenit' => 5,

    // PGL-20: error rate (galat server 5xx ÷ permintaan) di atas ini = alert kritis.
    'BatasErrorRatePersen' => '5',

    // PGL-20: p95 API POS di atas ini (ms) = alert peringatan. Mengikuti target §20 (< 400 ms); dibaca dari bucket
    // histogram, jadi p95 di bucket 401–800 ms sudah melanggar.
    'BatasP95PosMs' => 400,

    // PGL-20: perangkat yang outbox-nya tidak kosong lebih lama dari ini (jam) dianggap macet.
    'BatasOutboxMacetJam' => 2,

    // PGL-20 lanjutan: rute API dengan permintaan di bawah ini (dalam jendela) tidak diperingkat endpoint terlambat.
    'MinimumPermintaanRute' => 20,

    // Jumlah job gagal terbaru yang ditampilkan di dasbor.
    'JumlahTugasGagalDitampilkan' => 50,
];
