<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Terima item outbox `BahanTerbuang.Catat` (F-05f) dari kasir/dapur (bisa offline). Idempoten per Uuid. Kejadian sudah
 * terjadi, jadi hanya bentuk yang tidak mungkin benar yang ditolak (produk/pengguna tidak dikenal, produk tanpa stok,
 * outlet tanpa lokasi Toko); stok kurang, izin/outlet pencatat berubah, atau periode terkunci = dicatat + tinjauan.
 * Stok dikurangi dari lokasi Toko outlet perangkat (sama dengan penjualan).
 */
final class TerimaBahanTerbuangPos
{
    private const TOLERANSI_JAM_DETIK = 600;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly OutletPenjualan $outlet,
        private readonly InfoProdukStok $infoProduk,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly CatatBahanTerbuang $catat,
    ) {}

    public function Jalankan(
        string $uuid,
        int $idPerangkat,
        int $idOutlet,
        string $uuidProduk,
        Kuantitas $jumlah,
        AlasanBahanTerbuang $alasan,
        ?string $catatan,
        string $uuidPengguna,
        CarbonImmutable $dibuatPada,
    ): StatusItemSinkron {
        if ($dibuatPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu catatan ada di masa depan. Periksa jam perangkat.', 'DibuatPada');
        }

        if (($lama = $this->BandingkanLama($uuid, $uuidProduk, $jumlah)) !== null) {
            return $lama;
        }

        try {
            return DB::transaction(function () use ($uuid, $idPerangkat, $idOutlet, $uuidProduk, $jumlah, $alasan, $catatan, $uuidPengguna, $dibuatPada): StatusItemSinkron {
                $idTenant = $this->konteks->Wajib();
                [$kasir, $diOutlet] = $this->anggota->CariDiTenant($idTenant, $uuidPengguna, $idOutlet)
                    ?? throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pencatat ini bukan anggota usaha ini.', 'UuidPengguna');
                $tinjauan = array_values(array_filter([
                    $diOutlet ? null : "IzinBerubah: {$kasir->nama} tidak lagi terdaftar di outlet ini",
                    $kasir->CekIzin(IzinTenant::PersediaanTerbuangCatat->value) ? null : "IzinBerubah: {$kasir->nama} tidak punya izin mencatat bahan terbuang",
                ]));
                $produk = $this->infoProduk->AmbilDariUuid([$uuidProduk])[$uuidProduk]
                    ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk tidak ditemukan.', 'UuidProduk');
                $outlet = $this->outlet->Ambil($idOutlet, $idPerangkat);

                if ($outlet?->idGudangToko === null) {
                    throw new PelanggaranAturanBisnis('LokasiStokTidakAda', 'Outlet belum punya lokasi stok Toko untuk mencatat bahan terbuang.', 'UuidProduk');
                }

                $tanggal = $this->tanggalBisnis->Hitung($idOutlet, $dibuatPada);
                $pergeseran = $this->penjagaPeriode->JelaskanPergeseran($tanggal);

                if ($pergeseran !== null) {
                    $tinjauan[] = $pergeseran;
                }

                $this->catat->Jalankan(new DataBahanTerbuang(
                    uuid: $uuid,
                    idOutlet: $idOutlet,
                    idGudang: $outlet->idGudangToko,
                    idPerangkat: $idPerangkat,
                    idProduk: $produk->id,
                    jumlah: $jumlah,
                    alasan: $alasan,
                    catatan: $catatan,
                    idPengguna: $kasir->id,
                    sumber: BahanTerbuang::SUMBER_POS,
                    dibuatOfflinePada: $dibuatPada,
                    tanggalBisnis: $tanggal,
                    tinjauan: $tinjauan,
                    abaikanBatasMinus: true,
                    pakaiLokasiProduksi: true,
                ));

                return StatusItemSinkron::Diterima;
            });
        } catch (QueryException $galat) {
            if (($galat->errorInfo[1] ?? null) !== 1062) {
                throw $galat;
            }

            // Kiriman ganda bersamaan: pemenang sudah tersimpan; Uuid bentrok dengan data lain = ditolak.
            $lama = $this->BandingkanLama($uuid, $uuidProduk, $jumlah);

            if ($lama === null) {
                throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik ini sudah dipakai data lain. Buat ulang data di aplikasi.', 'Uuid');
            }

            return $lama;
        }
    }

    /**
     * Uuid yang sudah ada: `Duplikat` bila produk & jumlahnya sama (kirim ulang), selain itu `UuidSudahDipakai` (kontrak
     * `PenanganItemSinkron`); null bila Uuid belum ada.
     * Membaca basis data, jadi hasil panggilan kedua (setelah bentrok unik) bisa berbeda dengan yang pertama.
     *
     * @phpstan-impure
     */
    private function BandingkanLama(string $uuid, string $uuidProduk, Kuantitas $jumlah): ?StatusItemSinkron
    {
        $lama = BahanTerbuang::query()->where('Uuid', $uuid)->first();

        if ($lama === null) {
            return null;
        }

        $produk = $this->infoProduk->AmbilDariUuid([$uuidProduk])[$uuidProduk] ?? null;

        if ($produk !== null && $lama->IdProduk === $produk->id && Kuantitas::Dari($lama->Jumlah)->SamaDengan($jumlah)) {
            return StatusItemSinkron::Duplikat;
        }

        throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik ini sudah dipakai data lain dengan isi berbeda. Buat ulang data di aplikasi.', 'Uuid');
    }
}
