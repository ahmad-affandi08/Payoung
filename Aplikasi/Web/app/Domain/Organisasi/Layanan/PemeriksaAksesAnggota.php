<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Data\DataAksesAnggota;
use App\Domain\Organisasi\Enum\CakupanOutletPeran;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Peran;

/**
 * Memeriksa peran & akses outlet yang akan diberikan pelaku kepada anggota/undangan (F-02 langkah 3, §19.1):
 * - Hanya Pemilik yang bisa menunjuk Pemilik lain; peran Pemilik selalu mendapat semua outlet.
 * - Anti-eskalasi: pelaku bukan Pemilik hanya bisa memberi peran yang izinnya ia miliki semua, dan hanya outlet
 *   yang ia sendiri boleh akses.
 * - Cakupan outlet mengikuti peran (D-104): peran tingkat usaha (Admin, Akuntan) selalu semua outlet; peran operasional
 *   (Manajer Outlet, Staf Gudang, Kasir, ...) dipasang per outlet di formulir; peran kustom bebas memilih. Server hanya
 *   memaksa cakupan "Semua" (tidak ada Admin setengah terkurung); klien API lama yang mengirim "semua outlet" untuk peran
 *   operasional tetap diterima.
 * - Tanpa "semua outlet", minimal satu outlet aktif dipilih.
 */
final class PemeriksaAksesAnggota
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AksesPengguna $akses,
    ) {}

    /**
     * @return array{Peran: Peran, SemuaOutlet: bool, IdOutlet: list<int>}
     */
    public function Periksa(int $idPelaku, DataAksesAnggota $data): array
    {
        $idTenant = $this->konteks->Wajib();
        $pelaku = $this->akses->Ambil($idTenant, $idPelaku)
            ?? throw new PelanggaranAturanBisnis('BukanAnggota', 'Anda bukan anggota aktif usaha ini.');
        $peran = Peran::query()->where('Uuid', $data->uuidPeran)->first()
            ?? throw new PelanggaranAturanBisnis('PeranTidakDikenal', 'Pilih peran yang tersedia.', 'Peran');

        if ($peran->CekPemilik() && ! $pelaku['Pemilik']) {
            throw new PelanggaranAturanBisnis('HanyaPemilik', 'Hanya Pemilik yang bisa menunjuk Pemilik lain.', 'Peran');
        }

        if (! $pelaku['Pemilik']) {
            $lebih = array_diff($peran->AmbilKunciIzin(), $pelaku['Izin']);

            if ($lebih !== []) {
                throw new PelanggaranAturanBisnis('PeranMelebihiPelaku', "Peran {$peran->Nama} punya izin yang tidak Anda miliki. Minta Pemilik untuk memberikannya.", 'Peran');
            }
        }

        $cakupan = $peran->AmbilCakupanOutlet();

        $semuaOutlet = $cakupan === CakupanOutletPeran::Semua || $data->semuaOutlet || $peran->CekPemilik();

        if ($semuaOutlet) {
            if (! $pelaku['SemuaOutlet']) {
                throw new PelanggaranAturanBisnis('OutletMelebihiPelaku', 'Anda hanya bisa memberi akses ke outlet yang Anda kelola.', 'SemuaOutlet');
            }

            return ['Peran' => $peran, 'SemuaOutlet' => true, 'IdOutlet' => []];
        }

        $uuidOutlet = array_values(array_unique($data->uuidOutlet));
        $idOutlet = array_values(array_map('intval', Outlet::query()
            ->whereIn('Uuid', $uuidOutlet)
            ->where('Status', StatusOrganisasi::Aktif->value)
            ->pluck('Id')
            ->all()));

        if ($uuidOutlet === [] || count($idOutlet) !== count($uuidOutlet)) {
            throw new PelanggaranAturanBisnis('OutletKosong', 'Pilih minimal satu outlet aktif, atau beri akses ke semua outlet.', 'Outlet');
        }

        $bolehPelaku = $this->akses->AmbilIdOutlet($idTenant, $idPelaku);

        if ($bolehPelaku !== null && array_diff($idOutlet, $bolehPelaku) !== []) {
            throw new PelanggaranAturanBisnis('OutletMelebihiPelaku', 'Anda hanya bisa memberi akses ke outlet yang Anda kelola.', 'Outlet');
        }

        sort($idOutlet);

        return ['Peran' => $peran, 'SemuaOutlet' => false, 'IdOutlet' => $idOutlet];
    }
}
