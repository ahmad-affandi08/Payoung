<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Data\DataStokAwal;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Model\StokAwal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grid ukuran × warna (§9.4, D-143): stok awal banyak varian sekaligus sebagai **satu** dokumen stok awal yang langsung
 * dicatat (J-05.1: Dr Persediaan / Cr Ekuitas Saldo Awal, mutasi `StokAwal`), lewat `SimpanStokAwal` +
 * `PostingStokAwal` yang sama dengan halaman Stok awal sehingga semua pemeriksaannya berlaku (lokasi aktif, pemetaan akun,
 * stok awal ganda, jumlah bulat). Satu transaksi DB: satu baris ditolak = tidak ada yang tercatat. Hanya untuk produk
 * tanpa pelacakan batch/seri (pemanggil memastikannya). Tanggal = tanggal bisnis outlet lokasi stok hari ini.
 */
final class CatatStokAwalBanyakProduk
{
    public function __construct(
        private readonly SimpanStokAwal $simpan,
        private readonly PostingStokAwal $posting,
        private readonly PemeriksaLokasiDokumen $lokasi,
    ) {}

    /**
     * @param  list<DataBarisStokAwal>  $baris
     */
    public function Jalankan(DataInfoGudang $gudang, array $baris, string $catatan, int $idPengguna): StokAwal
    {
        if ($baris === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Isi stok minimal satu varian.', 'Baris');
        }

        return DB::transaction(function () use ($gudang, $baris, $catatan, $idPengguna): StokAwal {
            $draf = $this->simpan->Jalankan(new DataStokAwal(
                uuid: (string) Str::ulid(),
                idGudang: $gudang->id,
                tanggal: $this->lokasi->HariIni($gudang->idOutlet),
                catatan: mb_substr($catatan, 0, 255),
                baris: $baris,
            ), null);

            return $this->posting->Jalankan($draf, $idPengguna);
        });
    }
}
