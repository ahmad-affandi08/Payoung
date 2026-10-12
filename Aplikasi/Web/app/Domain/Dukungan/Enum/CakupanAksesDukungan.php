<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Enum;

/** Cakupan izin akses dukungan (BR-P09.2): BacaSaja menolak semua permintaan yang mengubah data. */
enum CakupanAksesDukungan: string
{
    case BacaSaja = 'BacaSaja';
    case BacaUbah = 'BacaUbah';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::BacaSaja => 'Baca saja',
            self::BacaUbah => 'Baca & ubah',
        };
    }

    public function AmbilPenjelasan(): string
    {
        return match ($this) {
            self::BacaSaja => 'Petugas hanya melihat. Semua tombol simpan, ubah, dan hapus ditolak.',
            self::BacaUbah => 'Petugas boleh melihat dan mengubah data usaha Anda, kecuali yang menyangkut kata sandi, PIN, kredensial integrasi, pengguna, dan langganan.',
        };
    }
}
