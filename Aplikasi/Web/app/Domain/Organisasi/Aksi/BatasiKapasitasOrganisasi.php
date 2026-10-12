<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\DB;

/**
 * Menyesuaikan outlet dan anggota aktif dengan batas paket yang baru (BR-P04.4, audit E5). Data tidak dihapus: outlet
 * yang tidak dipilih diarsipkan (`UbahStatusOutlet`) dan anggota yang tidak dipilih dinonaktifkan (`UbahStatusAnggota`),
 * keduanya bisa dipulihkan bila paket naik lagi. Pemilik tidak pernah dinonaktifkan. Pilihan Owner dihormati; bila
 * pilihannya kurang dari batas, sisa tempat diisi yang paling lama dibuat; bila lebih dari batas, yang terakhir
 * dipilih dilepas. Batas null = tak terbatas (tidak ada yang diubah).
 *
 * @phpstan-type Hasil array{OutletDiarsipkan: list<string>, AnggotaDinonaktifkan: list<string>}
 */
final class BatasiKapasitasOrganisasi
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly UbahStatusOutlet $ubahOutlet,
        private readonly UbahStatusAnggota $ubahAnggota,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<string>  $uuidOutletTetap
     * @param  list<string>  $uuidAnggotaTetap
     * @return Hasil
     */
    public function Jalankan(int $idPelaku, ?int $batasOutlet, ?int $batasPengguna, array $uuidOutletTetap, array $uuidAnggotaTetap): array
    {
        return DB::transaction(function () use ($idPelaku, $batasOutlet, $batasPengguna, $uuidOutletTetap, $uuidAnggotaTetap): array {
            $idTenant = $this->konteks->Wajib();
            $hasil = ['OutletDiarsipkan' => [], 'AnggotaDinonaktifkan' => []];

            if ($batasOutlet !== null) {
                $outlet = Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->orderBy('Id')->lockForUpdate()->get();

                foreach ($this->PilihYangTetap(array_values($outlet->pluck('Uuid')->all()), $uuidOutletTetap, max(1, $batasOutlet)) as $uuid => $tetap) {
                    $baris = $outlet->firstWhere('Uuid', $uuid);

                    if (! $tetap && $baris !== null) {
                        $this->ubahOutlet->Jalankan($baris, StatusOrganisasi::Diarsipkan);
                        $hasil['OutletDiarsipkan'][] = $uuid;
                    }
                }
            }

            if ($batasPengguna !== null) {
                $anggota = TenantPengguna::query()->where('IdTenant', $idTenant)->where('Status', StatusKeanggotaan::Aktif->value)
                    ->with('Pengguna:Id,Uuid')->orderByDesc('Pemilik')->orderBy('Id')->lockForUpdate()->get();
                $pemilik = $anggota->where('Pemilik', true)->map(fn (TenantPengguna $baris): string => $baris->Pengguna->Uuid)->values()->all();
                $diminta = array_values(array_unique([...$pemilik, ...$uuidAnggotaTetap]));

                foreach ($this->PilihYangTetap(array_values($anggota->map(fn (TenantPengguna $baris): string => $baris->Pengguna->Uuid)->all()), $diminta, max(count($pemilik), $batasPengguna)) as $uuid => $tetap) {
                    if (! $tetap) {
                        $baris = $anggota->first(fn (TenantPengguna $b): bool => $b->Pengguna->Uuid === $uuid);

                        if ($baris !== null && ! $baris->Pemilik) {
                            $this->ubahAnggota->Jalankan($idPelaku, $baris, StatusKeanggotaan::Nonaktif);
                            $hasil['AnggotaDinonaktifkan'][] = $uuid;
                        }
                    }
                }
            }

            if ($hasil['OutletDiarsipkan'] !== [] || $hasil['AnggotaDinonaktifkan'] !== []) {
                $this->audit->Catat('langganan.kapasitas-dibatasi', null, nilaiBaru: $hasil, idTenant: $idTenant);
            }

            return $hasil;
        });
    }

    /**
     * @param  list<string>  $semuaAktif  Uuid aktif, urut dari yang paling lama
     * @param  list<string>  $diminta  pilihan Owner (urutan = prioritas)
     * @return array<string, bool> Uuid → tetap aktif
     */
    private function PilihYangTetap(array $semuaAktif, array $diminta, int $batas): array
    {
        $tetap = array_slice(array_values(array_filter($diminta, fn (string $uuid): bool => in_array($uuid, $semuaAktif, true))), 0, $batas);

        foreach ($semuaAktif as $uuid) {
            if (count($tetap) >= $batas) {
                break;
            }

            if (! in_array($uuid, $tetap, true)) {
                $tetap[] = $uuid;
            }
        }

        $hasil = [];

        foreach ($semuaAktif as $uuid) {
            $hasil[$uuid] = in_array($uuid, $tetap, true);
        }

        return $hasil;
    }
}
