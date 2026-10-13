<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Model\PesanKeluar;
use App\Domain\Penjualan\Tugas\KirimStrukDigitalTugas;
use Illuminate\Support\Facades\DB;

/**
 * Mengirim ulang `PesanKeluar` yang berstatus `Gagal` (alat bantu dukungan, PGL-16 bagian 2). Status akhir pesan lama tidak
 * berubah (`StatusPesanKeluar`); yang dibuat adalah pesan baru ke tujuan yang sama untuk dokumen yang sama, lalu
 * `KirimStrukDigitalTugas` diantrekan setelah commit (aturan #10). Ditolak bila pesan lama tidak `Gagal`, kanalnya belum aktif
 * (WhatsApp tenant mati atau fitur tidak dimiliki), atau sudah ada pesan yang lebih baru ke tujuan itu yang menunggu atau
 * terkirim (mencegah kirim ganda ke pelanggan).
 */
final class KirimUlangPesanKeluar
{
    public function __construct(private readonly AntrekanKirimStrukDigital $antrean) {}

    /**
     * @throws PelanggaranAturanBisnis PesanTidakDitemukan, PesanTidakGagal, KanalTidakAktif, SudahDikirimUlang
     */
    public function Jalankan(string $uuidPesan): PesanKeluar
    {
        return DB::transaction(function () use ($uuidPesan): PesanKeluar {
            $lama = PesanKeluar::query()->where('Uuid', $uuidPesan)->lockForUpdate()->first();

            if (! $lama instanceof PesanKeluar) {
                throw new PelanggaranAturanBisnis('PesanTidakDitemukan', 'Pesan tidak ditemukan di tenant ini.');
            }

            if ($lama->Status !== StatusPesanKeluar::Gagal) {
                throw new PelanggaranAturanBisnis('PesanTidakGagal', 'Hanya pesan yang gagal terkirim yang bisa dikirim ulang.');
            }

            if (! $this->antrean->CekKanalAktif($lama->IdTenant, $lama->Kanal)) {
                throw new PelanggaranAturanBisnis('KanalTidakAktif', 'WhatsApp belum aktif untuk usaha ini (integrasi mati atau fitur tidak dimiliki), jadi pesan belum bisa dikirim ulang.');
            }

            $tujuan = $lama->Tujuan;
            $sudah = PesanKeluar::query()
                ->where('Jenis', $lama->Jenis->value)
                ->where('IdReferensi', $lama->IdReferensi)
                ->where('Kanal', $lama->Kanal->value)
                ->where('Id', '>', $lama->Id)
                ->whereIn('Status', [StatusPesanKeluar::Diantrekan->value, StatusPesanKeluar::Terkirim->value])
                ->get()
                ->contains(fn (PesanKeluar $p): bool => $p->Tujuan === $tujuan);

            if ($sudah) {
                throw new PelanggaranAturanBisnis('SudahDikirimUlang', 'Pesan ke tujuan ini sudah dikirim ulang atau sedang diantrekan.');
            }

            $baru = PesanKeluar::query()->create([
                'IdOutlet' => $lama->IdOutlet,
                'IdPerangkat' => $lama->IdPerangkat,
                'Kanal' => $lama->Kanal,
                'Jenis' => $lama->Jenis,
                'IdReferensi' => $lama->IdReferensi,
                'Tujuan' => $tujuan,
                'Status' => StatusPesanKeluar::Diantrekan,
                'Percobaan' => 0,
            ]);

            KirimStrukDigitalTugas::dispatch($lama->IdTenant, $baru->Id)->afterCommit();

            return $baru;
        });
    }
}
