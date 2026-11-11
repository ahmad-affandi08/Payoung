<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Data\HasilHargaOtomatis;
use App\Domain\Katalog\Harga\Layanan\PemberitahuHargaOtomatis;
use App\Domain\Katalog\Harga\Layanan\PenerapHargaOtomatis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Pembelian\Kueri\HargaBeliTerakhirProduk;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;

/**
 * Menjalankan harga otomatis (D-266) dari harga beli terakhir. Dua pintu masuk:
 * - `Jalankan`: produk-produk dari satu penerimaan barang yang baru dicatat (dipanggil penangan antrean),
 * - `JalankanUntukSemua`: "Terapkan sekarang" dari back-office, semua produk berstok yang punya aturan.
 * Tidak berbuat apa-apa bila fitur `harga.otomatis` tidak ada di paket tenant atau saklar utamanya mati. Perubahan
 * yang besar ditahan jadi usulan (`PenerapHargaOtomatis`). Hasilnya dikabarkan ke pengguna ber-izin ubah harga.
 */
final class TerapkanHargaOtomatis
{
    public const KUNCI_FITUR = 'harga.otomatis';

    /** Produk per putaran "Terapkan sekarang", supaya satu tugas tidak membaca seluruh katalog sekaligus. */
    private const POTONGAN = 300;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly HargaBeliTerakhirProduk $hargaBeli,
        private readonly PenerapHargaOtomatis $penerap,
        private readonly PemberitahuHargaOtomatis $pemberitahu,
    ) {}

    /**
     * @param  list<int>  $idProduk
     */
    public function Jalankan(array $idProduk, string $kunciPutaran, ?int $idPengguna = null): HasilHargaOtomatis
    {
        $idTenant = $this->konteks->Wajib();
        $hasil = new HasilHargaOtomatis;

        if (! $this->fitur->CekAktif($idTenant, self::KUNCI_FITUR)) {
            return $hasil;
        }

        $this->Gabung($hasil, $this->penerap->Terapkan($this->hargaBeli->Ambil($idProduk), $idPengguna));
        $this->pemberitahu->Beritahu($idTenant, $kunciPutaran, $hasil);

        return $hasil;
    }

    public function JalankanUntukSemua(string $kunciPutaran, ?int $idPengguna = null): HasilHargaOtomatis
    {
        $idTenant = $this->konteks->Wajib();
        $hasil = new HasilHargaOtomatis;

        if (! $this->fitur->CekAktif($idTenant, self::KUNCI_FITUR)) {
            return $hasil;
        }

        Produk::query()
            ->where('Jenis', JenisProduk::Stok->value)
            ->where('HargaTerbuka', false)
            ->where('Aktif', true)
            ->whereNull('DiarsipkanPada')
            ->orderBy('Id')
            ->chunkById(self::POTONGAN, function ($produk) use ($hasil, $idPengguna): void {
                $this->Gabung($hasil, $this->penerap->Terapkan($this->hargaBeli->Ambil(array_values($produk->map(fn (Produk $p): int => $p->Id)->all())), $idPengguna));
            }, 'Id');

        $this->pemberitahu->Beritahu($idTenant, $kunciPutaran, $hasil);

        return $hasil;
    }

    private function Gabung(HasilHargaOtomatis $semua, HasilHargaOtomatis $bagian): void
    {
        $semua->diterapkan += $bagian->diterapkan;
        $semua->diusulkan += $bagian->diusulkan;
        $semua->tetap += $bagian->tetap;
        $semua->dilewati += $bagian->dilewati;
        $semua->perubahan = [...$semua->perubahan, ...$bagian->perubahan];
    }
}
