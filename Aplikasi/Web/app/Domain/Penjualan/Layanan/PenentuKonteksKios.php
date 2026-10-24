<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Tenant\Kueri\StatusLanggananTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * F-17 bagian 4: outlet di balik tautan rahasia kios (`/{slug}/kios/{token}`). Kios boleh memesan bila sakelar
 * `Outlet.KiosAktif` hidup, outlet aktif, fitur paket `kanal.self-order` aktif di outlet itu, dan langganan boleh
 * bertransaksi. Token yang sudah diganti ("Buat ulang tautan") tidak dikenal lagi.
 */
final class PenentuKonteksKios
{
    public const POLA_TOKEN = '[A-Za-z0-9]{32}';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly StatusLanggananTenant $langganan,
        private readonly KuotaTransaksiTenant $kuota,
    ) {}

    public function Cari(string $token): ?DataKonteksPesanSendiri
    {
        if (preg_match('/^'.self::POLA_TOKEN.'$/', $token) !== 1) {
            return null;
        }

        $idTenant = $this->konteks->Wajib();
        $outlet = Outlet::query()->where('TokenKios', $token)->first();

        if (! $outlet instanceof Outlet) {
            return null;
        }

        $aktif = $outlet->KiosAktif
            && $outlet->Status === StatusOrganisasi::Aktif
            && $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI)
            && $this->langganan->CekBolehBertransaksiPos($this->langganan->Ambil($idTenant))
            && $this->kuota->CekBolehMulai($idTenant);

        return new DataKonteksPesanSendiri(
            $idTenant, $outlet->Id, $outlet->Kode, $outlet->Nama, $outlet->ZonaWaktu,
            0, '', '', $aktif, $outlet->Uuid, $outlet->KodeKota,
        );
    }

    public function WajibAktif(string $token): DataKonteksPesanSendiri
    {
        $konteks = $this->Cari($token);

        if ($konteks === null) {
            throw new PelanggaranAturanBisnis('KiosTidakDikenal', 'Tautan kios ini tidak berlaku.', 'Umum', 404);
        }

        if (! $konteks->aktif) {
            throw new PelanggaranAturanBisnis('KiosTidakAktif', 'Kios sedang tidak menerima pesanan.', 'Umum', 409);
        }

        return $konteks;
    }
}
