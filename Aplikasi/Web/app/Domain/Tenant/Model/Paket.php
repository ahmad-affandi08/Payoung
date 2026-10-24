<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Tenant\Enum\StatusPaket;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Paket langganan (P-04, PRD §15.3, §21). Harga paket tidak disimpan di sini, tetapi berversi di `HargaPaket`
 * (BR-P04.1, BR-P04.5). Batas bernilai null berarti tak terbatas.
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Kode
 * @property string $Nama
 * @property string|null $Keterangan
 * @property StatusPaket $Status
 * @property bool $HargaNegosiasi
 * @property int $MasaTrialHari
 * @property int|null $BatasOutlet
 * @property int|null $BatasPerangkatPerOutlet
 * @property int|null $BatasPengguna
 * @property int|null $BatasSku
 * @property int|null $KuotaPesanWaBulanan
 * @property int|null $BatasPenyimpananMb
 * @property int|null $KuotaTransaksiBulanan
 * @property int|null $KuotaTransaksiHarian
 * @property int $Urutan
 * @property Carbon|null $DiarsipkanPada
 */
final class Paket extends ModelDasar
{
    /** Kolom batas paket; nilai null = tak terbatas. */
    public const KOLOM_BATAS = [
        'BatasOutlet',
        'BatasPerangkatPerOutlet',
        'BatasPengguna',
        'BatasSku',
        'KuotaPesanWaBulanan',
        'BatasPenyimpananMb',
        'KuotaTransaksiBulanan',
        'KuotaTransaksiHarian',
    ];

    protected $table = 'Paket';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Keterangan' => null,
        'Status' => 'Draf',
        'HargaNegosiasi' => false,
        'MasaTrialHari' => 0,
        'BatasOutlet' => null,
        'BatasPerangkatPerOutlet' => null,
        'BatasPengguna' => null,
        'BatasSku' => null,
        'KuotaPesanWaBulanan' => null,
        'BatasPenyimpananMb' => null,
        'KuotaTransaksiBulanan' => null,
        'KuotaTransaksiHarian' => null,
        'Urutan' => 0,
        'DiarsipkanPada' => null,
    ];

    /**
     * @return HasMany<PaketFitur, $this>
     */
    public function Fitur(): HasMany
    {
        return $this->hasMany(PaketFitur::class, 'IdPaket', 'Id');
    }

    /**
     * @return HasMany<HargaPaket, $this>
     */
    public function Harga(): HasMany
    {
        return $this->hasMany(HargaPaket::class, 'IdPaket', 'Id');
    }

    /**
     * @return list<string>
     */
    public function AmbilKunciFitur(): array
    {
        return array_values($this->loadMissing('Fitur')->Fitur->map(fn (PaketFitur $fitur) => $fitur->KunciFitur)->sort()->values()->all());
    }

    /**
     * @return array<string, int|null>
     */
    public function AmbilBatas(): array
    {
        $batas = [];

        foreach (self::KOLOM_BATAS as $kolom) {
            $nilai = $this->getAttribute($kolom);
            $batas[$kolom] = is_int($nilai) ? $nilai : null;
        }

        return $batas;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPaket::class,
            'HargaNegosiasi' => 'boolean',
            'MasaTrialHari' => 'integer',
            'BatasOutlet' => 'integer',
            'BatasPerangkatPerOutlet' => 'integer',
            'BatasPengguna' => 'integer',
            'BatasSku' => 'integer',
            'KuotaPesanWaBulanan' => 'integer',
            'BatasPenyimpananMb' => 'integer',
            'KuotaTransaksiBulanan' => 'integer',
            'KuotaTransaksiHarian' => 'integer',
            'Urutan' => 'integer',
            'DiarsipkanPada' => 'datetime',
        ];
    }
}
