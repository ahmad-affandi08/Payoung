<?php

declare(strict_types=1);

/*
 * Tiket dukungan (P-09, PGL-15). Kunci PascalCase (D-05).
 */
return [
    // SLA respons pertama (P-09 "SLA per paket"), per kode paket lalu per prioritas. Satuannya JAM KERJA (lihat `JamKerja`
    // di bawah; hari libur P-02 dilewati), kecuali tiket Mendesak pada paket di `PaketSlaKalenderMendesak` yang dihitung
    // jam kalender 24 jam sehari. Kolom Normal = janji SLA paket: Gratis 2 hari kerja (18 jam kerja), Starter 1 hari
    // kerja (9 jam kerja), Pro 8 jam kerja, Bisnis 4 jam kerja. Tinggi/Mendesak hanya mempercepat untuk paket berbayar;
    // Rendah tidak memperlambat janji paket.
    'SlaResponsPertamaJam' => [
        'GRATIS' => ['Mendesak' => 18, 'Tinggi' => 18, 'Normal' => 18, 'Rendah' => 18],
        'STARTER' => ['Mendesak' => 8, 'Tinggi' => 9, 'Normal' => 9, 'Rendah' => 9],
        'PRO' => ['Mendesak' => 4, 'Tinggi' => 8, 'Normal' => 8, 'Rendah' => 8],
        'BISNIS' => ['Mendesak' => 2, 'Tinggi' => 4, 'Normal' => 4, 'Rendah' => 4],
        'ENTERPRISE' => ['Mendesak' => 2, 'Tinggi' => 4, 'Normal' => 4, 'Rendah' => 4],
    ],

    // Jam kerja tim dukungan (WIB). Hari kerja: 1 = Senin … 7 = Minggu. Jam kerja per hari = Selesai - Mulai.
    'JamKerja' => [
        'ZonaWaktu' => 'Asia/Jakarta',
        'Mulai' => 8,
        'Selesai' => 17,
        'HariKerja' => [1, 2, 3, 4, 5],
    ],

    // Paket yang tiket Mendesak-nya dihitung jam kalender (24 jam, termasuk malam, akhir pekan, dan hari libur).
    'PaketSlaKalenderMendesak' => ['STARTER', 'PRO', 'BISNIS', 'ENTERPRISE'],

    // Eskalasi (P-09): tiket yang belum direspons setelah melewati batas SLA naik L1 → L2 (Dukungan senior + Super Admin
    // diberi tahu); bila masih belum direspons setelah sekian jam kerja lagi (kalender untuk tiket kalender) naik ke Teknis.
    'JamEskalasiKeTeknis' => 4,

    // Dipakai bila paket tenant tidak dikenal (misal paket baru belum diatur di atas): janji paket Gratis.
    'SlaResponsPertamaJamBawaan' => ['Mendesak' => 18, 'Tinggi' => 18, 'Normal' => 18, 'Rendah' => 18],

    // P-09: tiket `Selesai` bisa dibuka lagi dalam 7 hari; setelah itu ditutup otomatis.
    'HariBukaUlang' => 7,

    // Lampiran pesan: disimpan di disk privat, tidak pernah punya URL publik.
    'DiskLampiran' => 'local',
    'MaksimalLampiranPerPesan' => 3,
    'UkuranMaksimalLampiranKb' => 5120,
    'EkstensiLampiran' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt', 'csv'],

    // PGL-15: tiket masuk dari email/WhatsApp lewat `POST /webhook/dukungan/masuk`. Rahasia HMAC bersama dengan adaptor
    // penyedia; kosong = pintu masuk mati (404). Pesan ber-tanda-waktu lebih tua/lebih baru dari toleransi ditolak.
    'MasukRahasia' => env('DUKUNGAN_MASUK_RAHASIA', ''),
    'MasukToleransiDetik' => 300,

    // PGL-15: pesan masuk yang diterima per pengirim per hari (mencegah banjir tiket).
    'MasukBatasPerPengirimHarian' => 20,
];
