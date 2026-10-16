<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\PengirimanInvoice;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu `PengirimanInvoice` lewat WhatsApp, dipanggil `KirimInvoiceTugas` di dalam konteks tenant. Invoice yang
 * dibatalkan (atau, untuk pengingat, sudah lunas) saat akan dikirim → `Dibatalkan`: pelanggan tidak ditagih yang sudah
 * dibayar. Penyedia resmi + templat invoice → templat [toko, nomor, kalimat ringkas, tautan]; selain itu teks lengkap.
 * Kegagalan penyedia dicoba ulang oleh tugas; pesan galat disaring dari nomor tujuan. Log tidak memuat nomor maupun
 * nama pelanggan. Sukses pada kiriman `Invoice` mengisi `Invoice.TerkirimPada`.
 */
final class PengirimInvoice
{
    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly IdentitasPelanggan $identitas,
        private readonly ProfilTenant $profil,
    ) {}

    /** @return bool true bila perlu dicoba ulang. */
    public function Kirim(int $idPengiriman, bool $percobaanTerakhir): bool
    {
        $pengiriman = PengirimanInvoice::query()->find($idPengiriman);

        if ($pengiriman === null || $pengiriman->Status !== StatusPengirimanInvoice::Diantrekan) {
            return false;
        }

        $pengiriman->Percobaan++;
        $invoice = Invoice::query()->find($pengiriman->IdInvoice);

        if ($invoice === null || $invoice->Status !== StatusInvoice::Terbit) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Dibatalkan, 'Invoice dibatalkan sebelum terkirim.');

            return false;
        }

        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->first();

        if ($pengiriman->Jenis === JenisPengirimanInvoice::Pengingat && ($piutang === null || ! $piutang->Status->CekTerbuka())) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Dibatalkan, 'Invoice sudah lunas atau dibatalkan sebelum pengingat terkirim.');

            return false;
        }

        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            $this->Akhiri($pengiriman, StatusPengirimanInvoice::Gagal, 'Integrasi WhatsApp tidak aktif.');

            return false;
        }

        $zona = $this->profil->Ambil($pengiriman->IdTenant)['ZonaWaktu'];
        $hariIni = CarbonImmutable::now($zona)->startOfDay();
        $namaUsaha = app(NamaTampilUsaha::class)->UntukTenant($pengiriman->IdTenant);
        $namaPelanggan = $this->identitas->AmbilKontak($invoice->IdPelanggan)['Nama'] ?? 'Bapak/Ibu';
        $tautan = url('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik));
        $jumlah = $pengiriman->Jenis === JenisPengirimanInvoice::Pengingat && $piutang !== null ? $piutang->AmbilSisa() : Uang::Dari($invoice->Tagihan);
        $ringkas = self::SusunRingkas($pengiriman->Jenis, $jumlah, $invoice->JatuhTempo->toDateString(), $hariIni->toDateString());
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatInvoice() : null;
        $pengiriman->Penyedia = $pengirim->AmbilKode();
        $hasil = $pengirim->Kirim(new PesanWhatsapp(
            $pengiriman->Tujuan,
            self::SusunTeks($namaUsaha, $namaPelanggan, $invoice, $ringkas, $tautan, $pengiriman->Jenis),
            $templat,
            $templat === null ? [] : [$namaUsaha, (string) $invoice->Nomor, $ringkas, $tautan],
        ));

        if ($hasil->berhasil) {
            $pengiriman->UbahStatus(StatusPengirimanInvoice::Terkirim);
            $pengiriman->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $pengiriman->PesanGalat = null;
            $pengiriman->TerkirimPada = now();
            $pengiriman->save();

            if ($pengiriman->Jenis === JenisPengirimanInvoice::Invoice) {
                $invoice->TerkirimPada = now();
                $invoice->save();
            }

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

    public function Akhiri(PengirimanInvoice $pengiriman, StatusPengirimanInvoice $status, string $galat): void
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

    /**
     * Kalimat ringkas jumlah & jatuh tempo (juga isi variabel {{3}} templat). Pengingat menyesuaikan nada dengan selisih
     * hari ke jatuh tempo: akan jatuh tempo, jatuh tempo hari ini, atau sudah lewat.
     */
    public static function SusunRingkas(JenisPengirimanInvoice $jenis, Uang $jumlah, string $jatuhTempo, string $hariIni): string
    {
        $tanggal = CarbonImmutable::parse($jatuhTempo)->translatedFormat('j F Y');
        $nominal = $jumlah->FormatRupiah();

        if ($jenis === JenisPengirimanInvoice::Invoice) {
            return "tagihan {$nominal}, jatuh tempo {$tanggal}";
        }

        $selisih = (int) CarbonImmutable::parse($hariIni)->diffInDays(CarbonImmutable::parse($jatuhTempo), false);

        return match (true) {
            $selisih > 0 => "sisa tagihan {$nominal} jatuh tempo {$selisih} hari lagi ({$tanggal})",
            $selisih === 0 => "sisa tagihan {$nominal} jatuh tempo hari ini ({$tanggal})",
            default => "sisa tagihan {$nominal} sudah lewat jatuh tempo ".abs($selisih)." hari ({$tanggal})",
        };
    }

    public static function SusunTeks(string $namaUsaha, string $namaPelanggan, Invoice $invoice, string $ringkas, string $tautan, JenisPengirimanInvoice $jenis): string
    {
        $pembuka = $jenis === JenisPengirimanInvoice::Invoice
            ? "Halo {$namaPelanggan}, berikut invoice dari {$namaUsaha}."
            : "Halo {$namaPelanggan}, ini pengingat dari {$namaUsaha}.";
        $perihal = $invoice->Perihal === null || $invoice->Perihal === '' ? '' : "\nPerihal: {$invoice->Perihal}";

        return "{$pembuka}\nInvoice {$invoice->Nomor}{$perihal}\n".ucfirst($ringkas).".\nLihat rincian & cara bayar: {$tautan}\nBila sudah membayar, abaikan pesan ini. Terima kasih.";
    }

    private function Saring(PengirimanInvoice $pengiriman, string $galat): string
    {
        $lokal = substr($pengiriman->Tujuan, 2);
        $rahasia = [$pengiriman->Tujuan, '+'.$pengiriman->Tujuan, '0'.$lokal, $lokal];
        usort($rahasia, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($rahasia as $nilai) {
            $galat = str_ireplace($nilai, '••••', $galat);
        }

        return mb_substr($galat, 0, PengirimanInvoice::PANJANG_PESAN_GALAT);
    }

    private function CatatGalat(PengirimanInvoice $pengiriman): void
    {
        Log::warning('Invoice gagal dikirim ke pelanggan.', [
            'IdTenant' => $pengiriman->IdTenant,
            'IdPengirimanInvoice' => $pengiriman->Id,
            'Penyedia' => $pengiriman->Penyedia,
            'Percobaan' => $pengiriman->Percobaan,
            'Status' => $pengiriman->Status->value,
            'Galat' => $pengiriman->PesanGalat,
        ]);
    }
}
