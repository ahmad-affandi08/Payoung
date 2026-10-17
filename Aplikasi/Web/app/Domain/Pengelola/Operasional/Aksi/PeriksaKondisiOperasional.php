<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Aksi;

use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pengelola\Operasional\Enum\JenisAlertOperasional;
use App\Domain\Pengelola\Operasional\Enum\TingkatAlert;
use App\Domain\Pengelola\Operasional\Kueri\KondisiOperasional;
use App\Domain\Pengelola\Operasional\Kueri\PenerimaSurelPeran;
use App\Domain\Pengelola\Operasional\Layanan\PengaturanAlertOperasional;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Operasional\Surel\AlertOperasionalTerbuka;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemeriksa alert operasional (P-11, BR-P11.1). Setiap masalah membuka satu insiden `AlertOperasional` dan email ke
 * Teknis aktif (bila tidak ada, ke Super Admin) dikirim **sekali per insiden**; insiden ditutup saat kondisi pulih.
 *
 * Dijalankan oleh detak scheduler tiap menit dan oleh `/sehat` (uptime monitor eksternal §20.3), sehingga scheduler
 * yang mati tetap terdeteksi. Email dikirim langsung (bukan antrean), karena antrean ikut berhenti bila scheduler mati.
 */
final class PeriksaKondisiOperasional
{
    private const KUNCI_KUNCI = 'operasional:periksa-kondisi';

    public function __construct(
        private readonly KondisiOperasional $kondisi,
        private readonly PenerimaSurelPeran $penerima,
        private readonly PengaturanAlertOperasional $pengaturan,
        private readonly PembuatPengirimWhatsapp $whatsapp,
    ) {}

    /**
     * @return list<string> Kunci alert yang sedang aktif setelah pemeriksaan.
     */
    public function Jalankan(): array
    {
        // Mencegah dua pemeriksa (scheduler & /sehat) membuka insiden atau mengirim email ganda.
        return Cache::lock(self::KUNCI_KUNCI, 30)->get(fn (): array => $this->Periksa()) ?? [];
    }

    /**
     * @return list<string>
     */
    private function Periksa(): array
    {
        $masalah = $this->kondisi->AmbilMasalah() + $this->kondisi->AmbilMasalahMetrik();
        $aktif = AlertOperasional::query()->whereNull('SelesaiPada')->orderBy('Id')->get()->keyBy(fn (AlertOperasional $alert) => $alert->Kunci->value);
        $masalah = $this->TundaAlertLaluLintas($masalah, $aktif);

        foreach ($aktif as $kunci => $alert) {
            if (! array_key_exists($kunci, $masalah)) {
                $alert->update(['SelesaiPada' => now()]);
                $aktif->forget($kunci);
            }
        }

        foreach ($masalah as $kunci => $pesan) {
            $jenis = JenisAlertOperasional::from($kunci);
            $alert = $aktif->get($kunci) ?? DB::transaction(fn () => AlertOperasional::query()->create([
                'Kunci' => $jenis,
                'Tingkat' => $jenis->AmbilTingkat(),
                'Pesan' => Str::limit($pesan, 500),
                'MulaiPada' => now(),
            ]));

            // Email yang gagal terkirim dicoba lagi pada pemeriksaan berikutnya; yang sudah terkirim tidak diulang.
            if ($alert->EmailTerkirimPada === null && $this->KirimEmail($alert)) {
                $alert->update(['EmailTerkirimPada' => now()]);
            }

            // PGL-20: alert kritis juga lewat WhatsApp ke Teknis yang bertugas, sekali per insiden.
            if ($alert->WaTerkirimPada === null && $alert->Tingkat === TingkatAlert::Kritis && $this->KirimWhatsapp($alert)) {
                $alert->update(['WaTerkirimPada' => now()]);
            }
        }

        return array_keys($masalah);
    }

    /**
     * Alert dari lalu lintas API (error rate, latensi POS) ditunda: baru dianggap ada setelah kondisi buruk teramati
     * terus-menerus selama `KonfirmasiMetrikMenit`, dan insiden yang sudah terbuka baru dianggap pulih setelah sehat
     * terus-menerus selama `PulihMetrikMenit`. Persentase dari sampel kecil naik-turun di sekitar ambang; tanpa ini
     * satu insiden terbuka-tutup berkali-kali dan tiap bukaan mengirim email kritis sendiri.
     *
     * Penanda "sejak kapan" disimpan di cache dengan masa hidup pendek yang diperbarui tiap pemeriksaan, jadi celah
     * pemeriksaan (scheduler mati) mengulang hitungan, bukan menganggap kondisi buruk berlangsung sepanjang celah itu.
     *
     * @param  array<string, string>  $masalah
     * @param  Collection<string, AlertOperasional>  $aktif
     * @return array<string, string>
     */
    private function TundaAlertLaluLintas(array $masalah, Collection $aktif): array
    {
        $konfirmasi = (int) config('operasional.KonfirmasiMetrikMenit');
        $pulih = (int) config('operasional.PulihMetrikMenit');
        $sekarang = now();

        foreach (JenisAlertOperasional::cases() as $jenis) {
            if (! $jenis->CekDariLaluLintasApi()) {
                continue;
            }

            $kunci = $jenis->value;
            $kunciBuruk = "operasional:buruk-sejak:{$kunci}";
            $kunciSehat = "operasional:sehat-sejak:{$kunci}";
            $terbuka = $aktif->get($kunci);

            if (array_key_exists($kunci, $masalah)) {
                Cache::forget($kunciSehat);
                $sejak = Cache::get($kunciBuruk);
                $sejak = is_int($sejak) ? $sejak : $sekarang->getTimestamp();
                Cache::put($kunciBuruk, $sejak, 180);

                // Insiden yang sudah terbuka tetap berjalan; yang baru menunggu kondisi buruk bertahan.
                if ($terbuka === null && $sekarang->getTimestamp() - $sejak < $konfirmasi * 60) {
                    unset($masalah[$kunci]);
                }

                continue;
            }

            Cache::forget($kunciBuruk);

            if ($terbuka === null) {
                Cache::forget($kunciSehat);

                continue;
            }

            $sejak = Cache::get($kunciSehat);
            $sejak = is_int($sejak) ? $sejak : $sekarang->getTimestamp();
            Cache::put($kunciSehat, $sejak, 180);

            if ($sekarang->getTimestamp() - $sejak < $pulih * 60) {
                $masalah[$kunci] = $terbuka->Pesan;
            } else {
                Cache::forget($kunciSehat);
            }
        }

        return $masalah;
    }

    /**
     * Mengirim ke semua nomor penerima; dianggap terkirim bila minimal satu berhasil. Tanpa nomor atau tanpa nomor
     * WhatsApp Payoung yang aktif = tidak mengirim (dicoba lagi pada pemeriksaan berikutnya bila nomor sudah diatur).
     */
    private function KirimWhatsapp(AlertOperasional $alert): bool
    {
        $nomor = $this->pengaturan->NomorWa();

        if ($nomor === []) {
            return false;
        }

        try {
            $pengirim = $this->whatsapp->AmbilAktifPlatform();

            if ($pengirim === null) {
                return false;
            }

            $teks = "*[Payoung] Alert {$alert->Tingkat->value}: {$alert->Kunci->AmbilLabel()}*\n{$alert->Pesan}\n"
                .'Sejak '.$alert->MulaiPada->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')." WIB\n"
                .'Dasbor: '.route('pengelola.operasional.dasbor');
            $terkirim = false;

            foreach ($nomor as $tujuan) {
                $terkirim = $pengirim->Kirim(new PesanWhatsapp($tujuan, $teks))->berhasil || $terkirim;
            }

            return $terkirim;
        } catch (Throwable $galat) {
            Log::error('Alert operasional via WhatsApp gagal terkirim.', ['Kunci' => $alert->Kunci->value, 'Pesan' => Str::limit($galat->getMessage(), 300)]);

            return false;
        }
    }

    private function KirimEmail(AlertOperasional $alert): bool
    {
        $penerima = $this->penerima->Ambil(PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::SuperAdmin);

        if ($penerima === []) {
            return false;
        }

        try {
            foreach ($penerima as $email) {
                Mail::to($email)->send(new AlertOperasionalTerbuka(
                    $alert->Kunci->AmbilLabel(),
                    $alert->Tingkat->value,
                    $alert->Pesan,
                    $alert->MulaiPada->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB',
                    route('pengelola.operasional.dasbor'),
                ));
            }

            return true;
        } catch (Throwable $galat) {
            Log::error('Alert operasional gagal terkirim.', ['Kunci' => $alert->Kunci->value, 'Pesan' => Str::limit($galat->getMessage(), 300)]);

            return false;
        }
    }
}
