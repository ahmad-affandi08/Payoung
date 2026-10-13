<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Enum\GolonganObat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\LokasiProduksi;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\StatusProduk;
use App\Domain\Katalog\Layanan\PemberitahuProdukDiubah;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Produk tenant (PRD §15.3). F-01 membuat produk awal (contoh template & tambah cepat); F-03 melengkapi SKU, varian,
 * merek, gambar, arsip, dan harga per daftar harga. `MetodeHpp`/`BolehMinus`/`HargaTermasukPajak` null = ikut
 * pengaturan tenant/outlet.
 * - Varian: induk `Jenis = IndukVarian` menyimpan definisi `AtributVarian` (`[{Nama, Nilai: string[]}]`); anak
 *   menyimpan `IdInduk`, `AtributVarian` (`[{Nama, Nilai: string}]`), dan `KunciVarian`.
 * - BR-03.2: arsip (`DiarsipkanPada`, `Aktif = false`) atau soft delete (`DihapusPada`) bila belum dipakai.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Sku
 * @property string $Nama
 * @property string|null $NamaStruk
 * @property string|null $Merek
 * @property JenisProduk $Jenis
 * @property int|null $IdInduk
 * @property array<int, array<string, mixed>>|null $AtributVarian
 * @property string|null $KunciVarian
 * @property int|null $IdKategori
 * @property int $IdSatuanDasar
 * @property PelacakanProduk $Pelacakan
 * @property int|null $IdKelompokPajak
 * @property bool|null $HargaTermasukPajak
 * @property string|null $MetodeHpp
 * @property bool|null $BolehMinus
 * @property bool $Aktif
 * @property bool $TampilDiPos
 * @property bool $TampilOnline
 * @property bool $Favorit K-16: ditandai pemilik, tampil di kategori "Favorit" kasir (tingkat tenant)
 * @property bool $HargaTerbuka
 * @property int|null $DurasiMenit F-07 mode service: lama layanan jasa untuk slot reservasi
 * @property LokasiProduksi|null $LokasiProduksi BR-05.4: lokasi stok tempat bahan resep dikurangi saat dijual; null = Toko
 * @property GolonganObat|null $GolonganObat Apotek (§9.5): golongan obat; null = bukan obat
 * @property bool $ObatWajibApotek Apotek: obat keras yang boleh diserahkan apoteker tanpa resep (OWA)
 * @property bool $Prekursor Apotek: penanda prekursor farmasi (opsional)
 * @property string|null $PathGambar
 * @property Carbon|null $DiarsipkanPada
 * @property Carbon|null $DihapusPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Produk|null $Induk
 * @property-read Collection<int, Produk> $Anak
 * @property-read Kategori|null $Kategori
 * @property-read Satuan $SatuanDasar
 * @property-read Collection<int, ProdukSatuan> $Satuan
 * @property-read Collection<int, ProdukBarcode> $Barcode
 * @property-read Collection<int, ProdukHarga> $Harga
 */
final class Produk extends ModelDasar
{
    use MilikTenant;
    use SoftDeletes;

    protected $table = 'Produk';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Sku' => null,
        'NamaStruk' => null,
        'Merek' => null,
        'IdInduk' => null,
        'AtributVarian' => null,
        'KunciVarian' => null,
        'IdKategori' => null,
        'Pelacakan' => 'Tidak',
        'IdKelompokPajak' => null,
        'HargaTermasukPajak' => null,
        'MetodeHpp' => null,
        'BolehMinus' => null,
        'Aktif' => true,
        'TampilDiPos' => true,
        'TampilOnline' => false,
        'Favorit' => false,
        'HargaTerbuka' => false,
        'DurasiMenit' => null,
        'MasaGaransiBulan' => null,
        'LokasiProduksi' => null,
        'KodeBarangJasaCoretax' => null,
        'KodeUnitCoretax' => null,
        'GolonganObat' => null,
        'ObatWajibApotek' => false,
        'Prekursor' => false,
        'PathGambar' => null,
        'DiarsipkanPada' => null,
    ];

    protected static function booted(): void
    {
        // X7 §16.4 (v3.96): webhook `produk.diubah` dari semua jalur, hanya bila ada perubahan nyata.
        self::saved(static function (Produk $produk): void {
            if ($produk->wasRecentlyCreated || $produk->wasChanged()) {
                PemberitahuProdukDiubah::TandaiDariModel($produk->IdTenant, $produk->Id);
            }
        });
    }

    /** Apotek (§9.5): wajib resep dokter = turunan golongan & OWA, tidak disimpan. */
    public function CekWajibResep(): bool
    {
        return GolonganObat::HitungWajibResep($this->GolonganObat, $this->ObatWajibApotek);
    }

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function Induk(): BelongsTo
    {
        return $this->belongsTo(self::class, 'IdInduk', 'Id');
    }

    /**
     * @return HasMany<Produk, $this>
     */
    public function Anak(): HasMany
    {
        return $this->hasMany(self::class, 'IdInduk', 'Id');
    }

    /**
     * @return BelongsTo<Kategori, $this>
     */
    public function Kategori(): BelongsTo
    {
        return $this->belongsTo(Kategori::class, 'IdKategori', 'Id');
    }

    /**
     * @return BelongsTo<Satuan, $this>
     */
    public function SatuanDasar(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'IdSatuanDasar', 'Id');
    }

    /**
     * @return HasMany<ProdukSatuan, $this>
     */
    public function Satuan(): HasMany
    {
        return $this->hasMany(ProdukSatuan::class, 'IdProduk', 'Id');
    }

    /**
     * @return HasMany<ProdukBarcode, $this>
     */
    public function Barcode(): HasMany
    {
        return $this->hasMany(ProdukBarcode::class, 'IdProduk', 'Id');
    }

    /**
     * @return HasMany<ProdukHarga, $this>
     */
    public function Harga(): HasMany
    {
        return $this->hasMany(ProdukHarga::class, 'IdProduk', 'Id');
    }

    public function AmbilStatus(): StatusProduk
    {
        return $this->DiarsipkanPada === null ? StatusProduk::Aktif : StatusProduk::Diarsipkan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisProduk::class,
            'Pelacakan' => PelacakanProduk::class,
            'AtributVarian' => 'array',
            'HargaTermasukPajak' => 'boolean',
            'BolehMinus' => 'boolean',
            'Aktif' => 'boolean',
            'TampilDiPos' => 'boolean',
            'TampilOnline' => 'boolean',
            'Favorit' => 'boolean',
            'HargaTerbuka' => 'boolean',
            'DurasiMenit' => 'integer',
            'MasaGaransiBulan' => 'integer',
            'LokasiProduksi' => LokasiProduksi::class,
            'GolonganObat' => GolonganObat::class,
            'ObatWajibApotek' => 'boolean',
            'Prekursor' => 'boolean',
            'DiarsipkanPada' => 'datetime',
        ];
    }
}
