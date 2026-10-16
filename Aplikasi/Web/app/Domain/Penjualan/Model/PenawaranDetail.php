<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris penawaran (F-21 bagian 3). Sama dengan baris invoice: nama, satuan, harga, dan pajak di-snapshot saat draf
 * disimpan. Tidak ada kolom pendapatan karena penawaran tidak dijurnal.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPenawaran
 * @property int $Urutan
 * @property int|null $IdProduk
 * @property string $Nama
 * @property string|null $Keterangan
 * @property JenisBarisInvoice $JenisBaris
 * @property string|null $SimbolSatuan
 * @property string $Jumlah
 * @property string $Harga
 * @property string $Diskon
 * @property string $Subtotal
 * @property bool|null $HargaTermasukPajak
 * @property int|null $IdKelompokPajak
 * @property string $Pajak
 */
final class PenawaranDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PenawaranDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdProduk' => null,
        'Keterangan' => null,
        'SimbolSatuan' => null,
        'Diskon' => '0.00',
        'HargaTermasukPajak' => null,
        'IdKelompokPajak' => null,
        'Pajak' => '0.00',
    ];

    /**
     * @return BelongsTo<Penawaran, $this>
     */
    public function Penawaran(): BelongsTo
    {
        return $this->belongsTo(Penawaran::class, 'IdPenawaran', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'JenisBaris' => JenisBarisInvoice::class,
            'Jumlah' => 'decimal:4',
            'Harga' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'Subtotal' => 'decimal:2',
            'HargaTermasukPajak' => 'boolean',
            'Pajak' => 'decimal:2',
        ];
    }
}
