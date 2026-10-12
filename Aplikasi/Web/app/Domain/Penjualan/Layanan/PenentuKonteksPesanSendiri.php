<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\MejaPesanSendiri;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Tenant\Kueri\StatusLanggananTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * F-17: meja dari token QR di tenant konteks aktif (dipasang pemanggil dari slug URL) dan apakah tamu boleh memesan:
 * meja & outlet aktif, sakelar `PesanSendiriAktif` outlet hidup, fitur paket `kanal.self-order` aktif di outlet, dan
 * langganan tenant boleh bertransaksi POS.
 */
final class PenentuKonteksPesanSendiri
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly MejaPesanSendiri $meja,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly StatusLanggananTenant $langganan,
    ) {}

    public function Cari(string $token): ?DataKonteksPesanSendiri
    {
        $idTenant = $this->konteks->Wajib();
        $meja = $this->meja->CariDariToken($token);

        if ($meja === null) {
            return null;
        }

        $aktif = $meja->bisaDipesan
            && $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_PESAN_SENDIRI)
            && $this->langganan->CekBolehBertransaksiPos($this->langganan->Ambil($idTenant));

        return new DataKonteksPesanSendiri($idTenant, $meja->idOutlet, $meja->kodeOutlet, $meja->namaOutlet, $meja->zonaWaktu, $meja->idMeja, $meja->uuidMeja, $meja->namaMeja, $aktif, $meja->uuidOutlet, $meja->kodeKota);
    }

    /** Meja dikenal (404 `MejaTidakDitemukan` bila tidak). */
    public function Wajib(string $token): DataKonteksPesanSendiri
    {
        return $this->Cari($token) ?? throw new PelanggaranAturanBisnis('MejaTidakDitemukan', 'QR meja ini tidak dikenal atau sudah diganti. Minta QR terbaru ke staf.', 'Token', 404);
    }

    /** Meja dikenal dan tamu boleh memesan (409 `PesanSendiriTidakAktif` bila tidak). */
    public function WajibAktif(string $token): DataKonteksPesanSendiri
    {
        $konteks = $this->Wajib($token);

        if (! $konteks->aktif) {
            throw new PelanggaranAturanBisnis('PesanSendiriTidakAktif', 'Pesan sendiri lewat QR sedang tidak aktif di outlet ini. Silakan pesan langsung ke staf.', 'Token', 409);
        }

        return $konteks;
    }
}
