<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/**
 * Tur produk interaktif yang ada di back-office (PLT-12). Isi langkahnya ada di frontend (`Pustaka/DefinisiTur`);
 * server hanya mencatat tur mana yang sudah dilihat pengguna. Versi ikut di kode: tur yang isinya berubah materiil
 * ditambahkan sebagai case baru (`dashboard.v2`) supaya ditawarkan ulang.
 */
enum KodeTurProduk: string
{
    case Dashboard = 'dashboard.v1';
}
