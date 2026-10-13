<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Surel;

use App\Domain\Bersama\Surel\SurelDasar;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\TahapPengingatTagihan;
use App\Domain\Tenant\Layanan\PenyusunPesanKomunikasi;

/**
 * Pengingat tagihan langganan ke Owner (P-08 langkah 2 & 4): H-7 (tagihan terbit), H-3, H0, H+3. Satu templat dengan
 * kalimat per tahap supaya isi rincian (nomor, jumlah, jatuh tempo, tautan bayar) selalu sama. Subjek, judul, dan kalimat
 * dirakit `PenyusunPesanKomunikasi` (PGL-07): versi terbit templat dari konsol, atau teks bawaan yang sama dengan teks
 * sebelum fitur templat ada.
 */
final class PengingatTagihanLangganan extends SurelDasar
{
    public function __construct(
        public readonly TahapPengingatTagihan $tahap,
        public readonly string $nama,
        public readonly string $nomorTagihan,
        public readonly string $total,
        public readonly string $namaPaket,
        public readonly string $jatuhTempo,
        public readonly ?string $tanggalDitangguhkan,
    ) {
        $tautan = rtrim((string) config('app.url'), '/').'/kelola/langganan';
        $pesan = app(PenyusunPesanKomunikasi::class)->Susun(
            PeristiwaKomunikasi::DariTahap($tahap),
            KanalKomunikasi::Email,
            self::SusunVariabel($tahap, $nama, $nomorTagihan, $total, $namaPaket, $jatuhTempo, $tanggalDitangguhkan, $tautan),
        );

        $this->subject((string) $pesan->subjek)
            ->IsiSurel('Tenant.PengingatTagihanLangganan', [
                'Judul' => (string) $pesan->judul,
                'Kalimat' => $pesan->isi,
                'Nama' => $nama,
                'NomorTagihan' => $nomorTagihan,
                'Total' => $total,
                'NamaPaket' => $namaPaket,
                'JatuhTempo' => $jatuhTempo,
                'Tautan' => $tautan,
            ]);
    }

    /**
     * Nilai semua variabel templat `PeristiwaKomunikasi` untuk satu tagihan (email dan WhatsApp memakai yang sama).
     *
     * @return array<string, string>
     */
    public static function SusunVariabel(TahapPengingatTagihan $tahap, string $nama, string $nomorTagihan, string $total, string $namaPaket, string $jatuhTempo, ?string $tanggalDitangguhkan, string $tautan): array
    {
        $catatan = $tahap !== TahapPengingatTagihan::HPlus3 ? '' : ($tanggalDitangguhkan === null
            ? 'Selesaikan pembayaran untuk mengaktifkan paket.'
            : "Bila belum dibayar, layanan akan ditangguhkan pada {$tanggalDitangguhkan}.");

        return [
            'NamaPemilik' => $nama,
            'NomorTagihan' => $nomorTagihan,
            'NamaPaket' => $namaPaket,
            'Total' => $total,
            'JatuhTempo' => $jatuhTempo,
            'TanggalDitangguhkan' => $tanggalDitangguhkan ?? '',
            'CatatanPenangguhan' => $catatan,
            'Kalimat' => self::AmbilTeks($tahap, $nomorTagihan, $namaPaket, $jatuhTempo, $tanggalDitangguhkan)[2],
            'Tautan' => $tautan,
        ];
    }

    /**
     * Subjek, judul, dan kalimat pembuka per tahap; juga dipakai isi pesan WhatsApp.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function AmbilTeks(TahapPengingatTagihan $tahap, string $nomor, string $paket, string $jatuhTempo, ?string $tanggalDitangguhkan): array
    {
        return match ($tahap) {
            TahapPengingatTagihan::HMinus7 => [
                "Tagihan {$nomor} sudah terbit",
                'Tagihan langganan Anda sudah terbit',
                "Tagihan langganan paket {$paket} sudah terbit. Bayar paling lambat {$jatuhTempo} supaya layanan tetap berjalan tanpa jeda.",
            ],
            TahapPengingatTagihan::HMinus3 => [
                "Tagihan {$nomor} jatuh tempo 3 hari lagi",
                'Tagihan Anda jatuh tempo 3 hari lagi',
                "Tagihan langganan paket {$paket} jatuh tempo pada {$jatuhTempo}. Bayar sekarang supaya layanan tidak terganggu.",
            ],
            TahapPengingatTagihan::HariH => [
                "Tagihan {$nomor} jatuh tempo hari ini",
                'Hari ini batas pembayaran tagihan Anda',
                "Hari ini ({$jatuhTempo}) batas pembayaran tagihan langganan paket {$paket}.",
            ],
            TahapPengingatTagihan::HPlus3 => [
                "Tagihan {$nomor} sudah lewat jatuh tempo",
                'Tagihan Anda sudah lewat jatuh tempo',
                $tanggalDitangguhkan === null
                    ? "Tagihan langganan paket {$paket} sudah lewat jatuh tempo sejak {$jatuhTempo}. Selesaikan pembayaran untuk mengaktifkan paket."
                    : "Tagihan langganan paket {$paket} sudah lewat jatuh tempo sejak {$jatuhTempo}. Bila belum dibayar, layanan akan ditangguhkan pada {$tanggalDitangguhkan}.",
            ],
        };
    }
}
