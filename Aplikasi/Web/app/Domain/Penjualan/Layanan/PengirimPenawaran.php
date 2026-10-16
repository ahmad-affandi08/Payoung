<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PengirimanPenawaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu `PengirimanPenawaran` lewat WhatsApp, dipanggil `KirimPenawaranTugas` di dalam konteks tenant. Penawaran
 * yang sudah tidak menunggu keputusan saat akan dikirim (dibatalkan, diputuskan) → `Dibatalkan`. Penyedia resmi + templat
 * penawaran → templat [toko, nomor, kalimat ringkas, tautan]; selain itu teks lengkap. Kegagalan penyedia dicoba ulang oleh
 * tugas; pesan galat disaring dari nomor tujuan, dan log tidak memuat nomor maupun nama pelanggan. Sukses mengisi
 * `Penawaran.TerkirimPada`.
 */
final class PengirimPenawaran
{
    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly IdentitasPelanggan $identitas,
    ) {}

    /** @return bool true bila perlu dicoba ulang. */
    public function Kirim(int $idPengiriman, bool $percobaanTerakhir): bool
    {
        $pengiriman = PengirimanPenawaran::query()->find($idPengiriman);

        if ($pengiriman === null || $pengiriman->Status !== StatusPengirimanInvoice::Diantrekan) {
            return false;
        }

        $pengiriman->Percobaan++;
        $penawaran = Penawaran::query()->find($pengiriman->IdPenawaran);

        if ($penawaran === null || $penawaran->Status !== StatusPenawaran::Terbit) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Dibatalkan, 'Penawaran tidak lagi menunggu keputusan sebelum terkirim.');

            return false;
        }

        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Gagal, 'Integrasi WhatsApp tidak aktif.');

            return false;
        }

        $namaUsaha = app(NamaTampilUsaha::class)->UntukTenant($pengiriman->IdTenant);
        $namaPelanggan = $this->identitas->AmbilKontak($penawaran->IdPelanggan)['Nama'] ?? 'Bapak/Ibu';
        $tautan = url('/p/'.KodeInvoicePublik::Buat($penawaran->IdTenant, $penawaran->KodePublik));
        $ringkas = self::SusunRingkas(Uang::Dari($penawaran->Total), $penawaran->BerlakuSampai->toDateString());
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatPenawaran() : null;
        $pengiriman->Penyedia = $pengirim->AmbilKode();
        $hasil = $pengirim->Kirim(new PesanWhatsapp(
            $pengiriman->Tujuan,
            self::SusunTeks($namaUsaha, $namaPelanggan, $penawaran, $ringkas, $tautan),
            $templat,
            $templat === null ? [] : [$namaUsaha, (string) $penawaran->Nomor, $ringkas, $tautan],
        ));

        if ($hasil->berhasil) {
            $pengiriman->UbahStatus(StatusPengirimanInvoice::Terkirim);
            $pengiriman->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $pengiriman->PesanGalat = null;
            $pengiriman->TerkirimPada = now();
            $pengiriman->save();
            $penawaran->TerkirimPada = now();
            $penawaran->save();

            return false;
        }

        if ($percobaanTerakhir) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Gagal, $hasil->pesan);

            return false;
        }

        $pengiriman->PesanGalat = $this->Saring($pengiriman, $hasil->pesan);
        $pengiriman->save();
        $this->CatatGalat($pengiriman);

        return true;
    }

    public function Akhiri(PengirimanPenawaran $pengiriman, StatusPengirimanInvoice $status, string $galat): void
    {
        if ($pengiriman->Status !== StatusPengirimanInvoice::Diantrekan) {
            return;
        }

        $pengiriman->UbahStatus($status);
        $pengiriman->PesanGalat = $this->Saring($pengiriman, $galat);
        $pengiriman->save();

        if ($status === StatusPengirimanInvoice::Gagal) {
            $this->CatatGalat($pengiriman);
        }
    }

    /** Kalimat ringkas total & masa berlaku (juga isi variabel {{3}} templat). */
    public static function SusunRingkas(Uang $total, string $berlakuSampai): string
    {
        return 'total '.$total->FormatRupiah().', berlaku sampai '.CarbonImmutable::parse($berlakuSampai)->translatedFormat('j F Y');
    }

    public static function SusunTeks(string $namaUsaha, string $namaPelanggan, Penawaran $penawaran, string $ringkas, string $tautan): string
    {
        $perihal = $penawaran->Perihal === null || $penawaran->Perihal === '' ? '' : "\nPerihal: {$penawaran->Perihal}";

        return "Halo {$namaPelanggan}, berikut penawaran harga dari {$namaUsaha}.\nPenawaran {$penawaran->Nomor}{$perihal}\n".ucfirst($ringkas).".\nLihat rincian, lalu terima atau tolak di sini: {$tautan}\nTerima kasih.";
    }

    private function Saring(PengirimanPenawaran $pengiriman, string $galat): string
    {
        $lokal = substr($pengiriman->Tujuan, 2);
        $rahasia = [$pengiriman->Tujuan, '+'.$pengiriman->Tujuan, '0'.$lokal, $lokal];
        usort($rahasia, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($rahasia as $nilai) {
            $galat = str_ireplace($nilai, '••••', $galat);
        }

        return mb_substr($galat, 0, PengirimanPenawaran::PANJANG_PESAN_GALAT);
    }

    private function CatatGalat(PengirimanPenawaran $pengiriman): void
    {
        Log::warning('Penawaran gagal dikirim ke pelanggan.', [
            'IdTenant' => $pengiriman->IdTenant,
            'IdPengirimanPenawaran' => $pengiriman->Id,
            'Penyedia' => $pengiriman->Penyedia,
            'Percobaan' => $pengiriman->Percobaan,
            'Status' => $pengiriman->Status->value,
            'Galat' => $pengiriman->PesanGalat,
        ]);
    }
}
