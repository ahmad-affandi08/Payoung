<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Merchant\PembuatPendaftarMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pendaftaran merchant pembayaran milik tenant di DOKU Partner API (KYB). Satu per (tenant, penyedia).
 *
 * `Nik`, `NomorRekening`, dan `KunciBersama` terenkripsi di database dan disembunyikan dari serialisasi; tampilan
 * memakai `NikTersamar()`/`RekeningTersamar()`. Foto tidak pernah disimpan permanen: `Path*` hanya berkas sementara.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Penyedia
 * @property StatusPendaftaranMerchant $Status
 * @property string|null $NamaPemilik
 * @property string|null $Nik
 * @property string|null $Email
 * @property string|null $NomorHp
 * @property string|null $NamaUsaha
 * @property string|null $AlamatUsaha
 * @property string|null $KategoriUsaha
 * @property int|null $IdReferensiBank
 * @property string|null $NamaPemilikRekening
 * @property string|null $NomorRekening
 * @property string|null $PathKtp
 * @property string|null $PathSwafoto
 * @property string|null $PathBuktiUsaha
 * @property Carbon|null $BerkasDiunggahPada
 * @property string|null $IdFileKtp
 * @property string|null $IdFileSwafoto
 * @property string|null $IdFileBuktiUsaha
 * @property string|null $IdBisnisDoku
 * @property string|null $IdBrandDoku
 * @property string|null $KunciBersama
 * @property string|null $StatusDoku
 * @property string|null $PesanGalat
 * @property string|null $AlasanPenolakan
 * @property string|null $TokenCallback
 * @property Carbon|null $DikirimPada
 * @property Carbon|null $DisetujuiPada
 * @property Carbon|null $DiperiksaPada
 * @property Carbon|null $CallbackDiterimaPada
 * @property string|null $IdPedagangQris
 * @property string|null $IdTerminalQris
 * @property Carbon $DibuatPada
 * @property Carbon $DiubahPada
 */
final class PendaftaranMerchantPembayaran extends ModelDasar
{
    use MilikTenant;

    public const PENYEDIA_DOKU = 'Doku';

    protected $table = 'PendaftaranMerchantPembayaran';

    /** @var list<string> */
    protected $hidden = ['Nik', 'NomorRekening', 'KunciBersama', 'TokenCallback', 'PathKtp', 'PathSwafoto', 'PathBuktiUsaha'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Penyedia' => self::PENYEDIA_DOKU,
        'Status' => 'Draf',
    ];

    /**
     * Pendaftaran milik penyedia yang sedang dipakai platform (`integrasi.PenyediaPendaftaranMerchant`, D-179). Satu
     * tenant punya satu baris per penyedia, jadi mengganti penyedia berarti tenant mendaftar baru di penyedia itu.
     *
     * @return Builder<PendaftaranMerchantPembayaran>
     */
    public static function UntukPenyediaAktif(): Builder
    {
        return self::query()->where('Penyedia', PembuatPendaftarMerchant::AmbilKodeAktif());
    }

    /** Ketiga berkas sementara masih ada di disk (siap diunggah). */
    public function CekBerkasLengkap(): bool
    {
        return $this->PathKtp !== null && $this->PathSwafoto !== null && $this->PathBuktiUsaha !== null;
    }

    /** Ketiga berkas sudah terunggah ke DOKU (id berkas tersimpan). */
    public function CekBerkasTerunggah(): bool
    {
        return $this->IdFileKtp !== null && $this->IdFileSwafoto !== null && $this->IdFileBuktiUsaha !== null;
    }

    public function CekSudahTerdaftar(): bool
    {
        return $this->IdBisnisDoku !== null && $this->IdBisnisDoku !== '';
    }

    public function NikTersamar(): ?string
    {
        return self::Samarkan($this->Nik);
    }

    public function RekeningTersamar(): ?string
    {
        return self::Samarkan($this->NomorRekening);
    }

    /** `••••1234`: hanya 4 karakter terakhir; nilai pendek tidak ditampilkan sama sekali (BR-P05.1). */
    private static function Samarkan(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return mb_strlen($nilai) >= 8 ? '••••'.mb_substr($nilai, -4) : '••••';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusPendaftaranMerchant::class,
            'Nik' => 'encrypted',
            'NomorRekening' => 'encrypted',
            'KunciBersama' => 'encrypted',
            'BerkasDiunggahPada' => 'datetime',
            'DikirimPada' => 'datetime',
            'DisetujuiPada' => 'datetime',
            'DiperiksaPada' => 'datetime',
            'CallbackDiterimaPada' => 'datetime',
        ];
    }
}
