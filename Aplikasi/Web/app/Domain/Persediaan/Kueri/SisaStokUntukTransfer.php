<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Model\SaldoStok;

/**
 * Isi awal form transfer untuk "Bongkar stok" kanvas (D-225): seluruh sisa stok di satu lokasi (bak kendaraan) menjadi
 * baris draf, supaya pengguna tidak menambah produk satu per satu. Produk ber-batch atau bernomor seri tidak diisi
 * otomatis karena batch/serinya harus dipilih per unit; jumlahnya dilaporkan agar pengguna menambahkannya sendiri.
 */
final class SisaStokUntukTransfer
{
    public function __construct(private readonly InfoProdukStok $infoProduk) {}

    /**
     * @return array{Baris: list<array<string, mixed>>, JumlahDilewati: int, Terpotong: bool}
     */
    public function Ambil(int $idGudang, int $batasBaris): array
    {
        $saldo = SaldoStok::query()
            ->where('IdGudang', $idGudang)
            ->where('JumlahTersedia', '>', 0)
            ->orderBy('IdProduk')
            ->get(['IdProduk', 'JumlahTersedia']);
        $info = $this->infoProduk->AmbilBanyak(array_values(array_map(static fn (SaldoStok $s): int => $s->IdProduk, $saldo->all())));
        $baris = [];
        $dilewati = 0;
        $terpotong = false;

        foreach ($saldo as $s) {
            $produk = $info[$s->IdProduk] ?? null;

            if ($produk === null || $produk->diarsipkan) {
                continue;
            }

            if ($produk->pelacakan !== PelacakanProduk::Tidak) {
                $dilewati++;

                continue;
            }

            if (count($baris) >= $batasBaris) {
                $terpotong = true;

                break;
            }

            $baris[] = [
                'UuidProduk' => $produk->uuid,
                'NamaProduk' => $produk->nama,
                'Sku' => $produk->sku,
                'SimbolSatuan' => $produk->simbolSatuan,
                'BolehDesimal' => $produk->bolehDesimal,
                'Pelacakan' => $produk->pelacakan->value,
                'Jumlah' => $this->Rapikan((string) $s->JumlahTersedia),
                'UuidBatchStok' => null,
                'NomorBatch' => null,
                'UuidNomorSeri' => null,
                'NomorSeri' => null,
            ];
        }

        return ['Baris' => $baris, 'JumlahDilewati' => $dilewati, 'Terpotong' => $terpotong];
    }

    /** "137.0000" → "137", "2.5000" → "2.5"; bilangan tanpa desimal tidak disentuh. */
    private function Rapikan(string $jumlah): string
    {
        return str_contains($jumlah, '.') ? rtrim(rtrim($jumlah, '0'), '.') : $jumlah;
    }
}
