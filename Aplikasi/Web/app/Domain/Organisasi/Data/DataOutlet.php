<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

/**
 * Isian outlet (F-02 langkah 1). Zona waktu ditentukan dari kota bila kota diisi; `zonaWaktu` (WIB/WITA/WIT)
 * hanya dipakai bila kota kosong. Profil pajak disimpan apa adanya, dihitung di F-03.
 * `kanvas` (Modul Salesman bagian 3): null = tanda kanvas & nomor kendaraan tidak diubah (pemanggil yang tidak
 * mengenal kanvas, misal panduan awal); `nomorKendaraan` hanya disimpan bila outlet kanvas.
 * `telepon` (D-177): null = tidak diubah (panduan awal & pemanggil lama); teks kosong = dikosongkan.
 */
final readonly class DataOutlet
{
    public function __construct(
        public string $nama,
        public string $kode,
        public string $uuidMerek,
        public ?string $alamat,
        public ?string $kodeKota,
        public string $zonaWaktu,
        public string $jamTutupBuku,
        public bool $pkp,
        public ?string $nitku,
        public bool $pungutPbjt,
        public ?bool $kanvas = null,
        public ?string $nomorKendaraan = null,
        public ?string $telepon = null,
    ) {}
}
