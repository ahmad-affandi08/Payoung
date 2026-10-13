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
 * Baris surat jalan grosir: jumlah yang benar-benar diserahkan, dengan harga & diskon disalin dari baris SO (diskon
 * dialokasikan sebanding jumlah kirim) serta HPP hasil mutasi stok.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdSuratJalan
 * @property int $Urutan
 * @property int $IdPesananGrosirDetail
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property int|null $IdProdukSatuan
 * @property string $SimbolSatuan
 * @property string $Konversi
 * @property string $Jumlah
 * @property string $JumlahDiretur
 * @property string $JumlahDasar
 * @property string $Harga
 * @property string $Diskon
 * @property string $Subtotal
 * @property bool|null $HargaTermasukPajak
 * @property int|null $IdKelompokPajak
 * @property string $HppSatuan
 * @property string $TotalHpp
 * @property list<array<string, string|null>>|null $RincianPelacakan batch (NomorBatch, TanggalKedaluwarsa, JumlahDasar, HppSatuan) atau nomor seri (Nomor, HppSatuan); null = produk tanpa pelacakan
 * @property-read Produk $Produk
 * @property-read SuratJalan $SuratJalan
 * @property-read PesananGrosirDetail $PesananGrosirDetail
 */
final class SuratJalanDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'SuratJalanDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'Sku' => null,
        'IdProdukSatuan' => null,
        'JumlahDiretur' => '0.0000',
        'Diskon' => '0.00',
        'HargaTermasukPajak' => null,
        'IdKelompokPajak' => null,
        'HppSatuan' => '0.000000',
        'TotalHpp' => '0.00',
        'RincianPelacakan' => null,
    ];

    public function AmbilJumlah(): Kuantitas
    {
        return Kuantitas::Dari($this->Jumlah);
    }

    public function AmbilJumlahDiretur(): Kuantitas
    {
        return Kuantitas::Dari($this->JumlahDiretur);
    }

    /** Sisa yang masih bisa diretur; nol berarti baris ini sudah diretur penuh (BR-12.7). */
    public function AmbilSisaRetur(): Kuantitas
    {
        return $this->AmbilJumlah()->Kurangi($this->AmbilJumlahDiretur());
    }

    public function AmbilHarga(): Uang
    {
        return Uang::Dari($this->Harga);
    }

    public function AmbilDiskon(): Uang
    {
        return Uang::Dari($this->Diskon);
    }

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function Produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'IdProduk', 'Id')->withTrashed();
    }

    /**
     * @return BelongsTo<SuratJalan, $this>
     */
    public function SuratJalan(): BelongsTo
    {
        return $this->belongsTo(SuratJalan::class, 'IdSuratJalan', 'Id');
    }

    /**
     * @return BelongsTo<PesananGrosirDetail, $this>
     */
    public function PesananGrosirDetail(): BelongsTo
    {
        return $this->belongsTo(PesananGrosirDetail::class, 'IdPesananGrosirDetail', 'Id');
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
            'JumlahDiretur' => 'decimal:4',
            'JumlahDasar' => 'decimal:4',
            'Harga' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'Subtotal' => 'decimal:2',
            'HargaTermasukPajak' => 'boolean',
            'HppSatuan' => 'decimal:6',
            'TotalHpp' => 'decimal:2',
            'RincianPelacakan' => 'array',
        ];
    }
}
