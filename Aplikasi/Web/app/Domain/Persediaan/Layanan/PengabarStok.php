<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Kueri\DaftarIdOutletKanvas;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;
use Closure;

/**
 * Alat bersama kabar stok antar-outlet (OWN-03 / PLT-08, D-187): permintaan stok, transfer diterima, dan transfer macet.
 * Penerima = pengguna ber-izin `persediaan.kelola` di outlet kejadian (bukan yang membisukan outlet itu) ditambah
 * orang tertentu (pembuat permintaan, pengirim), tanpa pelaku sendiri. Satu kabar per kunci dan penerima, jadi tugas yang
 * diulang tidak menggandakannya. Transfer dari/ke outlet kanvas (kendaraan salesman) tidak dikabari: muat dan bongkar
 * kendaraan adalah pekerjaan harian yang rutin.
 */
final class PengabarStok
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenerimaNotifikasi $penerima,
        private readonly BuatNotifikasiPengguna $buat,
        private readonly DaftarIdOutletKanvas $kanvas,
    ) {}

    /** Menjalankan [kerja] dengan tenant aktif, lalu mengembalikan konteks semula (tugas antrean tidak membawa konteks). */
    public function DalamTenant(int $idTenant, Closure $kerja): mixed
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($idTenant);

        try {
            return $kerja();
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }

    /** True bila salah satu outlet adalah kendaraan kanvas. */
    public function CekMelibatkanKanvas(?int $idOutletAsal, ?int $idOutletTujuan): bool
    {
        $kanvas = $this->kanvas->Ambil();

        return ($idOutletAsal !== null && in_array($idOutletAsal, $kanvas, true))
            || ($idOutletTujuan !== null && in_array($idOutletTujuan, $kanvas, true));
    }

    /**
     * @param  list<int|null>  $idTambahan  penerima di luar pemegang izin (pembuat, pengirim); null dilewati
     * @param  int|null  $idKecuali  pelaku yang tidak perlu dikabari
     * @return int jumlah penerima yang diproses
     */
    public function Kabari(
        int $idTenant,
        ?int $idOutlet,
        array $idTambahan,
        ?int $idKecuali,
        JenisNotifikasiPengguna $jenis,
        string $kunci,
        string $judul,
        string $isi,
        string $href,
    ): int {
        $penerima = array_unique([
            ...$this->penerima->AmbilIdPengguna($idTenant, IzinTenant::PersediaanKelola, $idOutlet),
            ...array_values(array_filter($idTambahan, fn (?int $id): bool => $id !== null)),
        ]);
        $jumlah = 0;

        foreach ($penerima as $idPengguna) {
            if ($idPengguna === $idKecuali) {
                continue;
            }

            $this->buat->Jalankan($idPengguna, $jenis, $kunci, $judul, $isi, ['Tautan' => 'notifikasi', 'Href' => $href]);
            $jumlah++;
        }

        return $jumlah;
    }
}
