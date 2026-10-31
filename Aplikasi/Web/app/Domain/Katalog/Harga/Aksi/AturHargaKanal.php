<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Harga\Data\DataDaftarHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Illuminate\Support\Facades\DB;

/**
 * "Atur harga ojol" (F-03, X8): mengisi daftar harga kanal GoFood/GrabFood/ShopeeFood dari harga dasar produk dalam
 * satu langkah, tanpa pengguna perlu mengerti konsep daftar harga. Pengguna hanya memilih platform, naik/turun
 * berapa (persen atau nominal), pembulatan, dan apakah harga yang sudah ada ditimpa.
 *
 * - Daftar harga per kanal dibuat otomatis ("Harga GoFood"). Daftar yang sama (kanal + outlet yang sama, tanpa tier atau
 *   rentang waktu) dipakai ulang, dan diaktifkan lagi bila sebelumnya dinonaktifkan. Prioritas 100 supaya harga ojol
 *   menang atas daftar umum untuk kanal itu.
 * - Sumber harga: harga dasar dan harga bertingkat (`IdDaftarHarga` null) produk aktif, termasuk varian (varian adalah
 *   produk sendiri). Harga 0 (harga terbuka, jasa tanpa harga) dilewati. Harga dasar tidak pernah diubah.
 * - Rumus dipakai bersama `UbahHargaProdukMassal` lewat {@see PenghitungPerubahanHarga}.
 * - Tanpa timpa, satuan produk yang sudah punya harga di daftar itu dilewati. Dengan timpa, seluruh baris harga satuan
 *   itu di daftar diganti. Setiap perubahan tercatat di `RiwayatHarga` (BR-03.3) lewat `SimpanHargaDaftarHarga`.
 * - `Terapkan` satu transaksi: semua kanal terisi atau tidak sama sekali. Pengguna yang dibatasi outlet hanya boleh
 *   memakai outletnya (`OutletDiLuarAkses`).
 */
final class AturHargaKanal
{
    public const PRIORITAS_DAFTAR = 100;

    public const MAKS_SATUAN_PER_SIMPAN = 200;

    public const JUMLAH_CONTOH = 8;

    public function __construct(
        private readonly SimpanDaftarHarga $simpanDaftar,
        private readonly SimpanHargaDaftarHarga $simpanHarga,
        private readonly UbahStatusDaftarHarga $ubahStatus,
    ) {}

    /**
     * @param  list<string>  $kanal  nilai {@see KanalPenjualan} platform
     * @param  list<int>|null  $idOutlet  null/kosong = semua outlet
     * @return array{Kanal: list<array{Kanal: string, Label: string, DaftarAda: bool, Diisi: int, Dilewati: int}>, Contoh: list<array{Produk: string, Sku: string|null, Satuan: string, HargaBiasa: string, HargaBaru: string}>, TotalSatuan: int}
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Pratinjau(array $kanal, string $mode, string $nilai, int $pembulatan, bool $timpa, ?array $idOutlet): array
    {
        $daftarKanal = $this->PastikanKanal($kanal);
        $rencana = $this->SusunRencana($mode, $nilai, $pembulatan);
        $idOutlet = $this->RapikanOutlet($idOutlet);
        $ringkas = [];

        foreach ($daftarKanal as $k) {
            $daftar = $this->CariDaftar($k, $idOutlet);
            $sudahAda = $daftar === null ? [] : $this->AmbilSatuanBerharga($daftar);
            [$diisi, $dilewati] = $this->HitungDiisi($rencana, $sudahAda, $timpa);
            $ringkas[] = ['Kanal' => $k->value, 'Label' => $k->AmbilLabel(), 'DaftarAda' => $daftar !== null, 'Diisi' => $diisi, 'Dilewati' => $dilewati];
        }

        return ['Kanal' => $ringkas, 'Contoh' => $this->AmbilContoh($rencana), 'TotalSatuan' => count($rencana)];
    }

    /**
     * @param  list<string>  $kanal
     * @param  list<int>|null  $idOutlet
     * @param  list<string>  $namaOutlet  nama outlet terpilih (untuk nama daftar), urut sama dengan `$idOutlet`
     * @param  list<int>|null  $idOutletBoleh  null = pelaku boleh semua outlet
     * @return array{Kanal: list<array{Kanal: string, Label: string, UuidDaftar: string, DaftarBaru: bool, Diisi: int, Dilewati: int}>}
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Terapkan(array $kanal, string $mode, string $nilai, int $pembulatan, bool $timpa, ?array $idOutlet, array $namaOutlet, ?array $idOutletBoleh): array
    {
        $daftarKanal = $this->PastikanKanal($kanal);
        $idOutlet = $this->RapikanOutlet($idOutlet);
        SimpanDaftarHarga::PastikanAksesOutlet($idOutlet, $idOutletBoleh);
        $rencana = $this->SusunRencana($mode, $nilai, $pembulatan);

        return DB::transaction(function () use ($daftarKanal, $rencana, $timpa, $idOutlet, $namaOutlet, $idOutletBoleh): array {
            $hasil = [];

            foreach ($daftarKanal as $k) {
                $daftar = $this->CariDaftar($k, $idOutlet);
                $baru = $daftar === null;

                if ($daftar === null) {
                    $daftar = $this->BuatDaftar($k, $idOutlet, $namaOutlet, $idOutletBoleh);
                } elseif (! $daftar->Aktif) {
                    $daftar = $this->ubahStatus->Jalankan($daftar, true);
                }

                $sudahAda = $baru ? [] : $this->AmbilSatuanBerharga($daftar);
                [$diisi, $dilewati] = $this->HitungDiisi($rencana, $sudahAda, $timpa);
                $this->IsiDaftar($daftar, $rencana, $sudahAda, $timpa);
                $hasil[] = ['Kanal' => $k->value, 'Label' => $k->AmbilLabel(), 'UuidDaftar' => $daftar->Uuid, 'DaftarBaru' => $baru, 'Diisi' => $diisi, 'Dilewati' => $dilewati];
            }

            return ['Kanal' => $hasil];
        });
    }

    /**
     * @param  list<string>  $kanal
     * @return list<KanalPenjualan>
     */
    private function PastikanKanal(array $kanal): array
    {
        $hasil = [];

        foreach (array_values(array_unique($kanal)) as $nilai) {
            $k = KanalPenjualan::tryFrom($nilai);

            if ($k === null || ! $k->CekPlatform()) {
                throw new PelanggaranAturanBisnis('KanalTidakDikenal', 'Pilih platform ojol yang tersedia.', 'Kanal');
            }

            $hasil[] = $k;
        }

        if ($hasil === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu platform.', 'Kanal');
        }

        return $hasil;
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @return list<int>|null
     */
    private function RapikanOutlet(?array $idOutlet): ?array
    {
        if ($idOutlet === null || $idOutlet === []) {
            return null;
        }

        $rapi = array_values(array_unique($idOutlet));
        sort($rapi);

        return $rapi;
    }

    /**
     * Rencana harga baru per satuan produk, dari harga dasar & bertingkat produk aktif.
     *
     * @return array<int, array{Produk: string, Sku: string|null, IdProduk: int, Baris: list<array{Jumlah: string, Lama: Uang, Baru: Uang}>}> kunci IdProdukSatuan
     */
    private function SusunRencana(string $mode, string $nilai, int $pembulatan): array
    {
        PenghitungPerubahanHarga::PastikanPilihanSah($mode, $pembulatan);
        $angka = PenghitungPerubahanHarga::AmbilAngka($nilai, str_ends_with($mode, 'Persen'));
        $produkAktif = Produk::query()->where('Aktif', true)->whereNull('DiarsipkanPada')->select('Id');
        $baris = ProdukHarga::query()
            ->whereNull('IdDaftarHarga')
            ->whereIn('IdProduk', $produkAktif)
            ->orderBy('IdProdukSatuan')
            ->orderBy('JumlahMinimum')
            ->get(['IdProduk', 'IdProdukSatuan', 'JumlahMinimum', 'Harga']);

        if ($baris->isEmpty()) {
            throw new PelanggaranAturanBisnis('TidakAdaProduk', 'Belum ada produk berharga. Isi harga produk dulu, lalu atur harga ojol.', 'Kanal');
        }

        $namaProduk = Produk::query()->whereIn('Id', $baris->pluck('IdProduk')->unique()->all())->get(['Id', 'Nama', 'Sku'])->keyBy('Id');
        $rencana = [];

        foreach ($baris as $b) {
            $lama = Uang::Dari($b->Harga);

            if ($lama->BernilaiNol()) {
                continue;
            }

            $baru = PenghitungPerubahanHarga::Hitung($lama, $mode, $angka, $pembulatan);
            $nama = $namaProduk->get($b->IdProduk)?->Nama ?? 'Produk';

            if ($baru->BernilaiNegatif()) {
                throw new PelanggaranAturanBisnis('HargaNegatif', "Harga {$nama} akan menjadi negatif. Kurangi nilai penurunan.", 'Nilai');
            }

            $rencana[$b->IdProdukSatuan]['Produk'] = $nama;
            $rencana[$b->IdProdukSatuan]['Sku'] = $namaProduk->get($b->IdProduk)?->Sku;
            $rencana[$b->IdProdukSatuan]['IdProduk'] = $b->IdProduk;
            $rencana[$b->IdProdukSatuan]['Baris'][] = ['Jumlah' => (string) $b->JumlahMinimum, 'Lama' => $lama, 'Baru' => $baru];
        }

        if ($rencana === []) {
            throw new PelanggaranAturanBisnis('TidakAdaProduk', 'Belum ada produk berharga. Isi harga produk dulu, lalu atur harga ojol.', 'Kanal');
        }

        return $rencana;
    }

    /**
     * @param  list<int>|null  $idOutlet
     */
    private function CariDaftar(KanalPenjualan $kanal, ?array $idOutlet): ?DaftarHarga
    {
        $kandidat = DaftarHarga::query()
            ->where('Kanal', $kanal->value)
            ->whereNull('TierPelanggan')
            ->whereNull('MulaiPada')
            ->whereNull('SelesaiPada')
            ->orderBy('Id')
            ->get();

        foreach ($kandidat as $daftar) {
            if ($this->RapikanOutlet($daftar->IdOutlet) === $idOutlet) {
                return $daftar;
            }
        }

        return null;
    }

    /**
     * @param  list<int>|null  $idOutlet
     * @param  list<string>  $namaOutlet
     * @param  list<int>|null  $idOutletBoleh
     */
    private function BuatDaftar(KanalPenjualan $kanal, ?array $idOutlet, array $namaOutlet, ?array $idOutletBoleh): DaftarHarga
    {
        $nama = 'Harga '.$kanal->AmbilLabel();

        if ($idOutlet !== null && $namaOutlet !== []) {
            $nama = mb_substr($nama.' - '.implode(', ', $namaOutlet), 0, 100);
        }

        try {
            return $this->simpanDaftar->Jalankan(null, new DataDaftarHarga($nama, $idOutlet, $kanal, null, null, null, self::PRIORITAS_DAFTAR), $idOutletBoleh);
        } catch (PelanggaranAturanBisnis $galat) {
            if ($galat->kode === 'DaftarHargaGanda') {
                throw new PelanggaranAturanBisnis('DaftarHargaGanda', "Sudah ada daftar harga bernama \"{$nama}\" dengan pengaturan lain (tier, rentang waktu, atau outlet berbeda). Ganti nama daftar itu di halaman Daftar harga, lalu coba lagi.", 'Kanal');
            }

            throw $galat;
        }
    }

    /**
     * @return array<int, true> kunci IdProdukSatuan yang sudah punya baris harga di daftar
     */
    private function AmbilSatuanBerharga(DaftarHarga $daftar): array
    {
        $hasil = [];

        foreach (ProdukHarga::query()->where('IdDaftarHarga', $daftar->Id)->distinct()->pluck('IdProdukSatuan') as $id) {
            $hasil[(int) $id] = true;
        }

        return $hasil;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rencana
     * @param  array<int, true>  $sudahAda
     * @return array{0: int, 1: int} [diisi, dilewati]
     */
    private function HitungDiisi(array $rencana, array $sudahAda, bool $timpa): array
    {
        $dilewati = $timpa ? 0 : count(array_intersect_key($rencana, $sudahAda));

        return [count($rencana) - $dilewati, $dilewati];
    }

    /**
     * @param  array<int, array{Produk: string, Sku: string|null, IdProduk: int, Baris: list<array{Jumlah: string, Lama: Uang, Baru: Uang}>}>  $rencana
     * @param  array<int, true>  $sudahAda
     */
    private function IsiDaftar(DaftarHarga $daftar, array $rencana, array $sudahAda, bool $timpa): void
    {
        $perSatuan = [];

        foreach ($rencana as $idSatuan => $data) {
            if (! $timpa && isset($sudahAda[$idSatuan])) {
                continue;
            }

            $perSatuan[$idSatuan] = array_map(fn (array $b): DataBarisHarga => new DataBarisHarga(Kuantitas::Dari($b['Jumlah']), $b['Baru']), $data['Baris']);
        }

        foreach (array_chunk($perSatuan, self::MAKS_SATUAN_PER_SIMPAN, true) as $potong) {
            $this->simpanHarga->Jalankan($daftar, $potong, SumberPerubahanHarga::Manual);
        }
    }

    /**
     * Contoh untuk pratinjau: satuan dengan nama produk terurut, hanya harga jumlah minimum 1.
     *
     * @param  array<int, array{Produk: string, Sku: string|null, IdProduk: int, Baris: list<array{Jumlah: string, Lama: Uang, Baru: Uang}>}>  $rencana
     * @return list<array{Produk: string, Sku: string|null, Satuan: string, HargaBiasa: string, HargaBaru: string}>
     */
    private function AmbilContoh(array $rencana): array
    {
        uasort($rencana, fn (array $a, array $b): int => strcasecmp($a['Produk'], $b['Produk']));
        $terpilih = array_slice($rencana, 0, self::JUMLAH_CONTOH, true);
        $simbol = ProdukSatuan::query()->whereIn('Id', array_keys($terpilih))->with('SatuanUnit')->get()->keyBy('Id');
        $contoh = [];

        foreach ($terpilih as $idSatuan => $data) {
            $dasar = $data['Baris'][0];
            $contoh[] = [
                'Produk' => $data['Produk'],
                'Sku' => $data['Sku'],
                'Satuan' => $simbol->get($idSatuan)?->SatuanUnit->Simbol ?? '',
                'HargaBiasa' => $dasar['Lama']->KeString(),
                'HargaBaru' => $dasar['Baru']->KeString(),
            ];
        }

        return $contoh;
    }
}
