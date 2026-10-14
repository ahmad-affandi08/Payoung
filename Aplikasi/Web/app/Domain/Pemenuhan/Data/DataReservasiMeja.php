<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Data;

use App\Domain\Pemenuhan\Enum\SumberReservasi;

/**
 * Masukan reservasi meja (FnB, D-145). `jam` = HH:MM zona waktu outlet. Meja dan area boleh kosong (dipilih belakangan,
 * meja wajib sebelum "Sudah datang"); keduanya diberikan sebagai Uuid.
 */
final readonly class DataReservasiMeja
{
    public function __construct(
        public int $idOutlet,
        public ?string $uuidMeja,
        public ?string $uuidArea,
        public string $namaPemesan,
        public string $noHp,
        public int $jumlahTamu,
        public string $tanggal,
        public string $jam,
        public int $durasiMenit,
        public ?string $catatan,
        public SumberReservasi $sumber,
        public ?int $idPengguna = null,
    ) {}
}
