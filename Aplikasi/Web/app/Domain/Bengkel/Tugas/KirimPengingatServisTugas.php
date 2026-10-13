<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Tugas;

use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bengkel (§9.10): pengingat servis berkala lewat WhatsApp, **paling banyak sekali** per tanggal servis. Kiriman
 * diklaim atomik lewat `PengingatServisDiprosesPada` sebelum memanggil penyedia (pola notifikasi laundry, audit F-17):
 * penyedia menjawab gagal = klaim dilepas (putaran besok boleh mencoba lagi selama masih dalam jendela); galat tak
 * terduga = klaim dipertahankan supaya percobaan ulang antrean tidak mengirim pesan ganda. Payload hanya Id.
 */
final class KirimPengingatServisTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPerintahKerja,
    ) {}

    public function handle(KonteksTenant $konteks, PembuatPengirimWhatsapp $whatsapp, ProfilTenant $profil, NamaTampilUsaha $namaTampil, IdentitasPelanggan $pelanggan): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $pk = PerintahKerja::query()->find($this->idPerintahKerja);
            $pengirim = $whatsapp->AmbilAktif();

            if ($pk === null || $pk->ServisBerikutnyaPada === null || $pk->PengingatServisTerkirimPada !== null || $pengirim === null) {
                return;
            }

            $kontak = $pelanggan->AmbilKontak($pk->IdPelanggan);
            $kendaraan = Kendaraan::query()->whereKey($pk->IdKendaraan)->first();

            if ($kontak === null || $kontak['NoHp'] === null || $kendaraan === null || ! $kendaraan->Aktif) {
                return;
            }

            $klaim = PerintahKerja::query()->whereKey($pk->Id)->whereNull('PengingatServisTerkirimPada')->whereNull('PengingatServisDiprosesPada')
                ->update(['PengingatServisDiprosesPada' => now()]);

            if ($klaim !== 1) {
                return;
            }

            $toko = $namaTampil->UntukOutlet($pk->IdOutlet, $this->idTenant);
            $tanggal = $pk->ServisBerikutnyaPada->translatedFormat('j F Y');
            $km = $pk->ServisBerikutnyaKm === null ? '' : ' atau saat kilometer mencapai '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $pk->ServisBerikutnyaKm).' km';
            $templat = $pengirim->CekResmi() ? $whatsapp->AmbilTemplatPengingatServis() : null;

            try {
                $hasil = $pengirim->Kirim(new PesanWhatsapp(
                    $kontak['NoHp'],
                    "Halo {$kontak['Nama']}, {$kendaraan->AmbilSebutan()} dijadwalkan servis berkala pada {$tanggal}{$km}.\nHubungi {$toko} untuk mengatur jadwal servisnya.",
                    $templat,
                    $templat === null ? [] : [$kontak['Nama'], $kendaraan->AmbilPengenal(), $tanggal, $toko],
                ));
            } catch (Throwable $galat) {
                report($galat);

                return;
            }

            if ($hasil->berhasil) {
                PerintahKerja::query()->whereKey($pk->Id)->update(['PengingatServisTerkirimPada' => now()]);

                return;
            }

            PerintahKerja::query()->whereKey($pk->Id)->whereNull('PengingatServisTerkirimPada')->update(['PengingatServisDiprosesPada' => null]);
            Log::warning('Pengingat servis berkala gagal dikirim.', ['IdTenant' => $this->idTenant, 'IdPerintahKerja' => $pk->Id, 'Penyedia' => $pengirim->AmbilKode()]);
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
