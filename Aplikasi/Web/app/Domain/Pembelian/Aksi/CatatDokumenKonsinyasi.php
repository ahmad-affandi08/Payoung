<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Pembelian\Data\DataBarisKonsinyasi;
use App\Domain\Pembelian\Data\DataDokumenKonsinyasi;
use App\Domain\Pembelian\Enum\JenisDokumenKonsinyasi;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Model\DokumenKonsinyasi;
use App\Domain\Pembelian\Model\DokumenKonsinyasiDetail;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenitipProduk;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\SaldoStokPasangan;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Model\SaldoStok;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * F-05i: mencatat titipan **Masuk** dari penitip atau **Retur** sisa titipan ke penitip (izin `pembelian.kelola`).
 *
 * - Produk wajib berjenis Konsinyasi, tanpa pelacakan batch/seri, satu kali per dokumen, maksimal 200 baris.
 * - Satu produk satu penitip (`PenitipProduk`): titipan masuk pertama menetapkannya; penitip lain ditolak
 *   (`ProdukMilikPenitipLain`), retur hanya ke penitip produk itu.
 * - Masuk: mutasi `KonsinyasiMasuk` dinilai harga titip (HPP rata-rata = harga titip, jadi HPP saat terjual = hutang
 *   ke penitip). Retur: mutasi `KonsinyasiRetur` dinilai HPP berjalan, ditolak bila melebihi stok di lokasi itu.
 * - **Tanpa jurnal**: barang titipan bukan aset toko (§11.3 J-05.7 hanya saat terjual). Tanggal tidak boleh di masa
 *   depan atau di periode terkunci. Audit `konsinyasi.catat` (nomor, jenis, penitip, jumlah baris, nilai).
 */
final class CatatDokumenKonsinyasi
{
    public const MAKS_BARIS = 200;

    public function __construct(
        private readonly InfoProdukStok $infoProduk,
        private readonly SaldoStokPasangan $saldo,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PemrosesPenerimaanBarang $pemroses,
        private readonly PenomorPembelian $penomor,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis
     */
    public function Jalankan(DataDokumenKonsinyasi $data): DokumenKonsinyasi
    {
        $pemasok = Pemasok::query()->where('Uuid', $data->uuidPemasok)->first();

        if ($pemasok === null) {
            throw new PelanggaranAturanBisnis('PemasokTidakDikenal', 'Penitip tidak ditemukan.', 'UuidPemasok');
        }

        if ($data->jenis === JenisDokumenKonsinyasi::Masuk && ! $pemasok->Aktif) {
            throw new PelanggaranAturanBisnis('PemasokNonaktif', "{$pemasok->Nama} nonaktif. Aktifkan dulu sebelum menerima titipan baru.", 'UuidPemasok');
        }

        if ($data->baris === [] || count($data->baris) > self::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('BarisTidakValid', 'Isi 1 sampai '.self::MAKS_BARIS.' barang titipan.', 'Baris');
        }

        $this->pemroses->PastikanTanggal($data->tanggal, $data->gudang->idOutlet);
        $produk = $this->infoProduk->AmbilDariUuid(array_map(fn (DataBarisKonsinyasi $b): string => $b->uuidProduk, $data->baris));
        $this->PeriksaBaris($data, $produk);

        return DB::transaction(function () use ($data, $pemasok, $produk): DokumenKonsinyasi {
            $penitip = $this->TetapkanPenitip($data, $pemasok, $produk);
            $dokumen = DokumenKonsinyasi::query()->create([
                'Nomor' => $this->penomor->AmbilNomorLokasi(JenisDokumenBernomor::DokumenKonsinyasi, $data->tanggal, $data->gudang),
                'Jenis' => $data->jenis,
                'IdPemasok' => $pemasok->Id,
                'IdOutlet' => $data->gudang->idOutlet,
                'IdGudang' => $data->gudang->id,
                'Tanggal' => $data->tanggal->toDateString(),
                'TotalNilai' => '0.00',
                'Catatan' => self::Bersihkan($data->catatan),
                'DibuatOleh' => $data->idPengguna,
            ]);

            $mutasi = [];
            $detail = [];

            foreach ($data->baris as $i => $b) {
                $p = $produk[$b->uuidProduk];
                $harga = $b->hargaTitip ?? Uang::Nol();
                $nilai = $harga->Kali($b->jumlah->KeDesimal());
                $hargaDesimal = BigDecimal::of($harga->KeString())->toScale(6);
                $detail[$i] = DokumenKonsinyasiDetail::query()->create([
                    'IdDokumenKonsinyasi' => $dokumen->Id,
                    'IdProduk' => $p->id,
                    'Jumlah' => $b->jumlah->KeString(),
                    'HargaSatuan' => (string) $hargaDesimal,
                    'Nilai' => $nilai->KeString(),
                ]);
                $masuk = $data->jenis === JenisDokumenKonsinyasi::Masuk;
                $mutasi[] = new DataBarisMutasi(
                    kunciBaris: 'K/'.($i + 1),
                    idProduk: $p->id,
                    idGudang: $data->gudang->id,
                    jenisMutasi: $data->jenis->AmbilJenisMutasi(),
                    jumlah: $masuk ? $b->jumlah : $b->jumlah->Negasi(),
                    modeNilai: $masuk ? ModeNilaiMutasi::Ditentukan : ModeNilaiMutasi::Berjalan,
                    nilai: $masuk ? $nilai : null,
                    hppSatuan: $masuk ? $hargaDesimal : null,
                    idReferensiDetail: $detail[$i]->Id,
                );
            }

            $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
                JenisReferensiMutasi::Konsinyasi,
                $dokumen->Id,
                $dokumen->Uuid,
                $dokumen->Nomor,
                $data->tanggal,
                $data->idPengguna,
                null,
                $mutasi,
                abaikanBatasMinus: false,
            ));

            $total = Uang::Nol();

            foreach ($hasil->baris as $h) {
                $nilai = AritmetikaHpp::AmbilMutlak($h->totalHpp);
                $total = $total->Tambah($nilai);

                if ($data->jenis === JenisDokumenKonsinyasi::Retur) {
                    $d = $detail[(int) substr($h->kunciBaris, 2) - 1];
                    $d->forceFill([
                        'HargaSatuan' => (string) BigDecimal::of($nilai->KeString())->dividedBy($h->jumlah->KeDesimal()->abs(), 6, RoundingMode::HalfUp),
                        'Nilai' => $nilai->KeString(),
                    ])->save();
                }
            }

            $dokumen->forceFill(['TotalNilai' => $total->KeString()])->save();

            $this->audit->Catat('konsinyasi.catat', $dokumen, nilaiBaru: [
                'Nomor' => $dokumen->Nomor,
                'Jenis' => $data->jenis->value,
                'Penitip' => $pemasok->Nama,
                'JumlahBaris' => count($data->baris),
                'TotalNilai' => $dokumen->TotalNilai,
                'PenitipBaru' => $penitip,
            ], idPengguna: $data->idPengguna);

            return $dokumen;
        }, 3);
    }

    /**
     * @param  array<string, DataInfoProdukStok>  $produk
     *
     * @throws PelanggaranAturanBisnis
     */
    private function PeriksaBaris(DataDokumenKonsinyasi $data, array $produk): void
    {
        $dipakai = [];
        $pasangan = [];

        foreach ($data->baris as $i => $b) {
            $p = $produk[$b->uuidProduk] ?? null;
            $bidang = "Baris.{$i}.UuidProduk";

            if ($p === null) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk tidak ditemukan.', $bidang);
            }

            if ($p->jenis !== JenisProduk::Konsinyasi) {
                throw new PelanggaranAturanBisnis('BukanProdukKonsinyasi', "{$p->nama} bukan produk konsinyasi. Ubah jenis produknya menjadi Konsinyasi (titipan) dulu.", $bidang);
            }

            if ($p->pelacakan !== PelacakanProduk::Tidak) {
                throw new PelanggaranAturanBisnis('PelacakanTidakDidukung', "{$p->nama} memakai batch/nomor seri. Titipan berpelacakan belum didukung.", $bidang);
            }

            if ($data->jenis === JenisDokumenKonsinyasi::Masuk && $p->diarsipkan) {
                throw new PelanggaranAturanBisnis('ProdukDiarsipkan', "{$p->nama} sudah diarsipkan.", $bidang);
            }

            if (isset($dipakai[$p->id])) {
                throw new PelanggaranAturanBisnis('ProdukGanda', "{$p->nama} muncul lebih dari sekali. Gabungkan jumlahnya di satu baris.", $bidang);
            }

            $dipakai[$p->id] = true;

            if (! $b->jumlah->KeDesimal()->isPositive()) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah harus lebih dari 0.', "Baris.{$i}.Jumlah");
            }

            if (! $p->bolehDesimal && $b->jumlah->KeDesimal()->strippedOfTrailingZeros()->getScale() > 0) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', "Jumlah {$p->nama} harus bilangan bulat ({$p->simbolSatuan}).", "Baris.{$i}.Jumlah");
            }

            if ($data->jenis === JenisDokumenKonsinyasi::Masuk && ($b->hargaTitip === null || $b->hargaTitip->Bandingkan(Uang::Nol()) <= 0)) {
                throw new PelanggaranAturanBisnis('HargaTitipWajib', "Isi harga titip {$p->nama} (harga yang dibayar ke penitip per {$p->simbolSatuan}).", "Baris.{$i}.HargaTitip");
            }

            $pasangan[] = [$p->id, $data->gudang->id];
        }

        if ($data->jenis !== JenisDokumenKonsinyasi::Retur) {
            return;
        }

        $saldo = $this->saldo->Ambil($pasangan);

        foreach ($data->baris as $i => $b) {
            $p = $produk[$b->uuidProduk];
            $ada = $saldo[SaldoStok::BuatKunciPasangan($p->id, $data->gudang->id)] ?? Kuantitas::Nol();

            if ($b->jumlah->Bandingkan($ada) > 0) {
                throw new PelanggaranAturanBisnis('StokTidakCukup', "Stok {$p->nama} di {$data->gudang->nama} tinggal {$ada->KeDesimal()->strippedOfTrailingZeros()} {$p->simbolSatuan}.", "Baris.{$i}.Jumlah");
            }
        }
    }

    /**
     * Mengikat produk ke penitipnya (titipan masuk pertama) dan menolak penitip lain. Mengembalikan jumlah produk yang
     * baru diikat.
     *
     * @param  array<string, DataInfoProdukStok>  $produk
     *
     * @throws PelanggaranAturanBisnis ProdukMilikPenitipLain, BukanTitipanPenitip
     */
    private function TetapkanPenitip(DataDokumenKonsinyasi $data, Pemasok $pemasok, array $produk): int
    {
        $id = array_map(fn (DataBarisKonsinyasi $b): int => $produk[$b->uuidProduk]->id, $data->baris);
        $ada = PenitipProduk::query()->whereIn('IdProduk', $id)->orderBy('IdProduk')->lockForUpdate()->pluck('IdPemasok', 'IdProduk')->all();
        $baru = 0;

        foreach ($data->baris as $i => $b) {
            $p = $produk[$b->uuidProduk];
            $milik = $ada[$p->id] ?? null;

            if ($milik !== null && (int) $milik !== $pemasok->Id) {
                $nama = (string) Pemasok::query()->withTrashed()->whereKey((int) $milik)->value('Nama');

                throw new PelanggaranAturanBisnis('ProdukMilikPenitipLain', "{$p->nama} adalah titipan {$nama}. Satu produk hanya untuk satu penitip. Buat produk terpisah untuk penitip lain.", "Baris.{$i}.UuidProduk");
            }

            if ($milik !== null) {
                continue;
            }

            if ($data->jenis === JenisDokumenKonsinyasi::Retur) {
                throw new PelanggaranAturanBisnis('BukanTitipanPenitip', "{$p->nama} belum pernah dititipkan {$pemasok->Nama}.", "Baris.{$i}.UuidProduk");
            }

            try {
                PenitipProduk::query()->create(['IdProduk' => $p->id, 'IdPemasok' => $pemasok->Id]);
            } catch (UniqueConstraintViolationException) {
                throw new PelanggaranAturanBisnis('ProdukMilikPenitipLain', "{$p->nama} baru saja dicatat sebagai titipan penitip lain. Muat ulang halaman.", "Baris.{$i}.UuidProduk");
            }

            $baru++;
        }

        return $baru;
    }

    private static function Bersihkan(?string $teks): ?string
    {
        $teks = $teks === null ? '' : trim($teks);

        return $teks === '' ? null : mb_substr($teks, 0, 500);
    }
}
