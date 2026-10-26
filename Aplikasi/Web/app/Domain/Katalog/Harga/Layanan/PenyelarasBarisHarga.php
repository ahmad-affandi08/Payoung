<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Harga\Data\HasilPenyelarasanHarga;
use App\Domain\Katalog\Harga\Data\HasilSimpanHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Layanan\PencatatPenghapusanKatalog;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use Illuminate\Support\Collection;

/**
 * Validasi & penyelarasan baris `ProdukHarga` per satuan produk untuk satu cakupan harga: harga dasar
 * (`IdDaftarHarga` null) atau satu daftar harga. Dipakai `SimpanHargaProduk` dan `SimpanHargaDaftarHarga` di dalam
 * transaksinya (Tenant sudah dikunci pemanggil).
 *
 * - Validasi: `JumlahMinimum` > 0, ≤ batas kolom, bulat bila satuan tidak boleh desimal (`JumlahMinimumTidakValid`),
 *   unik per satuan (`JumlahMinimumGanda`); `0 ≤ Harga ≤ 9999999999999999.99` (`HargaTidakValid`); harga dasar wajib
 *   berisi `JumlahMinimum = 1` bila diminta (`HargaDasarWajib`); satuan harus dikenal (`SatuanTidakDikenal`). Bidang
 *   galat `Satuan.{i}.Harga.{j}.{JumlahMinimum|Harga}`, `i` = urutan satuan di masukan.
 * - Beda per (satuan, `JumlahMinimum`): tambah, ubah bila harga berbeda, hapus (jejak `PenghapusanKatalog`). Setiap
 *   beda = satu `RiwayatHarga` (BR-03.3). Tanpa beda = tanpa tulis.
 */
final class PenyelarasBarisHarga
{
    public const HARGA_MAKSIMUM = '9999999999999999.99';

    public const JUMLAH_MINIMUM_MAKSIMUM = '99999999999999.9999';

    public function __construct(
        private readonly PencatatRiwayatHarga $riwayat,
        private readonly PencatatPenghapusanKatalog $penghapusan,
    ) {}

    /**
     * @param  array<int, list<DataBarisHarga>>  $perSatuan
     * @param  Collection<int, ProdukSatuan>  $satuan  satuan yang boleh (dengan relasi `SatuanUnit`), kunci Id
     * @return array<int, ProdukSatuan> satuan per IdProdukSatuan yang disebut
     */
    public function Validasi(array $perSatuan, Collection $satuan, bool $wajibDasar): array
    {
        $hasil = [];
        $satu = Kuantitas::Dari(1);
        $nol = Kuantitas::Nol();
        $jumlahMaksimum = Kuantitas::Dari(self::JUMLAH_MINIMUM_MAKSIMUM);
        $hargaMaksimum = Uang::Dari(self::HARGA_MAKSIMUM);
        $i = 0;

        foreach ($perSatuan as $idProdukSatuan => $daftar) {
            $produkSatuan = $satuan->get($idProdukSatuan);

            if (! $produkSatuan instanceof ProdukSatuan) {
                throw new PelanggaranAturanBisnis('SatuanTidakDikenal', 'Satuan produk ini tidak ditemukan. Muat ulang halaman lalu coba lagi.', "Satuan.{$i}");
            }

            $hasil[$idProdukSatuan] = $produkSatuan;
            $bolehDesimal = $produkSatuan->SatuanUnit->BolehDesimal;
            $terpakai = [];
            $adaDasar = false;

            foreach (array_values($daftar) as $j => $baris) {
                $jumlah = $baris->jumlahMinimum;
                $bidang = "Satuan.{$i}.Harga.{$j}";

                if ($jumlah->Bandingkan($nol) <= 0 || $jumlah->Bandingkan($jumlahMaksimum) > 0
                    || (! $bolehDesimal && ! $jumlah->KeDesimal()->getFractionalPart()->isZero())) {
                    throw new PelanggaranAturanBisnis(
                        'JumlahMinimumTidakValid',
                        $bolehDesimal ? 'Jumlah minimum harus lebih dari 0.' : 'Jumlah minimum harus bilangan bulat lebih dari 0 untuk satuan ini.',
                        "{$bidang}.JumlahMinimum",
                    );
                }

                if (in_array($jumlah->KeString(), $terpakai, true)) {
                    throw new PelanggaranAturanBisnis('JumlahMinimumGanda', 'Jumlah minimum ini sudah dipakai baris lain di satuan yang sama.', "{$bidang}.JumlahMinimum");
                }

                if ($baris->harga->BernilaiNegatif() || $baris->harga->Bandingkan($hargaMaksimum) > 0) {
                    throw new PelanggaranAturanBisnis('HargaTidakValid', 'Harga harus antara Rp 0 dan Rp 9.999.999.999.999.999,99.', "{$bidang}.Harga");
                }

                $terpakai[] = $jumlah->KeString();
                $adaDasar = $adaDasar || $jumlah->SamaDengan($satu);
            }

            if ($wajibDasar && $daftar !== [] && ! $adaDasar) {
                throw new PelanggaranAturanBisnis('HargaDasarWajib', 'Isi harga untuk jumlah minimum 1 (harga jual biasa) sebelum menambah harga bertingkat.', "Satuan.{$i}.Harga");
            }

            $i++;
        }

        return $hasil;
    }

    /**
     * Mengganti seluruh baris cakupan (`$idDaftarHarga`, null = harga dasar) untuk setiap satuan yang disebut.
     *
     * @param  array<int, list<DataBarisHarga>>  $perSatuan  sudah lolos `Validasi()`
     * @param  array<int, ProdukSatuan>  $satuan  hasil `Validasi()`
     */
    public function Selaraskan(array $perSatuan, array $satuan, ?int $idDaftarHarga, SumberPerubahanHarga $sumber): HasilPenyelarasanHarga
    {
        $lama = [];
        $kueri = ProdukHarga::query()->whereIn('IdProdukSatuan', array_keys($perSatuan))->orderBy('Id')->lockForUpdate();
        $idDaftarHarga === null ? $kueri->whereNull('IdDaftarHarga') : $kueri->where('IdDaftarHarga', $idDaftarHarga);

        foreach ($kueri->get() as $baris) {
            $lama[$baris->IdProdukSatuan][$baris->JumlahMinimum] = $baris;
        }

        $ditambah = $diubah = $dihapus = 0;
        $nilaiLama = $nilaiBaru = [];

        foreach ($perSatuan as $idProdukSatuan => $daftar) {
            $produkSatuan = $satuan[$idProdukSatuan];
            $lamaSatuan = $lama[$idProdukSatuan] ?? [];
            $baru = [];

            foreach ($daftar as $baris) {
                $baru[$baris->jumlahMinimum->KeString()] = $baris->harga;
            }

            ksort($baru, SORT_NATURAL);
            $berubah = false;
            $catat = fn (string $jumlahMinimum, ?string $hargaLama, ?string $hargaBaru) => $this->riwayat->Catat(
                $produkSatuan->IdProduk,
                $idProdukSatuan,
                $produkSatuan->IdSatuan,
                $idDaftarHarga,
                $jumlahMinimum,
                $hargaLama,
                $hargaBaru,
                $sumber,
            );

            foreach ($baru as $jumlahMinimum => $harga) {
                $jumlahMinimum = (string) $jumlahMinimum;
                $barisLama = $lamaSatuan[$jumlahMinimum] ?? null;

                if ($barisLama === null) {
                    ProdukHarga::query()->create([
                        'IdProduk' => $produkSatuan->IdProduk,
                        'IdProdukSatuan' => $idProdukSatuan,
                        'IdDaftarHarga' => $idDaftarHarga,
                        'JumlahMinimum' => $jumlahMinimum,
                        'Harga' => $harga->KeString(),
                    ]);
                    $catat($jumlahMinimum, null, $harga->KeString());
                    $ditambah++;
                    $berubah = true;
                } elseif (! Uang::Dari($barisLama->Harga)->SamaDengan($harga)) {
                    $hargaLama = $barisLama->Harga;
                    $barisLama->fill(['Harga' => $harga->KeString()])->save();
                    $catat($jumlahMinimum, $hargaLama, $harga->KeString());
                    $diubah++;
                    $berubah = true;
                }
            }

            foreach ($lamaSatuan as $jumlahMinimum => $barisLama) {
                if (array_key_exists($jumlahMinimum, $baru)) {
                    continue;
                }

                $catat((string) $jumlahMinimum, $barisLama->Harga, null);
                $this->penghapusan->Catat(EntitasKatalog::ProdukHarga, $barisLama->Uuid);
                $barisLama->delete();
                $dihapus++;
                $berubah = true;
            }

            if ($berubah) {
                $nilaiLama[$produkSatuan->Uuid] = self::RingkasBaris(array_map(fn (ProdukHarga $baris): string => $baris->Harga, $lamaSatuan));
                $nilaiBaru[$produkSatuan->Uuid] = self::RingkasBaris(array_map(fn (Uang $harga): string => $harga->KeString(), $baru));
            }
        }

        return new HasilPenyelarasanHarga(new HasilSimpanHarga($ditambah, $diubah, $dihapus), $nilaiLama, $nilaiBaru);
    }

    /**
     * @param  array<array-key, string>  $hargaPerJumlah
     * @return list<array{JumlahMinimum: string, Harga: string}>
     */
    private static function RingkasBaris(array $hargaPerJumlah): array
    {
        ksort($hargaPerJumlah, SORT_NATURAL);
        $hasil = [];

        foreach ($hargaPerJumlah as $jumlahMinimum => $harga) {
            $hasil[] = ['JumlahMinimum' => (string) $jumlahMinimum, 'Harga' => $harga];
        }

        return $hasil;
    }
}
