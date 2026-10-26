<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusOrderProduksi;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Order produksi per lokasi stok (F-05e). Tanpa hapus; isi hanya berubah selama Draf; Diposting hanya boleh menjadi
 * Dibatalkan (pembalik). `NilaiHasil` = `TotalNilaiBahan` + `BiayaOverhead`; `HppSatuanHasil` = NilaiHasil ÷ JumlahHasil.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property Carbon $Tanggal
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $JumlahHasil
 * @property int|null $IdResep
 * @property int|null $VersiResep
 * @property string $BiayaOverhead
 * @property string|null $NomorBatch
 * @property Carbon|null $TanggalKedaluwarsa
 * @property string|null $Keterangan
 * @property StatusOrderProduksi $Status
 * @property string $TotalNilaiBahan
 * @property string $NilaiHasil
 * @property string|null $HppSatuanHasil
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property string|null $AlasanBatal
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DipostingOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DipostingPada
 * @property Carbon|null $DibatalkanPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class OrderProduksi extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'OrderProduksi';

    /** @var list<string> */
    private const KOLOM_ISI = ['IdGudang', 'IdOutlet', 'Tanggal', 'IdProduk', 'JumlahHasil', 'BiayaOverhead', 'NomorBatch', 'TanggalKedaluwarsa', 'Keterangan'];

    protected $table = 'OrderProduksi';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Nomor' => null,
        'Status' => 'Draf',
        'Sku' => null,
        'IdResep' => null,
        'VersiResep' => null,
        'BiayaOverhead' => '0.00',
        'NomorBatch' => null,
        'TanggalKedaluwarsa' => null,
        'Keterangan' => null,
        'TotalNilaiBahan' => '0.00',
        'NilaiHasil' => '0.00',
        'HppSatuanHasil' => null,
        'IdJurnal' => null,
        'IdJurnalPembatalan' => null,
        'AlasanBatal' => null,
    ];

    /**
     * @return HasMany<OrderProduksiBahan, $this>
     */
    public function Bahan(): HasMany
    {
        return $this->hasMany(OrderProduksiBahan::class, 'IdOrderProduksi', 'Id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $dokumen): void {
            $asal = $dokumen->getOriginal('Status');
            $asal = $asal instanceof StatusOrderProduksi ? $asal : StatusOrderProduksi::tryFrom((string) $asal);

            if ($asal === StatusOrderProduksi::Draf) {
                return;
            }

            if ($asal === StatusOrderProduksi::Dibatalkan || array_intersect(array_keys($dokumen->getDirty()), self::KOLOM_ISI) !== []) {
                throw new LogicException("Order produksi berstatus {$asal?->value} tidak bisa diubah isinya.");
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Order produksi tidak pernah dihapus. Draf dibatalkan.');
        });
    }

    public function UbahStatus(StatusOrderProduksi $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status order produksi {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'TanggalKedaluwarsa' => 'date',
            'Status' => StatusOrderProduksi::class,
            'JumlahHasil' => 'string',
            'BiayaOverhead' => 'string',
            'TotalNilaiBahan' => 'string',
            'NilaiHasil' => 'string',
            'HppSatuanHasil' => 'string',
            'VersiResep' => 'integer',
            'DipostingPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
