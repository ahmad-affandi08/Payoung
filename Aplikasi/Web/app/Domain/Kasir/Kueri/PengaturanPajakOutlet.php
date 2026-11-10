<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Kueri;

use App\Domain\Organisasi\Kueri\ProfilPajakOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pajak\Data\DataTarifBerlaku;
use App\Domain\Pajak\Kueri\TarifPajakBerlaku;
use App\Domain\Referensi\Kueri\WilayahKota;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Data halaman Pajak & biaya layanan di Atur kasir (D-263): nilai yang TERSIMPAN di outlet (bukan usulan template),
 * status PKP usaha, kota outlet, dan tarif PBJT/PPN yang berlaku hari ini. Tidak ada angka tarif di kode
 * (CLAUDE.md #12): tarif dibaca dari `TarifPajak` bertanggal.
 */
final class PengaturanPajakOutlet
{
    public const KODE_PBJT = 'PbjtMakananMinuman';

    public const KODE_PPN = 'Ppn';

    public function __construct(
        private readonly ProfilTenant $profilTenant,
        private readonly ProfilPajakOutlet $profilPajakOutlet,
        private readonly TarifPajakBerlaku $tarifBerlaku,
        private readonly WilayahKota $wilayahKota,
    ) {}

    /**
     * @return array{
     *     Pkp: bool,
     *     Kota: array{Kode: string, Nama: string}|null,
     *     Nilai: array{PungutPbjt: bool, BiayaLayananAktif: bool, PersenBiayaLayanan: string, HargaTermasukPajak: bool},
     *     TarifPbjt: array<string, mixed>|null,
     *     TarifPpn: array<string, mixed>|null
     * }
     */
    public function Ambil(Outlet $outlet): array
    {
        $kota = $outlet->KodeKota === null ? null : $this->wilayahKota->Cari($outlet->KodeKota);
        $hari = now($outlet->ZonaWaktu);
        $tarifPbjt = $outlet->KodeKota === null ? null : $this->tarifBerlaku->CariData(self::KODE_PBJT, $outlet->KodeKota, $hari);
        $tarifPpn = $this->tarifBerlaku->CariData(self::KODE_PPN, null, $hari);
        $profil = $this->profilPajakOutlet->Ambil($outlet->Id);

        return [
            'Pkp' => $this->profilTenant->Ambil($outlet->IdTenant)['Pkp'],
            'Kota' => $kota === null ? null : ['Kode' => $kota['Kode'], 'Nama' => $kota['Nama']],
            'Nilai' => [
                'PungutPbjt' => $profil->pungutPbjt ?? false,
                'BiayaLayananAktif' => $profil->biayaLayananAktif ?? false,
                'PersenBiayaLayanan' => $profil->persenBiayaLayanan ?? '0.00',
                'HargaTermasukPajak' => $profil->hargaTermasukPajak ?? false,
            ],
            'TarifPbjt' => $tarifPbjt === null ? null : [...self::PetakanTarif($tarifPbjt), 'BiayaLayananMasukDpp' => $tarifPbjt->biayaLayananMasukDpp],
            'TarifPpn' => $tarifPpn === null ? null : [
                ...self::PetakanTarif($tarifPpn),
                'PengaliDppPembilang' => $tarifPpn->pengaliDppPembilang,
                'PengaliDppPenyebut' => $tarifPpn->pengaliDppPenyebut,
            ],
        ];
    }

    /**
     * @return array{Tarif: string, BerlakuMulai: string, NomorDasarHukum: string|null}
     */
    private static function PetakanTarif(DataTarifBerlaku $tarif): array
    {
        return ['Tarif' => $tarif->tarif, 'BerlakuMulai' => $tarif->berlakuMulai, 'NomorDasarHukum' => $tarif->nomorDasarHukum];
    }
}
