<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Data\DataStokAwal;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Model\StokAwal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audit kemudahan pakai #11: "Stok sekarang" + "Harga beli" di formulir produk baru menjadi dokumen stok awal satu
 * baris yang langsung dicatat (J-05.1: Dr Persediaan / Cr Ekuitas Saldo Awal, mutasi `StokAwal`), memakai
 * `SimpanStokAwal` + `PostingStokAwal` yang sama dengan halaman Stok awal sehingga semua pemeriksaannya berlaku
 * (lokasi aktif, pemetaan akun, stok awal ganda). Tanggal = tanggal bisnis outlet lokasi stok hari ini. Hanya untuk
 * produk tanpa pelacakan batch/seri (pemanggil memastikannya). Dipanggil di transaksi DB yang sama dengan pembuatan
 * produk: gagal di sini = produk juga tidak tersimpan.
 */
final class CatatStokAwalProdukBaru
{
    public function __construct(
        private readonly SimpanStokAwal $simpan,
        private readonly PostingStokAwal $posting,
        private readonly PemeriksaLokasiDokumen $lokasi,
    ) {}

    public function Jalankan(int $idProduk, DataInfoGudang $gudang, Kuantitas $jumlah, BigDecimal $hppSatuan, int $idPengguna): StokAwal
    {
        if ($jumlah->BernilaiNol() || $jumlah->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Stok sekarang harus lebih dari 0.', 'StokAwal.Jumlah');
        }

        if ($hppSatuan->isNegative()) {
            throw new PelanggaranAturanBisnis('HargaBeliTidakValid', 'Harga beli tidak boleh negatif.', 'StokAwal.HargaBeli');
        }

        return DB::transaction(function () use ($idProduk, $gudang, $jumlah, $hppSatuan, $idPengguna): StokAwal {
            $draf = $this->simpan->Jalankan(new DataStokAwal(
                uuid: (string) Str::ulid(),
                idGudang: $gudang->id,
                tanggal: $this->lokasi->HariIni($gudang->idOutlet),
                catatan: 'Stok awal dari formulir produk baru',
                baris: [new DataBarisStokAwal($idProduk, $jumlah, $hppSatuan, null, null, [])],
            ), null);

            return $this->posting->Jalankan($draf, $idPengguna);
        });
    }
}
