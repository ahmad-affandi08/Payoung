<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Aksi\SimpanKaryawan;
use App\Domain\Karyawan\Aksi\TambahStaf;
use App\Domain\Karyawan\Aksi\UbahStatusKaryawan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Kueri\DaftarTim;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Aksi\UbahStatusAnggota;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\TambahTimPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tim (D-101, F-02 & F-18): satu pintu untuk orang yang bekerja di usaha — daftar tunggal, satu formulir tambah, dan
 * nonaktif/aktif sekaligus untuk akun & data karyawannya. Akun (peran, outlet, PIN) dan data karyawan (jadwal,
 * absensi, gaji) tetap dua entitas dengan izin sendiri-sendiri; ubah akses, PIN, dan data karyawan memakai rute
 * Pengguna & Karyawan yang sudah ada.
 */
final class TimKontroler extends DasarKelolaKontroler
{
    public function Daftar(DaftarTim $tim, DaftarAnggota $anggota, PemakaianBatasOrganisasi $pemakaian, PastikanBatasPaket $batasPaket, PetaUuidOutlet $outlet, AksesPengguna $akses, PenyaringSektor $sektor): Response
    {
        $idTenant = $this->IdTenant();
        $boleh = fn (IzinTenant $izin): bool => $akses->CekIzin($idTenant, $this->Pelaku()->Id, $izin);
        $lihatAkun = $boleh(IzinTenant::PenggunaLihat);
        $lihatKaryawan = $boleh(IzinTenant::KaryawanLihat);
        $kelolaKaryawan = $boleh(IzinTenant::KaryawanKelola);

        return Inertia::render('Kelola/Tim/Daftar', [
            'Tim' => $tim->Ambil($idTenant, $kelolaKaryawan, $lihatAkun, $lihatKaryawan),
            'Undangan' => $lihatAkun ? $anggota->AmbilUndanganMenunggu($idTenant) : [],
            'Peran' => self::AmbilOpsiPeran($sektor),
            'Outlet' => self::AmbilOpsiOutlet(),
            'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh(), true)),
            'OpsiPengguna' => $kelolaKaryawan ? array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Nama' => $a['Nama']], $anggota->AmbilPilihanAktif($idTenant)) : [],
            'BatasPengguna' => $batasPaket->AmbilRingkasan($idTenant, 'BatasPengguna', $pemakaian->HitungPengguna($idTenant)),
            'UuidSaya' => $this->Pelaku()->Uuid,
            'Izin' => [
                'Tambah' => $boleh(IzinTenant::PenggunaUndang) || $kelolaKaryawan,
                'Undang' => $boleh(IzinTenant::PenggunaUndang),
                'UbahAkses' => $boleh(IzinTenant::PenggunaUbah),
                'Nonaktifkan' => $boleh(IzinTenant::PenggunaNonaktifkan),
                'AturPin' => $boleh(IzinTenant::PenggunaPinAtur),
                'KelolaKaryawan' => $kelolaKaryawan,
            ],
        ]);
    }

    /** Formulir "Tambah anggota tim"; `?karyawan=` memberi akses masuk untuk karyawan yang sudah ada (tanpa mencatat dua kali). */
    public function Buat(Request $permintaan, PemakaianBatasOrganisasi $pemakaian, PastikanBatasPaket $batasPaket, PetaUuidOutlet $outlet, AksesPengguna $akses, PenyaringSektor $sektor): Response
    {
        $idTenant = $this->IdTenant();
        $boleh = fn (IzinTenant $izin): bool => $akses->CekIzin($idTenant, $this->Pelaku()->Id, $izin);
        $bolehAkun = $boleh(IzinTenant::PenggunaUndang);
        $bolehKaryawan = $boleh(IzinTenant::KaryawanKelola);
        $uuidKaryawan = $bolehAkun && $bolehKaryawan ? $permintaan->query('karyawan') : null;
        $tertaut = is_string($uuidKaryawan) ? Karyawan::query()->where('Uuid', strtoupper($uuidKaryawan))->whereNull('IdPengguna')->first(['Uuid', 'Nama', 'Jabatan']) : null;

        return Inertia::render('Kelola/Tim/Tambah', [
            'KaryawanTertaut' => $tertaut === null ? null : ['Uuid' => $tertaut->Uuid, 'Nama' => $tertaut->Nama, 'Jabatan' => $tertaut->Jabatan],
            'BolehAkun' => $bolehAkun,
            'BolehKaryawan' => $bolehKaryawan,
            'Peran' => self::AmbilOpsiPeran($sektor),
            'Outlet' => self::AmbilOpsiOutlet(),
            'OpsiOutlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh(), true)),
            'BatasPengguna' => $batasPaket->AmbilRingkasan($idTenant, 'BatasPengguna', $pemakaian->HitungPengguna($idTenant)),
        ]);
    }

    public function Simpan(TambahTimPermintaan $permintaan, TambahStaf $tambah, SimpanKaryawan $simpan, AksesPengguna $akses): RedirectResponse
    {
        $idTenant = $this->IdTenant();
        $boleh = fn (IzinTenant $izin): bool => $akses->CekIzin($idTenant, $this->Pelaku()->Id, $izin);
        $bolehKaryawan = $boleh(IzinTenant::KaryawanKelola);

        if ($permintaan->AmbilJenisAkses() === 'Tanpa') {
            abort_unless($bolehKaryawan, 403);
            $karyawan = $simpan->Jalankan($permintaan->AmbilRincianKaryawan($this->Pelaku()->Id));

            return redirect()->route('kelola.tim.daftar')->with('Kilat', "{$karyawan->Nama} ditambahkan ke tim tanpa akun masuk. Atur jadwal dan gajinya di menu Tim; kasih akses masuk kapan saja dari daftar tim.");
        }

        abort_unless($boleh(IzinTenant::PenggunaUndang), 403);
        $uuidTertaut = $bolehKaryawan ? $permintaan->AmbilUuidKaryawan() : null;
        $pengguna = $tambah->Jalankan(
            $this->Pelaku(),
            $permintaan->AmbilPenggunaBaru(),
            $permintaan->AmbilAkses(),
            $bolehKaryawan && $permintaan->boolean('JugaKaryawan'),
            $permintaan->AmbilJabatan(),
            $uuidTertaut,
            $bolehKaryawan ? $permintaan->AmbilRincianKaryawan($this->Pelaku()->Id) : null,
        );
        $pesan = $pengguna->CekHanyaKasir()
            ? "{$pengguna->Nama} ditambahkan ke tim. Ia masuk aplikasi kasir dengan PIN yang Anda buat."
            : "{$pengguna->Nama} ditambahkan ke tim. Berikan email & kata sandi awal kepadanya; ia wajib menggantinya saat pertama masuk.";

        return redirect()->route('kelola.tim.daftar')->with('Kilat', $pesan);
    }

    /**
     * Nonaktifkan/aktifkan satu orang: akun dan/atau data karyawannya dalam satu transaksi (gagal salah satu = tidak
     * ada yang berubah). Izin dicek per bagian: akun `pengguna.nonaktifkan`, karyawan `karyawan.kelola`.
     */
    public function Status(Request $permintaan, UbahStatusAnggota $ubahAkun, UbahStatusKaryawan $ubahKaryawan, AksesPengguna $akses): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Aksi' => ['required', 'string', Rule::in(['Nonaktifkan', 'Aktifkan'])],
            'UuidPengguna' => ['nullable', 'ulid'],
            'UuidKaryawan' => ['nullable', 'ulid'],
        ]);
        $uuidPengguna = $valid['UuidPengguna'] ?? null;
        $uuidKaryawan = $valid['UuidKaryawan'] ?? null;

        if ($uuidPengguna === null && $uuidKaryawan === null) {
            throw new PelanggaranAturanBisnis('TimTidakDipilih', 'Pilih orang yang akan diubah statusnya.', 'Aksi');
        }

        $idTenant = $this->IdTenant();
        $nonaktif = $valid['Aksi'] === 'Nonaktifkan';
        abort_if($uuidPengguna !== null && ! $akses->CekIzin($idTenant, $this->Pelaku()->Id, IzinTenant::PenggunaNonaktifkan), 403);
        abort_if($uuidKaryawan !== null && ! $akses->CekIzin($idTenant, $this->Pelaku()->Id, IzinTenant::KaryawanKelola), 403);
        $nama = '';

        DB::transaction(function () use ($uuidPengguna, $uuidKaryawan, $nonaktif, $ubahAkun, $ubahKaryawan, &$nama): void {
            if ($uuidKaryawan !== null) {
                $karyawan = Karyawan::query()->where('Uuid', $uuidKaryawan)->firstOrFail();
                $nama = $karyawan->Nama;
                $ubahKaryawan->Jalankan($karyawan, $nonaktif ? StatusKaryawan::Nonaktif : StatusKaryawan::Aktif, $this->Pelaku()->Id);
            }

            if ($uuidPengguna !== null) {
                $pengguna = Pengguna::query()->where('Uuid', $uuidPengguna)->firstOrFail();
                $anggota = TenantPengguna::query()->where('IdTenant', $this->IdTenant())->where('IdPengguna', $pengguna->Id)->firstOrFail();
                $nama = $pengguna->Nama;
                $ubahAkun->Jalankan($this->Pelaku()->Id, $anggota, $nonaktif ? StatusKeanggotaan::Nonaktif : StatusKeanggotaan::Aktif);
            }
        });

        return back()->with('Kilat', $nonaktif
            ? "{$nama} dinonaktifkan: akun masuk, absensi, dan jadwalnya ikut berhenti. Riwayatnya tetap tersimpan dan bisa diaktifkan kembali."
            : "{$nama} aktif kembali.");
    }

    /**
     * @return array<int, array{Uuid: string, Nama: string, Pemilik: bool, Cakupan: string, IkutAbsensi: bool}>
     */
    private static function AmbilOpsiPeran(PenyaringSektor $sektor): array
    {
        // D-183: peran bawaan sektor lain (Pelayan, Salesman, Apoteker) tidak ditawarkan selama belum beranggota.
        $jumlahAnggota = TenantPengguna::query()->selectRaw('IdPeran, count(*) as Jumlah')->groupBy('IdPeran')->pluck('Jumlah', 'IdPeran');

        return Peran::query()->orderByDesc('Bawaan')->orderBy('Id')->get()
            ->filter(fn (Peran $peran): bool => $sektor->CekPeranTampil($peran->Kode, (bool) $peran->Bawaan, (int) ($jumlahAnggota->get($peran->Id) ?? 0)))
            ->map(fn (Peran $peran): array => [
            'Uuid' => $peran->Uuid,
            'Nama' => $peran->Nama,
            'Pemilik' => $peran->CekPemilik(),
            'Cakupan' => $peran->AmbilCakupanOutlet()->value,
            'IkutAbsensi' => ! $peran->CekPemilik() && (PeranTenantBawaan::tryFrom((string) $peran->Kode)?->AmbilIkutAbsensiBawaan() ?? true),
        ])->values()->all();
    }

    /**
     * @return array<int, array{Uuid: string, Kode: string, Nama: string}>
     */
    private static function AmbilOpsiOutlet(): array
    {
        return Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->orderBy('Nama')->get()
            ->map(fn (Outlet $outlet): array => ['Uuid' => $outlet->Uuid, 'Kode' => $outlet->Kode, 'Nama' => $outlet->Nama])->values()->all();
    }
}
