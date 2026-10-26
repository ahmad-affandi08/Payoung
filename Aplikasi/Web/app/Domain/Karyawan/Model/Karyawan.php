<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Karyawan\Enum\DasarGaji;
use App\Domain\Karyawan\Enum\SiklusGaji;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Karyawan (F-18, EMP-01), opsional tertaut ke pengguna tenant (akun + PIN kasir). Karyawan tanpa akun tetap bisa
 * dijadwalkan dan (bagian 2) menerima komisi, tetapi tidak bisa absen di POS.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdPengguna
 * @property int|null $IdOutlet
 * @property string $Nama
 * @property string|null $Jabatan
 * @property string|null $LevelStaf
 * @property string|null $GajiPokok gaji pokok per siklus (DasarGaji Tetap) atau upah per hari masuk (PerHariMasuk), D-219
 * @property SiklusGaji $SiklusGaji D-219: Bulanan atau Mingguan
 * @property DasarGaji $DasarGaji
 * @property string|null $TarifLemburPerJam F-18 bagian 5: tarif lembur per jam, potongan per menit terlambat, potongan per hari tidak masuk (null = tidak dihitung)
 * @property string|null $PotonganTerlambatPerMenit
 * @property string|null $PotonganTidakMasukPerHari
 * @property StatusKaryawan $Status
 * @property bool $IkutAbsensi D-180: ikut absensi (Kasir, Pelayan, dst. ya; Manajer, Supervisor, Pemilik bawaannya tidak)
 * @property string|null $TokenAbsen D-198: tautan absen pribadi yang bisa dibagikan (terenkripsi; ditampilkan ulang ke pengelola)
 * @property string|null $HashTokenAbsen
 * @property Carbon|null $TokenAbsenDibuatPada
 * @property int|null $DibuatOleh
 * @property Carbon|null $DibuatPada
 */
final class Karyawan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Karyawan';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Aktif', 'SiklusGaji' => 'Bulanan', 'DasarGaji' => 'Tetap', 'IkutAbsensi' => true, 'TokenAbsen' => null, 'HashTokenAbsen' => null, 'TokenAbsenDibuatPada' => null];

    /** @var list<string> */
    protected $hidden = ['TokenAbsen', 'HashTokenAbsen'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'GajiPokok' => 'decimal:2',
            'TarifLemburPerJam' => 'decimal:2',
            'PotonganTerlambatPerMenit' => 'decimal:2',
            'PotonganTidakMasukPerHari' => 'decimal:2',
            'Status' => StatusKaryawan::class,
            'SiklusGaji' => SiklusGaji::class,
            'DasarGaji' => DasarGaji::class,
            'IkutAbsensi' => 'boolean',
            'TokenAbsen' => 'encrypted',
            'TokenAbsenDibuatPada' => 'datetime',
        ];
    }

    /**
     * Karyawan aktif yang wajib absen (D-180). Dipakai notifikasi "belum masuk/terlambat", Kotak Tindakan, pantau
     * Pemilik, dan potongan tidak masuk di rekap gaji: yang tidak ikut absensi tidak pernah dianggap alpa.
     *
     * @param  Builder<Karyawan>  $kueri
     */
    public function scopeWajibAbsen(Builder $kueri): void
    {
        $kueri->where('Status', StatusKaryawan::Aktif->value)->where('IkutAbsensi', true);
    }
}
