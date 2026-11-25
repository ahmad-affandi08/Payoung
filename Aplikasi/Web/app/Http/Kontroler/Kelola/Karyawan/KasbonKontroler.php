<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Karyawan\Aksi\KelolaKasbon;
use App\Domain\Karyawan\Kueri\DaftarKaryawan;
use App\Domain\Karyawan\Kueri\DaftarKasbon;
use App\Domain\Karyawan\Model\Kasbon;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Kasbon karyawan (F-18 bagian 3, `/kelola/karyawan/kasbon`): seluruhnya `karyawan.gaji.kelola` (D-327), karena mengeluarkan
 * uang dari kas/bank untuk karyawan mana pun.
 */
final class KasbonKontroler extends DasarKelolaKontroler
{
    private const ATURAN_UANG = ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'];

    public function Daftar(Request $permintaan, DaftarKasbon $daftar, DaftarKaryawan $karyawan, DaftarAkunPilihan $akun, AksesPengguna $akses): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarKasbon::KOLOM_URUT, DaftarKasbon::URUT_BAWAAN, DaftarKasbon::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Karyawan/Kasbon', 'Kasbon', fn (): array => $daftar->Ambil($tabel), fn (): array => [
            'TotalSisa' => $daftar->HitungTotalSisa(),
            'OpsiKaryawan' => $karyawan->AmbilPilihan(),
            'OpsiAkunKasBank' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Nama' => $a['Kode'].' '.$a['Nama']], $akun->AmbilKasBank()),
            'Izin' => ['Kelola' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::KaryawanGajiKelola)],
        ]);
    }

    public function Simpan(Request $permintaan, KelolaKasbon $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Karyawan' => ['required', 'string', 'size:26'],
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => self::ATURAN_UANG,
            'AkunKasBank' => ['required', 'string', 'size:26'],
            'Keterangan' => ['nullable', 'string', 'max:255'],
        ], self::Pesan());
        $kasbon = $kelola->Catat(
            $data['Karyawan'],
            self::Tanggal($data['Tanggal']),
            Uang::Dari($data['Jumlah']),
            $data['AkunKasBank'],
            self::Teks($data['Keterangan'] ?? null),
            $this->Pelaku()->Id,
        );

        return to_route('kelola.karyawan.kasbon')->with('Kilat', 'Kasbon '.Uang::Dari($kasbon->Jumlah)->FormatRupiah().' dicatat.');
    }

    public function Lunasi(string $kasbon, Request $permintaan, KelolaKasbon $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'Jumlah' => self::ATURAN_UANG,
            'AkunKasBank' => ['required', 'string', 'size:26'],
            'Keterangan' => ['nullable', 'string', 'max:255'],
        ], self::Pesan());
        $kelola->Lunasi(
            Kasbon::query()->where('Uuid', $kasbon)->firstOrFail(),
            self::Tanggal($data['Tanggal']),
            Uang::Dari($data['Jumlah']),
            $data['AkunKasBank'],
            self::Teks($data['Keterangan'] ?? null),
            $this->Pelaku()->Id,
        );

        return to_route('kelola.karyawan.kasbon')->with('Kilat', 'Pelunasan kasbon dicatat.');
    }

    public function Batalkan(string $kasbon, Request $permintaan, KelolaKasbon $kelola): RedirectResponse
    {
        $permintaan->validate(['Alasan' => ['required', 'string', 'max:500']], ['Alasan.*' => 'Tulis alasan membatalkan kasbon.']);
        $kelola->Batalkan(Kasbon::query()->where('Uuid', $kasbon)->firstOrFail(), $permintaan->string('Alasan')->toString(), $this->Pelaku()->Id);

        return to_route('kelola.karyawan.kasbon')->with('Kilat', 'Kasbon dibatalkan.');
    }

    /**
     * @return array<string, string>
     */
    private static function Pesan(): array
    {
        return [
            'Karyawan.*' => 'Pilih karyawan.',
            'Tanggal.*' => 'Isi tanggal.',
            'Jumlah.*' => 'Isi jumlah dalam rupiah, misal 500000.',
            'AkunKasBank.*' => 'Pilih akun kas atau bank.',
            'Keterangan.*' => 'Keterangan paling panjang 255 karakter.',
        ];
    }

    private static function Tanggal(string $tanggal): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $tanggal) ?: CarbonImmutable::today();
    }

    private static function Teks(mixed $nilai): ?string
    {
        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }
}
