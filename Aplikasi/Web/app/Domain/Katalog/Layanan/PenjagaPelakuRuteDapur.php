<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;

/**
 * D-310: pemeriksaan bersama untuk mengatur stasiun dapur dari POS. Pelaku wajib anggota outlet perangkat dengan izin
 * `dapur.rute.atur` (bawaan Kasir, Supervisor, Manajer Outlet, Pemilik, Admin). Stasiun tujuan wajib aktif dan bukan
 * stasiun checker (checker menerima salinan semua tiket, bukan menu tertentu).
 */
final class PenjagaPelakuRuteDapur
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly DaftarStasiunDapur $stasiun,
    ) {}

    /** @return int Id pengguna pelaku */
    public function PeriksaPelaku(int $idOutlet, string $uuidPengguna): int
    {
        $pelaku = $this->anggota->Cari($this->konteks->Wajib(), $uuidPengguna, $idOutlet)
            ?? throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet ini.', 'UuidPengguna');

        if (! $pelaku->CekIzin(IzinTenant::DapurRuteAtur->value)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', "{$pelaku->nama} tidak punya izin mengatur stasiun dapur.", 'UuidPengguna', 403);
        }

        return $pelaku->id;
    }

    /** @return int|null Id stasiun tujuan; null bila Uuid kosong (kembali ikut aturan induk) */
    public function PeriksaStasiun(?string $uuidStasiun): ?int
    {
        if ($uuidStasiun === null || $uuidStasiun === '') {
            return null;
        }

        return $this->stasiun->CariIdAktif(strtoupper($uuidStasiun))
            ?? throw new PelanggaranAturanBisnis('StasiunTidakDitemukan', 'Stasiun ini tidak ada, sudah diarsipkan, atau berupa checker.', 'UuidStasiun', 404);
    }
}
