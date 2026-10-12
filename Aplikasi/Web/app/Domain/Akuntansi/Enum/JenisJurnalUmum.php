<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Enum;

/**
 * Jenis jurnal umum manual (FIN-05, D-131). Hanya pelabelan untuk filter dan pemeriksaan akuntan; ketiganya memakai
 * aturan posting yang sama (seimbang, periode terbuka, akun aktif, bukan akun kendali).
 */
enum JenisJurnalUmum: string
{
    case Umum = 'Umum';
    case Penyesuaian = 'Penyesuaian';
    case Koreksi = 'Koreksi';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Umum => 'Jurnal umum',
            self::Penyesuaian => 'Jurnal penyesuaian',
            self::Koreksi => 'Jurnal koreksi',
        };
    }

    public function AmbilPenjelasan(): string
    {
        return match ($this) {
            self::Umum => 'Pencatatan yang tidak punya dokumen sendiri di Payoung, misalnya saldo awal akun neraca atau pembagian beban.',
            self::Penyesuaian => 'Penyesuaian akhir periode, misalnya beban yang masih harus dibayar, biaya dibayar di muka, atau pengakuan pendapatan.',
            self::Koreksi => 'Memperbaiki catatan yang salah akun. Untuk membatalkan jurnal umum yang sudah dibuat, pakai dokumen pembalik.',
        };
    }
}
