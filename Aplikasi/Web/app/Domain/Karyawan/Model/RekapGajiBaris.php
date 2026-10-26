<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Satu karyawan di rekap gaji (F-18 bagian 3). Kotor = GajiPokok + Komisi + Tambahan + Lembur. Bersih = Kotor −
 * PotonganKasbon − PotonganLain − PotonganTerlambat − PotonganTidakMasuk. Lembur & potongan kehadiran (F-18 bagian 5, D-44)
 * dihitung dari absensi vs jadwal saat draf dibuat dan bisa disesuaikan pengelola selama draf.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdRekapGaji
 * @property int $IdKaryawan
 * @property string $GajiPokok
 * @property string $Komisi
 * @property string $Tambahan
 * @property string $PotonganKasbon
 * @property string $PotonganLain
 * @property int $LemburMenit
 * @property string $Lembur
 * @property int $TerlambatMenit
 * @property string $PotonganTerlambat
 * @property int $HariTidakMasuk
 * @property string $PotonganTidakMasuk
 * @property string|null $UpahHarian D-219: upah per hari (hanya karyawan DasarGaji PerHariMasuk); GajiPokok = UpahHarian × HariMasuk
 * @property int|null $HariMasuk
 * @property string $Bersih
 * @property string|null $Catatan
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class RekapGajiBaris extends ModelDasar
{
    use MilikTenant;

    protected $table = 'RekapGajiBaris';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Catatan' => null, 'UpahHarian' => null, 'HariMasuk' => null, 'LemburMenit' => 0, 'Lembur' => '0.00', 'TerlambatMenit' => 0, 'PotonganTerlambat' => '0.00', 'HariTidakMasuk' => 0, 'PotonganTidakMasuk' => '0.00'];

    public function HitungKotor(): Uang
    {
        return Uang::Dari($this->GajiPokok)->Tambah(Uang::Dari($this->Komisi))->Tambah(Uang::Dari($this->Tambahan))->Tambah(Uang::Dari($this->Lembur));
    }

    public function HitungPotongan(): Uang
    {
        return Uang::Dari($this->PotonganKasbon)->Tambah(Uang::Dari($this->PotonganLain))
            ->Tambah(Uang::Dari($this->PotonganTerlambat))->Tambah(Uang::Dari($this->PotonganTidakMasuk));
    }
}
