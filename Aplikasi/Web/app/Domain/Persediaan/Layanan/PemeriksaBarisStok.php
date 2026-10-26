<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\NomorSeri;
use Brick\Math\BigDecimal;

/**
 * Pemeriksaan baris dokumen persediaan F-05b (transfer, penyesuaian) sebelum draf disimpan dan diulang saat
 * dicatat. `transfer` = jumlah selalu positif dan berarti keluar dari lokasi asal; selain itu jumlah bertanda
 * (+ masuk hanya bila `bolehMasuk`, − keluar). Diperiksa: produk berstok milik tenant (bukan konsinyasi, belum diarsipkan), jumlah ≠ 0 dengan arah yang diizinkan,
 * satuan bulat/desimal, kelengkapan batch/nomor seri sesuai pelacakan produk (batch/seri keluar harus ada di lokasi
 * stok itu), dan baris ganda. Galat pertama dilempar sebagai `PelanggaranAturanBisnis` bernomor baris; daftar semua
 * galat di `Detail.Baris`.
 */
final class PemeriksaBarisStok
{
    public function __construct(private readonly InfoProdukStok $infoProduk) {}

    /**
     * @param  list<DataBarisDokumenStok>  $baris
     * @return array<int, DataInfoProdukStok> kunci = IdProduk
     */
    public function Periksa(array $baris, int $idGudang, bool $transfer, bool $bolehMasuk = false): array
    {
        $maksimal = (int) config('persediaan.Dokumen.MaksimalBaris', 500);

        if ($baris === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Tambahkan minimal satu produk.', 'Baris');
        }

        if (count($baris) > $maksimal) {
            throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', "Satu dokumen maksimal {$maksimal} baris. Pecah menjadi beberapa dokumen.", 'Baris');
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (DataBarisDokumenStok $b): int => $b->idProduk, $baris))));
        $batch = BatchStok::query()->whereIn('Id', array_values(array_filter(array_map(fn (DataBarisDokumenStok $b): ?int => $b->idBatchStok, $baris))))->get()->keyBy('Id');
        $seri = NomorSeri::query()->whereIn('Id', array_values(array_filter(array_map(fn (DataBarisDokumenStok $b): ?int => $b->idNomorSeri, $baris))))->get()->keyBy('Id');
        $galat = [];
        $kunci = [];

        foreach ($baris as $i => $b) {
            $urutan = $i + 1;
            $info = $produk[$b->idProduk] ?? null;
            $pesan = $this->PeriksaSatu($b, $info, $idGudang, $transfer, $bolehMasuk, $batch->get((int) $b->idBatchStok), $seri->get((int) $b->idNomorSeri));

            if ($pesan === null && $info !== null) {
                $k = $b->idProduk.':'.($b->idBatchStok ?? mb_strtolower(trim((string) $b->nomorBatch))).':'.($b->idNomorSeri ?? trim((string) $b->nomorSeri)).':'.($b->jumlah->BernilaiNegatif() ? '-' : '+');

                if (isset($kunci[$k])) {
                    $pesan = "{$info->nama} sudah ada di baris {$kunci[$k]}. Gabungkan barisnya.";
                } else {
                    $kunci[$k] = $urutan;
                }
            }

            if ($pesan !== null) {
                $galat[] = ['Urutan' => $urutan, 'Pesan' => $pesan];
            }
        }

        if ($galat !== []) {
            $pertama = $galat[0];

            throw new PelanggaranAturanBisnis(
                'BarisTidakValid',
                "Baris {$pertama['Urutan']}: {$pertama['Pesan']}".(count($galat) > 1 ? ' (dan '.(count($galat) - 1).' baris lain)' : ''),
                'Baris',
                detail: ['Baris' => $galat],
            );
        }

        return $produk;
    }

    private function PeriksaSatu(DataBarisDokumenStok $b, ?DataInfoProdukStok $p, int $idGudang, bool $transfer, bool $bolehMasuk, ?BatchStok $batch, ?NomorSeri $seri): ?string
    {
        if ($p === null || $p->dihapus) {
            return 'Produk tidak ditemukan.';
        }

        if (! $p->jenis->CekPunyaStok() || $p->jenis === JenisProduk::Konsinyasi) {
            return "{$p->nama} berjenis {$p->jenis->AmbilLabel()} tidak bisa dipakai di dokumen stok.";
        }

        $q = $b->jumlah->KeDesimal();

        if ($q->isZero()) {
            return "Jumlah {$p->nama} tidak boleh 0.";
        }

        if ($transfer && $q->isNegative()) {
            return "Jumlah {$p->nama} harus lebih dari 0.";
        }

        if (! $transfer && $q->isPositive() && ! $bolehMasuk) {
            return "Alasan penyesuaian ini hanya boleh mengurangi stok {$p->nama}.";
        }

        if ($q->strippedOfTrailingZeros()->getScale() > 4 || (! $p->bolehDesimal && ! $q->getFractionalPart()->isZero())) {
            return $p->bolehDesimal ? "Jumlah {$p->nama} maksimal 4 angka desimal." : "Jumlah {$p->nama} harus bilangan bulat ({$p->simbolSatuan}).";
        }

        $keluar = $transfer || $q->isNegative();

        if (! $keluar && $b->hppSatuan === null) {
            return "Isi harga modal per satuan {$p->nama} untuk stok masuk.";
        }

        if ($b->hppSatuan !== null && ($keluar || $b->hppSatuan->isNegative() || $b->hppSatuan->strippedOfTrailingZeros()->getScale() > 6)) {
            return $keluar ? "Stok keluar {$p->nama} dinilai HPP berjalan; harga modal tidak diisi." : "Harga modal {$p->nama} tidak boleh negatif, maksimal 6 angka desimal.";
        }

        return match ($p->pelacakan) {
            PelacakanProduk::Tidak => $b->idBatchStok !== null || $b->idNomorSeri !== null || $b->nomorBatch !== null || $b->nomorSeri !== null
                ? "{$p->nama} tidak memakai batch atau nomor seri."
                : null,
            PelacakanProduk::Batch => $this->PeriksaBatch($b, $p, $idGudang, $keluar, $batch),
            PelacakanProduk::Seri => $this->PeriksaSeri($b, $p, $idGudang, $keluar, $seri),
        };
    }

    private function PeriksaBatch(DataBarisDokumenStok $b, DataInfoProdukStok $p, int $idGudang, bool $keluar, ?BatchStok $batch): ?string
    {
        if ($b->idNomorSeri !== null || $b->nomorSeri !== null) {
            return "{$p->nama} memakai batch, bukan nomor seri.";
        }

        if ($keluar) {
            if ($b->nomorBatch !== null || $b->tanggalKedaluwarsa !== null) {
                return "Batch keluar {$p->nama} dipilih dari batch yang ada, bukan diketik.";
            }

            if ($batch === null || $batch->IdProduk !== $p->id || $batch->IdGudang !== $idGudang) {
                return "Pilih batch {$p->nama} yang ada di lokasi stok ini.";
            }

            return BigDecimal::of($batch->JumlahSisa)->isLessThan($b->jumlah->KeDesimal()->abs())
                ? "Stok batch {$batch->NomorBatch} untuk {$p->nama} tidak cukup (tersedia ".PemeriksaStokMinus::FormatJumlah(Kuantitas::Dari($batch->JumlahSisa)).').'
                : null;
        }

        $nomor = trim((string) $b->nomorBatch);

        if ($b->idBatchStok !== null) {
            return "Batch masuk {$p->nama} diisi nomor & kedaluwarsanya.";
        }

        if ($nomor === '' || mb_strlen($nomor) > 60) {
            return "Isi nomor batch {$p->nama} (maksimal 60 karakter).";
        }

        return (bool) config('persediaan.StokAwal.WajibKedaluwarsaBatch', true) && $b->tanggalKedaluwarsa === null
            ? "Isi tanggal kedaluwarsa batch {$nomor}."
            : null;
    }

    private function PeriksaSeri(DataBarisDokumenStok $b, DataInfoProdukStok $p, int $idGudang, bool $keluar, ?NomorSeri $seri): ?string
    {
        if ($b->idBatchStok !== null || $b->nomorBatch !== null) {
            return "{$p->nama} memakai nomor seri, bukan batch.";
        }

        if (! $b->jumlah->KeDesimal()->abs()->isEqualTo(1)) {
            return "Satu baris {$p->nama} berisi tepat satu nomor seri (jumlah 1).";
        }

        if ($keluar) {
            if ($b->nomorSeri !== null) {
                return "Nomor seri keluar {$p->nama} dipilih dari nomor yang tersedia.";
            }

            return $seri === null || $seri->IdProduk !== $p->id || $seri->Status !== StatusNomorSeri::Tersedia || $seri->IdGudang !== $idGudang
                ? "Pilih nomor seri {$p->nama} yang tersedia di lokasi stok ini."
                : null;
        }

        $nomor = trim((string) $b->nomorSeri);

        if ($b->idNomorSeri !== null) {
            return "Nomor seri masuk {$p->nama} diketik, bukan dipilih.";
        }

        return $nomor === '' || mb_strlen($nomor) > 100 ? "Isi nomor seri {$p->nama} (maksimal 100 karakter)." : null;
    }
}
