<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Enum\KeputusanPenawaran;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat keputusan atas penawaran terbit (F-21 bagian 3, D-168): terima atau tolak.
 *
 * - **Pelanggan** (halaman publik `/p/{kode}`): wajib menyebut nama penyetuju (2–100 karakter) dan hanya selama penawaran
 *   masih berlaku. Tidak ada login, jadi nama itu adalah satu-satunya jejak siapa yang menyetujui; ia ditampilkan kepada
 *   penagih apa adanya dan tidak dipakai untuk apa pun lagi.
 * - **Staf** (back-office): mengonfirmasi keputusan yang diterima lewat jalur lain (telepon, email), boleh setelah masa
 *   berlaku lewat; nama penyetuju opsional.
 *
 * Idempoten: keputusan yang sama diulang mengembalikan penawaran apa adanya; keputusan berbeda setelah diputuskan ditolak
 * (`PenawaranSudahDiputuskan`) supaya pelanggan tidak bisa membalik penerimaan yang sudah dipegang penagih.
 */
final class PutuskanPenawaran
{
    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis NamaPemutusWajib, PenawaranTidakDitemukan, PenawaranKedaluwarsa, PenawaranSudahDiputuskan, PenawaranTidakBerlaku
     */
    public function Jalankan(int $idPenawaran, KeputusanPenawaran $keputusan, string $sumber, ?string $nama, ?string $catatan, ?int $idPengguna): Penawaran
    {
        $nama = $nama === null ? null : trim($nama);
        $catatan = $catatan === null ? null : trim($catatan);

        if ($sumber === Penawaran::SUMBER_PELANGGAN && ($nama === null || mb_strlen($nama) < 2)) {
            throw new PelanggaranAturanBisnis('NamaPemutusWajib', 'Tulis nama Anda sebagai penyetuju (minimal 2 huruf).', 'Nama');
        }

        if (($nama !== null && mb_strlen($nama) > 100) || ($catatan !== null && mb_strlen($catatan) > 500)) {
            throw new PelanggaranAturanBisnis('IsianTerlaluPanjang', 'Nama paling banyak 100 karakter dan catatan 500 karakter.', 'Nama');
        }

        return DB::transaction(function () use ($idPenawaran, $keputusan, $sumber, $nama, $catatan, $idPengguna): Penawaran {
            $penawaran = Penawaran::query()->whereKey($idPenawaran)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PenawaranTidakDitemukan', 'Penawaran tidak ditemukan.');
            $tujuan = $keputusan->AmbilStatus();

            if ($penawaran->Status === $tujuan) {
                return $penawaran;
            }

            if ($penawaran->Status !== StatusPenawaran::Terbit) {
                $pesan = match ($penawaran->Status) {
                    StatusPenawaran::Diterima, StatusPenawaran::Ditolak => 'Penawaran ini sudah diputuskan ('.mb_strtolower($penawaran->Status->AmbilLabel()).').',
                    default => 'Penawaran ini tidak berlaku lagi.',
                };

                throw new PelanggaranAturanBisnis(
                    in_array($penawaran->Status, [StatusPenawaran::Diterima, StatusPenawaran::Ditolak], true) ? 'PenawaranSudahDiputuskan' : 'PenawaranTidakBerlaku',
                    $pesan,
                    'Umum',
                    409,
                );
            }

            $hariIni = $this->tanggalBisnis->Hitung($penawaran->IdOutlet)->toDateString();

            if ($sumber === Penawaran::SUMBER_PELANGGAN && $penawaran->BerlakuSampai->toDateString() < $hariIni) {
                throw new PelanggaranAturanBisnis('PenawaranKedaluwarsa', 'Masa berlaku penawaran ini sudah lewat. Hubungi penjual untuk penawaran terbaru.', 'Umum', 409);
            }

            $penawaran->UbahStatus($tujuan);
            $penawaran->fill([
                'SumberKeputusan' => $sumber,
                'NamaPemutus' => $nama === '' ? null : $nama,
                'CatatanKeputusan' => $catatan === '' ? null : $catatan,
                'DiputuskanPada' => CarbonImmutable::now(),
                'DiubahOleh' => $idPengguna ?? $penawaran->DiubahOleh,
            ])->save();

            $this->riwayat->Catat(Penawaran::JENIS_DOKUMEN, $penawaran->Id, StatusPenawaran::Terbit->value, $tujuan->value, $idPengguna, $sumber === Penawaran::SUMBER_PELANGGAN ? 'Diputuskan pelanggan' : null);
            $this->audit->Catat('penawaran.diputuskan', $penawaran, nilaiLama: ['Status' => StatusPenawaran::Terbit->value], nilaiBaru: [
                'Status' => $tujuan->value,
                'Nomor' => $penawaran->Nomor,
                'Sumber' => $sumber,
            ], idPengguna: $idPengguna);

            return $penawaran;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
