<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Kueri\StatusTutupTahun;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Model\KunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Illuminate\Support\Facades\DB;

/**
 * F-15 *reopen*: membuka kunci periode oleh Akuntan (izin `akuntansi.kelola`) dengan alasan wajib yang dicatat audit
 * `akuntansi.periode.buka` (nilai lama: siapa & kapan dikunci). Riwayat penguncian tetap ada di log audit. Bulan di
 * tahun buku yang sudah ditutup (J-15.1) tidak bisa dibuka.
 */
final class BukaKunciPeriode
{
    public const PANJANG_ALASAN_MINIMAL = 10;

    public const PANJANG_ALASAN_MAKSIMAL = 500;

    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly StatusTutupTahun $tutupTahun,
    ) {}

    public function Jalankan(string $periode, string $alasan): void
    {
        KunciPeriodeAkuntansi::UraiPeriode($periode);
        $alasan = trim($alasan);
        $label = PenjagaKunciPeriode::FormatPeriode($periode);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > self::PANJANG_ALASAN_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan membuka kunci periode, '.self::PANJANG_ALASAN_MINIMAL.' sampai '.self::PANJANG_ALASAN_MAKSIMAL.' karakter.', 'Alasan');
        }

        $tahun = (int) substr($periode, 0, 4);

        if ($this->tutupTahun->CekDitutup($tahun)) {
            throw new PelanggaranAturanBisnis('TahunSudahDitutup', "Tahun buku {$tahun} sudah ditutup, jadi kunci {$label} tidak bisa dibuka. Catat koreksinya di tahun berjalan.", 'Periode');
        }

        DB::transaction(function () use ($periode, $alasan, $label, $tahun): void {
            $kunci = KunciPeriode::query()->where('Periode', $periode)->lockForUpdate()->first();

            // Diperiksa ulang SETELAH kunci baris didapat: TutupTahunAkuntansi mengunci semua baris kunci tahun itu, jadi bila
            // penutupan tahun sedang berjalan, aksi ini menunggu lalu melihat hasilnya. Pemeriksaan di luar transaksi saja
            // meloloskan pembukaan kunci pada tahun yang baru saja ditutup (bulannya terbuka di tahun buku yang sudah tutup).
            if ($this->tutupTahun->CekDitutup($tahun)) {
                throw new PelanggaranAturanBisnis('TahunSudahDitutup', "Tahun buku {$tahun} sudah ditutup, jadi kunci {$label} tidak bisa dibuka. Catat koreksinya di tahun berjalan.", 'Periode');
            }

            if ($kunci === null) {
                throw new PelanggaranAturanBisnis('PeriodeTidakTerkunci', "Periode {$label} tidak sedang dikunci.", 'Periode');
            }

            $this->audit->Catat(
                'akuntansi.periode.buka',
                $kunci,
                nilaiLama: ['Periode' => $periode, 'DikunciPada' => $kunci->DikunciPada?->toIso8601String(), 'DikunciOleh' => $kunci->DikunciOleh],
                nilaiBaru: ['Alasan' => $alasan],
            );
            $kunci->delete();
        });
    }
}
