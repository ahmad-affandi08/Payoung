<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Penjualan\Enum\KondisiBarangRetur;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris retur grosir: berapa yang dikembalikan dari satu baris surat jalan, dalam kondisi apa, dan ke lokasi stok mana.
 *
 * Harga, diskon, dan HPP disalin dari baris surat jalan — yang dibalik adalah penyerahan yang sudah terjadi, jadi
 * nilainya harus nilai saat itu, bukan harga atau HPP hari ini.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdReturGrosir
 * @property int $Urutan
 * @property int $IdSuratJalanDetail
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $SimbolSatuan
 * @property string $Konversi
 * @property string $Jumlah
 * @property string $JumlahDasar
 * @property KondisiBarangRetur $Kondisi
 * @property int $IdGudang
 * @property string $Harga
 * @property string $Diskon
 * @property string $Subtotal
 * @property bool|null $HargaTermasukPajak
 * @property int|null $IdKelompokPajak
 * @property string $HppSatuan
 * @property string $TotalHpp
 * @property list<array<string, string|null>>|null $RincianPelacakan batch (NomorBatch, TanggalKedaluwarsa, JumlahDasar, HppSatuan) atau nomor seri (Nomor, HppSatuan); null = produk tanpa pelacakan
 * @property-read Produk $Produk
 * @property-read ReturGrosir $ReturGrosir
 * @property-read SuratJalanDetail $SuratJalanDetail
 */
final class ReturGrosirDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ReturGrosirDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'Sku' => null,
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

    public function AmbilJumlahDasar(): Kuantitas
    {
        return Kuantitas::Dari($this->JumlahDasar);
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
     * @return BelongsTo<ReturGrosir, $this>
     */
    public function ReturGrosir(): BelongsTo
    {
        return $this->belongsTo(ReturGrosir::class, 'IdReturGrosir', 'Id');
    }

    /**
     * @return BelongsTo<SuratJalanDetail, $this>
     */
    public function SuratJalanDetail(): BelongsTo
    {
        return $this->belongsTo(SuratJalanDetail::class, 'IdSuratJalanDetail', 'Id');
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
            'JumlahDasar' => 'decimal:4',
            'Kondisi' => KondisiBarangRetur::class,
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
