<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Hasil satu alat bantu dukungan beserta keadaan sebelum dan sesudahnya (PGL-16 bagian 2, BR-P09.3). Append-only.
 * `Parameter`, `Sebelum`, dan `Sesudah` hanya berisi angka, pengenal, dan kode (tanpa data pribadi pelanggan).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdAksesDukungan
 * @property int $IdPenggunaPengelola
 * @property string $NamaPetugas
 * @property AlatDukungan $Alat
 * @property StatusHasilAlatDukungan $Status
 * @property string $Ringkasan
 * @property array<string, mixed>|null $Parameter
 * @property array<string, mixed>|null $Sebelum
 * @property array<string, mixed>|null $Sesudah
 * @property Carbon $DibuatPada
 */
final class HasilAlatDukungan extends ModelDasar
{
    use MilikTenant;

    public const UPDATED_AT = null;

    protected $table = 'HasilAlatDukungan';

    /** @var array<string, mixed> */
    protected $attributes = ['Parameter' => null, 'Sebelum' => null, 'Sesudah' => null];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('HasilAlatDukungan bersifat append-only dan tidak boleh diubah.');
        });
        self::deleting(static function (): never {
            throw new LogicException('HasilAlatDukungan bersifat append-only dan tidak boleh dihapus.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Alat' => AlatDukungan::class,
            'Status' => StatusHasilAlatDukungan::class,
            'Parameter' => 'array',
            'Sebelum' => 'array',
            'Sesudah' => 'array',
            'DibuatPada' => 'datetime',
        ];
    }
}
