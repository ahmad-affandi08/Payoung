<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\ProdukKategoriStok;
use App\Domain\Persediaan\Data\DataHitungOpname;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan lembar hitung stok opname (F-05b), boleh berkali-kali selama opname Berlangsung: jumlah fisik baris yang
 * ada (per `urutan`), atau baris baru hasil hitung/pindai (produk tanpa snapshot, batch baru, nomor seri yang
 * ditemukan) yang langsung digabung ke baris yang sudah ada bila produk/batch/seri-nya sama. Baris baru bersistem
 * Σ mutasinya sampai penanda snapshot opname, sehingga BR-05.3 tetap berlaku. Opname kategori menolak produk di luar
 * kategori (`ProdukDiLuarKategori`), opname per rak menolak produk yang tidak berada di rak itu (`ProdukDiLuarRak`).
 * Setiap baris menyimpan siapa yang menghitung dan kapan (`DihitungOleh`/`DihitungPada`), jadi beberapa penghitung
 * dapat mengisi lembar yang sama dan ringkasan per penghitung tersedia di detail. Jumlah fisik ≥ 0 (seri 0/1). Audit `stok-opname.hitung`.
 */
final class SimpanHitungStokOpname
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly InfoProdukStok $infoProduk,
        private readonly ProdukKategoriStok $kategori,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<DataHitungOpname>  $hitung
     */
    public function Jalankan(StokOpname $opname, array $hitung, int $idPengguna): StokOpname
    {
        return DB::transaction(function () use ($opname, $hitung, $idPengguna): StokOpname {
            $opname = StokOpname::query()->whereKey($opname->Id)->lockForUpdate()->firstOrFail();

            if ($opname->Status !== StatusStokOpname::Berlangsung) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Stok opname berstatus {$opname->Status->AmbilLabel()}. Lembar hitung hanya bisa diubah selama opname berlangsung.");
            }

            $baris = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->orderBy('Urutan')->get();
            $perUrutan = $baris->keyBy('Urutan');
            $perKunci = $baris->keyBy(fn (StokOpnameDetail $d): string => self::Kunci($d->IdProduk, $d->NomorBatch, $d->NomorSeri));
            $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_filter([
                ...$baris->pluck('IdProduk')->all(),
                ...array_map(fn (DataHitungOpname $h): ?int => $h->idProduk, $hitung),
            ], 'is_int'))), true);
            $dalamKategori = match (true) {
                $opname->IdKategori !== null => array_flip($this->kategori->AmbilIdProduk($opname->IdKategori)),
                $opname->IdRak !== null => array_flip(array_map('intval', ProdukRak::query()->where('IdRak', $opname->IdRak)->pluck('IdProduk')->all())),
                default => null,
            };
            $waktu = now();
            $urutanBaru = (int) $baris->max('Urutan');
            $jumlahDiubah = 0;

            foreach ($hitung as $i => $h) {
                $label = 'Baris '.($i + 1);
                $detail = $h->urutan !== null ? $perUrutan->get($h->urutan) : null;

                if ($h->urutan !== null && $detail === null) {
                    throw new PelanggaranAturanBisnis('BarisTidakValid', "{$label}: baris {$h->urutan} tidak ada di opname ini.", 'Hitung');
                }

                if ($detail === null) {
                    $info = $h->idProduk === null ? null : ($produk[$h->idProduk] ?? null);
                    $detail = $this->CariAtauBuatBaris($opname, $info, $h, $label, $perKunci, $dalamKategori, ++$urutanBaru);
                    $perUrutan->put($detail->Urutan, $detail);
                }

                $info = $produk[$detail->IdProduk] ?? null;
                $this->PeriksaJumlah($h, $info, $label);
                $detail->JumlahFisik = $h->jumlahFisik?->KeString();
                $detail->DihitungOleh = $h->jumlahFisik === null ? null : $idPengguna;
                $detail->DihitungPada = $h->jumlahFisik === null ? null : $waktu;
                $detail->save();
                $jumlahDiubah++;
            }

            $opname->JumlahBaris = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->count();
            $opname->JumlahDihitung = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->whereNotNull('JumlahFisik')->count();
            $opname->DiubahOleh = $idPengguna;
            $opname->save();
            $this->audit->Catat('stok-opname.hitung', $opname, null, ['BarisDisimpan' => $jumlahDiubah, 'JumlahDihitung' => $opname->JumlahDihitung, 'JumlahBaris' => $opname->JumlahBaris], idPengguna: $idPengguna);

            return $opname;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /**
     * @param  Collection<string, StokOpnameDetail>  $perKunci
     * @param  array<int, int>|null  $dalamKategori
     */
    private function CariAtauBuatBaris(StokOpname $opname, ?DataInfoProdukStok $info, DataHitungOpname $h, string $label, Collection $perKunci, ?array $dalamKategori, int $urutan): StokOpnameDetail
    {
        if ($info === null || $info->dihapus) {
            throw new PelanggaranAturanBisnis('ProdukTidakDikenal', "{$label}: produk tidak ditemukan.", 'Hitung');
        }

        if (! $info->jenis->CekPunyaStok() || $info->jenis === JenisProduk::Konsinyasi) {
            throw new PelanggaranAturanBisnis('ProdukTanpaStok', "{$label}: {$info->nama} tidak punya stok untuk dihitung.", 'Hitung');
        }

        if ($dalamKategori !== null && ! isset($dalamKategori[$info->id])) {
            throw $opname->IdRak !== null
                ? new PelanggaranAturanBisnis('ProdukDiLuarRak', "{$label}: {$info->nama} tidak berada di rak {$opname->NamaRak} yang sedang diopname.", 'Hitung')
                : new PelanggaranAturanBisnis('ProdukDiLuarKategori', "{$label}: {$info->nama} tidak termasuk kategori {$opname->NamaKategori} yang sedang diopname.", 'Hitung');
        }

        $nomorBatch = $info->pelacakan === PelacakanProduk::Batch ? trim((string) $h->nomorBatch) : null;
        $nomorSeri = $info->pelacakan === PelacakanProduk::Seri ? trim((string) $h->nomorSeri) : null;

        if ($nomorBatch === '' || ($nomorBatch !== null && mb_strlen($nomorBatch) > 60)) {
            throw new PelanggaranAturanBisnis('BatchWajib', "{$label}: isi nomor batch {$info->nama}.", 'Hitung');
        }

        if ($nomorSeri === '' || ($nomorSeri !== null && mb_strlen($nomorSeri) > 100)) {
            throw new PelanggaranAturanBisnis('NomorSeriWajib', "{$label}: isi nomor seri {$info->nama}.", 'Hitung');
        }

        if ($info->pelacakan === PelacakanProduk::Tidak && ($h->nomorBatch !== null || $h->nomorSeri !== null)) {
            throw new PelanggaranAturanBisnis('BarisTidakValid', "{$label}: {$info->nama} tidak memakai batch atau nomor seri.", 'Hitung');
        }

        $ada = $perKunci->first(fn (StokOpnameDetail $d): bool => $d->IdProduk === $info->id
            && mb_strtolower((string) $d->NomorBatch) === mb_strtolower((string) $nomorBatch)
            && (string) $d->NomorSeri === (string) $nomorSeri);

        if ($ada instanceof StokOpnameDetail) {
            return $ada;
        }

        $barisSnapshot = $perKunci->first(fn (StokOpnameDetail $d): bool => $d->IdProduk === $info->id && $d->DariSnapshot);
        $penanda = $barisSnapshot instanceof StokOpnameDetail ? $barisSnapshot->IdMutasiSnapshot : $opname->IdMutasiSnapshot;
        $batch = $nomorBatch === null ? null : BatchStok::query()->where('IdProduk', $info->id)->where('IdGudang', $opname->IdGudang)->where('NomorBatch', $nomorBatch)->first();
        $seri = $nomorSeri === null ? null : NomorSeri::query()->where('IdProduk', $info->id)->where('Nomor', $nomorSeri)->first();

        if ($batch === null && $nomorBatch !== null && (bool) config('persediaan.StokAwal.WajibKedaluwarsaBatch', true) && $h->tanggalKedaluwarsa === null) {
            throw new PelanggaranAturanBisnis('KedaluwarsaWajib', "{$label}: batch {$nomorBatch} belum tercatat. Isi tanggal kedaluwarsanya.", 'Hitung');
        }

        $sistem = MutasiStok::query()
            ->where('IdProduk', $info->id)
            ->where('IdGudang', $opname->IdGudang)
            ->where('Id', '<=', $penanda)
            ->when($nomorBatch !== null, fn ($k) => $k->where('IdBatchStok', $batch->Id ?? 0))
            ->when($nomorSeri !== null, fn ($k) => $k->where('IdNomorSeri', $seri->Id ?? 0))
            ->sum('Jumlah');

        $detail = new StokOpnameDetail;
        $detail->fill([
            'IdTenant' => $this->konteks->Wajib(),
            'IdStokOpname' => $opname->Id,
            'Urutan' => $urutan,
            'IdProduk' => $info->id,
            'NamaProduk' => mb_substr($info->nama, 0, 150),
            'Sku' => $info->sku,
            'IdBatchStok' => $batch?->Id,
            'NomorBatch' => $batch->NomorBatch ?? $nomorBatch,
            'TanggalKedaluwarsa' => $batch?->TanggalKedaluwarsa?->format('Y-m-d') ?? $h->tanggalKedaluwarsa?->format('Y-m-d'),
            'IdNomorSeri' => $seri?->Id,
            'NomorSeri' => $seri->Nomor ?? $nomorSeri,
            'DariSnapshot' => false,
            'JumlahSistem' => (string) BigDecimal::of((string) $sistem)->toScale(4),
            'IdMutasiSnapshot' => $penanda,
        ]);
        $detail->save();
        $perKunci->put(self::Kunci($detail->IdProduk, $detail->NomorBatch, $detail->NomorSeri), $detail);

        return $detail;
    }

    private function PeriksaJumlah(DataHitungOpname $h, ?DataInfoProdukStok $info, string $label): void
    {
        if ($h->jumlahFisik === null) {
            return;
        }

        $q = $h->jumlahFisik->KeDesimal();
        $nama = $info->nama ?? 'produk';

        if ($q->isNegative() || $q->strippedOfTrailingZeros()->getScale() > 4 || ($info !== null && ! $info->bolehDesimal && ! $q->getFractionalPart()->isZero())) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', "{$label}: jumlah fisik {$nama} harus ≥ 0".($info !== null && ! $info->bolehDesimal ? ' dan bilangan bulat.' : ', maksimal 4 desimal.'), 'Hitung');
        }

        if ($info?->pelacakan === PelacakanProduk::Seri && ! $q->isZero() && ! $q->isEqualTo(1)) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', "{$label}: nomor seri {$nama} dihitung 1 (ada) atau 0 (tidak ada).", 'Hitung');
        }
    }

    private static function Kunci(int $idProduk, ?string $nomorBatch, ?string $nomorSeri): string
    {
        return $idProduk.':'.mb_strtolower((string) $nomorBatch).':'.$nomorSeri;
    }
}
