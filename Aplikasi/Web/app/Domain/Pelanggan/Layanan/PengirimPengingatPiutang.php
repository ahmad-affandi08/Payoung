<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Layanan;

use App\Domain\Bersama\Antrean\KunciPengiriman;
use App\Domain\Integrasi\Whatsapp\HasilKirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Enum\JenisPengingatPiutang;
use App\Domain\Pelanggan\Enum\KanalPengingatPiutang;
use App\Domain\Pelanggan\Enum\StatusPengingatPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PengingatPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pelanggan\Surel\PengingatPiutangPelanggan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mengirim satu `PengingatPiutang` (D-23 D bagian 4b), dipanggil `KirimPengingatPiutangTugas` di dalam konteks tenant.
 * Piutang yang sudah lunas/batal saat akan dikirim → `Dibatalkan` (pelanggan tidak ditagih yang sudah dibayar).
 * WhatsApp: penyedia resmi + templat pengingat → templat [toko, nomor, sisa, jatuh tempo]; selain itu teks. Email:
 * `PengingatPiutangPelanggan`. Kegagalan penyedia dicoba ulang oleh tugas; pesan galat disaring dari tujuan & kredensial.
 * Log tidak pernah memuat tujuan atau nama pelanggan.
 */
final class PengirimPengingatPiutang
{
    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly ProfilTenant $profil,
    ) {}

    /** @return bool true bila perlu dicoba ulang. */
    public function Kirim(int $idPengingat, bool $percobaanTerakhir): bool
    {
        // Satu pengirim aktif per pesan (lihat `KunciPengiriman`).
        return KunciPengiriman::Jalankan('pengingat-piutang:'.$idPengingat, fn (): bool => $this->Proses($idPengingat, $percobaanTerakhir), false);
    }

    /** @return bool true bila perlu dicoba ulang. */
    private function Proses(int $idPengingat, bool $percobaanTerakhir): bool
    {
        $pengingat = PengingatPiutang::query()->find($idPengingat);

        if ($pengingat === null || $pengingat->Status !== StatusPengingatPiutang::Diantrekan) {
            return false;
        }

        $pengingat->Percobaan++;
        $piutang = Piutang::query()->find($pengingat->IdPiutang);

        if ($piutang === null || ! in_array($piutang->Status, [StatusPiutang::BelumLunas, StatusPiutang::DibayarSebagian], true)) {
            $this->Akhiri($pengingat, StatusPengingatPiutang::Dibatalkan, 'Piutang sudah lunas atau dibatalkan sebelum pengingat terkirim.');

            return false;
        }

        $profil = $this->profil->Ambil($pengingat->IdTenant);
        $hariIni = now()->setTimezone($profil['ZonaWaktu'])->toDateString();
        $isi = [
            'NamaUsaha' => app(NamaTampilUsaha::class)->UntukTenant($pengingat->IdTenant),
            'NamaPelanggan' => (string) Pelanggan::query()->whereKey($piutang->IdPelanggan)->value('Nama'),
            'Nomor' => $piutang->Nomor,
            'Sisa' => $piutang->AmbilSisa()->FormatRupiah(),
            'JatuhTempo' => $piutang->JatuhTempo->translatedFormat('j F Y'),
            'Lewat' => $pengingat->Jenis === JenisPengingatPiutang::LewatJatuhTempo || $piutang->JatuhTempo->toDateString() < $hariIni,
        ];

        $hasil = $pengingat->Kanal === KanalPengingatPiutang::Whatsapp ? $this->KirimWhatsapp($pengingat, $isi) : $this->KirimEmail($pengingat, $isi);

        if ($hasil === null) {
            $this->Akhiri($pengingat, StatusPengingatPiutang::Gagal, 'Integrasi WhatsApp tidak aktif.');

            return false;
        }

        if ($hasil->berhasil) {
            $pengingat->UbahStatus(StatusPengingatPiutang::Terkirim);
            $pengingat->IdPesanPenyedia = $hasil->idPesan === null ? null : mb_substr($hasil->idPesan, 0, 191);
            $pengingat->PesanGalat = null;
            $pengingat->TerkirimPada = now();
            $pengingat->save();

            return false;
        }

        if ($percobaanTerakhir) {
            $this->Akhiri($pengingat, StatusPengingatPiutang::Gagal, $hasil->pesan);

            return false;
        }

        $pengingat->PesanGalat = $this->Saring($pengingat, $hasil->pesan);
        $pengingat->save();
        $this->CatatGalat($pengingat);

        return true;
    }

    public function Akhiri(PengingatPiutang $pengingat, StatusPengingatPiutang $status, string $galat): void
    {
        if ($pengingat->Status !== StatusPengingatPiutang::Diantrekan) {
            return;
        }

        $pengingat->UbahStatus($status);
        $pengingat->PesanGalat = $this->Saring($pengingat, $galat);
        $pengingat->save();

        if ($status === StatusPengingatPiutang::Gagal) {
            $this->CatatGalat($pengingat);
        }
    }

    /**
     * Teks pengingat (juga isi email): sopan, tanpa ancaman, dengan ajakan mengabaikan bila sudah dibayar.
     *
     * @param  array{NamaUsaha: string, NamaPelanggan: string, Nomor: string, Sisa: string, JatuhTempo: string, Lewat: bool}  $isi
     */
    public static function SusunTeks(array $isi): string
    {
        $pembuka = "Halo {$isi['NamaPelanggan']}, ini pengingat dari {$isi['NamaUsaha']}.";
        $inti = $isi['Lewat']
            ? "Tagihan nota {$isi['Nomor']} sebesar {$isi['Sisa']} sudah melewati jatuh tempo {$isi['JatuhTempo']}."
            : "Tagihan nota {$isi['Nomor']} sebesar {$isi['Sisa']} jatuh tempo pada {$isi['JatuhTempo']}.";

        return "{$pembuka}\n{$inti}\nAbaikan pesan ini bila sudah dibayar. Terima kasih.";
    }

    /**
     * @param  array{NamaUsaha: string, NamaPelanggan: string, Nomor: string, Sisa: string, JatuhTempo: string, Lewat: bool}  $isi
     */
    private function KirimWhatsapp(PengingatPiutang $pengingat, array $isi): ?HasilKirimWhatsapp
    {
        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return null;
        }

        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatPengingatPiutang() : null;
        $pengingat->Penyedia = $pengirim->AmbilKode();

        return $pengirim->Kirim(new PesanWhatsapp(
            $pengingat->Tujuan,
            self::SusunTeks($isi),
            $templat,
            $templat === null ? [] : [$isi['NamaUsaha'], $isi['Nomor'], $isi['Sisa'], $isi['JatuhTempo']],
        ));
    }

    /**
     * @param  array{NamaUsaha: string, NamaPelanggan: string, Nomor: string, Sisa: string, JatuhTempo: string, Lewat: bool}  $isi
     */
    private function KirimEmail(PengingatPiutang $pengingat, array $isi): HasilKirimWhatsapp
    {
        $pengingat->Penyedia = mb_substr((string) config('mail.default'), 0, 30);

        try {
            $terkirim = Mail::to($pengingat->Tujuan)->send(new PengingatPiutangPelanggan($isi['NamaUsaha'], $isi['Nomor'], self::SusunTeks($isi)));
        } catch (Exception $galat) {
            return HasilKirimWhatsapp::Gagal('Email gagal dikirim: '.$galat->getMessage());
        }

        return HasilKirimWhatsapp::Berhasil($terkirim?->getMessageId());
    }

    private function Saring(PengingatPiutang $pengingat, string $galat): string
    {
        $rahasia = [$pengingat->Tujuan];

        if ($pengingat->Kanal === KanalPengingatPiutang::Whatsapp) {
            $lokal = substr($pengingat->Tujuan, 2);
            array_push($rahasia, '+'.$pengingat->Tujuan, '0'.$lokal, $lokal);
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

        return mb_substr($galat, 0, PengingatPiutang::PANJANG_PESAN_GALAT);
    }

    private function CatatGalat(PengingatPiutang $pengingat): void
    {
        Log::warning('Pengingat piutang gagal dikirim.', [
            'IdTenant' => $pengingat->IdTenant,
            'IdPengingatPiutang' => $pengingat->Id,
            'Kanal' => $pengingat->Kanal->value,
            'Penyedia' => $pengingat->Penyedia,
            'Percobaan' => $pengingat->Percobaan,
            'Status' => $pengingat->Status->value,
            'Galat' => $pengingat->PesanGalat,
        ]);
    }
}
