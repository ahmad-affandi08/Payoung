<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Model\Outlet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Pencairan dana non-tunai `PC/{YYYY}/{MM}/{SEQ4}` (F-08, BR-08.4, J-08.1): satu setoran yang benar-benar masuk
 * rekening toko, melunasi akun kliring metode pembayarannya dan membukukan potongan platform sebagai beban.
 *
 * `JumlahBersih` datang dari mutasi rekening, bukan dari persen biaya metode; `Biaya` = `JumlahKotor − JumlahBersih`
 * dan **boleh negatif** (platform menyetor lebih dari nilai transaksinya). `BiayaDiharapkan` hanya pembanding dari
 * pengaturan metode dan tidak pernah masuk jurnal — selisih antara keduanya itulah yang perlu dilihat manusia.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdOutlet
 * @property int $IdMetodePembayaran
 * @property Carbon $Tanggal
 * @property int $IdAkunTujuan
 * @property int|null $IdAkunKliring
 * @property StatusDokumenTerposting $Status
 * @property string $JumlahKotor
 * @property string $JumlahBersih
 * @property string $Biaya
 * @property string $BiayaDiharapkan
 * @property string|null $Referensi
 * @property string|null $Catatan
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Outlet $Outlet
 * @property-read MetodePembayaran $MetodePembayaran
 * @property-read Collection<int, PencairanDetail> $Detail
 */
final class Pencairan extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'Pencairan';

    /**
     * Kolom yang masih boleh berubah setelah dokumen dicatat: pembatalan, dan hasil posting dokumen ini sendiri di
     * transaksi yang sama (nomor jurnalnya baru ada setelah jurnalnya dibuat).
     */
    private const KOLOM_STATUS = [
        'Status', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh', 'IdJurnal',
    ];

    protected $table = 'Pencairan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'IdAkunKliring' => null,
        'Referensi' => null,
        'Catatan' => null,
    ];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return self::KOLOM_STATUS;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'JumlahKotor' => 'decimal:2',
            'JumlahBersih' => 'decimal:2',
            'Biaya' => 'decimal:2',
            'BiayaDiharapkan' => 'decimal:2',
        ];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status pencairan {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilJumlahKotor(): Uang
    {
        return Uang::Dari($this->JumlahKotor);
    }

    public function AmbilJumlahBersih(): Uang
    {
        return Uang::Dari($this->JumlahBersih);
    }

    /** Potongan platform; negatif = platform menyetor lebih besar daripada nilai transaksinya. */
    public function AmbilBiaya(): Uang
    {
        return Uang::Dari($this->Biaya);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function Outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'IdOutlet', 'Id');
    }

    /**
     * @return BelongsTo<MetodePembayaran, $this>
     */
    public function MetodePembayaran(): BelongsTo
    {
        return $this->belongsTo(MetodePembayaran::class, 'IdMetodePembayaran', 'Id');
    }

    /**
     * @return HasMany<PencairanDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(PencairanDetail::class, 'IdPencairan', 'Id');
    }
}
