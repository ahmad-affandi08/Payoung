<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Layanan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pembelian\Enum\StatusPengirimanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PengirimanPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim satu `PengirimanPesananPembelian` lewat WhatsApp, dipanggil `KirimPesananPembelianTugas` di dalam konteks
 * tenant. PO yang dibatalkan/ditutup saat akan dikirim → `Dibatalkan` (pemasok tidak dikabari pesanan yang sudah
 * batal). Penyedia resmi + templat PO → templat [toko, nomor, ringkasan barang, total]; selain itu teks lengkap.
 * Kegagalan penyedia dicoba ulang oleh tugas; pesan galat disaring dari nomor tujuan. Log tidak memuat nomor.
 */
final class PengirimPesananPembelian
{
    /** Banyak baris barang yang ditulis di pesan; sisanya diringkas "dan N barang lainnya". */
    public const MAKS_BARIS_PESAN = 12;

    public function __construct(private readonly PembuatPengirimWhatsapp $whatsapp) {}

    /** @return bool true bila perlu dicoba ulang. */
    public function Kirim(int $idPengiriman, bool $percobaanTerakhir): bool
    {
        $pengiriman = PengirimanPesananPembelian::query()->find($idPengiriman);

        if ($pengiriman === null || $pengiriman->Status !== StatusPengirimanPesananPembelian::Diantrekan) {
            return false;
        }

        $pengiriman->Percobaan++;
        $po = PesananPembelian::query()->with('Pemasok')->find($pengiriman->IdPesananPembelian);

        if ($po === null || in_array($po->Status, [StatusPesananPembelian::Dibatalkan, StatusPesananPembelian::Ditutup], true)) {
            $this->Akhiri($pengiriman, StatusPengirimanPesananPembelian::Dibatalkan, 'Pesanan dibatalkan atau ditutup sebelum terkirim.');

            return false;
        }

        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            $this->Akhiri($pengiriman, StatusPengirimanPesananPembelian::Gagal, 'Integrasi WhatsApp tidak aktif.');

            return false;
        }

        $namaUsaha = app(NamaTampilUsaha::class)->UntukTenant($pengiriman->IdTenant);
        $detail = PesananPembelianDetail::query()->where('IdPesananPembelian', $po->Id)->orderBy('Id')->get();
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatPesananPembelian() : null;
        $pengiriman->Penyedia = $pengirim->AmbilKode();
        $hasil = $pengirim->Kirim(new PesanWhatsapp(
            $pengiriman->Tujuan,
            self::SusunTeks($namaUsaha, $po, $detail),
            $templat,
            $templat === null ? [] : [$namaUsaha, $po->Nomor, self::RingkasBarang($detail), Uang::Dari($po->Total)->FormatRupiah()],
        ));

        if ($hasil->berhasil) {
            $pengiriman->UbahStatus(StatusPengirimanPesananPembelian::Terkirim);
            $pengiriman->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $pengiriman->PesanGalat = null;
            $pengiriman->TerkirimPada = now();
            $pengiriman->save();

            return false;
        }

        if ($percobaanTerakhir) {
            $this->Akhiri($pengiriman, StatusPengirimanPesananPembelian::Gagal, $hasil->pesan);

            return false;
        }

        $pengiriman->PesanGalat = $this->Saring($pengiriman, $hasil->pesan);
        $pengiriman->save();
        $this->CatatGalat($pengiriman);

        return true;
    }

    public function Akhiri(PengirimanPesananPembelian $pengiriman, StatusPengirimanPesananPembelian $status, string $galat): void
    {
        if ($pengiriman->Status !== StatusPengirimanPesananPembelian::Diantrekan) {
            return;
        }

        $pengiriman->UbahStatus($status);
        $pengiriman->PesanGalat = $this->Saring($pengiriman, $galat);
        $pengiriman->save();

        if ($status === StatusPengirimanPesananPembelian::Gagal) {
            $this->CatatGalat($pengiriman);
        }
    }

    /**
     * Teks pesan ke pemasok: salam, nomor & tanggal PO, daftar barang (maks [MAKS_BARIS_PESAN]), total, dan permintaan konfirmasi.
     *
     * @param  iterable<PesananPembelianDetail>  $detail
     */
    public static function SusunTeks(string $namaUsaha, PesananPembelian $po, iterable $detail): string
    {
        $nama = $po->Pemasok->NamaKontak ?? $po->Pemasok->Nama;
        $baris = [];
        $jumlah = 0;

        foreach ($detail as $d) {
            $jumlah++;

            if ($jumlah <= self::MAKS_BARIS_PESAN) {
                $baris[] = '- '.$d->NamaProduk.' x '.self::FormatJumlah(Kuantitas::Dari($d->Jumlah)->KeString()).' '.$d->SimbolSatuan;
            }
        }

        if ($jumlah > self::MAKS_BARIS_PESAN) {
            $baris[] = '- dan '.($jumlah - self::MAKS_BARIS_PESAN).' barang lainnya';
        }

        $tiba = $po->PerkiraanTiba === null ? '' : "\nMohon dikirim paling lambat {$po->PerkiraanTiba->translatedFormat('j F Y')}.";

        return "Halo {$nama}, ini pesanan pembelian dari {$namaUsaha}.\nNo. {$po->Nomor} | {$po->Tanggal->translatedFormat('j F Y')}\n"
            .implode("\n", $baris)
            ."\nTotal: ".Uang::Dari($po->Total)->FormatRupiah().$tiba
            ."\nMohon konfirmasi ketersediaan barang. Terima kasih.";
    }

    /**
     * @param  iterable<PesananPembelianDetail>  $detail
     */
    public static function RingkasBarang(iterable $detail): string
    {
        $nama = [];

        foreach ($detail as $d) {
            $nama[] = $d->NamaProduk;
        }

        $teks = implode(', ', array_slice($nama, 0, 3));

        return count($nama) > 3 ? $teks.' dan '.(count($nama) - 3).' lainnya' : $teks;
    }

    /** "12.0000" → "12"; "1.5000" → "1,5". */
    public static function FormatJumlah(string $jumlah): string
    {
        return str_contains($jumlah, '.') ? str_replace('.', ',', rtrim(rtrim($jumlah, '0'), '.')) : $jumlah;
    }

    private function Saring(PengirimanPesananPembelian $pengiriman, string $galat): string
    {
        $lokal = substr($pengiriman->Tujuan, 2);
        $rahasia = [$pengiriman->Tujuan, '+'.$pengiriman->Tujuan, '0'.$lokal, $lokal];
        usort($rahasia, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($rahasia as $nilai) {
            $galat = str_ireplace($nilai, '••••', $galat);
        }

        return mb_substr($galat, 0, PengirimanPesananPembelian::PANJANG_PESAN_GALAT);
    }

    private function CatatGalat(PengirimanPesananPembelian $pengiriman): void
    {
        Log::warning('Pesanan pembelian gagal dikirim ke pemasok.', [
            'IdTenant' => $pengiriman->IdTenant,
            'IdPengirimanPesananPembelian' => $pengiriman->Id,
            'Penyedia' => $pengiriman->Penyedia,
            'Percobaan' => $pengiriman->Percobaan,
            'Status' => $pengiriman->Status->value,
            'Galat' => $pengiriman->PesanGalat,
        ]);
    }
}
