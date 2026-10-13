<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Aksi\SiapkanIdentitasDukungan;
use App\Domain\Dukungan\Enum\AlatDukungan;
use App\Domain\Dukungan\Enum\CakupanAksesDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Enum\StatusHasilAlatDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\HasilAlatDukungan;
use App\Domain\Pengelola\Dukungan\Data\HasilKerjaAlat;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Tenant;
use Closure;
use Throwable;

/**
 * Membungkus setiap alat bantu dukungan di konsol (PGL-16 bagian 2, P-09, BR-P09.3, D-133).
 *
 * Syarat yang sama untuk semua alat: petugas aktif dan berizin `dukungan.alat.pakai`; tenant belum ditutup; ada izin
 * akses dukungan yang berlaku (izin Owner, atau akses darurat yang dibuka petugas itu sendiri); alat yang mengubah data
 * hanya di bawah cakupan "Baca & ubah" (BR-P09.2). Pekerjaan berjalan di dalam konteks tenant (`JalankanLintasTenant`,
 * tercatat `tenant.data.akses`). Alat yang mengubah data meninggalkan tiga jejak: baris `HasilAlatDukungan` (sebelum dan
 * sesudah, dibaca Owner), `LogAudit` tenant bertanda pelaku pengelola, dan `LogAuditPengelola`. Kegagalan karena aturan
 * bisnis juga dicatat sebagai hasil `Gagal` lalu diteruskan ke petugas.
 */
final class PelaksanaAlatDukungan
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PencatatAudit $auditTenant,
        private readonly PencatatAuditPengelola $auditPengelola,
        private readonly SiapkanIdentitasDukungan $identitas,
    ) {}

    /**
     * @param  array<string, mixed>  $parameter  pengenal masukan petugas (disimpan di hasil; tanpa data pribadi)
     * @param  Closure(AksesDukungan): HasilKerjaAlat  $kerja
     *
     * @throws PelanggaranAturanBisnis TanpaIzin, TenantTidakDitemukan, AksesTidakAda, AksesDaruratBukanMilikAnda, IzinHanyaBaca, atau galat alatnya
     */
    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, AlatDukungan $alat, array $parameter, Closure $kerja): HasilKerjaAlat
    {
        if (! $petugas->Aktif || ! $petugas->PunyaIzin(IzinPengelola::DukunganAlatPakai)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Anda tidak berizin memakai alat bantu dukungan.');
        }

        $tenant = Tenant::query()->whereKey($idTenant)->first();

        if (! $tenant instanceof Tenant || $tenant->Status === StatusTenant::Ditutup) {
            throw new PelanggaranAturanBisnis('TenantTidakDitemukan', 'Tenant tidak ditemukan atau akunnya sudah ditutup.');
        }

        return $this->konteks->JalankanLintasTenant("Alat bantu dukungan: {$alat->AmbilLabel()} pada tenant {$tenant->Nama}", function () use ($petugas, $idTenant, $alat, $parameter, $kerja): HasilKerjaAlat {
            $akses = $this->CariAkses($petugas, $alat);

            try {
                $hasil = $kerja($akses);
            } catch (PelanggaranAturanBisnis $galat) {
                if ($alat->Mengubah()) {
                    $this->Catat($petugas, $idTenant, $akses, $alat, $parameter, new HasilKerjaAlat(StatusHasilAlatDukungan::Gagal, mb_substr($galat->getMessage(), 0, 300)));
                }

                throw $galat;
            } catch (Throwable $galat) {
                if ($alat->Mengubah()) {
                    $this->Catat($petugas, $idTenant, $akses, $alat, $parameter, new HasilKerjaAlat(StatusHasilAlatDukungan::Gagal, 'Alat berhenti karena galat sistem. Tim teknis perlu memeriksa log.'));
                }

                throw $galat;
            }

            if ($alat->Mengubah()) {
                $this->Catat($petugas, $idTenant, $akses, $alat, $parameter, $hasil);
            }

            return $hasil;
        }, $idTenant);
    }

    /** Izin akses yang sedang berlaku untuk tenant aktif (konteks tenant sudah diatur). */
    private function CariAkses(PenggunaPengelola $petugas, AlatDukungan $alat): AksesDukungan
    {
        $akses = AksesDukungan::query()
            ->whereNull('DicabutPada')
            ->where('MulaiPada', '<=', now())
            ->where('BerakhirPada', '>', now())
            ->orderByDesc('Id')
            ->first();

        if (! $akses instanceof AksesDukungan) {
            throw new PelanggaranAturanBisnis('AksesTidakAda', 'Tenant ini belum memberi izin akses dukungan, atau izinnya sudah berakhir. Minta Owner memberi izin dari Pengaturan › Akses dukungan.');
        }

        if ($akses->Jenis === JenisAksesDukungan::Darurat && $akses->IdPenggunaPengelola !== $petugas->Id) {
            throw new PelanggaranAturanBisnis('AksesDaruratBukanMilikAnda', 'Akses darurat hanya bisa dipakai Super Admin yang membukanya.');
        }

        if ($alat->Mengubah() && $akses->Cakupan !== CakupanAksesDukungan::BacaUbah) {
            throw new PelanggaranAturanBisnis('IzinHanyaBaca', 'Izin akses hanya baca.');
        }

        return $akses;
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function Catat(PenggunaPengelola $petugas, int $idTenant, AksesDukungan $akses, AlatDukungan $alat, array $parameter, HasilKerjaAlat $hasil): void
    {
        HasilAlatDukungan::query()->create([
            'IdAksesDukungan' => $akses->Id,
            'IdPenggunaPengelola' => $petugas->Id,
            'NamaPetugas' => mb_substr($petugas->Nama, 0, 150),
            'Alat' => $alat,
            'Status' => $hasil->status,
            'Ringkasan' => mb_substr($hasil->ringkasan, 0, 300),
            'Parameter' => $parameter === [] ? null : $parameter,
            'Sebelum' => $hasil->sebelum,
            'Sesudah' => $hasil->sesudah,
        ]);

        $nilai = ['Alat' => $alat->value, 'Status' => $hasil->status->value, 'Ringkasan' => $hasil->ringkasan, 'Parameter' => $parameter, 'Sebelum' => $hasil->sebelum, 'Sesudah' => $hasil->sesudah];
        $this->auditTenant->Catat(
            'dukungan.alat',
            nilaiBaru: $nilai,
            idTenant: $idTenant,
            idPengguna: $this->identitas->Jalankan($petugas->Id, $petugas->Nama),
            idPenggunaPengelola: $petugas->Id,
        );
        $this->auditPengelola->Catat(
            'dukungan.alat.'.$alat->value,
            nilaiBaru: $nilai,
            alasan: $alat->AmbilLabel(),
            idPelaku: $petugas->Id,
            idTenant: $idTenant,
        );
    }
}
