<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\UbahJadwalKasBank;
use App\Domain\Akuntansi\Kueri\DaftarJadwalKasBank;
use App\Domain\Akuntansi\Model\JadwalKasBank;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * D-23 D bagian 2: daftar transaksi kas & bank berulang, hentikan/aktifkan lagi, ubah jumlah (izin lihat laporan
 * keuangan untuk daftar, `akuntansi.kelola` untuk mengubah; jadwal dibuat dari formulir catat transaksi).
 */
final class JadwalKasBankKontroler extends DasarAkuntansiKontroler
{
    public function Daftar(Request $permintaan, DaftarJadwalKasBank $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarJadwalKasBank::KOLOM_URUT, DaftarJadwalKasBank::URUT_BAWAAN, DaftarJadwalKasBank::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Akuntansi/KasBank/Berulang', 'Jadwal', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Ubah(Request $permintaan, string $jadwalKasBank, DaftarJadwalKasBank $daftar, UbahJadwalKasBank $ubah, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $jadwal = $daftar->Cari($jadwalKasBank, $this->IdOutletBoleh());
        abort_unless($jadwal instanceof JadwalKasBank, 404);
        $valid = $permintaan->validate([
            'Aktif' => ['sometimes', 'boolean'],
            'Jumlah' => ['sometimes', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
        ], ['Jumlah.regex' => 'Jumlah harus angka Rupiah, maksimal 2 angka di belakang koma.']);
        $hasil = $ubah->Jalankan(
            $jadwal,
            array_key_exists('Aktif', $valid) ? (bool) $valid['Aktif'] : null,
            isset($valid['Jumlah']) ? Uang::Dari((string) $valid['Jumlah']) : null,
            $tanggal->Hitung(null),
            $this->Pelaku()->Id,
        );

        return back()->with('Kilat', $hasil->Aktif
            ? "Jadwal {$hasil->Keterangan} aktif. Berikutnya dicatat {$hasil->TanggalBerikutnya->toDateString()}."
            : "Jadwal {$hasil->Keterangan} dihentikan. Transaksi yang sudah tercatat tidak berubah.");
    }
}
