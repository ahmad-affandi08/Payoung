<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BatasiKapasitasOrganisasi;
use App\Domain\Organisasi\Kueri\DaftarKapasitasOrganisasi;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PemvalidasiPilihanKapasitas;
use App\Domain\Tenant\Layanan\PenilaiKapasitasPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Facades\DB;

/**
 * Langganan yang ditangguhkan karena tunggakan (bukan penangguhan manual P-07) bisa dipindah Owner ke paket Gratis saat
 * itu juga, supaya kasir terbuka lagi dalam batas Gratis tanpa melunasi tagihan (BR-00.7: Ditangguhkan → Gratis;
 * pesan penangguhan di aplikasi kasir menjanjikan jalur ini). Tagihan yang masih terbuka dibatalkan, kelebihan outlet
 * dan pengguna diselesaikan dengan pilihan Owner (BR-P04.4), data tidak dihapus.
 */
final class PindahKeGratis
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenilaiKapasitasPaket $penilai,
        private readonly DaftarKapasitasOrganisasi $daftar,
        private readonly PemvalidasiPilihanKapasitas $pemvalidasi,
        private readonly BatasiKapasitasOrganisasi $batasi,
        private readonly BatalkanTagihanLangganan $batalkanTagihan,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<string>  $uuidOutletTetap
     * @param  list<string>  $uuidAnggotaTetap
     */
    public function Jalankan(int $idPengguna, array $uuidOutletTetap = [], array $uuidAnggotaTetap = []): Langganan
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idPengguna, $uuidOutletTetap, $uuidAnggotaTetap): Langganan {
            $langganan = Langganan::query()->where('IdTenant', $idTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Data langganan usaha ini tidak ditemukan. Hubungi tim kami.');

            if ($langganan->Status !== StatusLangganan::Ditangguhkan || $langganan->CekDitangguhkanManual()) {
                throw new PelanggaranAturanBisnis(
                    'PindahGratisTidakBisa',
                    $langganan->CekDitangguhkanManual()
                        ? 'Usaha ini ditangguhkan oleh tim kami. Hubungi kami lewat menu Bantuan.'
                        : 'Pindah ke paket Gratis seketika hanya untuk langganan yang ditangguhkan karena tunggakan.',
                );
            }

            $gratis = Paket::query()->where('Kode', (string) config('tenant.KodePaketGratis'))->first()
                ?? throw new PelanggaranAturanBisnis('PaketTidakTersedia', 'Paket Gratis belum tersedia. Hubungi tim kami.');
            $kapasitas = $this->penilai->Nilai($idTenant, $gratis, $this->daftar->AmbilIdOutletAktif());
            [$outletTetap, $anggotaTetap] = $this->pemvalidasi->Periksa($kapasitas, $this->daftar->AmbilOutletAktif(), $this->daftar->AmbilAnggotaAktif($idTenant), $uuidOutletTetap, $uuidAnggotaTetap);

            foreach (TagihanLangganan::query()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->get() as $terbuka) {
                $this->batalkanTagihan->Jalankan($terbuka->Uuid, 'Dibatalkan karena usaha pindah ke paket Gratis.');
            }

            $asal = $langganan->IdPaket;
            $langganan->update(['Status' => StatusLangganan::Gratis, 'IdPaket' => $gratis->Id]);
            PermintaanTurunPaket::query()->where('IdLangganan', $langganan->Id)->where('Status', StatusPermintaanTurunPaket::Menunggu->value)
                ->update(['Status' => StatusPermintaanTurunPaket::Dibatalkan->value, 'DibatalkanPada' => now(), 'AlasanBatal' => 'Usaha pindah ke paket Gratis.']);

            $hasil = $this->batasi->Jalankan($idPengguna, $kapasitas['Batas']['Outlet'], $kapasitas['Batas']['Pengguna'], $outletTetap ?? [], $anggotaTetap ?? []);
            $this->audit->Catat('langganan.pindah-gratis', $langganan, nilaiLama: ['IdPaket' => $asal], nilaiBaru: [
                'Status' => StatusLangganan::Gratis->value,
                'OutletDiarsipkan' => $hasil['OutletDiarsipkan'],
                'AnggotaDinonaktifkan' => $hasil['AnggotaDinonaktifkan'],
            ], idTenant: $idTenant, idPengguna: $idPengguna);

            return $langganan;
        });
    }
}
