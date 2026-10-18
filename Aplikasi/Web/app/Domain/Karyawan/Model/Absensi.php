<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Absensi masuk/keluar dari aplikasi kasir (F-18, EMP-03). `Uuid` dibuat perangkat; swafoto di disk privat
 * (`config('karyawan.DiskSwafoto')`), hanya diunduh lewat rute berizin `karyawan.lihat`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdKaryawan
 * @property int $IdOutlet
 * @property int|null $IdPerangkat
 * @property Carbon $TanggalBisnis
 * @property Carbon $MasukPada
 * @property Carbon|null $KeluarPada
 * @property string|null $PathSwafotoMasuk
 * @property string|null $PathSwafotoKeluar
 * @property string $Sumber
 * @property int|null $DikoreksiOleh
 * @property Carbon|null $DikoreksiPada
 * @property string|null $AlasanKoreksi
 * @property string|null $LintangMasuk F-18 bagian 4 (absen web): lokasi, akurasi GPS, jarak ke outlet, kemiripan wajah
 * @property string|null $BujurMasuk
 * @property int|null $AkurasiMasukMeter
 * @property int|null $JarakMasukMeter
 * @property string|null $KemiripanWajahMasuk
 * @property string|null $LintangKeluar
 * @property string|null $BujurKeluar
 * @property int|null $AkurasiKeluarMeter
 * @property int|null $JarakKeluarMeter
 * @property string|null $KemiripanWajahKeluar
 * @property bool $DiluarJadwal absen POS masuk saat aturan wajib-jadwal aktif tetapi tidak sesuai jadwal (F-18 bagian 5)
 * @property bool $PerluDicek D-180: lokasi/akurasi janggal; absen tetap tercatat, pengelola memeriksa
 * @property string|null $CatatanPeriksa
 */
final class Absensi extends ModelDasar
{
    use MilikTenant;

    protected $table = 'Absensi';

    public const SUMBER_POS = 'Pos';

    public const SUMBER_MANUAL = 'Manual';

    /** F-18 bagian 4 (D-37): absen dari HP pribadi lewat web dengan geofence & pencocokan wajah. */
    public const SUMBER_WEB = 'Web';

    /** @var array<string, mixed> */
    protected $attributes = ['Sumber' => self::SUMBER_POS, 'DiluarJadwal' => false, 'PerluDicek' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'TanggalBisnis' => 'date',
            'DiluarJadwal' => 'boolean',
            'PerluDicek' => 'boolean',
            'MasukPada' => 'datetime',
            'KeluarPada' => 'datetime',
            'DikoreksiPada' => 'datetime',
            'LintangMasuk' => 'decimal:7',
            'BujurMasuk' => 'decimal:7',
            'AkurasiMasukMeter' => 'integer',
            'JarakMasukMeter' => 'integer',
            'KemiripanWajahMasuk' => 'decimal:4',
            'LintangKeluar' => 'decimal:7',
            'BujurKeluar' => 'decimal:7',
            'AkurasiKeluarMeter' => 'integer',
            'JarakKeluarMeter' => 'integer',
            'KemiripanWajahKeluar' => 'decimal:4',
            'QrMasukTerverifikasi' => 'boolean',
            'QrKeluarTerverifikasi' => 'boolean',
        ];
    }
}
