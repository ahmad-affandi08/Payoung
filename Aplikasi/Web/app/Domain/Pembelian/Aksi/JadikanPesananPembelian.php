<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\SatuanProdukPembelian;
use App\Domain\Pembelian\Data\DataBarisPesananPembelian;
use App\Domain\Pembelian\Data\DataPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelianDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menjadikan permintaan pembelian yang Disetujui sebagai draf PO (F-04 PR, izin `pembelian.kelola`): semua barang,
 * satuan, dan jumlah disalin, harga perkiraan menjadi harga awal PO (bisa diubah di draf), pemasok = yang dipilih saat
 * ini atau usulan permintaan. PO dibuat lewat `SimpanPesananPembelian` (nomor, pajak, audit sama dengan draf manual),
 * permintaan menjadi DijadikanPo dan menyimpan PO-nya. Satu permintaan paling banyak menjadi satu PO; audit
 * `permintaan-pembelian.jadikan-po`.
 */
final class JadikanPesananPembelian
{
    public function __construct(
        private readonly SimpanPesananPembelian $simpanPo,
        private readonly InfoProdukStok $infoProduk,
        private readonly SatuanProdukPembelian $satuan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis StatusTidakSesuai, PemasokWajib, PemasokTidakDikenal, ProdukTidakDikenal
     */
    public function Jalankan(PermintaanPembelian $permintaan, int $idPengguna, ?string $uuidPemasok = null): PesananPembelian
    {
        return DB::transaction(function () use ($permintaan, $idPengguna, $uuidPemasok): PesananPembelian {
            $terkunci = PermintaanPembelian::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status !== StatusPermintaanPembelian::Disetujui) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan pembelian berstatus {$terkunci->Status->AmbilLabel()} tidak bisa dijadikan PO. Hanya yang disetujui.");
            }

            $pemasok = $uuidPemasok !== null && $uuidPemasok !== ''
                ? Pemasok::query()->where('Uuid', $uuidPemasok)->first()
                : ($terkunci->IdPemasok === null ? null : Pemasok::query()->find($terkunci->IdPemasok));

            if ($pemasok === null) {
                throw new PelanggaranAturanBisnis('PemasokWajib', 'Pilih pemasok untuk pesanan pembelian ini.', 'UuidPemasok');
            }

            $detail = $terkunci->Detail()->get();
            $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
            $satuan = $this->satuan->AmbilUntukProduk(array_keys($produk));

            $baris = $detail->map(function (PermintaanPembelianDetail $d) use ($produk, $satuan): DataBarisPesananPembelian {
                $info = $produk[$d->IdProduk] ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', "{$d->NamaProduk} sudah tidak tersedia.", 'Baris');
                $uuidSatuan = collect($satuan[$d->IdProduk] ?? [])->first(fn (array $s): bool => $s['Id'] === $d->IdProdukSatuan)['Uuid'] ?? null;

                return new DataBarisPesananPembelian($info->uuid, $uuidSatuan, Kuantitas::Dari($d->Jumlah), Uang::Dari($d->HargaPerkiraan), Uang::Nol());
            })->all();

            $po = $this->simpanPo->Jalankan(new DataPesananPembelian(
                uuidPemasok: $pemasok->Uuid,
                idGudang: $terkunci->IdGudang,
                tanggal: CarbonImmutable::today(),
                perkiraanTiba: $terkunci->DibutuhkanPada === null ? null : CarbonImmutable::instance($terkunci->DibutuhkanPada),
                terminHari: null,
                ongkir: Uang::Nol(),
                catatan: mb_substr("Dari permintaan {$terkunci->Nomor}".($terkunci->Catatan === null ? '' : ": {$terkunci->Catatan}"), 0, 500),
                baris: array_values($baris),
                idPengguna: $idPengguna,
            ));

            $terkunci->UbahStatus(StatusPermintaanPembelian::DijadikanPo);
            $terkunci->fill(['IdPesananPembelian' => $po->Id, 'DijadikanPoOleh' => $idPengguna, 'DijadikanPoPada' => now(), 'DiubahOleh' => $idPengguna])->save();

            $this->riwayat->Catat(PermintaanPembelian::JENIS_DOKUMEN, $terkunci->Id, StatusPermintaanPembelian::Disetujui->value, StatusPermintaanPembelian::DijadikanPo->value, $idPengguna);
            $this->audit->Catat('permintaan-pembelian.jadikan-po', $terkunci, ['Status' => StatusPermintaanPembelian::Disetujui->value], [
                'Status' => StatusPermintaanPembelian::DijadikanPo->value,
                'NomorPo' => $po->Nomor,
                'Pemasok' => $pemasok->Nama,
            ], idPengguna: $idPengguna);

            return $po;
        }, 3);
    }
}
