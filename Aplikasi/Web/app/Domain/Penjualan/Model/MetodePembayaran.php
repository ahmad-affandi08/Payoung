<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;

/**
 * Metode pembayaran tenant (PRD §15.3, F-01 langkah 5, F-08). Tunai selalu ada dan tidak bisa dinonaktifkan.
 * `IdAkun` null = akun diturunkan dari `PemetaanAkun` menurut jenis (Tunai→KasOutlet, QRIS/EDC→PiutangPencairan,
 * Transfer→Bank); F-13 mengikuti aturan ini. `PathGambarQris` = berkas di disk privat, tidak pernah dikirim ke browser.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property JenisMetodePembayaran $Jenis
 * @property string $Nama
 * @property int|null $IdReferensiBank
 * @property string|null $NomorRekening
 * @property string|null $NamaPemilikRekening
 * @property KanalPenjualan|null $Kanal X8: kanal platform metode `Marketplace` (ojol/marketplace); null untuk jenis lain
 * @property string|null $PathGambarQris
 * @property string|null $TeksQris D-189: payload QRIS statis (publik di gambar QR-nya) untuk menyusun QRIS bernominal di kasir
 * @property int|null $IdAkun
 * @property int|null $IdAkunKliring
 * @property string $PersenBiaya
 * @property string $BiayaTetap
 * @property int|null $BatasHariMenunggu F-08: batas hari menunggu pencairan buatan tenant; null = bawaan jenis
 * @property bool $Aktif
 * @property int $Urutan
 */
final class MetodePembayaran extends ModelDasar
{
    use MilikTenant;

    protected $table = 'MetodePembayaran';

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdReferensiBank' => null,
        'NomorRekening' => null,
        'NamaPemilikRekening' => null,
        'Kanal' => null,
        'PathGambarQris' => null,
        'TeksQris' => null,
        'IdAkun' => null,
        'IdAkunKliring' => null,
        'PersenBiaya' => '0',
        'BiayaTetap' => '0',
        'BatasHariMenunggu' => null,
        'Aktif' => true,
        'Urutan' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisMetodePembayaran::class,
            'Kanal' => KanalPenjualan::class,
            'PersenBiaya' => 'decimal:6',
            'BiayaTetap' => 'decimal:2',
            'BatasHariMenunggu' => 'integer',
            'Aktif' => 'boolean',
            'Urutan' => 'integer',
        ];
    }
}
