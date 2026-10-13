<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusBspTemplat;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Satu versi templat komunikasi (PGL-07, P-06). Isi versi terbit tidak pernah diubah atau dihapus (BR-P06.1); hanya
 * catatan status BSP (`StatusBsp`, `CatatanBsp`) yang boleh diperbarui pada versi terbit karena itu metadata persetujuan
 * penyedia, bukan isi pesan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $KodePeristiwa
 * @property KanalKomunikasi $Kanal
 * @property BahasaKomunikasi $Bahasa
 * @property int $Versi
 * @property string|null $Subjek
 * @property string|null $Judul
 * @property string $Isi
 * @property StatusTemplateKomunikasi $Status
 * @property StatusBspTemplat|null $StatusBsp
 * @property string|null $CatatanBsp
 * @property int|null $IdPenggunaPengelolaPenyusun
 * @property int|null $IdPenggunaPengelolaPenerbit
 * @property Carbon|null $DiterbitkanPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class TemplateKomunikasi extends ModelDasar
{
    protected $table = 'TemplateKomunikasi';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Draf', 'Subjek' => null, 'Judul' => null, 'StatusBsp' => null, 'CatatanBsp' => null];

    /** Kolom isi pesan: terkunci setelah terbit. */
    private const KOLOM_ISI = ['KodePeristiwa', 'Kanal', 'Bahasa', 'Versi', 'Subjek', 'Judul', 'Isi'];

    protected static function booted(): void
    {
        self::updating(static function (TemplateKomunikasi $templat): void {
            if ($templat->getOriginal('Status') === StatusTemplateKomunikasi::Terbit && array_intersect(array_keys($templat->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException('Versi templat komunikasi yang sudah terbit tidak boleh diubah (BR-P06.1).');
            }
        });

        self::deleting(static function (TemplateKomunikasi $templat): void {
            if ($templat->getOriginal('Status') === StatusTemplateKomunikasi::Terbit) {
                throw new LogicException('Versi templat komunikasi yang sudah terbit tidak boleh dihapus (BR-P06.1).');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Kanal' => KanalKomunikasi::class,
            'Bahasa' => BahasaKomunikasi::class,
            'Status' => StatusTemplateKomunikasi::class,
            'StatusBsp' => StatusBspTemplat::class,
            'Versi' => 'integer',
            'DiterbitkanPada' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'Uuid';
    }

    public function AmbilPeristiwa(): ?PeristiwaKomunikasi
    {
        return PeristiwaKomunikasi::tryFrom($this->KodePeristiwa);
    }
}
