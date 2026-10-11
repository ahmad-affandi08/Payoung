<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Karyawan\Aksi\SimpanKaryawan;
use App\Domain\Karyawan\Data\DataKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Aksi\BatalkanUndangan;
use App\Domain\Organisasi\Aksi\UbahAksesAnggota;
use App\Domain\Organisasi\Aksi\UbahAnggotaMassal;
use App\Domain\Organisasi\Aksi\UndangPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\AksesAnggotaPermintaan;
use App\Http\Permintaan\Kelola\UndangPenggunaPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anggota tenant: undangan email, peran & akses outlet, aksi massal, dan catat sebagai karyawan (F-02 langkah 3,
 * BR-02.1). Daftar, tambah langsung (D-22), dan nonaktif/aktif ada di Tim (`TimKontroler`, D-101).
 * Anggota dicari lewat `Uuid` pengguna di dalam tenant aktif; pengguna yang bukan anggota tenant ini → 404.
 */
final class PenggunaKontroler extends DasarKelolaKontroler
{
    /** Halaman penuh "Undang pengguna" (pola sama dengan Tambah produk); kursi paket tetap ditampilkan. */
    public function BuatUndangan(PemakaianBatasOrganisasi $pemakaian, PastikanBatasPaket $batasPaket): Response
    {
        $idTenant = $this->IdTenant();

        return Inertia::render('Kelola/Pengguna/Buat', [
            'Peran' => self::AmbilOpsiPeran(),
            'Outlet' => self::AmbilOpsiOutlet(),
            'BatasPengguna' => $batasPaket->AmbilRingkasan($idTenant, 'BatasPengguna', $pemakaian->HitungPengguna($idTenant)),
        ]);
    }

    /** D-46: catat akun yang sudah ada sebagai karyawan (tautan akun ↔ karyawan), tanpa mengisi nama dua kali. */
    public function CatatSebagaiKaryawan(string $pengguna, SimpanKaryawan $simpan): RedirectResponse
    {
        $model = Pengguna::query()->where('Uuid', $pengguna)->firstOrFail();
        TenantPengguna::query()->where('IdTenant', $this->IdTenant())->where('IdPengguna', $model->Id)->firstOrFail();
        $simpan->Jalankan(new DataKaryawan(
            nama: $model->Nama,
            jabatan: null,
            levelStaf: null,
            gajiPokok: null,
            uuidPengguna: $model->Uuid,
            uuidOutlet: null,
            idPengguna: $this->Pelaku()->Id,
        ));

        return back()->with('Kilat', "{$model->Nama} dicatat sebagai karyawan. Atur jadwal dan gajinya di menu Tim.");
    }

    public function Undang(UndangPenggunaPermintaan $permintaan, UndangPengguna $undang, RingkasanTenant $ringkasan): RedirectResponse
    {
        $email = mb_strtolower(trim($permintaan->string('Email')->toString()));
        $namaTenant = $ringkasan->Ambil([$this->IdTenant()])[0]['Nama'] ?? '';
        $undang->Jalankan($this->Pelaku(), $email, $permintaan->AmbilAkses(), $namaTenant);

        return redirect()->route('kelola.tim.daftar')->with('Kilat', "Undangan terkirim ke {$email}. Berlaku ".config('organisasi.JamBerlakuUndangan').' jam.');
    }

    public function BatalkanUndangan(string $undangan, BatalkanUndangan $batalkan): RedirectResponse
    {
        $baris = UndanganPengguna::query()->where('IdTenant', $this->IdTenant())->where('Uuid', $undangan)->firstOrFail();
        $batalkan->Jalankan($baris);

        return back()->with('Kilat', "Undangan untuk {$baris->Email} dibatalkan.");
    }

    public function UbahAkses(string $pengguna, AksesAnggotaPermintaan $permintaan, UbahAksesAnggota $ubah): RedirectResponse
    {
        [$anggota, $nama] = $this->CariAnggota($pengguna);
        $ubah->Jalankan($this->Pelaku()->Id, $anggota, $permintaan->AmbilAkses());

        return back()->with('Kilat', "Peran & akses {$nama} disimpan.");
    }

    /** Aksi massal anggota terpilih: nonaktifkan, aktifkan kembali, atau ganti peran. Izin dicek per aksi. */
    public function Massal(Request $permintaan, UbahAnggotaMassal $ubah, AksesPengguna $akses): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Aksi' => ['required', 'string', Rule::in(UbahAnggotaMassal::AKSI)],
            'Uuid' => ['required', 'array', 'min:1', 'max:'.UbahAnggotaMassal::MAKS],
            'Uuid.*' => ['required', 'ulid'],
            'UuidPeran' => ['nullable', 'ulid'],
        ], attributes: ['Uuid' => 'pengguna terpilih', 'UuidPeran' => 'peran']);
        $izin = $valid['Aksi'] === 'Peran' ? IzinTenant::PenggunaUbah : IzinTenant::PenggunaNonaktifkan;
        abort_unless($akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, $izin), 403);
        /** @var list<string> $uuid */
        $uuid = array_values($valid['Uuid']);
        $hasil = $ubah->Jalankan($this->Pelaku()->Id, $valid['Aksi'], $uuid, is_string($valid['UuidPeran'] ?? null) ? $valid['UuidPeran'] : null);
        $kata = match ($valid['Aksi']) {
            'Nonaktifkan' => 'dinonaktifkan dan langsung keluar dari usaha ini',
            'Aktifkan' => 'aktif kembali',
            default => 'berganti peran',
        };
        $lewat = $hasil['Dilewati'] > 0 ? " {$hasil['Dilewati']} dilewati karena sudah begitu." : '';

        return back()->with('Kilat', "{$hasil['Diubah']} pengguna {$kata}.{$lewat}");
    }

    /**
     * @return array<int, array{Uuid: string, Nama: string, Pemilik: bool, SemuaOutletBawaan: bool}>
     */
    private static function AmbilOpsiPeran(): array
    {
        return Peran::query()->orderByDesc('Bawaan')->orderBy('Id')->get()->map(fn (Peran $peran): array => [
            'Uuid' => $peran->Uuid,
            'Nama' => $peran->Nama,
            'Pemilik' => $peran->CekPemilik(),
            'SemuaOutletBawaan' => $peran->Kode !== null && (PeranTenantBawaan::tryFrom($peran->Kode)?->CekSemuaOutletBawaan() ?? false),
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

    /**
     * @return array{0: TenantPengguna, 1: string}
     */
    private function CariAnggota(string $uuidPengguna): array
    {
        $pengguna = Pengguna::query()->where('Uuid', $uuidPengguna)->firstOrFail();
        $anggota = TenantPengguna::query()->where('IdTenant', $this->IdTenant())->where('IdPengguna', $pengguna->Id)->firstOrFail();

        return [$anggota, $pengguna->Nama];
    }
}
