<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Dokumen stok opname per lokasi stok (F-05b, BR-05.3): seluruh produk atau satu kategori (`IdKategori`, termasuk
 * sub-kategori) atau satu rak (`IdRak`, F-05c). Snapshot saldo sistem diambil saat mulai; hitung fisik disimpan di detail; persetujuan mencatat
 * mutasi `OpnameLebih`/`OpnameKurang`. Tanpa hapus; Disetujui & Dibatalkan final. `KunciAktif` (kolom tersimpan)
 * menjaga satu opname aktif per lokasi & kategori.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property int|null $IdKategori
 * @property string|null $NamaKategori
 * @property int|null $IdRak
 * @property string|null $NamaRak
 * @property bool $HitungButa
 * @property StatusStokOpname $Status
 * @property Carbon $TanggalSnapshot
 * @property Carbon $SnapshotPada
 * @property int $IdMutasiSnapshot
 * @property string|null $Catatan
 * @property int $JumlahBaris
 * @property int $JumlahDihitung
 * @property string $TotalNilaiLebih
 * @property string $TotalNilaiKurang
 * @property Carbon|null $TanggalPosting
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DiajukanOleh
 * @property int|null $DisetujuiOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DiajukanPada
 * @property Carbon|null $DisetujuiPada
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property-read string|null $KunciAktif
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, StokOpnameDetail> $Detail
 */
final class StokOpname extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'StokOpname';

    protected $table = 'StokOpname';

    /** @var list<string> */
    protected $guarded = ['Id', 'KunciAktif'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Berlangsung',
        'HitungButa' => false,
        'JumlahBaris' => 0,
        'JumlahDihitung' => 0,
        'TotalNilaiLebih' => '0.00',
        'TotalNilaiKurang' => '0.00',
    ];

    /**
     * @return HasMany<StokOpnameDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(StokOpnameDetail::class, 'IdStokOpname', 'Id');
    }

    /** Penjaga aturan #8: tidak pernah dihapus; opname yang sudah Disetujui/Dibatalkan tidak berubah lagi. */
    protected static function booted(): void
    {
        self::updating(function (self $opname): void {
            $asal = $opname->getOriginal('Status');
            $asal = $asal instanceof StatusStokOpname ? $asal : StatusStokOpname::tryFrom((string) $asal);

            if ($asal !== null && ! $asal->CekAktif()) {
                throw new LogicException("Stok opname berstatus {$asal->value} tidak bisa diubah.");
            }

            if (array_intersect(array_keys($opname->getDirty()), ['IdGudang', 'IdKategori', 'IdRak', 'HitungButa', 'TanggalSnapshot', 'SnapshotPada', 'IdMutasiSnapshot', 'Nomor']) !== []) {
                throw new LogicException('Lokasi, kategori, rak, snapshot, dan nomor stok opname tidak bisa diubah.');
            }
        });
        self::deleting(function (): void {
            throw new LogicException('Dokumen stok opname tidak pernah dihapus. Opname dibatalkan.');
        });
    }

    public function UbahStatus(StatusStokOpname $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status stok opname {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'HitungButa' => 'boolean',
            'Status' => StatusStokOpname::class,
            'TanggalSnapshot' => 'date',
            'SnapshotPada' => 'datetime',
            'IdMutasiSnapshot' => 'integer',
            'JumlahBaris' => 'integer',
            'JumlahDihitung' => 'integer',
            'TotalNilaiLebih' => 'string',
            'TotalNilaiKurang' => 'string',
            'TanggalPosting' => 'date',
            'DiajukanPada' => 'datetime',
            'DisetujuiPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
