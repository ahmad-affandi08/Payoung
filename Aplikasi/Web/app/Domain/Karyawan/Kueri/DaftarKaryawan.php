<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Karyawan back-office (F-18, `TabelData` mode server): cari nama/jabatan, saring status & outlet utama. Gaji pokok
 * hanya dikirim bila pelihat ber-izin `karyawan.kelola`.
 */
final class DaftarKaryawan
{
    public const KOLOM_URUT = ['Nama', 'Jabatan', 'DibuatPada'];

    public const KOLOM_SARING = ['Status', 'Outlet'];

    public const URUT_BAWAAN = 'Nama';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly DaftarAnggota $anggota,
        private readonly PetaUuidOutlet $outlet,
    ) {}

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, bool $lihatGaji): array
    {
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusKaryawan $s): string => $s->value, StatusKaryawan::cases()));
        $uuidOutlet = $p->AmbilDaftar('Outlet');
        $idOutlet = $uuidOutlet === [] ? null : array_values($this->outlet->AmbilIdDariUuid($uuidOutlet));
        $kueri = Karyawan::query()
            ->when($status !== [], fn (Builder $k) => $k->whereIn('Status', $status))
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->when($p->cari !== '', function (Builder $k) use ($p): void {
                $pola = PenerapKueriTabel::PolaCari($p->cari);
                $k->where(fn (Builder $q) => $q->where('Nama', 'like', $pola)->orWhere('Jabatan', 'like', $pola));
            });

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Nama' => 'Nama', 'Jabatan' => 'Jabatan', 'DibuatPada' => 'DibuatPada'], fn (Collection $baris): array => $this->Petakan($baris, $lihatGaji));
    }

    /**
     * D-101: semua karyawan tenant dalam bentuk baris daftar (untuk halaman Tim yang menggabungkan akun & karyawan).
     *
     * @return list<array<string, mixed>>
     */
    public function AmbilSemua(bool $lihatGaji): array
    {
        return $this->Petakan(Karyawan::query()->orderBy('Nama')->get(), $lihatGaji);
    }

    /**
     * Karyawan aktif (pilihan saring & jadwal).
     *
     * @return list<array{Uuid: string, Nama: string}>
     */
    public function AmbilPilihan(): array
    {
        return array_values(Karyawan::query()->where('Status', StatusKaryawan::Aktif->value)->orderBy('Nama')->get(['Uuid', 'Nama'])
            ->map(fn (Karyawan $k): array => ['Uuid' => $k->Uuid, 'Nama' => $k->Nama])->all());
    }

    /**
     * @param  Collection<int, Karyawan>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris, bool $lihatGaji): array
    {
        $pengguna = [];

        foreach ($this->anggota->AmbilPilihanAktif($this->konteks->Wajib()) as $a) {
            $pengguna[$a['Id']] = $a;
        }

        // F-18 bagian 4 (D-37): status absen HP per karyawan (tautan ada? wajah terakhir).
        $wajah = WajahKaryawan::query()
            ->whereIn('IdKaryawan', $baris->pluck('Id')->all())
            ->orderBy('Id')
            ->get(['IdKaryawan', 'Status'])
            ->keyBy('IdKaryawan');
        $outlet = [];

        foreach ($this->outlet->AmbilRingkas() as $o) {
            $outlet[$o['Id']] = $o;
        }

        return array_values($baris->map(fn (Karyawan $k): array => [
            'Uuid' => $k->Uuid,
            'Nama' => $k->Nama,
            'Jabatan' => $k->Jabatan,
            'LevelStaf' => $k->LevelStaf,
            'GajiPokok' => $lihatGaji && $k->GajiPokok !== null ? (string) $k->GajiPokok : null,
            'TarifLemburPerJam' => $lihatGaji && $k->TarifLemburPerJam !== null ? (string) $k->TarifLemburPerJam : null,
            'PotonganTerlambatPerMenit' => $lihatGaji && $k->PotonganTerlambatPerMenit !== null ? (string) $k->PotonganTerlambatPerMenit : null,
            'PotonganTidakMasukPerHari' => $lihatGaji && $k->PotonganTidakMasukPerHari !== null ? (string) $k->PotonganTidakMasukPerHari : null,
            'UuidPengguna' => $k->IdPengguna === null ? null : ($pengguna[$k->IdPengguna]['Uuid'] ?? null),
            'NamaPengguna' => $k->IdPengguna === null ? null : ($pengguna[$k->IdPengguna]['Nama'] ?? 'Akun nonaktif'),
            'UuidOutlet' => $k->IdOutlet === null ? null : ($outlet[$k->IdOutlet]['Uuid'] ?? null),
            'NamaOutlet' => $k->IdOutlet === null ? null : ($outlet[$k->IdOutlet]['Nama'] ?? null),
            'Status' => $k->Status->value,
            'LabelStatus' => $k->Status->AmbilLabel(),
            'TautanAbsen' => $k->HashTokenAbsen !== null,
            'StatusWajah' => $wajah->get($k->Id)?->Status->value,
        ])->all());
    }
}
