<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\KategoriTiketDukungan;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Kueri\ObrolanTenant;
use App\Domain\Dukungan\Model\TiketDukungan;
use Illuminate\Support\Str;

/**
 * Pelapor mengirim satu pesan obrolan (PLT-12, D-163). Ada obrolan terbuka milik pelapor = pesan ditambahkan ke tiket itu
 * (`BalasTiketDukungan`); belum ada = tiket baru berkanal `Obrolan` (`BuatTiketDukungan`, jadi SLA, nomor, dan
 * pemberitahuan tim berlaku sama seperti tiket biasa). Obrolan tanpa petugas online otomatis berlanjut sebagai tiket.
 */
final class KirimPesanObrolan
{
    public function __construct(
        private readonly ObrolanTenant $kueri,
        private readonly BuatTiketDukungan $buat,
        private readonly BalasTiketDukungan $balas,
    ) {}

    /**
     * @param  array<string, mixed>  $konteks
     * @return array{Tiket: TiketDukungan, Baru: bool}
     */
    public function Jalankan(int $idPengguna, string $namaPengguna, string $isi, array $konteks = []): array
    {
        $aktif = $this->kueri->CariAktif($idPengguna);

        if ($aktif !== null) {
            return ['Tiket' => $this->balas->Jalankan($idPengguna, $namaPengguna, $aktif, $isi), 'Baru' => false];
        }

        $judul = 'Obrolan: '.Str::limit(trim((string) preg_replace('/\s+/u', ' ', $isi)), 70, '...');

        $tiket = $this->buat->Jalankan($idPengguna, $namaPengguna, new DataTiketBaru(
            kategori: KategoriTiketDukungan::Lainnya,
            prioritas: PrioritasTiketDukungan::Normal,
            judul: $judul,
            isi: $isi,
            konteks: [...$konteks, 'Obrolan' => true],
            kanal: KanalTiketDukungan::Obrolan,
        ));

        return ['Tiket' => $tiket, 'Baru' => true];
    }
}
