<?php

declare(strict_types=1);

/*
 * Tagihan langganan platform (P-08/F-19 Fase 0: transfer manual + verifikasi bukti). Kunci PascalCase (D-05).
 * Rekening tujuan & status PKP platform dikelola Super Admin dari konsol (D-114, `PengaturanPlatform`); nilai di file ini
 * hanya nilai awal selama belum pernah disimpan dari konsol (deployment lama tidak berubah mendadak).
 */
return [
    // Awalan nomor tagihan: {Awalan}/{Tahun}/{Bulan}/{Urut 6 digit}; urut tanpa celah per tahun (BR-P08.1).
    'AwalanNomor' => env('TAGIHAN_AWALAN_NOMOR', 'INV'),

    // Nilai awal status PKP {{APP}} (P-08 langkah 1). true = PPN ditagihkan memakai TarifPajak Ppn terbit (tidak di-hard-code).
    'PlatformPkp' => (bool) env('TAGIHAN_PLATFORM_PKP', true),

    // Jatuh tempo tagihan aktivasi/ganti paket (hari sejak terbit). Perpanjangan jatuh tempo di akhir periode berjalan.
    'HariJatuhTempo' => (int) env('TAGIHAN_HARI_JATUH_TEMPO', 7),

    // F-00: masa tenggang status Tertunggak sebelum Ditangguhkan.
    'HariMasaTenggang' => (int) env('TAGIHAN_HARI_MASA_TENGGANG', 7),

    // D-249: lama langganan yang boleh dipilih (dalam bulan) dan tangga diskon menurut lama. Nilai di sini hanya nilai awal:
    // Super Admin mengubahnya dari konsol (`PengaturanPlatform`, izin tagihan.pengaturan) dan nilai konsol yang berlaku.
    // `TanggaDiskon` = daftar {DariBulan, Persen}: diskon berlaku untuk lama >= DariBulan (persen dari harga bulanan x bulan).
    // Lama kelipatan 12 bulan memakai harga tahunan katalog (HargaPaket), bukan tangga ini.
    'LamaLangganan' => [
        'MinimalBulan' => (int) env('TAGIHAN_LAMA_MINIMAL_BULAN', 1),
        'MaksimalBulan' => (int) env('TAGIHAN_LAMA_MAKSIMAL_BULAN', 12),
        // Batas pengaman isian konsol (bukan pilihan tenant): maksimal yang boleh diset dan lama tagihan manual (harga negosiasi).
        'BatasTertinggiBulan' => (int) env('TAGIHAN_LAMA_BATAS_TERTINGGI_BULAN', 36),
        'MaksimalBulanManual' => (int) env('TAGIHAN_LAMA_MAKSIMAL_MANUAL_BULAN', 60),
        'TanggaDiskon' => [
            ['DariBulan' => 3, 'Persen' => '7'],
            ['DariBulan' => 6, 'Persen' => '12'],
            ['DariBulan' => 12, 'Persen' => '20'],
        ],
    ],

    // Bukti transfer: gambar atau PDF, disimpan privat (disk `local` = storage/app/private), tidak pernah publik.
    'DiskBukti' => 'local',
    'FolderBukti' => 'tagihan-langganan/bukti',
    'UkuranBuktiMaksimalKb' => (int) env('TAGIHAN_UKURAN_BUKTI_MAKS_KB', 5120),
    'EkstensiBukti' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    // Nilai awal rekening tujuan transfer platform. Baris dengan nomor kosong diabaikan; tanpa rekening, tagihan belum bisa dibuat.
    'RekeningTujuan' => [
        [
            'Kode' => 'UTAMA',
            'NamaBank' => env('TAGIHAN_REKENING_BANK', ''),
            'NomorRekening' => env('TAGIHAN_REKENING_NOMOR', ''),
            'AtasNama' => env('TAGIHAN_REKENING_ATAS_NAMA', ''),
        ],
    ],
];
