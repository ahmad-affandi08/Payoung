<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Data\DataKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Support\Facades\DB;

/**
 * Tambah/ubah karyawan (F-18, izin `karyawan.kelola`). Akun tertaut harus anggota aktif tenant dan hanya boleh
 * tertaut ke satu karyawan (`PenggunaSudahTertaut`); outlet utama opsional. Audit `karyawan.tambah|ubah`.
 */
final class SimpanKaryawan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly DaftarAnggota $anggota,
        private readonly PetaUuidOutlet $outlet,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataKaryawan $data, ?Karyawan $karyawan = null): Karyawan
    {
        $nama = trim($data->nama);

        if ($nama === '' || mb_strlen($nama) > 150) {
            throw new PelanggaranAturanBisnis('NamaTidakValid', 'Isi nama karyawan, paling panjang 150 karakter.', 'Nama');
        }

        $idPengguna = null;
        $profilAbsensi = null;

        if ($data->uuidPengguna !== null) {
            $cocok = array_values(array_filter($this->anggota->AmbilPilihanAktif($this->konteks->Wajib()), fn (array $a): bool => $a['Uuid'] === $data->uuidPengguna));

            if ($cocok === []) {
                throw new PelanggaranAturanBisnis('PenggunaTidakDitemukan', 'Akun yang dipilih bukan anggota aktif usaha ini.', 'UuidPengguna');
            }

            $idPengguna = $cocok[0]['Id'];
            $profilAbsensi = $this->anggota->AmbilProfilAbsensi($this->konteks->Wajib(), $idPengguna);
            // D-46: karyawan yang punya akun memakai nama akun (satu sumber nama, tidak diisi dua kali).
            $nama = mb_substr($cocok[0]['Nama'], 0, 150);
        }

        $idOutlet = null;

        if ($data->uuidOutlet !== null) {
            $idOutlet = $this->outlet->AmbilIdDariUuid([$data->uuidOutlet])[$data->uuidOutlet] ?? null;

            if ($idOutlet === null) {
                throw new PelanggaranAturanBisnis('OutletTidakDitemukan', 'Outlet tidak ditemukan.', 'UuidOutlet');
            }
        }

        $isian = [
            'Nama' => $nama,
            'Jabatan' => self::Bersihkan($data->jabatan, 80),
            'LevelStaf' => self::Bersihkan($data->levelStaf, 40),
            'GajiPokok' => $data->gajiPokok?->KeString(),
            'TarifLemburPerJam' => $data->tarifLemburPerJam?->KeString(),
            'PotonganTerlambatPerMenit' => $data->potonganTerlambatPerMenit?->KeString(),
            'PotonganTidakMasukPerHari' => $data->potonganTidakMasukPerHari?->KeString(),
            'IdPengguna' => $idPengguna,
            'IdOutlet' => $idOutlet,
        ];

        // D-180: absen lewat login, jadi ikut absensi butuh akun beremail. Karyawan baru: bawaan menurut peran (tanpa akun
        // beremail = tidak ikut). Ubah data lewat jalur yang tidak menyebutnya tidak diam-diam menyalakan/mematikannya.
        $bisaAbsen = $profilAbsensi !== null && $profilAbsensi['PunyaEmail'];

        if ($data->ikutAbsensi === true && ! $bisaAbsen && ($karyawan === null || ! $karyawan->IkutAbsensi)) {
            throw new PelanggaranAturanBisnis('AbsensiButuhAkunEmail', 'Ikut absensi butuh akun masuk dengan email (Gmail), karena karyawan absen setelah login. Buat akun dashboard untuknya dulu.', 'IkutAbsensi');
        }

        if ($data->ikutAbsensi !== null) {
            $isian['IkutAbsensi'] = $data->ikutAbsensi;
        } elseif ($karyawan === null) {
            $isian['IkutAbsensi'] = $bisaAbsen && $profilAbsensi['IkutAbsensiBawaan'];
        }

        $idSendiri = $karyawan?->Id;

        return DB::transaction(function () use ($isian, $karyawan, $data, $idPengguna, $idSendiri): Karyawan {
            if ($idPengguna !== null && Karyawan::query()->where('IdPengguna', $idPengguna)->when($idSendiri !== null, fn ($k) => $k->whereKeyNot($idSendiri))->lockForUpdate()->exists()) {
                throw new PelanggaranAturanBisnis('PenggunaSudahTertaut', 'Akun ini sudah tertaut ke karyawan lain.', 'UuidPengguna');
            }

            if ($karyawan === null) {
                $baru = Karyawan::query()->create([...$isian, 'DibuatOleh' => $data->idPengguna]);
                $this->audit->Catat('karyawan.tambah', $baru, nilaiBaru: self::UntukAudit($isian), idPengguna: $data->idPengguna);

                return $baru;
            }

            $terkunci = Karyawan::query()->whereKey($karyawan->Id)->lockForUpdate()->firstOrFail();
            $lama = $terkunci->only(array_keys($isian));
            $terkunci->fill($isian)->save();
            $this->audit->Catat('karyawan.ubah', $terkunci, self::UntukAudit($lama), self::UntukAudit($isian), idPengguna: $data->idPengguna);

            return $terkunci;
        }, 3);
    }

    /**
     * Gaji pokok dan tarif lembur/potongan kehadiran tidak ditulis ke log audit (data pribadi karyawan); hanya tanda terisi.
     *
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private static function UntukAudit(array $nilai): array
    {
        foreach (['GajiPokok', 'TarifLemburPerJam', 'PotonganTerlambatPerMenit', 'PotonganTidakMasukPerHari'] as $kunci) {
            $nilai[$kunci] = ($nilai[$kunci] ?? null) === null ? null : 'diisi';
        }

        return $nilai;
    }

    private static function Bersihkan(?string $teks, int $maks): ?string
    {
        $teks = $teks === null ? '' : trim($teks);

        return $teks === '' ? null : mb_substr($teks, 0, $maks);
    }
}
