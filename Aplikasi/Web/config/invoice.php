<?php

declare(strict_types=1);

/*
 * Invoice & penagihan B2B (F-21). Bukti transfer yang dilampirkan pelanggan di halaman publik invoice: disk privat, batas
 * ukuran unggahan, sisi terpanjang foto setelah diolah ulang (EXIF termasuk lokasi GPS dibuang), dan batas piksel gambar
 * sebelum didekode (pengunjung anonim tidak boleh memaksa server mendekode gambar raksasa).
 */
return [
    'DiskBukti' => 'local',
    'UkuranMaksimalBuktiKb' => 4096,
    'SisiMaksimalBuktiPx' => 1600,
    'PikselMaksimalBukti' => 40_000_000,
];
