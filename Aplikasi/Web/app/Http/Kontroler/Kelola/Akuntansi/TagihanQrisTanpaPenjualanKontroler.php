<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Aksi\BukaKembaliTagihanQris;
use App\Domain\Penjualan\Aksi\TutupTagihanQrisTanpaPenjualan;
use App\Domain\Penjualan\Enum\HasilPenutupanTagihanQris;
use App\Domain\Penjualan\Kueri\DaftarTagihanQrisTanpaPenjualan;
use App\Http\Permintaan\Kelola\Akuntansi\TutupTagihanQrisPermintaan;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Uang QRIS yang masuk tanpa penjualan (E8, `/kelola/akuntansi/qris-tanpa-penjualan`): lihat `laporan.keuangan.lihat`,
 * tutup & buka kembali `akuntansi.kelola`. Butir Kotak Tindakan "Uang QRIS masuk tanpa penjualan" bermuara di sini.
 */
final class TagihanQrisTanpaPenjualanKontroler extends DasarAkuntansiKontroler
{
    public function Daftar(Request $permintaan, DaftarTagihanQrisTanpaPenjualan $daftar, DaftarAkunPilihan $akun): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTagihanQrisTanpaPenjualan::KOLOM_URUT, DaftarTagihanQrisTanpaPenjualan::URUT_BAWAAN, DaftarTagihanQrisTanpaPenjualan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Akuntansi/QrisTanpaPenjualan/Daftar', 'Tagihan', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'OpsiHasil' => array_map(fn (HasilPenutupanTagihanQris $h): array => ['Nilai' => $h->value, 'Label' => $h->AmbilLabel()], HasilPenutupanTagihanQris::cases()),
            'HariIni' => app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d'),
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Tutup(TutupTagihanQrisPermintaan $permintaan, string $tagihan, TutupTagihanQrisTanpaPenjualan $tutup): RedirectResponse
    {
        $uuidAkun = $permintaan->validated('UuidAkun');
        $hasil = $tutup->Jalankan(
            strtoupper($tagihan),
            $permintaan->AmbilHasil(),
            (string) $permintaan->validated('Catatan'),
            is_string($uuidAkun) ? $uuidAkun : null,
            $permintaan->AmbilTanggal(),
            $this->Pelaku()->Id,
        );

        return to_route('kelola.akuntansi.qris-tanpa-penjualan.daftar', ['cari' => $hasil->Uuid])
            ->with('Kilat', "Tagihan {$hasil->NomorPesanan} ditutup: {$hasil->HasilPenutupan?->AmbilLabel()}.");
    }

    public function BukaKembali(AlasanPembelianPermintaan $permintaan, string $tagihan, BukaKembaliTagihanQris $buka): RedirectResponse
    {
        $hasil = $buka->Jalankan(strtoupper($tagihan), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.akuntansi.qris-tanpa-penjualan.daftar', ['cari' => $hasil->Uuid])
            ->with('Kilat', "Penutupan tagihan {$hasil->NomorPesanan} dibuka kembali.");
    }
}
