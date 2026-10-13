<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use App\Domain\Persediaan\Aksi\CatatBahanTerbuang;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BR-07.5 opsi "waste": item yang sudah dikirim ke dapur, dibuat, lalu dibatalkan, tetap memakai bahan. Void item ini dicatat
 * sebagai bahan terbuang (`CatatBahanTerbuang`: menu resep diuraikan ke bahan, mutasi `Susut`, jurnal J-05.4) dari lokasi stok
 * Toko outlet pesanan, dengan alasan `VoidItem`. `Uuid` bahan terbuang = `Uuid` baris, jadi pembatalan yang dikirim ulang tidak
 * memotong stok dua kali. Kejadian sudah terjadi, jadi stok kurang dicatat (bukan ditolak) dan periode terkunci menjadi
 * tinjauan. Item yang memang tidak punya stok atau resep (jasa), konsinyasi, atau berpelacakan batch/seri dilewati dengan
 * keterangan, tanpa menggagalkan pembatalan.
 */
final class PencatatTerbuangVoidItem
{
    /** Kode galat `CatatBahanTerbuang` yang berarti "barang ini tidak bisa diuraikan ke stok", bukan data rusak. */
    private const DILEWATI = ['ProdukTanpaStok', 'ProdukKonsinyasi', 'PelacakanTidakDidukung'];

    public function __construct(
        private readonly CatatBahanTerbuang $catat,
        private readonly OutletPenjualan $outlet,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
    ) {}

    /**
     * @return string|null null = tercatat; selain itu alasan singkat kenapa dilewati
     */
    public function Catat(PesananTerbukaDetail $baris, int $idOutlet, int $idPerangkat, int $idPengguna, CarbonImmutable $waktu, ?string $alasan): ?string
    {
        $gudang = $this->outlet->Ambil($idOutlet, $idPerangkat)?->idGudangToko;

        if ($gudang === null) {
            return 'Outlet belum punya lokasi stok Toko.';
        }

        $satuan = $baris->UuidProdukSatuan === null
            ? '1'
            : (ProdukSatuan::query()->where('Uuid', $baris->UuidProdukSatuan)->value('KonversiKeDasar') ?? '1');
        $jumlah = Kuantitas::Dari($baris->Jumlah)->Kali((string) $satuan);
        $tanggal = $this->tanggalBisnis->Hitung($idOutlet, $waktu);
        $tinjauan = array_values(array_filter([$this->penjagaPeriode->JelaskanPergeseran($tanggal)]));

        try {
            // Titik simpan sendiri: galat "tanpa stok" tidak boleh meninggalkan tulisan setengah jalan di transaksi pembatalan.
            DB::transaction(fn (): BahanTerbuang => $this->catat->Jalankan(new DataBahanTerbuang(
                uuid: $baris->Uuid,
                idOutlet: $idOutlet,
                idGudang: $gudang,
                idPerangkat: $idPerangkat,
                idProduk: $baris->IdProduk,
                jumlah: $jumlah,
                alasan: AlasanBahanTerbuang::VoidItem,
                catatan: $alasan === null ? null : mb_substr($alasan, 0, 255),
                idPengguna: $idPengguna,
                sumber: BahanTerbuang::SUMBER_POS,
                dibuatOfflinePada: $waktu,
                tanggalBisnis: $tanggal,
                tinjauan: $tinjauan,
                abaikanBatasMinus: true,
                pakaiLokasiProduksi: true,
            )));
        } catch (PelanggaranAturanBisnis $galat) {
            if (in_array($galat->kode, self::DILEWATI, true)) {
                return $galat->getMessage();
            }

            throw $galat;
        }

        $baris->update(['TerbuangDicatatPada' => $waktu]);

        return null;
    }
}
