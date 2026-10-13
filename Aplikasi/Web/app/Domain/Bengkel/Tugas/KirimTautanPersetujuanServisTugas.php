<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Tugas;

use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Layanan\TautanPersetujuanServis;
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

/**
 * Bengkel (§9.10): kirim tautan persetujuan estimasi ke WhatsApp pelanggan (teks, atau templat resmi bila diatur).
 * Payload hanya `IdTenant` & `IdPerintahKerja`; nomor pelanggan dan token tidak ikut antrean maupun log. Dilewati bila
 * WhatsApp toko belum aktif, pelanggan tanpa nomor, atau estimasinya sudah diputuskan/tautannya diganti. Berhasil =
 * `PersetujuanDikirimPada` diisi (tampil di back-office); gagal = staf tetap bisa menyalin tautannya.
 */
final class KirimTautanPersetujuanServisTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

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

            if ($pk === null || $pk->Status !== StatusPerintahKerja::MenungguPersetujuan || ! $pk->CekTautanBerlaku() || $pk->TokenPersetujuan === null || $pengirim === null) {
                return;
            }

            $kontak = $pelanggan->AmbilKontak($pk->IdPelanggan);

            if ($kontak === null || $kontak['NoHp'] === null) {
                return;
            }

            $toko = $namaTampil->UntukOutlet($pk->IdOutlet, $this->idTenant);
            $unit = Kendaraan::query()->whereKey($pk->IdKendaraan)->first();
            $plat = $unit?->AmbilPengenal() ?? '';
            $sebutan = $unit?->AmbilSebutan() ?? 'kendaraan';
            $tautan = TautanPersetujuanServis::Buat($profil->AmbilSlug($this->idTenant), $pk->TokenPersetujuan);
            $templat = $pengirim->CekResmi() ? $whatsapp->AmbilTemplatPersetujuanServis() : null;
            $hasil = $pengirim->Kirim(new PesanWhatsapp(
                $kontak['NoHp'],
                "Halo {$kontak['Nama']}, estimasi servis {$sebutan} ({$pk->Nomor}) di {$toko} sudah siap.\nLihat rincian dan setujui pekerjaannya: {$tautan}",
                $templat,
                $templat === null ? [] : [$toko, $plat, $pk->Nomor, $tautan],
            ));

            if ($hasil->berhasil) {
                PerintahKerja::query()->whereKey($pk->Id)->update(['PersetujuanDikirimPada' => now()]);

                return;
            }

            Log::warning('Tautan persetujuan servis gagal dikirim.', ['IdTenant' => $this->idTenant, 'IdPerintahKerja' => $pk->Id, 'Penyedia' => $pengirim->AmbilKode()]);
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
