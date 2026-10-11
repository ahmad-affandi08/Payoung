<?php

declare(strict_types=1);

namespace Tests\Pendukung\Persediaan;

use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Persediaan\Aksi\SimpanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\SimpanTransferStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataPenyesuaianStok;
use App\Domain\Persediaan\Data\DataTransferStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\TransferStok;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

/**
 * Prasyarat test dokumen persediaan F-05b (transfer, opname, penyesuaian): outlet kedua, baris masukan, draf lewat
 * Aksi, saldo ringkas, baris jurnal per peran, dan penangkap kode galat.
 */
final class BantuanDokumenPersediaan
{
    public static function BuatOutlet(string $kode = 'SOLO', string $nama = 'Cabang Solo Baru'): Outlet
    {
        return Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => $kode, 'Nama' => $nama]);
    }

    public static function Baris(Produk $produk, string $jumlah, ?string $hpp = null, ?int $idBatch = null, ?string $nomorBatch = null, ?string $kedaluwarsa = null, ?int $idSeri = null, ?string $nomorSeri = null): DataBarisDokumenStok
    {
        return new DataBarisDokumenStok(
            $produk->Id,
            Kuantitas::Dari($jumlah),
            $hpp === null ? null : BigDecimal::of($hpp),
            $idBatch,
            $nomorBatch,
            $kedaluwarsa === null ? null : CarbonImmutable::parse($kedaluwarsa),
            $idSeri,
            $nomorSeri,
        );
    }

    /**
     * @param  list<DataBarisDokumenStok>  $baris
     */
    public static function DrafTransfer(Gudang $asal, Gudang $tujuan, array $baris, ?string $tanggal = null, ?string $uuidPermintaanStok = null): TransferStok
    {
        return app(SimpanTransferStok::class)->Jalankan(new DataTransferStok(
            (string) Str::ulid(),
            $asal->Id,
            $tujuan->Id,
            CarbonImmutable::parse($tanggal ?? self::Kemarin()),
            'Kirim stok mingguan ke cabang',
            $baris,
            uuidPermintaanStok: $uuidPermintaanStok,
        ), null);
    }

    /**
     * @param  list<DataBarisDokumenStok>  $baris
     */
    public static function DrafPenyesuaian(Gudang $gudang, AlasanPenyesuaian $alasan, array $baris, ?string $keterangan = null, ?string $tanggal = null): PenyesuaianStok
    {
        return app(SimpanPenyesuaianStok::class)->Jalankan(new DataPenyesuaianStok(
            (string) Str::ulid(),
            $gudang->Id,
            CarbonImmutable::parse($tanggal ?? self::Kemarin()),
            $alasan,
            $keterangan,
            $baris,
        ), null);
    }

    public static function Kemarin(): string
    {
        return CarbonImmutable::now('Asia/Jakarta')->subDay()->format('Y-m-d');
    }

    /** @return array{0: string, 1: string} [JumlahTersedia, NilaiPersediaan] */
    public static function Saldo(Produk $produk, Gudang|int $gudang): array
    {
        $s = SaldoStok::query()->where('IdProduk', $produk->Id)->where('IdGudang', $gudang instanceof Gudang ? $gudang->Id : $gudang)->first();

        return [$s->JumlahTersedia ?? '0.0000', $s->NilaiPersediaan ?? '0.00'];
    }

    /**
     * Baris jurnal sebagai [Kunci peran, Debit, Kredit, IdOutlet].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int|null}>
     */
    public static function BarisJurnal(int $idJurnal): array
    {
        $peran = PemetaanAkun::query()->whereNull('IdOutlet')->pluck('Kunci', 'IdAkun')->all();

        return array_values(JurnalDetail::query()->where('IdJurnal', $idJurnal)->orderBy('Urutan')->get()
            ->map(fn (JurnalDetail $d): array => [(string) ($peran[$d->IdAkun] ?? $d->IdAkun), $d->Debit, $d->Kredit, $d->IdOutlet])
            ->all());
    }

    public static function KodeGalat(Closure $kerja): ?string
    {
        try {
            $kerja();
        } catch (PelanggaranAturanBisnis $galat) {
            return $galat->kode;
        }

        return null;
    }
}
