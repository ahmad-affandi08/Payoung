<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Layanan\AturanProduk;
use App\Domain\Katalog\Layanan\PenyusunAnakVarian;
use App\Domain\Katalog\Layanan\RingkasanAuditProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-03 impor (mode tambah & perbarui): ubah sebagian kolom produk; null = tidak diubah. Aturan sama dengan form
 * (jenis, pelacakan termasuk `PelacakanTerkunci` F-05a, kelompok pajak, BahanBaku tidak tampil di POS). Satuan, barcode, dan harga diurus Aksi lain.
 * Audit `produk.ubah` kecuali sumber Impor (impor mencatat audit per potongan). Tanpa perubahan = tanpa tulis.
 */
final class PerbaruiProdukSebagian
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly AturanProduk $aturan,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(Produk $produk, DataPerubahanProduk $data, SumberPerubahanKatalog $sumber = SumberPerubahanKatalog::Manual): Produk
    {
        return DB::transaction(function () use ($produk, $data, $sumber): Produk {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $produk = Produk::query()->whereKey($produk->Id)->lockForUpdate()->firstOrFail();
            $lama = RingkasanAuditProduk::Ambil($produk);
            $jenis = $data->jenis ?? $produk->Jenis;

            if ($data->jenis !== null) {
                $this->aturan->PastikanJenisBolehDiubah($produk, $data->jenis);

                if ($produk->IdInduk !== null) {
                    $this->aturan->PastikanJenisAnak($data->jenis);
                }
            }

            if ($data->pelacakan !== null) {
                $this->aturan->PastikanPelacakanBolehDiubah($produk, $data->pelacakan);
            }

            if ($data->nama !== null && (trim($data->nama) === '' || mb_strlen(trim($data->nama)) > 150)) {
                throw new PelanggaranAturanBisnis('NamaTidakValid', 'Nama produk wajib diisi, maksimal 150 karakter.', 'Nama');
            }

            if ($data->idKategori !== null) {
                $this->aturan->PastikanKategori($data->idKategori);
            }

            $idKelompokPajak = $data->idKelompokPajak ?? $produk->IdKelompokPajak;
            $this->aturan->PastikanKelompokPajak($jenis, $idKelompokPajak);
            [$pelacakan, $bolehMinus] = $this->aturan->TentukanPelacakan(
                $jenis,
                $data->pelacakan ?? $produk->Pelacakan,
                $this->aturan->AmbilSatuan($produk->IdSatuanDasar),
                $data->bolehMinus ?? $produk->BolehMinus,
            );

            $produk->fill(array_filter([
                'Nama' => $data->nama === null ? null : trim($data->nama),
                'NamaStruk' => $data->namaStruk === null ? null : (trim($data->namaStruk) === '' ? null : trim($data->namaStruk)),
                'Merek' => $data->merek === null ? null : (trim($data->merek) === '' ? null : trim($data->merek)),
                'IdKategori' => $data->idKategori,
                'TampilOnline' => $data->tampilOnline,
            ], fn (mixed $nilai): bool => $nilai !== null));
            $produk->fill([
                'Jenis' => $jenis,
                'IdKelompokPajak' => $idKelompokPajak,
                'Pelacakan' => $pelacakan,
                'BolehMinus' => $bolehMinus,
                'TampilDiPos' => AturanProduk::TentukanTampilDiPos($jenis, $data->tampilDiPos ?? $produk->TampilDiPos),
                'Favorit' => AturanProduk::TentukanFavorit($jenis, $data->favorit ?? $produk->Favorit),
            ]);

            if ($data->kosongkanHargaTermasukPajak) {
                $produk->HargaTermasukPajak = null;
            } elseif ($data->hargaTermasukPajak !== null) {
                $produk->HargaTermasukPajak = $data->hargaTermasukPajak;
            }

            if (! $produk->isDirty()) {
                return $produk;
            }

            $produk->save();

            if ($produk->Jenis === JenisProduk::IndukVarian) {
                PenyusunAnakVarian::TeruskanDariInduk($produk);
            }

            if ($sumber !== SumberPerubahanKatalog::Impor) {
                $this->audit->Catat('produk.ubah', $produk, $lama, RingkasanAuditProduk::Ambil($produk));
            }

            return $produk;
        });
    }
}
