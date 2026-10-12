<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Dukungan\Aksi\SiapkanIdentitasDukungan;
use App\Domain\Dukungan\Enum\JenisAksesDukungan;
use App\Domain\Dukungan\Layanan\PembuatTokenDukungan;
use App\Domain\Dukungan\Model\AksesDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Petugas pengelola memulai "Masuk sebagai tenant" (PGL-16, P-09 langkah 2, izin `dukungan.akses.masuk`). Hanya bila tenant
 * punya izin akses yang masih berlaku: izin Owner (siapa pun berizin masuk) atau akses darurat (hanya Super Admin yang
 * membukanya). Hasilnya alamat sekali pakai di domain tenant yang berlaku 60 detik; sesi baru dibuat saat alamat itu
 * ditukar (`TukarTokenAksesDukungan`). Tanpa izin berlaku, tenant ditutup, atau petugas tak berizin = ditolak.
 */
final class MulaiSesiAksesDukungan
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly SiapkanIdentitasDukungan $identitas,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @return array{Url: string, BerakhirPada: string}
     *
     * @throws PelanggaranAturanBisnis TanpaIzin, TenantTidakDitemukan, AksesTidakAda, AksesDaruratBukanMilikAnda
     */
    public function Jalankan(PenggunaPengelola $petugas, int $idTenant, ?string $ip = null): array
    {
        if (! $petugas->Aktif || ! $petugas->PunyaIzin(IzinPengelola::DukunganAksesMasuk)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Anda tidak berizin masuk sebagai tenant.');
        }

        $tenant = Tenant::query()->whereKey($idTenant)->first();

        if (! $tenant instanceof Tenant || $tenant->Status === StatusTenant::Ditutup) {
            throw new PelanggaranAturanBisnis('TenantTidakDitemukan', 'Tenant tidak ditemukan atau akunnya sudah ditutup.');
        }

        return $this->konteks->JalankanLintasTenant("Masuk sebagai tenant {$tenant->Nama} (akses dukungan)", function () use ($petugas, $idTenant, $ip): array {
            return DB::transaction(function () use ($petugas, $idTenant, $ip): array {
                $akses = AksesDukungan::query()
                    ->whereNull('DicabutPada')
                    ->where('MulaiPada', '<=', now())
                    ->where('BerakhirPada', '>', now())
                    ->orderByDesc('Id')
                    ->lockForUpdate()
                    ->first();

                if (! $akses instanceof AksesDukungan) {
                    throw new PelanggaranAturanBisnis('AksesTidakAda', 'Tenant ini belum memberi izin akses dukungan, atau izinnya sudah berakhir. Minta Owner memberi izin dari Pengaturan › Akses dukungan.');
                }

                if ($akses->Jenis === JenisAksesDukungan::Darurat && $akses->IdPenggunaPengelola !== $petugas->Id) {
                    throw new PelanggaranAturanBisnis('AksesDaruratBukanMilikAnda', 'Akses darurat hanya bisa dipakai Super Admin yang membukanya.');
                }

                $this->identitas->Jalankan($petugas->Id, $petugas->Nama);
                $token = PembuatTokenDukungan::Buat();
                $batasSesi = now()->addHours(4);
                $sesi = SesiAksesDukungan::query()->create([
                    'IdAksesDukungan' => $akses->Id,
                    'IdPenggunaPengelola' => $petugas->Id,
                    'NamaPetugas' => mb_substr($petugas->Nama, 0, 150),
                    'HashToken' => PembuatTokenDukungan::Hash($token),
                    'TokenKedaluwarsaPada' => now()->addSeconds(PembuatTokenDukungan::DETIK_UMUR_TOKEN),
                    'BerakhirPada' => $akses->BerakhirPada->lt($batasSesi) ? $akses->BerakhirPada : $batasSesi,
                    'Ip' => $ip,
                ]);

                $this->audit->Catat('dukungan.sesi.siapkan', $sesi, nilaiBaru: [
                    'Jenis' => $akses->Jenis->value,
                    'Cakupan' => $akses->Cakupan->value,
                    'UuidAkses' => $akses->Uuid,
                ], alasan: 'Masuk sebagai tenant', idPelaku: $petugas->Id, idTenant: $idTenant);

                return [
                    'Url' => AlamatDomain::BuatUrlAbsolutTenant("/dukungan/masuk/{$idTenant}/{$token}"),
                    'BerakhirPada' => $sesi->BerakhirPada->toIso8601String(),
                ];
            });
        }, $idTenant);
    }
}
