<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Templat invoice berulang (F-21): bukan dokumen akuntansi, jadi boleh diubah dan dihentikan. Yang terbit darinya adalah
 * `Invoice` biasa. Jadwal: siklus ke-N jatuh pada `Frekuensi` × `Interval` × N dari `TanggalMulai`; `SiklusBerikutnya`
 * dan `BerikutnyaPada` menandai siklus yang belum diterbitkan. Status hanya lewat `UbahStatus()`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nama
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property FrekuensiInvoiceBerulang $Frekuensi
 * @property int $Interval
 * @property Carbon $TanggalMulai
 * @property Carbon|null $TanggalBerakhir
 * @property int|null $BatasKali
 * @property int $SiklusBerikutnya
 * @property int $JumlahTerbit
 * @property Carbon|null $BerikutnyaPada
 * @property StatusInvoiceBerulang $Status
 * @property ModeInvoiceBerulang $Mode
 * @property int|null $TerminHari
 * @property bool $HargaTermasukPajak
 * @property string|null $Perihal
 * @property string|null $NomorReferensi
 * @property string|null $KodePph
 * @property string|null $TarifPph
 * @property string|null $Catatan
 * @property string|null $Syarat
 * @property string|null $InfoPembayaran
 * @property Carbon|null $TerakhirTerbitPada
 * @property string|null $GalatTerakhir
 * @property Carbon|null $GalatPada
 * @property int $DibuatOleh
 * @property int|null $DiubahOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class InvoiceBerulang extends ModelDasar
{
    use MilikTenant;

    protected $table = 'InvoiceBerulang';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Interval' => 1,
        'TanggalBerakhir' => null,
        'BatasKali' => null,
        'SiklusBerikutnya' => 0,
        'JumlahTerbit' => 0,
        'BerikutnyaPada' => null,
        'TerminHari' => null,
        'HargaTermasukPajak' => false,
        'Perihal' => null,
        'NomorReferensi' => null,
        'KodePph' => null,
        'TarifPph' => null,
        'Catatan' => null,
        'Syarat' => null,
        'InfoPembayaran' => null,
        'TerakhirTerbitPada' => null,
        'GalatTerakhir' => null,
        'GalatPada' => null,
        'DiubahOleh' => null,
    ];

    public function UbahStatus(StatusInvoiceBerulang $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status templat invoice berulang {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<InvoiceBerulangDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(InvoiceBerulangDetail::class, 'IdInvoiceBerulang', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Frekuensi' => FrekuensiInvoiceBerulang::class,
            'Mode' => ModeInvoiceBerulang::class,
            'Status' => StatusInvoiceBerulang::class,
            'Interval' => 'integer',
            'TanggalMulai' => 'date',
            'TanggalBerakhir' => 'date',
            'BatasKali' => 'integer',
            'SiklusBerikutnya' => 'integer',
            'JumlahTerbit' => 'integer',
            'BerikutnyaPada' => 'date',
            'TerminHari' => 'integer',
            'HargaTermasukPajak' => 'boolean',
            'TarifPph' => 'decimal:6',
            'TerakhirTerbitPada' => 'datetime',
            'GalatPada' => 'datetime',
        ];
    }
}
