<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Data\HasilPembersihanTemplate;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\StasiunDapur;

/**
 * Ganti jenis usaha: stasiun dapur sisa template sektor lama diarsipkan (`UbahStatusStasiunDapur`), bukan dihapus,
 * karena tiket dapur lama menunjuk ke sana dan stasiun yang diarsipkan bisa dipulihkan. Item di kategori yang
 * merujuknya otomatis dirutekan ke stasiun aktif pertama.
 */
final class BersihkanStasiunDapurTemplate
{
    public function __construct(private readonly UbahStatusStasiunDapur $ubahStatus) {}

    /**
     * @param  list<string>  $nama  nama stasiun sisa template lama yang tidak ada di template baru
     */
    public function Jalankan(array $nama, bool $terapkan): HasilPembersihanTemplate
    {
        $incaran = array_map(fn (string $satu): string => mb_strtolower(trim($satu)), $nama);

        if ($incaran === []) {
            return new HasilPembersihanTemplate;
        }

        $diarsipkan = [];

        foreach (StasiunDapur::query()->where('Status', StatusOrganisasi::Aktif->value)->orderBy('Urutan')->get() as $stasiun) {
            if (! in_array(mb_strtolower(trim($stasiun->Nama)), $incaran, true)) {
                continue;
            }

            if ($terapkan) {
                $this->ubahStatus->Jalankan($stasiun, StatusOrganisasi::Diarsipkan);
            }

            $diarsipkan[] = $stasiun->Nama;
        }

        return new HasilPembersihanTemplate($diarsipkan);
    }
}
