<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Organisasi\Kueri\DaftarAnggota;

/**
 * D-101 (F-02, F-18): daftar "Tim" — satu baris per orang. Akun (`Pengguna`, peran & akses) dan data karyawan
 * (jadwal, absensi, gaji) tetap dua entitas; di sini keduanya digabung supaya pemilik toko hanya melihat satu daftar:
 * akun yang tertaut ke karyawan jadi satu baris, akun tanpa karyawan (akuntan luar) dan karyawan tanpa akun (hanya
 * dijadwalkan & digaji) jadi baris sendiri.
 */
final class DaftarTim
{
    public function __construct(
        private readonly DaftarAnggota $anggota,
        private readonly DaftarKaryawan $karyawan,
        private readonly PetaKaryawanPengguna $peta,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function Ambil(int $idTenant, bool $lihatGaji, bool $lihatAkun = true, bool $lihatKaryawan = true): array
    {
        // Pelihat tanpa `pengguna.lihat` tidak boleh melihat email & peran; tanpa `karyawan.lihat` tidak melihat data karyawan.
        $anggota = $lihatAkun ? $this->anggota->AmbilAnggota($idTenant) : [];
        $idPengguna = $lihatAkun ? $this->anggota->AmbilPetaIdPengguna($idTenant) : [];
        $tertaut = $lihatAkun && $lihatKaryawan ? $this->peta->Ambil(array_values($idPengguna)) : [];
        /** @var array<string, array<string, mixed>> $karyawan */
        $karyawan = [];

        foreach ($lihatKaryawan ? $this->karyawan->AmbilSemua($lihatGaji) : [] as $baris) {
            $karyawan[(string) $baris['Uuid']] = $baris;
        }

        $dipakai = [];
        $hasil = [];

        foreach ($anggota as $a) {
            $uuidKaryawan = $tertaut[$idPengguna[$a['Uuid']] ?? 0]['Uuid'] ?? null;
            $k = $uuidKaryawan === null ? null : ($karyawan[$uuidKaryawan] ?? null);

            if ($uuidKaryawan !== null) {
                $dipakai[$uuidKaryawan] = true;
            }

            $hasil[] = [
                'Kunci' => $a['Uuid'],
                'Nama' => $a['Nama'],
                'Jabatan' => $k['Jabatan'] ?? null,
                'Email' => $a['Email'],
                'Pemilik' => $a['Pemilik'],
                'UuidPengguna' => $a['Uuid'],
                'StatusAkun' => $a['Status'],
                'UuidPeran' => $a['UuidPeran'],
                'NamaPeran' => $a['NamaPeran'],
                'SemuaOutlet' => $a['SemuaOutlet'],
                'UuidOutlet' => $a['UuidOutlet'],
                'Karyawan' => $k,
                'Status' => self::GabungStatus($a['Status'], $k['Status'] ?? null),
            ];
        }

        foreach ($karyawan as $uuid => $k) {
            if (isset($dipakai[$uuid])) {
                continue;
            }

            $hasil[] = [
                'Kunci' => $uuid,
                'Nama' => $k['Nama'],
                'Jabatan' => $k['Jabatan'],
                'Email' => null,
                'Pemilik' => false,
                'UuidPengguna' => null,
                'StatusAkun' => null,
                'UuidPeran' => null,
                'NamaPeran' => null,
                'SemuaOutlet' => false,
                'UuidOutlet' => [],
                'Karyawan' => $k,
                'Status' => self::GabungStatus(null, $k['Status']),
            ];
        }

        usort($hasil, fn (array $x, array $y): int => [$x['Status'] === 'Nonaktif', mb_strtolower((string) $x['Nama'])] <=> [$y['Status'] === 'Nonaktif', mb_strtolower((string) $y['Nama'])]);

        return $hasil;
    }

    /** Aktif bila semua bagian aktif, Nonaktif bila semua nonaktif, selain itu Sebagian (mis. akun aktif tetapi karyawan dinonaktifkan). */
    private static function GabungStatus(?string $akun, ?string $karyawan): string
    {
        $bagian = array_values(array_filter([$akun, $karyawan], fn (?string $s): bool => $s !== null));

        if ($bagian === [] || ! in_array('Aktif', $bagian, true)) {
            return 'Nonaktif';
        }

        return in_array('Nonaktif', $bagian, true) ? 'Sebagian' : 'Aktif';
    }
}
