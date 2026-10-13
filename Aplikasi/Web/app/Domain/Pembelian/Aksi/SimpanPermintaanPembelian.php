<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Pembelian\Data\DataBarisTerhitung;
use App\Domain\Pembelian\Data\DataPermintaanPembelian;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyelesaiBarisPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelianDetail;
use Illuminate\Support\Facades\DB;

/**
 * Membuat atau mengubah draf permintaan pembelian (F-04 PR, izin `pembelian.kelola`). Nomor `PP/{OUTLET}/{YYMM}/{SEQ4}`,
 * status Draf; hanya Draf yang bisa diubah (`StatusTidakSesuai`). Lokasi stok harus aktif, pemasok usulan (bila ada)
 * aktif, 1–500 baris, satu baris per (produk, satuan). Tidak ada dampak stok/jurnal. Audit `permintaan-pembelian.simpan`.
 */
final class SimpanPermintaanPembelian
{
    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly PenyelesaiBarisPembelian $penyelesai,
        private readonly PenomorPembelian $penomor,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PemasokTidakDikenal, GudangTidakDikenal, BarisKosong, BarisGanda, StatusTidakSesuai, …
     */
    public function Jalankan(DataPermintaanPembelian $data, ?PermintaanPembelian $permintaan = null): PermintaanPembelian
    {
        return DB::transaction(function () use ($data, $permintaan): PermintaanPembelian {
            $terkunci = $permintaan === null ? null : PermintaanPembelian::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci !== null && $terkunci->Status !== StatusPermintaanPembelian::Draf) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Permintaan pembelian berstatus {$terkunci->Status->AmbilLabel()} tidak bisa diubah.");
            }

            $pemasok = null;

            if ($data->uuidPemasok !== null && $data->uuidPemasok !== '') {
                $pemasok = Pemasok::query()->where('Uuid', $data->uuidPemasok)->first();

                if ($pemasok === null || (! $pemasok->Aktif && $pemasok->Id !== $terkunci?->IdPemasok)) {
                    throw new PelanggaranAturanBisnis('PemasokTidakDikenal', 'Pemasok tidak ditemukan atau nonaktif.', 'UuidPemasok');
                }
            }

            $gudang = $this->infoGudang->AmbilBanyak([$data->idGudang])[$data->idGudang] ?? null;

            if ($gudang === null || (! $gudang->aktif && $gudang->id !== $terkunci?->IdGudang)) {
                throw new PelanggaranAturanBisnis('GudangTidakDikenal', 'Lokasi stok tidak ditemukan atau diarsipkan.', 'UuidGudang');
            }

            if ($data->dibutuhkanPada !== null && $data->dibutuhkanPada->lt($data->tanggal)) {
                throw new PelanggaranAturanBisnis('TanggalTidakValid', 'Tanggal dibutuhkan tidak boleh sebelum tanggal permintaan.', 'DibutuhkanPada');
            }

            $baris = $this->HitungBaris($data);
            $isian = [
                'IdGudang' => $gudang->id,
                'IdOutlet' => $gudang->idOutlet,
                'IdPemasok' => $pemasok?->Id,
                'Tanggal' => $data->tanggal->toDateString(),
                'DibutuhkanPada' => $data->dibutuhkanPada?->toDateString(),
                'Catatan' => $data->catatan === null || trim($data->catatan) === '' ? null : trim($data->catatan),
                'DiubahOleh' => $data->idPengguna,
            ];

            if ($terkunci === null) {
                $terkunci = PermintaanPembelian::query()->create([
                    ...$isian,
                    'Nomor' => $this->penomor->AmbilNomorLokasi(JenisDokumenBernomor::PermintaanPembelian, $data->tanggal, $gudang),
                    'Status' => StatusPermintaanPembelian::Draf,
                    'DibuatOleh' => $data->idPengguna,
                ]);
                $this->riwayat->Catat(PermintaanPembelian::JENIS_DOKUMEN, $terkunci->Id, null, StatusPermintaanPembelian::Draf->value, $data->idPengguna);
            } else {
                $terkunci->fill([...$isian, 'AlasanDitolak' => $terkunci->AlasanDitolak])->save();
                PermintaanPembelianDetail::query()->where('IdPermintaanPembelian', $terkunci->Id)->get()->each(fn (PermintaanPembelianDetail $d) => $d->delete());
            }

            foreach ($baris as $i => $b) {
                PermintaanPembelianDetail::query()->create([
                    'IdPermintaanPembelian' => $terkunci->Id,
                    'Urutan' => $i + 1,
                    'IdProduk' => $b->produk->id,
                    'NamaProduk' => mb_substr($b->produk->nama, 0, 150),
                    'Sku' => $b->produk->sku,
                    'IdProdukSatuan' => $b->idProdukSatuan,
                    'SimbolSatuan' => mb_substr($b->simbolSatuan, 0, 20),
                    'Konversi' => $b->konversi->KeString(),
                    'Jumlah' => $b->jumlah->KeString(),
                    'HargaPerkiraan' => $b->harga->KeString(),
                ]);
            }

            $this->audit->Catat('permintaan-pembelian.simpan', $terkunci, nilaiBaru: [
                'Nomor' => $terkunci->Nomor,
                'JumlahBaris' => count($baris),
                'Pemasok' => $pemasok?->Nama,
            ], idPengguna: $data->idPengguna);

            return $terkunci;
        }, 3);
    }

    /**
     * @return list<DataBarisTerhitung>
     */
    private function HitungBaris(DataPermintaanPembelian $data): array
    {
        if ($data->baris === [] || count($data->baris) > SimpanPesananPembelian::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Isi 1 sampai '.SimpanPesananPembelian::MAKS_BARIS.' baris barang.', 'Baris');
        }

        $produk = $this->penyelesai->AmbilProduk(array_values(array_unique(array_map(fn ($b): string => $b->uuidProduk, $data->baris))));
        $hasil = [];
        $kunci = [];

        foreach ($data->baris as $i => $b) {
            $hitung = $this->penyelesai->Hitung($i, $produk, $b->uuidProduk, $b->uuidProdukSatuan, $b->jumlah, $b->harga, $b->diskon);
            $k = $hitung->produk->id.':'.($hitung->idProdukSatuan ?? 0);

            if (isset($kunci[$k])) {
                throw PenyelesaiBarisPembelian::Galat($i, 'Produk', 'BarisGanda', "{$hitung->produk->nama} dengan satuan {$hitung->simbolSatuan} sudah ada di baris lain.");
            }

            $kunci[$k] = true;
            $hasil[] = $hitung;
        }

        return $hasil;
    }
}
