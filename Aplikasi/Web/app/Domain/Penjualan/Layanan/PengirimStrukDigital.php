<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Antrean\KunciPengiriman;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Whatsapp\HasilKirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Penjualan\Enum\KanalPesanKeluar;
use App\Domain\Penjualan\Enum\StatusPesanKeluar;
use App\Domain\Penjualan\Kueri\StrukDigitalPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesanKeluar;
use App\Domain\Penjualan\Surel\StrukBelanjaDigital;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mengirim satu `PesanKeluar` struk digital (K3), dipanggil `KirimStrukDigitalTugas` di dalam konteks tenant.
 * WhatsApp: penyedia resmi + nama templat struk → templat [nama usaha, total Rupiah, URL struk]; selain itu teks.
 * Email: `StrukBelanjaDigital`. Kegagalan penyedia dicatat (`PesanGalat` tanpa tujuan & kredensial) dan diulang oleh
 * tugas sampai percobaan terakhir; kegagalan permanen (struk dimatikan, integrasi mati) langsung `Gagal`. Log tidak
 * pernah memuat tujuan.
 */
final class PengirimStrukDigital
{
    public function __construct(
        private readonly StrukDigitalPenjualan $struk,
        private readonly PembuatPengirimWhatsapp $whatsapp,
    ) {}

    /** @return bool true bila perlu dicoba ulang. */
    public function Kirim(int $idPesanKeluar, bool $percobaanTerakhir): bool
    {
        // Satu pengirim aktif per pesan (lihat `KunciPengiriman`).
        return KunciPengiriman::Jalankan('struk-digital:'.$idPesanKeluar, fn (): bool => $this->Proses($idPesanKeluar, $percobaanTerakhir), false);
    }

    /** @return bool true bila perlu dicoba ulang. */
    private function Proses(int $idPesanKeluar, bool $percobaanTerakhir): bool
    {
        $pesan = PesanKeluar::query()->find($idPesanKeluar);

        if ($pesan === null || $pesan->Status !== StatusPesanKeluar::Diantrekan) {
            return false;
        }

        $pesan->Percobaan++;
        $uuidPenjualan = Penjualan::query()->whereKey($pesan->IdReferensi)->value('Uuid');
        $isi = is_string($uuidPenjualan) ? $this->struk->Ambil($pesan->IdTenant, $uuidPenjualan) : null;

        if ($isi === null) {
            $this->TandaiGagal($pesan, 'Struk digital tidak tersedia (dimatikan di pengaturan struk).');

            return false;
        }

        $tautan = url('/s/'.KodeStrukDigital::Buat($pesan->IdTenant, (string) $uuidPenjualan));

        $hasil = $pesan->Kanal === KanalPesanKeluar::Whatsapp
            ? $this->KirimWhatsapp($pesan, $isi, $tautan)
            : $this->KirimEmail($pesan, $isi, $tautan);

        if ($hasil === null) {
            $this->TandaiGagal($pesan, 'Integrasi WhatsApp tidak aktif.');

            return false;
        }

        if ($hasil->berhasil) {
            $pesan->UbahStatus(StatusPesanKeluar::Terkirim);
            $pesan->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $pesan->PesanGalat = null;
            $pesan->TerkirimPada = now();
            $pesan->save();

            return false;
        }

        if ($percobaanTerakhir) {
            $this->TandaiGagal($pesan, $hasil->pesan);

            return false;
        }

        $pesan->PesanGalat = $this->Saring($pesan, $hasil->pesan);
        $pesan->save();
        $this->CatatGalat($pesan);

        return true;
    }

    public function TandaiGagal(PesanKeluar $pesan, string $galat): void
    {
        if ($pesan->Status !== StatusPesanKeluar::Diantrekan) {
            return;
        }

        $pesan->UbahStatus(StatusPesanKeluar::Gagal);
        $pesan->PesanGalat = $this->Saring($pesan, $galat);
        $pesan->save();
        $this->CatatGalat($pesan);
    }

    /**
     * @param  array<string, mixed>  $isi
     */
    private function KirimWhatsapp(PesanKeluar $pesan, array $isi, string $tautan): ?HasilKirimWhatsapp
    {
        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return null;
        }

        $namaUsaha = (string) $isi['NamaUsaha'];
        $total = Uang::Dari((string) $isi['TotalAkhir'])->FormatRupiah();
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatStruk() : null;
        $pesan->Penyedia = $pengirim->AmbilKode();

        return $pengirim->Kirim(new PesanWhatsapp(
            $pesan->Tujuan,
            "Terima kasih telah berbelanja di {$namaUsaha}.\nTotal: {$total}\nStruk digital: {$tautan}",
            $templat,
            $templat === null ? [] : [$namaUsaha, $total, $tautan],
        ));
    }

    /**
     * @param  array<string, mixed>  $isi
     */
    private function KirimEmail(PesanKeluar $pesan, array $isi, string $tautan): HasilKirimWhatsapp
    {
        /** @var list<array{NamaProduk: string, Jumlah: string, Total: string}> $baris */
        $baris = $isi['Baris'];
        $pesan->Penyedia = mb_substr((string) config('mail.default'), 0, 30);
        $surel = new StrukBelanjaDigital(
            (string) $isi['NamaUsaha'],
            is_string($isi['NamaOutlet']) ? $isi['NamaOutlet'] : null,
            (string) $isi['Nomor'],
            CarbonImmutable::parse((string) $isi['Waktu'])->timezone('Asia/Jakarta')->translatedFormat('d F Y H.i').' WIB',
            array_map(fn (array $b): array => [
                'Nama' => $b['NamaProduk'],
                'Jumlah' => str_replace('.', ',', (string) Kuantitas::Dari($b['Jumlah'])->KeDesimal()->strippedOfTrailingZeros()),
                'Total' => Uang::Dari($b['Total'])->FormatRupiah(),
            ], $baris),
            Uang::Dari((string) $isi['TotalAkhir'])->FormatRupiah(),
            $isi['Dibatalkan'] === true,
            $tautan,
        );

        try {
            $terkirim = Mail::to($pesan->Tujuan)->send($surel);
        } catch (Exception $galat) {
            // Galat transport (SMTP menolak, koneksi putus): dicoba ulang; pesannya disaring sebelum disimpan.
            return HasilKirimWhatsapp::Gagal('Email gagal dikirim: '.$galat->getMessage());
        }

        return HasilKirimWhatsapp::Berhasil($terkirim?->getMessageId());
    }

    /** Pesan galat aman disimpan & ditampilkan: tanpa tujuan pelanggan dan tanpa kredensial email platform. */
    private function Saring(PesanKeluar $pesan, string $galat): string
    {
        $rahasia = [$pesan->Tujuan];

        if ($pesan->Kanal === KanalPesanKeluar::Whatsapp) {
            $lokal = substr($pesan->Tujuan, 2);
            array_push($rahasia, '+'.$pesan->Tujuan, '0'.$lokal, $lokal);
        } else {
            foreach (['username', 'password'] as $kunci) {
                $nilai = config("mail.mailers.smtp.{$kunci}");

                if (is_string($nilai) && $nilai !== '') {
                    $rahasia[] = $nilai;
                }
            }
        }

        usort($rahasia, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($rahasia as $nilai) {
            $galat = str_ireplace($nilai, '••••', $galat);
        }

        return mb_substr($galat, 0, PesanKeluar::PANJANG_PESAN_GALAT);
    }

    private function CatatGalat(PesanKeluar $pesan): void
    {
        Log::warning('Struk digital gagal dikirim.', [
            'IdTenant' => $pesan->IdTenant,
            'IdPesanKeluar' => $pesan->Id,
            'Kanal' => $pesan->Kanal->value,
            'Penyedia' => $pesan->Penyedia,
            'Percobaan' => $pesan->Percobaan,
            'Status' => $pesan->Status->value,
            'Galat' => $pesan->PesanGalat,
        ]);
    }
}
