<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Audit\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Log audit tenant, **append-only** (PRD §15.3, aturan `LogAudit` §13.2). Baris tidak pernah diubah atau dihapus.
 * Tulis hanya lewat `PencatatAudit`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int|null $IdPengguna
 * @property int|null $IdPerangkat
 * @property int|null $IdPenggunaPengelola petugas pengelola pelaku bila berasal dari sesi akses dukungan (PGL-16)
 * @property string $Peristiwa
 * @property string|null $JenisObjek
 * @property int|null $IdObjek
 * @property array<string, mixed>|null $NilaiLama
 * @property array<string, mixed>|null $NilaiBaru
 * @property string|null $Ip
 * @property string|null $AgenPengguna
 * @property Carbon $DibuatPada
 */
final class LogAudit extends ModelDasar
{
    use MilikTenant;

    public const UPDATED_AT = null;

    protected $table = 'LogAudit';

    protected bool $pakaiUuid = false;

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('LogAudit bersifat append-only dan tidak boleh diubah.');
        });

        self::deleting(static function (): never {
            throw new LogicException('LogAudit bersifat append-only dan tidak boleh dihapus.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'NilaiLama' => 'array',
            'NilaiBaru' => 'array',
            'DibuatPada' => 'datetime',
        ];
    }
}
