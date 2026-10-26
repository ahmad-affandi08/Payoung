<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kontrak\PengosongStokProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Menghabiskan sisa stok beberapa produk lewat penyesuaian stok biasa (alasan `Hilang`, jurnal J-05.4: Dr Beban Susut,
 * Cr Persediaan), satu dokumen per lokasi stok, dicatat langsung oleh Pemilik (D-38). Dipakai hapus permanen produk
 * (D-218) supaya nilai persediaan di pembukuan turun lebih dulu, bukan menggantung setelah ledger stok produk dihapus.
 *
 * Yang belum didukung dan ditolak dengan petunjuk: stok minus (perbaiki lewat penyesuaian/opname dulu) dan produk ber-batch
 * atau bernomor seri (butuh pemilihan batch/nomor seri per baris; kosongkan lewat Penyesuaian stok).
 */
final class KosongkanStokProduk implements PengosongStokProduk
{
    public function __construct(
        private readonly InfoProdukStok $infoProduk,
        private readonly SimpanPenyesuaianStok $simpan,
        private readonly AjukanPenyesuaianStok $ajukan,
    ) {}

    /**
     * @param  list<int>  $idProduk
     * @return array{Lokasi: int, Nilai: string, Penghalang: string|null}
     */
    public function Pratinjau(array $idProduk): array
    {
        $saldo = $this->AmbilSaldoBerisi($idProduk);
        $nilai = BigDecimal::zero();

        foreach ($saldo as $baris) {
            $nilai = $nilai->plus($baris->NilaiPersediaan);
        }

        return ['Lokasi' => $saldo->count(), 'Nilai' => (string) $nilai->toScale(2), 'Penghalang' => $this->AmbilPenghalang($saldo)];
    }

    /**
     * @param  list<int>  $idProduk
     * @return int jumlah dokumen penyesuaian yang dicatat
     */
    public function Jalankan(array $idProduk, int $idPengguna, string $namaProduk): int
    {
        $saldo = $this->AmbilSaldoBerisi($idProduk);
        $penghalang = $this->AmbilPenghalang($saldo);

        if ($penghalang !== null) {
            throw new PelanggaranAturanBisnis('StokTidakBisaDikosongkan', $penghalang);
        }

        $dokumen = 0;

        foreach ($saldo->groupBy('IdGudang') as $idGudang => $barisGudang) {
            $baris = [];

            foreach ($barisGudang->sortBy('IdProduk') as $s) {
                $baris[] = new DataBarisDokumenStok($s->IdProduk, Kuantitas::Dari(BigDecimal::of($s->JumlahTersedia)->negated()));
            }

            $draf = $this->simpan->Jalankan(new DataPenyesuaianStok(
                null,
                (int) $idGudang,
                CarbonImmutable::now(),
                AlasanPenyesuaian::Hilang,
                mb_substr("Sisa stok dihapus bersama produk {$namaProduk} (hapus permanen)", 0, 250),
                $baris,
            ), null);
            $posting = $this->ajukan->Jalankan($draf, $idPengguna);

            if ($posting->Status !== StatusPenyesuaianStok::Diposting) {
                throw new PelanggaranAturanBisnis('StokTidakBisaDikosongkan', 'Penyesuaian untuk menghabiskan stok produk ini belum bisa dicatat. Kosongkan stok lewat Penyesuaian stok, lalu coba lagi.');
            }

            $dokumen++;
        }

        return $dokumen;
    }

    /**
     * @param  list<int>  $idProduk
     * @return Collection<int, SaldoStok>
     */
    private function AmbilSaldoBerisi(array $idProduk): Collection
    {
        return SaldoStok::query()->whereIn('IdProduk', $idProduk)->where('JumlahTersedia', '<>', 0)->orderBy('IdProduk')->orderBy('IdGudang')->get();
    }

    /** @param  Collection<int, SaldoStok>  $saldo */
    private function AmbilPenghalang(Collection $saldo): ?string
    {
        if ($saldo->isEmpty()) {
            return null;
        }

        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(static fn (SaldoStok $s): int => $s->IdProduk, $saldo->all()))));

        foreach ($saldo as $baris) {
            $produk = $info[$baris->IdProduk] ?? null;
            $nama = $produk->nama ?? 'Produk ini';

            if (BigDecimal::of($baris->JumlahTersedia)->isNegative()) {
                return "Stok {$nama} minus di sebagian lokasi. Perbaiki dulu lewat Penyesuaian stok atau Stok opname, lalu hapus permanen.";
            }

            if ($produk !== null && $produk->pelacakan !== PelacakanProduk::Tidak) {
                return "{$nama} memakai pelacakan batch atau nomor seri. Kosongkan stoknya lewat Penyesuaian stok (pilih batch atau nomor serinya), lalu hapus permanen.";
            }
        }

        return null;
    }
}
