<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Penjualan\Model\NotifikasiPesananOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * F-17 bagian 3 (v3.32): kirim satu pemberitahuan status pesanan online lewat WhatsApp (teks, atau templat resmi
 * `NamaTemplatStatusPesanan` di WhatsApp Cloud API). Payload hanya `IdTenant` & `IdNotifikasi`; nomor pembeli tidak
 * ikut antrean maupun log. Gagal kirim dicoba lagi 1 & 5 menit kemudian (3 kali); setelah itu dibiarkan tercatat
 * dengan `Galat` — status pesanan tetap bisa dilihat pembeli di halaman statusnya.
 */
final class KirimNotifikasiPesananOnlineTugas implements ShouldQueue
{
    use Queueable;

    public const BATAS_PERCOBAAN = 3;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idNotifikasi,
    ) {}

    public function handle(KonteksTenant $konteks, PembuatPengirimWhatsapp $whatsapp, ProfilTenant $profil, NamaTampilUsaha $namaTampil): void
    {
        // Satu pengirim aktif per pesan (lihat `KunciPengiriman`): job ganda untuk pesan yang sama dilewati.
        $kunciKirim = Cache::lock('kirim:notifikasi-pesanan-online:'.$this->idNotifikasi, 300);

        if (! $kunciKirim->get()) {
            return;
        }

        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $n = NotifikasiPesananOnline::query()->find($this->idNotifikasi);
            $pesanan = $n === null ? null : PesananOnline::query()->find($n->IdPesananOnline);
            $pengirim = $whatsapp->AmbilAktif();

            if ($n === null || $pesanan === null || $n->TerkirimPada !== null || $n->Percobaan >= self::BATAS_PERCOBAAN) {
                return;
            }

            if ($pengirim === null) {
                $n->forceFill(['Galat' => 'WhatsApp platform belum tersambung.'])->save();

                return;
            }

            $toko = $namaTampil->UntukOutlet($pesanan->IdOutlet, $this->idTenant);
            $nama = trim(explode(' ', trim($pesanan->NamaPelanggan))[0] ?? '');
            $tautan = url('/'.$profil->AmbilSlug($this->idTenant).'/pesanan/'.$pesanan->KodeAkses);
            $templat = $pengirim->CekResmi() ? $whatsapp->AmbilTemplatStatusPesanan() : null;
            $hasil = $pengirim->Kirim(new PesanWhatsapp(
                (string) $pesanan->NoHp,
                ($nama === '' ? 'Halo' : "Halo {$nama}").", pesanan {$pesanan->Nomor} di {$toko} {$n->Peristiwa->AmbilKalimat()}\nLihat status: {$tautan}",
                $templat,
                $templat === null ? [] : [$toko, $pesanan->Nomor, $n->Peristiwa->AmbilLabel(), $tautan],
            ));

            if ($hasil->berhasil) {
                $n->forceFill(['TerkirimPada' => now(), 'Percobaan' => $n->Percobaan + 1, 'Galat' => null])->save();

                return;
            }

            $n->forceFill(['Percobaan' => $n->Percobaan + 1, 'Galat' => mb_substr($hasil->pesan, 0, 255)])->save();
            Log::warning('Notifikasi pesanan online gagal dikirim.', ['IdTenant' => $this->idTenant, 'IdNotifikasi' => $n->Id, 'Penyedia' => $pengirim->AmbilKode()]);

            if ($n->Percobaan < self::BATAS_PERCOBAAN) {
                $this->release($n->Percobaan === 1 ? 60 : 300);
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
            $kunciKirim->release();
        }
    }
}
