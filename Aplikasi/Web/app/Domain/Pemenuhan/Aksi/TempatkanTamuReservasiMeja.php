<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pemenuhan\Data\DataReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;

/**
 * FnB reservasi meja (D-145): kasir menentukan meja bagi reservasi yang belum bermeja (tamu datang, meja baru dipilih).
 * Memakai `SimpanReservasiMeja` supaya kapasitas dan bentrok diperiksa sama seperti pemesanan biasa; isi lain tetap.
 */
final class TempatkanTamuReservasiMeja
{
    public function __construct(
        private readonly SimpanReservasiMeja $simpan,
        private readonly ZonaWaktuOutlet $zona,
    ) {}

    public function Jalankan(int $idTenant, ReservasiMeja $reservasi, string $uuidMeja, int $idPengguna): ReservasiMeja
    {
        $lokal = $reservasi->MulaiPada->copy()->setTimezone($this->zona->Ambil($reservasi->IdOutlet));

        return $this->simpan->Jalankan($idTenant, new DataReservasiMeja(
            idOutlet: $reservasi->IdOutlet,
            uuidMeja: $uuidMeja,
            uuidArea: null,
            namaPemesan: $reservasi->NamaPemesan,
            noHp: $reservasi->NoHp,
            jumlahTamu: $reservasi->JumlahTamu,
            tanggal: $lokal->format('Y-m-d'),
            jam: $lokal->format('H:i'),
            durasiMenit: $reservasi->DurasiMenit,
            catatan: $reservasi->Catatan,
            sumber: $reservasi->Sumber,
            idPengguna: $idPengguna,
        ), $reservasi, abaikanWaktuLewat: true);
    }
}
