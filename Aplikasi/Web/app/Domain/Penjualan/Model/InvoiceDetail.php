<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris invoice (F-21). Nama, satuan, harga, dan angka pajak di-**snapshot** saat draf disimpan, jadi invoice tetap
 * terbaca apa adanya walau produk atau tarifnya berubah kemudian.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdInvoice
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
 * @property string $Pendapatan
 * @property-read Invoice $Invoice
 */
final class InvoiceDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'InvoiceDetail';

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
        'Pendapatan' => '0.00',
    ];

    public function AmbilJumlah(): Kuantitas
    {
        return Kuantitas::Dari($this->Jumlah);
    }

    public function AmbilSubtotal(): Uang
    {
        return Uang::Dari($this->Subtotal);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function Invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'IdInvoice', 'Id');
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
            'Pendapatan' => 'decimal:2',
        ];
    }
}
