<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;

/**
 * Baris templat invoice berulang (F-21): bahan baris `InvoiceDetail` tiap siklus. Angka turunan tidak disimpan; setiap
 * siklus dihitung ulang oleh mesin invoice dengan tarif pajak yang berlaku pada tanggal siklusnya.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdInvoiceBerulang
 * @property int $Urutan
 * @property int|null $IdProduk
 * @property string $Nama
 * @property string|null $Keterangan
 * @property JenisBarisInvoice $JenisBaris
 * @property string|null $SimbolSatuan
 * @property string $Jumlah
 * @property string $Harga
 * @property string $Diskon
 * @property bool|null $HargaTermasukPajak
 * @property int|null $IdKelompokPajak
 */
final class InvoiceBerulangDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'InvoiceBerulangDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdProduk' => null,
        'Keterangan' => null,
        'SimbolSatuan' => null,
        'Diskon' => '0.00',
        'HargaTermasukPajak' => null,
        'IdKelompokPajak' => null,
    ];

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
            'HargaTermasukPajak' => 'boolean',
        ];
    }
}
