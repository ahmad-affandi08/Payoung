<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Data;

/**
 * Kiriman absen dari halaman absensi web (F-18 bagian 4, D-37). [uuid] dibuat browser (idempoten): untuk masuk = Uuid
 * absensi baru, untuk keluar = Uuid absensi masuk yang ditutup. Lintang/bujur derajat desimal sebagai teks,
 * [akurasiMeter] dari Geolocation API, [sidikWajah] deskriptor × 10.000, [swafoto] JPEG base64 (hanya dikirim bila aturan `SimpanFotoBukti` menyala).
 */
final readonly class DataAbsensiWeb
{
    /**
     * @param  list<mixed>  $sidikWajah
     */
    public function __construct(
        public string $uuid,
        public string $lintang,
        public string $bujur,
        public int $akurasiMeter,
        public array $sidikWajah,
        public ?string $swafoto = null,
    ) {}
}
