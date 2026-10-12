<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\DaftarKapasitasOrganisasi;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Layanan\PembandingHargaTurunPaket;
use App\Domain\Tenant\Layanan\PenilaiKapasitasPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;

/**
 * Data halaman Langganan untuk turun paket (BR-P04.4, audit E5): paket tujuan yang lebih murah (atau Gratis) beserta
 * kelebihan kapasitasnya, outlet dan anggota yang bisa dipilih tetap aktif, dan permintaan yang sedang menunggu.
 */
final class PratinjauTurunPaket
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PermintaanTurunPaketMenunggu $menunggu,
        private readonly PembandingHargaTurunPaket $pembanding,
        private readonly PenilaiKapasitasPaket $penilai,
        private readonly DaftarKapasitasOrganisasi $daftar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $idTenant = $this->konteks->Wajib();
        $langganan = Langganan::query()->with('Paket')->where('IdTenant', $idTenant)->first();
        $kosong = ['Menunggu' => null, 'Bisa' => false, 'Alasan' => null, 'Pilihan' => [], 'Outlet' => [], 'Anggota' => [], 'PindahGratis' => null];

        if ($langganan === null) {
            return $kosong;
        }

        $menunggu = $this->menunggu->Cari($langganan->Id);
        $hasil = ['Menunggu' => $menunggu === null ? null : [
            'Uuid' => $menunggu->Uuid,
            'KodePaket' => $menunggu->PaketTujuan->Kode,
            'NamaPaket' => $menunggu->PaketTujuan->Nama,
            'Gratis' => $menunggu->PaketTujuan->Kode === (string) config('tenant.KodePaketGratis'),
            'BerlakuPada' => $menunggu->BerlakuPada->toIso8601ZuluString(),
            'Kelebihan' => $menunggu->Kelebihan['Lebih'] ?? null,
        ]] + $kosong;

        // Ditangguhkan karena tunggakan: bisa pindah ke Gratis seketika (kasir terbuka lagi dalam batas Gratis).
        if ($langganan->Status === StatusLangganan::Ditangguhkan && ! $langganan->CekDitangguhkanManual()) {
            $gratis = Paket::query()->where('Kode', (string) config('tenant.KodePaketGratis'))->first();

            return $gratis === null ? $hasil : [
                'PindahGratis' => ['Kapasitas' => $this->penilai->Nilai($idTenant, $gratis, $this->daftar->AmbilIdOutletAktif())],
                'Outlet' => $this->daftar->AmbilOutletAktif(),
                'Anggota' => $this->daftar->AmbilAnggotaAktif($idTenant),
            ] + $hasil;
        }

        if ($langganan->Status !== StatusLangganan::Aktif || $langganan->Paket->HargaNegosiasi) {
            return ['Alasan' => $langganan->Paket->HargaNegosiasi ? 'Paket dengan harga khusus diubah lewat tim kami.' : null] + $hasil;
        }

        $idOutlet = $this->daftar->AmbilIdOutletAktif();
        $pilihan = [];

        foreach (Paket::query()->where('Status', StatusPaket::Aktif->value)->where('HargaNegosiasi', false)->where('Id', '!=', $langganan->IdPaket)->orderBy('Urutan')->get() as $paket) {
            $gratis = $paket->Kode === (string) config('tenant.KodePaketGratis');
            $banding = $gratis ? ['LebihMurah' => true, 'HargaBulanan' => '0.00', 'HargaTahunan' => '0.00'] : $this->pembanding->Bandingkan($langganan, $paket);

            if ($banding === null || ! $banding['LebihMurah']) {
                continue;
            }

            $pilihan[] = [
                'Kode' => $paket->Kode,
                'Nama' => $paket->Nama,
                'Gratis' => $gratis,
                'HargaBulanan' => $banding['HargaBulanan'],
                'HargaTahunan' => $banding['HargaTahunan'],
                'Kapasitas' => $this->penilai->Nilai($idTenant, $paket, $idOutlet),
            ];
        }

        return [
            'Bisa' => $menunggu === null && $pilihan !== [],
            'Pilihan' => $pilihan,
            'Outlet' => $this->daftar->AmbilOutletAktif(),
            'Anggota' => $this->daftar->AmbilAnggotaAktif($idTenant),
        ] + $hasil;
    }
}
