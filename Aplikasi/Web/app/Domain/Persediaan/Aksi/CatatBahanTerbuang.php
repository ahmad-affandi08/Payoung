<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\KomposisiPenjualan;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusBahanTerbuang;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PenyusunJurnalPersediaan;
use App\Domain\Persediaan\Model\BahanTerbuang;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * F-05f: mencatat bahan/menu terbuang di dalam transaksi pemanggil. Produk berstok dikurangi dirinya sendiri; menu
 * resep & paket diuraikan ke bahan berstok (resep versi terbaru, susut ikut, sama dengan penjualan F-07b). Mutasi
 * `Susut` dinilai HPP berjalan dan jurnal J-05.4 (Dr Susut & Barang Rusak, Cr persediaan per peran) dicatat bersama.
 * Idempoten per Uuid (catatan sama = dikembalikan apa adanya). Jasa/non-stok, konsinyasi, dan produk berpelacakan
 * ditolak. Stok kurang: back-office ditolak (`StokTidakCukup`), kasir dicatat + tinjauan. Audit `bahan-terbuang.catat`.
 */
final class CatatBahanTerbuang
{
    private const PANJANG_TINJAUAN = 500;

    public function __construct(
        private readonly KomposisiPenjualan $komposisi,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly OutletPenjualan $outletPenjualan,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPersediaan $penyusunJurnal,
        private readonly PencatatJurnalPersediaan $pencatatJurnal,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataBahanTerbuang $data): BahanTerbuang
    {
        // Tanpa kunci baris: `lockForUpdate` pada Uuid yang belum ada memasang kunci celah yang membuat dua pencatatan
        // bersamaan saling deadlock (audit F-07). Keunikan dijaga indeks unik Uuid (lihat penyimpanan di bawah).
        $lama = BahanTerbuang::query()->where('Uuid', $data->uuid)->first();

        if ($lama !== null) {
            return $lama;
        }

        if (! $data->jumlah->KeDesimal()->isPositive()) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah terbuang harus lebih dari 0.', 'Jumlah');
        }

        $produk = $this->infoProduk->AmbilBanyak([$data->idProduk], denganTerhapus: true)[$data->idProduk] ?? null;

        if ($produk === null) {
            throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk tidak ditemukan.', 'UuidProduk');
        }

        $kebutuhan = $this->komposisi->AmbilKebutuhanStok([$data->idProduk])[$data->idProduk] ?? [];

        if ($kebutuhan === []) {
            throw new PelanggaranAturanBisnis('ProdukTanpaStok', "{$produk->nama} tidak punya stok atau resep, jadi tidak ada yang dikurangi.", 'UuidProduk');
        }

        $baris = [];
        $gudangDipakai = [$data->idGudang];
        $urutan = 0;

        foreach ($kebutuhan as $k) {
            if ($k->jenis === JenisProduk::Konsinyasi) {
                throw new PelanggaranAturanBisnis('ProdukKonsinyasi', "{$k->nama} barang konsinyasi; catat lewat retur ke penitip.", 'UuidProduk');
            }

            if ($k->pelacakan !== PelacakanProduk::Tidak) {
                throw new PelanggaranAturanBisnis('PelacakanTidakDidukung', "{$k->nama} memakai batch/nomor seri; catat lewat penyesuaian stok dengan memilih batch/seri.", 'UuidProduk');
            }

            $jumlah = $data->jumlah->KeDesimal()->multipliedBy($k->pembilang)->dividedBy($k->penyebut, Kuantitas::SKALA, RoundingMode::HalfUp);

            if ($jumlah->isZero() || $k->dihapus) {
                continue;
            }

            $urutan++;
            $idGudang = $data->pakaiLokasiProduksi && $k->lokasiProduksi !== null && $data->idOutlet !== null
                ? ($this->outletPenjualan->AmbilIdGudangProduksi($data->idOutlet, $k->lokasiProduksi->value) ?? $data->idGudang)
                : $data->idGudang;
            $gudangDipakai[] = $idGudang;
            $baris[] = new DataBarisMutasi(
                kunciBaris: 'T/'.$urutan,
                idProduk: $k->idProduk,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::Susut,
                jumlah: Kuantitas::Dari($jumlah->negated()),
                modeNilai: ModeNilaiMutasi::Berjalan,
            );
        }

        if ($baris === []) {
            throw new PelanggaranAturanBisnis('ProdukTanpaStok', "Jumlah {$produk->nama} terlalu kecil untuk mengurangi stok bahan.", 'Jumlah');
        }

        $nomor = 'BT-'.$data->uuid;
        $catatan = $data->catatan === null ? null : trim($data->catatan);
        $dokumen = new BahanTerbuang;
        $dokumen->forceFill([
            'Uuid' => $data->uuid,
            'IdOutlet' => $data->idOutlet,
            'IdGudang' => $data->idGudang,
            'IdPerangkat' => $data->idPerangkat,
            'IdProduk' => $produk->id,
            'NamaProduk' => mb_substr($produk->nama, 0, 150),
            'Jumlah' => $data->jumlah->KeString(),
            'Alasan' => $data->alasan,
            'Catatan' => $catatan === '' ? null : ($catatan === null ? null : mb_substr($catatan, 0, 255)),
            'Sumber' => $data->sumber,
            'IdPengguna' => $data->idPengguna,
            'DibuatOfflinePada' => $data->dibuatOfflinePada,
            'TanggalBisnis' => $data->tanggalBisnis->toDateString(),
        ]);

        try {
            $dokumen->save();
        } catch (UniqueConstraintViolationException) {
            // Pencatatan ber-Uuid sama baru saja disimpan permintaan lain: kembalikan yang sudah ada (idempoten).
            return BahanTerbuang::query()->where('Uuid', $data->uuid)->firstOrFail();
        }

        $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::BahanTerbuang,
            $dokumen->Id,
            $dokumen->Uuid,
            $nomor,
            $data->tanggalBisnis,
            $data->idPengguna,
            $data->idPerangkat,
            $baris,
            abaikanBatasMinus: $data->abaikanBatasMinus,
        ));

        $tinjauan = $data->tinjauan;
        $kurang = array_map(fn ($b): string => $this->infoProduk->AmbilBanyak([$b->idProduk], denganTerhapus: true)[$b->idProduk]->nama ?? 'Produk', $hasil->AmbilBarisStokTidakCukup());

        if ($kurang !== []) {
            $tinjauan[] = 'StokTidakCukup: '.implode(', ', $kurang);
        }

        $idProdukMutasi = array_values(array_unique(array_map(fn ($b): int => $b->idProduk, $hasil->baris)));
        $infoMutasi = $this->infoProduk->AmbilBanyak($idProdukMutasi, denganTerhapus: true);
        $nilai = Uang::Nol();

        foreach ($hasil->baris as $b) {
            $nilai = $nilai->Tambah(AritmetikaHpp::AmbilMutlak($b->totalHpp));
        }

        $jurnal = $this->pencatatJurnal->Posting(
            JenisSumberJurnal::BahanTerbuang,
            $dokumen->Id,
            $dokumen->Uuid,
            $nomor,
            $data->tanggalBisnis,
            "Bahan terbuang: {$data->jumlah->KeDesimal()->strippedOfTrailingZeros()} {$produk->simbolSatuan} {$produk->nama} ({$data->alasan->AmbilLabel()})",
            $this->penyusunJurnal->Susun(
                array_values(array_map(fn ($b): array => [$b, PeranAkun::SusutPersediaan], $hasil->baris)),
                $infoMutasi,
                $this->infoGudang->AmbilBanyak(array_values(array_unique($gudangDipakai))),
                $data->idOutlet,
            ),
            $data->idPengguna,
        );

        $dokumen->forceFill([
            'Nilai' => $nilai->KeString(),
            'Status' => StatusBahanTerbuang::Tercatat,
            'IdJurnal' => $jurnal?->idJurnal,
            'PerluTinjauan' => $tinjauan !== [],
            'AlasanTinjauan' => $tinjauan === [] ? null : mb_substr(implode('; ', $tinjauan), 0, self::PANJANG_TINJAUAN),
        ])->save();

        $this->audit->Catat('bahan-terbuang.catat', $dokumen, nilaiBaru: [
            'Produk' => $dokumen->NamaProduk,
            'Jumlah' => $dokumen->Jumlah,
            'Alasan' => $data->alasan->value,
            'Nilai' => $dokumen->Nilai,
            'Sumber' => $data->sumber,
            'PerluTinjauan' => $dokumen->PerluTinjauan,
        ], idPengguna: $data->idPengguna);

        return $dokumen;
    }
}
