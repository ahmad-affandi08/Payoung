<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola;

use App\Domain\Pengelola\TimInternal\Aksi\NonaktifkanAnggotaTim;
use App\Domain\Pengelola\TimInternal\Aksi\TambahAnggotaTim;
use App\Domain\Pengelola\TimInternal\Aksi\TetapkanPeran;
use App\Domain\Pengelola\TimInternal\Aksi\UbahAllowlistIpPengelola;
use App\Domain\Pengelola\TimInternal\Aksi\UndangAnggotaTim;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\AllowlistIpPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PenjagaPerangkatTepercaya;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Pengelola\TimInternal\Model\PeranPengelola;
use App\Domain\Pengelola\TimInternal\Model\UndanganPengelola;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\Pengelola\SesiPengelola;
use App\Http\Permintaan\Pengelola\NonaktifkanAnggotaTimPermintaan;
use App\Http\Permintaan\Pengelola\TambahAnggotaTimPermintaan;
use App\Http\Permintaan\Pengelola\TetapkanPeranPermintaan;
use App\Http\Permintaan\Pengelola\UndangAnggotaTimPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manajemen tim internal: daftar, tambah langsung (D-22) atau undang, tetapkan peran, nonaktifkan (P-01 langkah 3, 5, 6).
 */
final class TimInternalKontroler extends Kontroler
{
    public function Daftar(Request $permintaan, AllowlistIpPengelola $allowlist): Response
    {
        $anggota = PenggunaPengelola::query()
            ->with('Peran')
            ->withCount(['PerangkatTepercaya as JumlahPerangkatTepercaya' => fn ($kueri) => $kueri
                ->whereNull('DicabutPada')
                ->where('BerlakuSampai', '>', now())])
            ->orderByDesc('Aktif')
            ->orderBy('Nama')
            ->get()
            ->map(fn (PenggunaPengelola $pengguna): array => [
                'Uuid' => $pengguna->Uuid,
                'Nama' => $pengguna->Nama,
                'Email' => $pengguna->Email,
                'KodePeran' => $pengguna->AmbilKodePeran(),
                'Aktif' => $pengguna->Aktif,
                'DuaFaktorAktif' => $pengguna->CekDuaFaktorAktif(),
                'WajibGantiKataSandi' => $pengguna->WajibGantiKataSandi,
                'TerakhirMasukPada' => $pengguna->TerakhirMasukPada?->toIso8601String(),
                'DinonaktifkanPada' => $pengguna->DinonaktifkanPada?->toIso8601String(),
                'JumlahPerangkatTepercaya' => (int) $pengguna->getAttribute('JumlahPerangkatTepercaya'),
            ]);

        $undangan = UndanganPengelola::query()
            ->whereNull('DiterimaPada')
            ->whereNull('DibatalkanPada')
            ->where('BerlakuSampai', '>', now())
            ->orderByDesc('DibuatPada')
            ->get()
            ->map(fn (UndanganPengelola $undangan): array => [
                'Uuid' => $undangan->Uuid,
                'Email' => $undangan->Email,
                'KodePeran' => $undangan->KodePeran,
                'BerlakuSampai' => $undangan->BerlakuSampai->toIso8601String(),
            ]);

        $peran = PeranPengelola::query()
            ->orderBy('Id')
            ->get()
            ->map(fn (PeranPengelola $peran): array => ['Kode' => $peran->Kode, 'Nama' => $peran->Nama]);

        return Inertia::render('Pengelola/TimInternal/Daftar', [
            'Anggota' => $anggota,
            'Undangan' => $undangan,
            'Peran' => $peran,
            // PGL-01 (D-146): hanya dikirim ke pemegang izin pengaturan IP.
            'BatasanIp' => $this->Pelaku()->PunyaIzin(IzinPengelola::TimBatasanIpKelola)
                ? ['Daftar' => $allowlist->Ambil(), 'IpAnda' => (string) $permintaan->ip()]
                : null,
        ]);
    }

    /** PGL-01 (D-146): ubah daftar IP yang boleh membuka konsol; alamat pelaku sendiri wajib termasuk. */
    public function UbahBatasanIp(Request $permintaan, UbahAllowlistIpPengelola $ubah): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Daftar' => ['nullable', 'string', 'max:5000'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['Daftar' => 'daftar IP', 'Alasan' => 'alasan']);
        $simpan = $ubah->Jalankan($this->Pelaku(), (string) ($valid['Daftar'] ?? ''), (string) $permintaan->ip(), (string) $valid['Alasan']);

        return back()->with('Kilat', $simpan === [] ? 'Pembatasan IP dimatikan.' : 'Pembatasan IP disimpan: '.count($simpan).' entri.');
    }

    /** D-22: tambah anggota langsung dengan kata sandi awal (tanpa email), wajib diganti saat pertama masuk. */
    public function Tambah(TambahAnggotaTimPermintaan $permintaan, TambahAnggotaTim $tambah): RedirectResponse
    {
        /** @var list<string> $kodePeran */
        $kodePeran = $permintaan->array('KodePeran');
        $pengguna = $tambah->Jalankan(
            $this->Pelaku(),
            $permintaan->string('Nama')->toString(),
            $permintaan->string('Email')->toString(),
            $permintaan->string('KataSandi')->toString(),
            $kodePeran,
        );

        return back()->with('Kilat', "{$pengguna->Nama} ditambahkan. Berikan email & kata sandi awal kepadanya; ia wajib menggantinya dan mengaktifkan 2FA saat pertama masuk.");
    }

    public function Undang(UndangAnggotaTimPermintaan $permintaan, UndangAnggotaTim $undang): RedirectResponse
    {
        /** @var list<string> $kodePeran */
        $kodePeran = $permintaan->array('KodePeran');
        $email = $permintaan->string('Email')->toString();

        $undang->Jalankan($this->Pelaku(), $email, $kodePeran);

        return back()->with('Kilat', "Undangan terkirim ke {$email}. Berlaku 48 jam.");
    }

    public function TetapkanPeran(
        PenggunaPengelola $penggunaPengelola,
        TetapkanPeranPermintaan $permintaan,
        TetapkanPeran $tetapkan,
    ): RedirectResponse {
        /** @var list<string> $kodePeran */
        $kodePeran = $permintaan->array('KodePeran');
        $alasan = $permintaan->filled('Alasan') ? $permintaan->string('Alasan')->toString() : null;

        $tetapkan->Jalankan($this->Pelaku(), $penggunaPengelola, $kodePeran, $alasan);

        return back()->with('Kilat', "Peran {$penggunaPengelola->Nama} diperbarui.");
    }

    public function Nonaktifkan(
        PenggunaPengelola $penggunaPengelola,
        NonaktifkanAnggotaTimPermintaan $permintaan,
        NonaktifkanAnggotaTim $nonaktifkan,
    ): RedirectResponse {
        $nonaktifkan->Jalankan($this->Pelaku(), $penggunaPengelola, $permintaan->string('Alasan')->toString());

        return back()->with('Kilat', "{$penggunaPengelola->Nama} dinonaktifkan. Sesinya langsung terputus.");
    }

    /** D-42: Super Admin mencabut semua perangkat tepercaya anggota (laptop hilang, dicurigai), tanpa menonaktifkan. */
    public function CabutPerangkat(PenggunaPengelola $penggunaPengelola, PenjagaPerangkatTepercaya $perangkatTepercaya): RedirectResponse
    {
        $jumlah = $perangkatTepercaya->CabutSemua($penggunaPengelola, 'Dicabut Super Admin dari Tim internal.', $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah > 0
            ? "{$jumlah} perangkat tepercaya {$penggunaPengelola->Nama} dicabut. Login berikutnya wajib kode 2FA."
            : "{$penggunaPengelola->Nama} tidak punya perangkat tepercaya.");
    }

    private function Pelaku(): PenggunaPengelola
    {
        $pengguna = Auth::guard(SesiPengelola::GUARD)->user();
        abort_unless($pengguna instanceof PenggunaPengelola, 403);

        return $pengguna;
    }
}
