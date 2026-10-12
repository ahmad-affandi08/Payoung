<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Organisasi\Aksi\BatasiKapasitasOrganisasi;
use App\Domain\Organisasi\Kueri\DaftarKapasitasOrganisasi;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanTurunPaket;
use App\Domain\Tenant\Layanan\PenilaiKapasitasPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PermintaanTurunPaket;
use Illuminate\Support\Facades\DB;

/**
 * Menerapkan permintaan turun paket yang sudah waktunya (BR-P04.4, audit E5), dijalankan terjadwal per jam:
 * - Ke paket berbayar yang lebih murah: berlaku begitu tagihan perpanjangan ke paket tujuan lunas (`Langganan.IdPaket`
 *   sudah paket tujuan). Outlet dan anggota di luar batas diarsipkan/dinonaktifkan; data tidak dihapus.
 * - Ke Gratis: berlaku saat periode berbayar berakhir; langganan Aktif → Gratis, paket Gratis, lalu kapasitas disesuaikan.
 * - Dibatalkan sendiri bila paket langganan berubah lewat jalur lain (naik paket, penggantian oleh tim kami) atau
 *   langganan berhenti, supaya tidak menurunkan paket yang sudah bukan rencana Owner.
 * Idempoten: baris dikunci dan diperiksa ulang.
 */
final class TerapkanTurunPaket
{
    public function __construct(
        private readonly PenilaiKapasitasPaket $penilai,
        private readonly DaftarKapasitasOrganisasi $daftar,
        private readonly BatasiKapasitasOrganisasi $batasi,
        private readonly PemilikTenant $pemilik,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * Menerapkan permintaan `Menunggu` tenant aktif (konteks tenant sudah diatur pemanggil: perintah terjadwal memilih
     * tenant yang punya permintaan). Mengembalikan 1 bila diterapkan, 0 bila belum waktunya, dibatalkan, atau tidak ada.
     */
    public function Jalankan(): int
    {
        return $this->ProsesTenant();
    }

    private function ProsesTenant(): int
    {
        return DB::transaction(function (): int {
            $permintaan = PermintaanTurunPaket::query()->where('Status', StatusPermintaanTurunPaket::Menunggu->value)->with('PaketTujuan')->lockForUpdate()->first();

            if ($permintaan === null) {
                return 0;
            }

            $langganan = Langganan::query()->whereKey($permintaan->IdLangganan)->lockForUpdate()->first();

            if ($langganan === null || $langganan->Status === StatusLangganan::Berhenti
                || ($langganan->IdPaket !== $permintaan->IdPaketAsal && $langganan->IdPaket !== $permintaan->IdPaketTujuan)) {
                $permintaan->update(['Status' => StatusPermintaanTurunPaket::Dibatalkan, 'DibatalkanPada' => now(), 'AlasanBatal' => 'Langganan atau paket berubah sebelum turun paket berlaku.']);
                $this->audit->Catat('langganan.turun-paket-batal', $permintaan, nilaiBaru: ['Alasan' => $permintaan->AlasanBatal], idTenant: $permintaan->IdTenant);

                return 0;
            }

            $ke = $permintaan->PaketTujuan;

            if ($ke->Kode === (string) config('tenant.KodePaketGratis')) {
                if ($langganan->Status !== StatusLangganan::Aktif || $langganan->PeriodeSelesai === null || $langganan->PeriodeSelesai->isFuture()) {
                    return 0;
                }

                $langganan->update(['Status' => StatusLangganan::Gratis, 'IdPaket' => $ke->Id]);
            } elseif ($langganan->IdPaket !== $ke->Id || $langganan->Status !== StatusLangganan::Aktif) {
                return 0;
            }

            $kapasitas = $this->penilai->Nilai($permintaan->IdTenant, $ke, $this->daftar->AmbilIdOutletAktif());
            $hasil = $this->batasi->Jalankan(
                $this->pemilik->AmbilIdPemilik($permintaan->IdTenant) ?? 0,
                $kapasitas['Batas']['Outlet'],
                $kapasitas['Batas']['Pengguna'],
                $permintaan->UuidOutletTetap ?? [],
                $permintaan->UuidAnggotaTetap ?? [],
            );
            $permintaan->update(['Status' => StatusPermintaanTurunPaket::Diterapkan, 'DiterapkanPada' => now()]);
            $this->audit->Catat('langganan.turun-paket-berlaku', $permintaan, nilaiBaru: [
                'PaketTujuan' => $ke->Kode,
                'OutletDiarsipkan' => $hasil['OutletDiarsipkan'],
                'AnggotaDinonaktifkan' => $hasil['AnggotaDinonaktifkan'],
            ], idTenant: $permintaan->IdTenant);

            return 1;
        });
    }
}
