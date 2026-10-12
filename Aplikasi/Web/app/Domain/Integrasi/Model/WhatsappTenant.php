<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use Illuminate\Support\Carbon;

/**
 * WhatsApp milik tenant (D-108): nomor dan akun penyedia sendiri, satu per tenant. `Kredensial` terenkripsi dan
 * disembunyikan dari serialisasi; tampilan hanya memakai `PetunjukKredensial` (BR-P05.1). Pesan lewat nomor ini tidak
 * memakai kuota paket.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property PenyediaIntegrasi $Penyedia
 * @property array<string, string|int> $Pengaturan
 * @property array<string, string> $Kredensial
 * @property array<string, string> $PetunjukKredensial
 * @property StatusUjiGerbang $StatusUji
 * @property string|null $PesanUji
 * @property Carbon|null $DiujiPada
 * @property bool $Aktif
 */
final class WhatsappTenant extends ModelDasar
{
    use MilikTenant;

    protected $table = 'WhatsappTenant';

    /** @var list<string> */
    protected $hidden = ['Kredensial'];

    /** @var array<string, mixed> */
    protected $attributes = ['StatusUji' => 'BelumDiuji', 'Aktif' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Penyedia' => PenyediaIntegrasi::class,
            'Pengaturan' => 'array',
            'Kredensial' => 'encrypted:array',
            'PetunjukKredensial' => 'array',
            'StatusUji' => StatusUjiGerbang::class,
            'DiujiPada' => 'datetime',
            'Aktif' => 'boolean',
        ];
    }
}
