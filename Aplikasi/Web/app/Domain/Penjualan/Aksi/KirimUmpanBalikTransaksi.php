<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\UmpanBalikTransaksi;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * F7 (CRM-08): pelanggan memberi nilai 1–5 dan komentar opsional (maks. 500 karakter) atas transaksinya dari struk digital.
 * Hanya untuk penjualan yang tidak di-void dan masih dalam `HARI_BERLAKU` hari; satu umpan balik per penjualan, kiriman
 * pertama yang berlaku (kiriman kedua ditolak `UmpanBalikSudahAda`, tidak menimpa). Tanpa data pribadi pengirim.
 */
final class KirimUmpanBalikTransaksi
{
    public function __construct(private readonly PengaturanStrukTenant $pengaturanStruk) {}

    public const HARI_BERLAKU = 30;

    public const PANJANG_KOMENTAR = 500;

    /**
     * @throws PelanggaranAturanBisnis StrukTidakDitemukan, NilaiTidakValid, TransaksiDibatalkan, UmpanBalikDimatikan, UmpanBalikKedaluwarsa, UmpanBalikSudahAda
     */
    public function Jalankan(string $uuidPenjualan, int $nilai, ?string $komentar): UmpanBalikTransaksi
    {
        if ($nilai < 1 || $nilai > 5) {
            throw new PelanggaranAturanBisnis('NilaiTidakValid', 'Pilih nilai 1 sampai 5.', 'Nilai');
        }

        $penjualan = Penjualan::query()->where('Uuid', $uuidPenjualan)->first();

        if (! $penjualan instanceof Penjualan) {
            throw new PelanggaranAturanBisnis('StrukTidakDitemukan', 'Struk tidak ditemukan.', 'Umum');
        }

        if ($penjualan->Status === StatusPenjualan::Void) {
            throw new PelanggaranAturanBisnis('TransaksiDibatalkan', 'Transaksi ini sudah dibatalkan.', 'Umum');
        }

        if (! $this->pengaturanStruk->Ambil($penjualan->IdTenant, $penjualan->IdOutlet)->tampilkanUmpanBalik) {
            throw new PelanggaranAturanBisnis('UmpanBalikDimatikan', 'Penilaian untuk transaksi ini tidak dibuka.', 'Umum');
        }

        if ($penjualan->DibuatOfflinePada->copy()->addDays(self::HARI_BERLAKU)->lessThan(now())) {
            throw new PelanggaranAturanBisnis('UmpanBalikKedaluwarsa', 'Batas waktu memberi penilaian untuk transaksi ini sudah lewat.', 'Umum');
        }

        $teks = $komentar === null ? '' : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $komentar) ?? '');

        try {
            return DB::transaction(function () use ($penjualan, $nilai, $teks): UmpanBalikTransaksi {
                if (UmpanBalikTransaksi::query()->where('IdPenjualan', $penjualan->Id)->exists()) {
                    throw new PelanggaranAturanBisnis('UmpanBalikSudahAda', 'Penilaian untuk transaksi ini sudah dikirim.', 'Umum');
                }

                return UmpanBalikTransaksi::query()->create([
                    'IdPenjualan' => $penjualan->Id,
                    'IdOutlet' => $penjualan->IdOutlet,
                    'IdPelanggan' => $penjualan->IdPelanggan,
                    'Nilai' => $nilai,
                    'Komentar' => $teks === '' ? null : mb_substr($teks, 0, self::PANJANG_KOMENTAR),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new PelanggaranAturanBisnis('UmpanBalikSudahAda', 'Penilaian untuk transaksi ini sudah dikirim.', 'Umum');
        }
    }
}
