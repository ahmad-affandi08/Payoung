<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Kehadiran satu petugas dukungan untuk obrolan langsung (PLT-12, D-163). Tabel platform: tanpa `IdTenant` dan tanpa isi
 * percakapan. `Siap` = petugas menekan tombol siap di inbox obrolan; `TerakhirAktifPada` = detak terakhir inbox yang
 * terbuka. Online = siap dan detaknya masih baru (`KehadiranPetugasObrolan`).
 *
 * @property int $Id
 * @property int $IdPenggunaPengelola
 * @property bool $Siap
 * @property Carbon|null $TerakhirAktifPada
 */
final class PetugasObrolan extends ModelDasar
{
    protected $table = 'PetugasObrolan';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'Siap' => false,
        'TerakhirAktifPada' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Siap' => 'boolean',
            'TerakhirAktifPada' => 'datetime',
        ];
    }
}
