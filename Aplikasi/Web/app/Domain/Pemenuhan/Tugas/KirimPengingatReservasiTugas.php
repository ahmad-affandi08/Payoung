<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Katalog\Kueri\LayananReservasi;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pemenuhan\Enum\StatusReservasi;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * F-07 mode service: kirim pengingat reservasi H-1 lewat WhatsApp (teks, atau templat resmi bila diatur). Payload hanya
 * `IdTenant` & `IdReservasi`; nomor pelanggan tidak ikut antrean maupun log. Berhasil = `PengingatTerkirimPada` diisi;
 * gagal = dicoba ulang oleh putaran jadwal berikutnya selama masih dalam jendela.
 */
final class KirimPengingatReservasiTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idReservasi,
    ) {}

    public function handle(KonteksTenant $konteks, PembuatPengirimWhatsapp $whatsapp, ProfilTenant $profil, NamaTampilUsaha $namaTampil, LayananReservasi $layanan, ZonaWaktuOutlet $zona): void
    {
        // Satu pengirim aktif per pesan (lihat `KunciPengiriman`): job ganda untuk pesan yang sama dilewati.
        $kunciKirim = Cache::lock('kirim:pengingat-reservasi:'.$this->idReservasi, 300);

        if (! $kunciKirim->get()) {
            return;
        }

        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $r = Reservasi::query()->find($this->idReservasi);
            $pengirim = $whatsapp->AmbilAktif();

            if ($r === null || $r->PengingatTerkirimPada !== null || ! in_array($r->Status, [StatusReservasi::Menunggu, StatusReservasi::Dikonfirmasi], true) || $pengirim === null) {
                return;
            }

            $toko = $namaTampil->UntukOutlet($r->IdOutlet, $this->idTenant);
            $namaLayanan = $layanan->AmbilNama([$r->IdProduk])[$r->IdProduk] ?? 'layanan';
            $waktu = $r->MulaiPada->copy()->setTimezone($zona->Ambil($r->IdOutlet))->translatedFormat('l, j F Y \p\u\k\u\l H.i');
            $tautan = url('/'.$profil->AmbilSlug($this->idTenant).'/reservasi/'.$r->KodeAkses);
            $templat = $pengirim->CekResmi() ? $whatsapp->AmbilTemplatPengingatReservasi() : null;
            $hasil = $pengirim->Kirim(new PesanWhatsapp(
                $r->NoHp,
                "Halo {$r->NamaPelanggan}, ini pengingat reservasi {$namaLayanan} di {$toko} pada {$waktu}.\nLihat atau batalkan: {$tautan}",
                $templat,
                $templat === null ? [] : [$toko, $namaLayanan, $waktu, $tautan],
            ));

            if ($hasil->berhasil) {
                $r->PengingatTerkirimPada = now();
                $r->save();

                return;
            }

            Log::warning('Pengingat reservasi gagal dikirim.', ['IdTenant' => $this->idTenant, 'IdReservasi' => $r->Id, 'Penyedia' => $pengirim->AmbilKode()]);
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
            $kunciKirim->release();
        }
    }
}
