<?php

declare(strict_types=1);

/*
 * Persediaan: stok awal & buku stok (F-05a, DesainF05a B.6). Kunci PascalCase (D-05). Perubahan lewat Tim 0.
 */
return [
    // Dokumen stok awal: batas baris per dokumen; di atas `BatasPostingLangsung` baris (seri dihitung satu per
    // nomor) posting dijalankan di antrean. Batch wajib berkedaluwarsa (H-3).
    'StokAwal' => [
        'MaksimalBaris' => 2000,
        'BatasPostingLangsung' => 300,
        'MaksimalNomorSeriPerBaris' => 1000,
        'WajibKedaluwarsaBatch' => true,
    ],

    // Impor stok awal (membuat draf, tidak memposting): batas berkas & pemrosesan antrean per potongan.
    'Impor' => [
        'UkuranMaksimalKb' => 10240,
        'MaksimalBaris' => 20000,
        'BatasBarisSinkron' => 300,
        'UkuranPotongan' => 200,
        'MaksimalDetikPerTugas' => 40,
        'HariSimpan' => 30,
    ],

    // F-05b dokumen transfer, opname, penyesuaian: batas baris per dokumen (seri = satu baris per nomor) dan baris
    // hitung per sekali simpan lembar opname.
    'Dokumen' => [
        'MaksimalBaris' => 500,
        'MaksimalBarisOpname' => 5000,
    ],

    // D-187: kiriman stok yang belum diterima: kabar ke manajer outlet tujuan setelah HariPeringatan hari, ke pengirim dan
    // manajer outlet asal setelah HariLanjut hari.
    'TransferMacet' => [
        'HariPeringatan' => 3,
        'HariLanjut' => 7,
    ],

    // Jumlah percobaan `DB::transaction` saat deadlock (Aksi terluar mutasi stok).
    'PercobaanTransaksi' => 3,
];
