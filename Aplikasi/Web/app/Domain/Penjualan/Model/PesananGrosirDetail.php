<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris sales order grosir. Nama produk, SKU, simbol satuan, konversi, dan harga di-**snapshot** saat baris dibuat,
 * sehingga dokumen tetap terbaca apa adanya walau produk, satuan, atau daftar harganya berubah kemudian.
 *
 * `JumlahTerkirim` diisi bertahap oleh surat jalan tercatat; SO `Selesai` saat setiap baris terkirim penuh.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPesananGrosir
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property int|null $IdProdukSatuan
 * @property string $SimbolSatuan
 * @property string $Konversi
 * @property string $Jumlah
 * @property string $JumlahTerkirim
 * @property string $Harga
 * @property string $Diskon
 * @property string $Subtotal
 * @property bool|null $HargaTermasukPajak
 * @property int|null $IdKelompokPajak
 * @property-read Produk $Produk
 * @property-read PesananGrosir $PesananGrosir
 */
final class PesananGrosirDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PesananGrosirDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'Sku' => null,
        'IdProdukSatuan' => null,
        'JumlahTerkirim' => '0.0000',
        'Diskon' => '0.00',
        'HargaTermasukPajak' => null,
        'IdKelompokPajak' => null,
    ];

    public function AmbilJumlah(): Kuantitas
    {
        return Kuantitas::Dari($this->Jumlah);
    }

    public function AmbilJumlahTerkirim(): Kuantitas
    {
        return Kuantitas::Dari($this->JumlahTerkirim);
    }

    /** Sisa yang belum diserahkan; nol berarti baris ini sudah terkirim penuh. */
    public function AmbilSisaKirim(): Kuantitas
    {
        return $this->AmbilJumlah()->Kurangi($this->AmbilJumlahTerkirim());
    }

    public function AmbilHarga(): Uang
    {
        return Uang::Dari($this->Harga);
    }

    public function AmbilSubtotal(): Uang
    {
        return Uang::Dari($this->Subtotal);
    }

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function Produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'IdProduk', 'Id')->withTrashed();
    }

    /**
     * @return BelongsTo<PesananGrosir, $this>
     */
    public function PesananGrosir(): BelongsTo
    {
        return $this->belongsTo(PesananGrosir::class, 'IdPesananGrosir', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Urutan' => 'integer',
            'Konversi' => 'decimal:4',
            'Jumlah' => 'decimal:4',
            'JumlahTerkirim' => 'decimal:4',
            'Harga' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'Subtotal' => 'decimal:2',
            'HargaTermasukPajak' => 'boolean',
        ];
    }
}
