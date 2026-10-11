<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\TahapPengingatTagihan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Surel\PengingatTagihanLangganan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * P-08 langkah 2 & 4 / F-19 dunning (PRD v4.04): pengingat tagihan langganan terbuka ke Owner lewat email dan
 * WhatsApp (bila penyedia WhatsApp platform aktif dan Owner punya nomor HP) pada H-7, H-3, H0, dan H+3 terhadap
 * jatuh tempo (tanggal WIB).
 *
 * - Satu tahap paling banyak sekali per tagihan: `PengingatTerakhir` diklaim atomik (hanya naik) **sebelum** dikirim.
 *   Pengiriman yang gagal tidak diulang (lebih baik satu pengingat hilang daripada Owner menerima rentetan pesan);
 *   tahap berikutnya tetap dikirim.
 * - Tahap yang terlewat tidak dikirim berurutan; hanya tahap terbaru yang berlaku (`TahapPengingatTagihan::Tentukan`).
 * - Tagihan buatan Owner sendiri sudah ditandai tahap saat terbit (`PenerbitTagihanLangganan`), jadi Owner tidak
 *   menerima email "tagihan terbit" atas tagihan yang baru dibuatnya.
 * - Tenant berpenanda Uji/Demo/Internal dan langganan Berhenti dilewati.
 */
final class KirimPengingatTagihanLangganan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly PembuatPengirimWhatsapp $whatsapp,
    ) {}

    /**
     * @return int jumlah tagihan yang diingatkan
     */
    public function Jalankan(): int
    {
        $sekarang = CarbonImmutable::now();
        $jumlah = 0;

        $daftar = Langganan::query()
            ->whereHas('Tenant', fn ($kueri) => $kueri->whereNull('Penanda'))
            ->where('Status', '!=', StatusLangganan::Berhenti->value)
            ->orderBy('Id')
            ->get(['Id', 'IdTenant', 'Status', 'PeriodeSelesai']);

        foreach ($daftar as $langganan) {
            $sebelumnya = $this->konteks->Ambil();
            $this->konteks->Atur($langganan->IdTenant);

            try {
                $tagihanTerbuka = TagihanLangganan::query()
                    ->with('Paket')
                    ->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())
                    // D-49/D-98: tagihan add-on & naik paket berumur pendek dan dibatalkan otomatis bila tidak dibayar; tanpa pengingat.
                    ->whereNotIn('Jenis', [JenisTagihanLangganan::Addon->value, JenisTagihanLangganan::NaikPaket->value])
                    ->orderBy('Id')
                    ->get();

                foreach ($tagihanTerbuka as $tagihan) {
                    $jumlah += $this->Ingatkan($tagihan, $langganan, $sekarang) ? 1 : 0;
                }
            } finally {
                $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
            }
        }

        return $jumlah;
    }

    private function Ingatkan(TagihanLangganan $tagihan, Langganan $langganan, CarbonImmutable $sekarang): bool
    {
        $tahap = TahapPengingatTagihan::Tentukan($sekarang, $tagihan->JatuhTempoPada);
        $terakhir = $tagihan->PengingatTerakhir === null ? null : TahapPengingatTagihan::tryFrom($tagihan->PengingatTerakhir);

        if ($tahap === null || ($terakhir !== null && $terakhir->AmbilUrutan() >= $tahap->AmbilUrutan())) {
            return false;
        }

        $klaim = TagihanLangganan::query()
            ->whereKey($tagihan->Id)
            ->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())
            ->where(fn ($kueri) => $kueri->whereNull('PengingatTerakhir')->orWhereIn('PengingatTerakhir', $tahap->AmbilNilaiSebelumnya()))
            ->update(['PengingatTerakhir' => $tahap->value, 'PengingatTerakhirPada' => $sekarang]);

        if ($klaim !== 1) {
            return false;
        }

        $jatuhTempo = $this->FormatTanggal($tagihan->JatuhTempoPada);
        $tanggalDitangguhkan = $langganan->Status === StatusLangganan::Tertunggak && $langganan->PeriodeSelesai !== null
            ? $this->FormatTanggal(CarbonImmutable::instance($langganan->PeriodeSelesai)->addDays((int) config('tagihan.HariMasaTenggang')))
            : null;
        $total = Uang::Dari($tagihan->Total)->FormatRupiah();
        $namaPaket = $tagihan->Paket->Nama;

        foreach ($this->pemilik->AmbilKontak($tagihan->IdTenant) as $kontak) {
            if ($kontak['Email'] !== null) {
                try {
                    Mail::to($kontak['Email'])->send(new PengingatTagihanLangganan($tahap, $kontak['Nama'], $tagihan->Nomor, $total, $namaPaket, $jatuhTempo, $tanggalDitangguhkan));
                } catch (Throwable $galat) {
                    Log::warning('Email pengingat tagihan langganan gagal dikirim.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor, 'Galat' => $galat->getMessage()]);
                }
            }

            if ($kontak['NoHp'] !== null && $kontak['NoHp'] !== '') {
                $this->KirimWhatsapp($tahap, $kontak['Nama'], $kontak['NoHp'], $tagihan, $total, $namaPaket, $jatuhTempo, $tanggalDitangguhkan);
            }
        }

        return true;
    }

    private function KirimWhatsapp(TahapPengingatTagihan $tahap, string $nama, string $noHp, TagihanLangganan $tagihan, string $total, string $namaPaket, string $jatuhTempo, ?string $tanggalDitangguhkan): void
    {
        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return;
        }

        $kalimat = PengingatTagihanLangganan::AmbilTeks($tahap, $tagihan->Nomor, $namaPaket, $jatuhTempo, $tanggalDitangguhkan)[2];
        $tautan = rtrim((string) config('app.url'), '/').'/kelola/langganan';
        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatPengingatTagihan() : null;

        try {
            $hasil = $pengirim->Kirim(new PesanWhatsapp(
                $noHp,
                "Halo {$nama}, {$kalimat}\nTagihan {$tagihan->Nomor}: {$total}.\nBayar di {$tautan}",
                $templat,
                $templat === null ? [] : [$nama, $kalimat, $total, $tautan],
            ));

            if (! $hasil->berhasil) {
                Log::warning('WhatsApp pengingat tagihan langganan gagal.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor]);
            }
        } catch (Throwable $galat) {
            Log::warning('WhatsApp pengingat tagihan langganan gagal.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor, 'Galat' => $galat->getMessage()]);
        }
    }

    private function FormatTanggal(CarbonInterface $waktu): string
    {
        return $waktu->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y');
    }
}
