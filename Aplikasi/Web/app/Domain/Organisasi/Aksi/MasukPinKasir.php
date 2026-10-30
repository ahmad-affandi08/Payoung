<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Layanan\PenguncianPin;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\Hash;

/**
 * F-02 langkah 4 (online): kasir masuk di perangkat bersama dengan PIN 6 digit (`POST /api/pos/v1/kasir/masuk-pin`).
 * - Hanya anggota aktif tenant perangkat yang punya akses ke outlet perangkat; selain itu `KasirTidakDitemukan` (404).
 * - §20.2: 5 kali salah per perangkat + pengguna → terkunci 5 menit (`PinTerkunci`, 429).
 *
 * TODO F-06: verifikasi offline memakai hash PIN dari data-awal, dengan kunci yang sama di aplikasi.
 *
 * @phpstan-type HasilMasukPin array{Pengguna: Pengguna, Pemilik: bool, Izin: list<string>}
 */
final class MasukPinKasir
{
    public function __construct(
        private readonly AksesPengguna $akses,
        private readonly PenguncianPin $penguncian,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return HasilMasukPin
     */
    public function Jalankan(Perangkat $perangkat, string $uuidPengguna, string $pin): array
    {
        $pengguna = Pengguna::query()->where('Uuid', $uuidPengguna)->first();
        $anggota = $pengguna === null ? null : TenantPengguna::query()
            ->where('IdTenant', $perangkat->IdTenant)
            ->where('IdPengguna', $pengguna->Id)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->first();
        $outlet = $anggota === null || $pengguna === null ? [] : $this->akses->AmbilIdOutlet($perangkat->IdTenant, $pengguna->Id);

        if ($pengguna === null || $anggota === null || ($outlet !== null && ! in_array($perangkat->IdOutlet, $outlet, true))) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet perangkat ini.', 'UuidPengguna', 404);
        }

        $this->PastikanTidakTerkunci($perangkat, $pengguna->Id);

        if ($anggota->HashPin === null) {
            throw new PelanggaranAturanBisnis('PinBelumDiatur', 'PIN belum diatur. Atur PIN di back-office menu Keamanan akun, atau minta manajer mengaturnya.', 'Pin');
        }

        if (! $this->penguncian->PesanPercobaan($perangkat->Id, $pengguna->Id)) {
            $this->PastikanTidakTerkunci($perangkat, $pengguna->Id);
        }

        if (! Hash::check($pin, $anggota->HashPin)) {
            $sisa = $this->penguncian->CatatGagal($perangkat->Id, $pengguna->Id);

            if ($sisa === 0) {
                $this->audit->Catat('kasir.pin.terkunci', $perangkat, nilaiBaru: ['IdPengguna' => $pengguna->Id], idTenant: $perangkat->IdTenant, idPengguna: $pengguna->Id);
                $this->PastikanTidakTerkunci($perangkat, $pengguna->Id);
            }

            throw new PelanggaranAturanBisnis('PinSalah', "PIN salah. Sisa {$sisa} percobaan sebelum PIN dikunci sementara.", 'Pin', 422, ['SisaPercobaan' => $sisa]);
        }

        $this->penguncian->Bersihkan($perangkat->Id, $pengguna->Id);
        $this->audit->Catat('kasir.masuk-pin', $perangkat, idTenant: $perangkat->IdTenant, idPengguna: $pengguna->Id);
        $akses = $this->akses->Ambil($perangkat->IdTenant, $pengguna->Id);

        return ['Pengguna' => $pengguna, 'Pemilik' => $akses['Pemilik'] ?? false, 'Izin' => $akses['Izin'] ?? []];
    }

    private function PastikanTidakTerkunci(Perangkat $perangkat, int $idPengguna): void
    {
        $detik = $this->penguncian->AmbilSisaDetikKunci($perangkat->Id, $idPengguna);

        if ($detik > 0) {
            $menit = (int) ceil($detik / 60);

            throw new PelanggaranAturanBisnis('PinTerkunci', "Terlalu banyak PIN salah. Coba lagi dalam {$menit} menit atau minta manajer mengatur ulang PIN.", 'Pin', 429, ['DetikTersisa' => $detik]);
        }
    }
}
