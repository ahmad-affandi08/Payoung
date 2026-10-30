<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Bersama\Antrean\KunciPengiriman;
use App\Domain\Integrasi\Whatsapp\HasilKirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Enum\KanalKampanye;
use App\Domain\Pelanggan\Enum\StatusKampanye;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Enum\StatusPenerimaKampanye;
use App\Domain\Pelanggan\Model\KampanyePesan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PenerimaKampanye;
use App\Domain\Pelanggan\Surel\PesanKampanyePelanggan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mengirim kampanye CRM-07 bertahap (dipanggil `KirimKampanyePesanTugas` di dalam konteks tenant): per giliran paling
 * banyak `UKURAN_GILIRAN` penerima, jeda `JEDA_DETIK` antargiliran (tidak membanjiri penyedia & menjaga nomor
 * WhatsApp dari blokir). **Jam tenang**: hanya mengirim pukul 08.00–20.59 waktu usaha; di luar itu menunggu sampai
 * 08.00. Sebelum dikirim, pelanggan yang sudah berhenti berlangganan/diarsipkan → `Dilewati`. Gagal dicoba sekali
 * lagi di giliran berikutnya, lalu `Gagal`. Log tidak memuat tujuan atau nama pelanggan.
 */
final class PengirimKampanyePesan
{
    public const UKURAN_GILIRAN = 25;

    public const JEDA_DETIK = 60;

    public const MAKS_PERCOBAAN = 2;

    public const JAM_MULAI = 8;

    public const JAM_SELESAI = 21;

    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly ProfilTenant $profil,
    ) {}

    /** @return int|null detik sampai giliran berikutnya; null bila kampanye selesai/berhenti. */
    public function KirimGiliran(int $idKampanye): ?int
    {
        // Satu giliran per kampanye pada satu waktu: dua pekerja untuk kampanye yang sama mengirim penerima yang sama dua kali.
        // Giliran yang kalah kunci berhenti (null); giliran yang memegang kunci yang menjadwalkan giliran berikutnya.
        return KunciPengiriman::Jalankan('kampanye-pesan:'.$idKampanye, fn (): ?int => $this->ProsesGiliran($idKampanye), null, 600);
    }

    /** @return int|null detik sampai giliran berikutnya; null bila kampanye selesai/berhenti. */
    private function ProsesGiliran(int $idKampanye): ?int
    {
        $kampanye = KampanyePesan::query()->find($idKampanye);

        if ($kampanye === null || $kampanye->Status !== StatusKampanye::Berjalan) {
            return null;
        }

        $profil = $this->profil->Ambil($kampanye->IdTenant);
        $namaTampil = app(NamaTampilUsaha::class)->UntukTenant($kampanye->IdTenant);
        $tunggu = self::HitungTungguJamTenang(CarbonImmutable::now()->setTimezone($profil['ZonaWaktu']));

        if ($tunggu > 0) {
            return $tunggu;
        }

        $daftar = PenerimaKampanye::query()
            ->where('IdKampanyePesan', $kampanye->Id)
            ->where('Status', StatusPenerimaKampanye::Diantrekan->value)
            ->orderBy('Id')
            ->limit(self::UKURAN_GILIRAN)
            ->get();

        foreach ($daftar as $penerima) {
            if (KampanyePesan::query()->whereKey($kampanye->Id)->first(['Id', 'Status'])?->Status !== StatusKampanye::Berjalan) {
                return null;
            }

            $this->KirimSatu($kampanye, $penerima, $namaTampil);
        }

        $kampanye->refresh();

        if ($kampanye->Status !== StatusKampanye::Berjalan) {
            return null;
        }

        $sisa = PenerimaKampanye::query()->where('IdKampanyePesan', $kampanye->Id)->where('Status', StatusPenerimaKampanye::Diantrekan->value)->exists();
        self::HitungUlang($kampanye);

        if (! $sisa) {
            $kampanye->UbahStatus(StatusKampanye::Selesai);
            $kampanye->SelesaiPada = now();
        }

        $kampanye->save();

        return $sisa ? self::JEDA_DETIK : null;
    }

    /** Detik sampai pukul 08.00 bila sekarang di luar 08.00–20.59 waktu usaha; 0 bila boleh mengirim. */
    public static function HitungTungguJamTenang(CarbonImmutable $lokal): int
    {
        if ($lokal->hour >= self::JAM_MULAI && $lokal->hour < self::JAM_SELESAI) {
            return 0;
        }

        $mulai = $lokal->setTime(self::JAM_MULAI, 0);

        if ($lokal->hour >= self::JAM_SELESAI) {
            $mulai = $mulai->addDay();
        }

        return max(60, (int) $lokal->diffInSeconds($mulai));
    }

    /** Isi pesan akhir: `{nama}`/`{toko}` diganti, ditutup nama usaha & tautan berhenti berlangganan. */
    public static function SusunTeks(string $isi, string $namaPelanggan, string $namaUsaha, string $tautanBerhenti): string
    {
        $teks = str_replace(['{nama}', '{toko}'], [$namaPelanggan, $namaUsaha], trim($isi));

        return "{$teks}\n\n— {$namaUsaha}\nBerhenti menerima pesan promosi: {$tautanBerhenti}";
    }

    public static function HitungUlang(KampanyePesan $kampanye): void
    {
        $hitung = PenerimaKampanye::query()
            ->where('IdKampanyePesan', $kampanye->Id)
            ->groupBy('Status')
            ->selectRaw('Status, COUNT(*) AS Jumlah')
            ->pluck('Jumlah', 'Status');

        $kampanye->JumlahTerkirim = (int) ($hitung[StatusPenerimaKampanye::Terkirim->value] ?? 0);
        $kampanye->JumlahGagal = (int) ($hitung[StatusPenerimaKampanye::Gagal->value] ?? 0);
        $kampanye->JumlahDilewati = (int) ($hitung[StatusPenerimaKampanye::Dilewati->value] ?? 0);
    }

    private function KirimSatu(KampanyePesan $kampanye, PenerimaKampanye $penerima, string $namaUsaha): void
    {
        $pelanggan = Pelanggan::query()->find($penerima->IdPelanggan);

        if ($pelanggan === null || $pelanggan->Status !== StatusPelanggan::Aktif || ! $pelanggan->SetujuPemasaran) {
            $penerima->UbahStatus(StatusPenerimaKampanye::Dilewati);
            $penerima->PesanGalat = 'Pelanggan sudah berhenti berlangganan atau diarsipkan.';
            $penerima->save();

            return;
        }

        $penerima->Percobaan++;
        $tautan = TautanBerhentiLangganan::Buat($kampanye->IdTenant, $pelanggan->Uuid);
        $teks = self::SusunTeks($kampanye->Isi, $pelanggan->Nama, $namaUsaha, $tautan);
        $hasil = $kampanye->Kanal === KanalKampanye::Whatsapp
            ? $this->KirimWhatsapp($penerima, $kampanye, $pelanggan->Nama, $namaUsaha, $tautan, $teks)
            : $this->KirimEmail($penerima, $kampanye, $namaUsaha, $tautan, $teks);

        if ($hasil->berhasil) {
            $penerima->UbahStatus(StatusPenerimaKampanye::Terkirim);
            $penerima->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $penerima->PesanGalat = null;
            $penerima->TerkirimPada = now();
        } else {
            if ($penerima->Percobaan >= self::MAKS_PERCOBAAN) {
                $penerima->UbahStatus(StatusPenerimaKampanye::Gagal);
            }

            $penerima->PesanGalat = $this->Saring($penerima, $kampanye->Kanal, $hasil->pesan);
            Log::warning('Pesan kampanye gagal dikirim.', [
                'IdTenant' => $kampanye->IdTenant,
                'IdKampanyePesan' => $kampanye->Id,
                'IdPenerimaKampanye' => $penerima->Id,
                'Kanal' => $kampanye->Kanal->value,
                'Penyedia' => $penerima->Penyedia,
                'Percobaan' => $penerima->Percobaan,
                'Galat' => $penerima->PesanGalat,
            ]);
        }

        $penerima->save();
    }

    private function KirimWhatsapp(PenerimaKampanye $penerima, KampanyePesan $kampanye, string $namaPelanggan, string $namaUsaha, string $tautan, string $teks): HasilKirimWhatsapp
    {
        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return HasilKirimWhatsapp::Gagal('Integrasi WhatsApp tidak aktif.');
        }

        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatPromosi() : null;
        $penerima->Penyedia = $pengirim->AmbilKode();
        // Parameter templat Meta tidak boleh memuat baris baru.
        $isiSatuBaris = trim((string) preg_replace('/\s+/u', ' ', str_replace(['{nama}', '{toko}'], [$namaPelanggan, $namaUsaha], $kampanye->Isi)));

        return $pengirim->Kirim(new PesanWhatsapp(
            $penerima->Tujuan,
            $teks,
            $templat,
            $templat === null ? [] : [$namaUsaha, $namaPelanggan, $isiSatuBaris, $tautan],
        ));
    }

    private function KirimEmail(PenerimaKampanye $penerima, KampanyePesan $kampanye, string $namaUsaha, string $tautan, string $teks): HasilKirimWhatsapp
    {
        $penerima->Penyedia = mb_substr((string) config('mail.default'), 0, 30);

        try {
            $terkirim = Mail::to($penerima->Tujuan)->send(new PesanKampanyePelanggan($namaUsaha, (string) $kampanye->Judul, $teks, $tautan));
        } catch (Exception $galat) {
            return HasilKirimWhatsapp::Gagal('Email gagal dikirim: '.$galat->getMessage());
        }

        return HasilKirimWhatsapp::Berhasil($terkirim?->getMessageId());
    }

    private function Saring(PenerimaKampanye $penerima, KanalKampanye $kanal, string $galat): string
    {
        $rahasia = [$penerima->Tujuan];

        if ($kanal === KanalKampanye::Whatsapp) {
            $lokal = substr($penerima->Tujuan, 2);
            array_push($rahasia, '+'.$penerima->Tujuan, '0'.$lokal, $lokal);
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

        return mb_substr($galat, 0, PenerimaKampanye::PANJANG_PESAN_GALAT);
    }
}
