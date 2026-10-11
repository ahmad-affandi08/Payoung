<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\CakupanOutletPeran;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peran tenant (PRD §15.3, §19.1). Peran bawaan (`Bawaan`, `Kode` terisi) diselaraskan sistem; peran kustom
 * (`Kode` kosong) dibuat Owner/Admin.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Kode
 * @property string $Nama
 * @property string|null $Keterangan
 * @property bool $Bawaan
 * @property-read Collection<int, PeranIzin> $Izin
 */
final class Peran extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Peran';

    /** @var array<string, mixed> */
    protected $attributes = ['Kode' => null, 'Keterangan' => null, 'Bawaan' => false];

    /**
     * @return HasMany<PeranIzin, $this>
     */
    public function Izin(): HasMany
    {
        return $this->hasMany(PeranIzin::class, 'IdPeran', 'Id');
    }

    /**
     * @return list<string>
     */
    public function AmbilKunciIzin(): array
    {
        return array_values($this->loadMissing('Izin')->Izin->map(fn (PeranIzin $izin) => $izin->KunciIzin)->sort()->values()->all());
    }

    public function CekPemilik(): bool
    {
        return $this->Kode === PeranTenantBawaan::Pemilik->value;
    }

    /** Cakupan outlet peran ini (D-104): peran bawaan menurut enum-nya, peran kustom bebas. */
    public function AmbilCakupanOutlet(): CakupanOutletPeran
    {
        if ($this->Kode === null) {
            return CakupanOutletPeran::Bebas;
        }

        return PeranTenantBawaan::tryFrom($this->Kode)?->AmbilCakupanOutlet() ?? CakupanOutletPeran::Bebas;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Bawaan' => 'boolean'];
    }
}
