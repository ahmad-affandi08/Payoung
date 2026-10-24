<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisPermintaanStok;
use App\Domain\Persediaan\Data\DataPermintaanStok;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PenomorDokumenPersediaan;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStokDetail;
use App\Domain\Persediaan\Tugas\BeritahuPermintaanStokTugas;
use Illuminate\Support\Facades\DB;

/**
 * Mengajukan permintaan stok (F-05b, D-105): lokasi tujuan (yang meminta dan akan menerima) meminta barang dari lokasi
 * asal (yang diminta mengirim). Lokasi harus berbeda, aktif, dan bukan lokasi dalam perjalanan; produk berstok milik
 * tenant, satu baris per produk, jumlah > 0 dalam satuan dasar. Idempoten per Uuid klien. Tidak mengubah stok atau
 * jurnal. Audit `permintaan-stok.ajukan`. Mengabari manajer outlet asal (D-187).
 */
final class AjukanPermintaanStok
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly InfoProdukStok $infoProduk,
        private readonly PenomorDokumenPersediaan $penomor,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataPermintaanStok $data): PermintaanStok
    {
        if ($data->uuid !== null) {
            $lama = PermintaanStok::query()->where('Uuid', $data->uuid)->first();

            if ($lama !== null) {
                return $lama;
            }
        }

        return DB::transaction(function () use ($data): PermintaanStok {
            $oleh = $this->audit->AmbilIdPengguna();
            $asal = $this->pemeriksaLokasi->AmbilLokasi($data->idGudangAsal, 'UuidGudangAsal');
            $tujuan = $this->pemeriksaLokasi->AmbilLokasi($data->idGudangTujuan, 'UuidGudangTujuan');

            if ($asal->id === $tujuan->id) {
                throw new PelanggaranAturanBisnis('LokasiSama', 'Lokasi pengirim harus berbeda dari lokasi yang meminta.', 'UuidGudangAsal');
            }

            $this->pemeriksaLokasi->PastikanBukanMasaDepan($data->tanggal, $tujuan->idOutlet);
            $produk = $this->PeriksaBaris($data->baris);
            $catatan = $data->catatan === null ? null : trim($data->catatan);
            $permintaan = new PermintaanStok;

            if ($data->uuid !== null) {
                $permintaan->Uuid = $data->uuid;
            }

            $permintaan->fill([
                'Nomor' => $this->penomor->AmbilPermintaanStok($data->tanggal, $tujuan->kode, $asal->kode),
                'IdGudangAsal' => $asal->id,
                'IdOutletAsal' => $asal->idOutlet,
                'IdGudangTujuan' => $tujuan->id,
                'IdOutletTujuan' => $tujuan->idOutlet,
                'Tanggal' => $data->tanggal->format('Y-m-d'),
                'Status' => StatusPermintaanStok::Diajukan,
                'Catatan' => $catatan === '' ? null : $catatan,
                'JumlahBaris' => count($data->baris),
                'DibuatOleh' => $oleh,
            ]);
            $permintaan->save();

            $idTenant = $this->konteks->Wajib();
            $waktu = now();
            PermintaanStokDetail::query()->insert(array_map(fn (DataBarisPermintaanStok $b, int $i): array => [
                'IdTenant' => $idTenant,
                'IdPermintaanStok' => $permintaan->Id,
                'Urutan' => $i + 1,
                'IdProduk' => $b->idProduk,
                'NamaProduk' => mb_substr($produk[$b->idProduk]->nama, 0, 150),
                'Sku' => $produk[$b->idProduk]->sku,
                'JumlahDiminta' => $b->jumlah->KeString(),
                'JumlahDikirim' => '0.0000',
                'DibuatPada' => $waktu,
                'DiubahPada' => $waktu,
            ], $data->baris, array_keys($data->baris)));

            $this->riwayat->Catat(PermintaanStok::JENIS_DOKUMEN, $permintaan->Id, null, StatusPermintaanStok::Diajukan->value, $oleh);
            $this->audit->Catat('permintaan-stok.ajukan', $permintaan, null, [
                'Nomor' => $permintaan->Nomor,
                'NamaGudangAsal' => $asal->nama,
                'NamaGudangTujuan' => $tujuan->nama,
                'JumlahBaris' => $permintaan->JumlahBaris,
            ]);

            // Kabar non-kritis (aturan #10): antrean, setelah commit.
            BeritahuPermintaanStokTugas::dispatch($permintaan->IdTenant, $permintaan->Id, StatusPermintaanStok::Diajukan)->afterCommit();

            return $permintaan;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /**
     * @param  list<DataBarisPermintaanStok>  $baris
     * @return array<int, DataInfoProdukStok> kunci = IdProduk
     */
    private function PeriksaBaris(array $baris): array
    {
        $maksimal = (int) config('persediaan.Dokumen.MaksimalBaris', 500);

        if ($baris === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Tambahkan minimal satu produk.', 'Baris');
        }

        if (count($baris) > $maksimal) {
            throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', "Satu permintaan maksimal {$maksimal} baris.", 'Baris');
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (DataBarisPermintaanStok $b): int => $b->idProduk, $baris))));
        $terlihat = [];

        foreach (array_values($baris) as $i => $b) {
            $info = $produk[$b->idProduk] ?? null;
            $kunci = "Baris.{$i}.Jumlah";

            if ($info === null || $info->diarsipkan || $info->dihapus) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', 'Baris '.($i + 1).': produk tidak ditemukan atau sudah diarsipkan.', "Baris.{$i}.UuidProduk");
            }

            if (isset($terlihat[$b->idProduk])) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', 'Baris '.($i + 1).": {$info->nama} sudah ada di permintaan ini. Gabungkan jumlahnya dalam satu baris.", $kunci);
            }

            $terlihat[$b->idProduk] = true;
            $q = $b->jumlah->KeDesimal();

            if ($q->isNegativeOrZero() || $q->strippedOfTrailingZeros()->getScale() > 4 || (! $info->bolehDesimal && ! $q->getFractionalPart()->isZero())) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Baris '.($i + 1).": jumlah {$info->nama} harus lebih dari 0".($info->bolehDesimal ? '.' : ' dan bilangan bulat.'), $kunci);
            }
        }

        return $produk;
    }
}
