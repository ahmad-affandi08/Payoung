<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use Illuminate\Support\Carbon;

/**
 * Nomor pengirim WhatsApp per outlet (D-299). `IdOutlet` kosong = nomor tingkat usaha. `TokenPerangkat` adalah sesi
 * perangkat di penyedia, dipegang platform: terenkripsi dan tidak pernah dikirim ke klien.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdOutlet
 * @property string $Label
 * @property FungsiNomorWhatsapp $Fungsi
 * @property string $NomorTelepon
 * @property StatusNomorWhatsapp $Status
 * @property bool $Aktif
 * @property string $Penyedia
 * @property string|null $TokenPerangkat
 * @property string|null $PesanStatus
 * @property Carbon|null $TerhubungPada
 * @property Carbon|null $DiperiksaPada
 * @property Carbon|null $PeringatanTerputusPada
 */
final class NomorPengirimWhatsapp extends ModelDasar
{
    use MilikTenant;

    /** Batas nomor per outlet (sama dengan Majoo). */
    public const MAKS_PER_OUTLET = 6;

    protected $table = 'NomorPengirimWhatsapp';

    /** @var list<string> */
    protected $hidden = ['TokenPerangkat'];

    /** @var array<string, mixed> */
    protected $attributes = ['Fungsi' => 'Umum', 'Status' => 'MenungguPindai', 'Aktif' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Fungsi' => FungsiNomorWhatsapp::class,
            'Status' => StatusNomorWhatsapp::class,
            'Aktif' => 'boolean',
            'TokenPerangkat' => 'encrypted',
            'TerhubungPada' => 'datetime',
            'DiperiksaPada' => 'datetime',
            'PeringatanTerputusPada' => 'datetime',
        ];
    }

    /** Siap dipakai mengirim: dinyalakan, terhubung, dan punya sesi. */
    public function SiapKirim(): bool
    {
        return $this->Aktif && $this->Status === StatusNomorWhatsapp::Terhubung && $this->TokenPerangkat !== null;
    }
}
