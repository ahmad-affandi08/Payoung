<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Enum;

/**
 * Cakupan outlet yang dibawa sebuah peran (F-02, D-104). Cakupan mengikuti peran, bukan saklar terpisah:
 * - `Semua`: peran tingkat usaha (Pemilik, Admin, Akuntan); selalu semua outlet, tidak ada pilihan outlet.
 * - `Terpilih`: peran operasional (Manajer Outlet, Staf Gudang, Kasir, ...); wajib memilih minimal satu outlet.
 * - `Bebas`: peran kustom buatan tenant; pembuat peran memilih sendiri semua outlet atau outlet tertentu.
 */
enum CakupanOutletPeran: string
{
    case Semua = 'Semua';
    case Terpilih = 'Terpilih';
    case Bebas = 'Bebas';
}
