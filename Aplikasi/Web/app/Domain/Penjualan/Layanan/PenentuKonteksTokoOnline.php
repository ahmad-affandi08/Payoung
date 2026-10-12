<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Tenant\Kueri\StatusLanggananTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

final class PenentuKonteksTokoOnline
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly StatusLanggananTenant $langganan,
    ) {}

    public function Cari(?string $uuidOutlet = null): ?DataKonteksPesanSendiri
    {
        $idTenant = $this->konteks->Wajib();
        $uuid = $uuidOutlet === null ? null : strtoupper($uuidOutlet);
        $kueri = Outlet::query()
            ->where('Status', StatusOrganisasi::Aktif->value)
            ->where('TokoOnlineAktif', true)
            ->orderBy('Id');

        if ($uuid !== null) {
            $kueri->where('Uuid', $uuid);
        }

        $outlet = $kueri->first();

        if (! $outlet instanceof Outlet) {
            return null;
        }

        $pengaturan = PengaturanTokoOnline::query()->first();
        $aktif = $pengaturan?->Aktif === true
            && $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_TOKO_ONLINE)
            && $this->langganan->CekBolehBertransaksiPos($this->langganan->Ambil($idTenant));

        return new DataKonteksPesanSendiri(
            $idTenant, $outlet->Id, $outlet->Kode, $outlet->Nama, $outlet->ZonaWaktu,
            0, '', '', $aktif, $outlet->Uuid, $outlet->KodeKota,
        );
    }

    public function WajibAktif(?string $uuidOutlet = null): DataKonteksPesanSendiri
    {
        $konteks = $this->Cari($uuidOutlet);

        if ($konteks === null || ! $konteks->aktif) {
            throw new PelanggaranAturanBisnis('TokoOnlineTidakAktif', 'Toko online sedang tidak menerima pesanan.', 'Umum', 409);
        }

        return $konteks;
    }
}
