<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Jejak satu permintaan dalam sesi akses dukungan (PGL-16). Append-only. Hanya jalur tanpa query string yang disimpan.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdSesiAksesDukungan
 * @property string $Metode
 * @property string|null $Rute
 * @property string $Jalur
 * @property int $StatusHttp
 * @property bool $Ditolak
 * @property string|null $Alasan
 * @property Carbon $DibuatPada
 */
final class AktivitasAksesDukungan extends ModelDasar
{
    use MilikTenant;

    public const UPDATED_AT = null;

    protected $table = 'AktivitasAksesDukungan';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Rute' => null, 'Ditolak' => false, 'Alasan' => null];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('AktivitasAksesDukungan bersifat append-only dan tidak boleh diubah.');
        });
        self::deleting(static function (): never {
            throw new LogicException('AktivitasAksesDukungan bersifat append-only dan tidak boleh dihapus.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Ditolak' => 'boolean', 'StatusHttp' => 'integer', 'DibuatPada' => 'datetime'];
    }
}
