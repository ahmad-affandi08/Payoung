<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\KanalKetersediaan;
use App\Domain\Katalog\Harga\Data\HasilHarga;
use App\Domain\Katalog\Harga\Kueri\HargaProdukBerlaku;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHabis;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Pilihan\Model\KelompokPilihan;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Katalog\Pilihan\Model\ProdukKelompokPilihan;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * F-17 Self-Order QR Meja: menu publik satu outlet dan harga baris yang dihitung server (tamu tidak pernah menentukan
 * harga). Produk tampil bila aktif, `TampilDiPos`, bisa dijual (bukan bahan baku), bukan anak varian, punya harga jual
 * kanal `MakanDiTempat` (harga yang sama dengan tagihan meja di kasir), dan setiap kelompok pilihan wajibnya punya cukup
 * pilihan aktif. Satuan = satuan jual bawaan (atau satuan dasar).
 *
 * Varian (PRD v2.06): induk varian tampil sebagai satu kartu dengan daftar `Varian` = anak aktif & `TampilDiPos` yang
 * jenisnya bisa dijual; harga per varian = harga kanal `MakanDiTempat` anak itu (tanpa harga → `Tersedia` false, tidak
 * bisa dipilih). Induk tampil bila minimal satu varian tersedia; `Harga` kartu = harga varian termurah. Kelompok
 * pilihan induk berlaku untuk semua anak. Baris pesanan menyimpan anak varian (produk yang benar-benar dijual).
 */
final class MenuPesanSendiri
{
    /** Pemisah nama tampil "Induk — Varian". */
    public const PEMISAH_VARIAN = ' — ';

    public function __construct(private readonly HargaProdukBerlaku $harga) {}

    /**
     * @param  string  $dasarGambar  URL gambar dengan penanda `{uuid}`
     * @return array{Kategori: list<array{Uuid: string, Nama: string}>, Produk: list<array<string, mixed>>}
     */
    public function Ambil(int $idOutlet, string $dasarGambar, KanalPenjualan $kanal = KanalPenjualan::MakanDiTempat, bool $tampilOnline = false): array
    {
        $produk = $this->KueriProduk($tampilOnline, $idOutlet)->orderBy('Nama')->get();
        $idInduk = array_values($produk->filter(fn (Produk $p): bool => $p->Jenis === JenisProduk::IndukVarian)->map(fn (Produk $p): int => $p->Id)->all());
        $anak = $idInduk === [] ? new Collection : $this->KueriAnak($tampilOnline, $idOutlet)->whereIn('IdInduk', $idInduk)->orderBy('Id')->get();
        $satuan = $this->AmbilSatuanJual(collect([...$produk->all(), ...$anak->all()]));
        $anakPerInduk = $anak->groupBy('IdInduk');
        $kelompok = $this->AmbilKelompok(array_values($produk->map(fn (Produk $p): int => $p->Id)->all()));
        $waktu = CarbonImmutable::now();
        $hasil = [];
        $hargaSatuan = $this->HitungHargaSatuan($produk, $anak, $satuan, $kelompok, $idOutlet, $kanal, $waktu);

        foreach ($produk as $p) {
            $grup = $kelompok[$p->Id] ?? [];

            if (! self::CekKelompokBisaDipenuhi($grup)) {
                continue;
            }

            $varian = [];
            $s = $satuan[$p->Id] ?? null;

            if ($p->Jenis === JenisProduk::IndukVarian) {
                $hargaKartu = null;

                /** @var Produk $a */
                foreach ($anakPerInduk->get($p->Id, new Collection) as $a) {
                    $sa = $satuan[$a->Id] ?? null;
                    $hargaAnak = $sa instanceof ProdukSatuan ? ($hargaSatuan[$sa->Id] ?? null)?->harga : null;
                    $varian[] = [
                        'Uuid' => $a->Uuid,
                        'Nama' => self::AmbilNamaVarian($p, $a),
                        'Atribut' => self::AmbilAtribut($a),
                        'Harga' => $hargaAnak?->KeString(),
                        'Tersedia' => $hargaAnak !== null,
                    ];

                    if ($hargaAnak !== null && ($hargaKartu === null || $hargaAnak->Bandingkan($hargaKartu) < 0)) {
                        $hargaKartu = $hargaAnak;
                    }
                }
            } else {
                $hargaKartu = $s instanceof ProdukSatuan ? ($hargaSatuan[$s->Id] ?? null)?->harga : null;
            }

            if ($hargaKartu === null) {
                continue;
            }

            $hasil[] = [
                'Uuid' => $p->Uuid,
                'UuidProdukSatuan' => $varian === [] && $s instanceof ProdukSatuan ? $s->Uuid : null,
                'Nama' => $p->Nama,
                'IdKategori' => $p->IdKategori,
                'Harga' => $hargaKartu->KeString(),
                // K-16: pilihan pemilik. Toko online memakainya untuk kartu sorotan di kepala halaman.
                'Favorit' => $p->Favorit,
                'UrlGambar' => PenyimpanGambarProduk::BuatUrl($p, 'kecil', $dasarGambar),
                'KelompokPilihan' => array_map(fn (array $g): array => [
                    'Uuid' => $g['Kelompok']->Uuid,
                    'Nama' => $g['Kelompok']->Nama,
                    'MinimalPilih' => $g['Kelompok']->MinimalPilih,
                    'MaksimalPilih' => $g['Kelompok']->MaksimalPilih,
                    'Pilihan' => array_map(fn (Pilihan $pl): array => ['Uuid' => $pl->Uuid, 'Nama' => $pl->Nama, 'Harga' => Uang::Dari($pl->Harga)->KeString()], $g['Pilihan']),
                ], $grup),
                'NamaAtributVarian' => $varian === [] ? null : self::AmbilNamaAtribut($p),
                'Varian' => $varian,
            ];
        }

        $idKategori = array_values(array_unique(array_filter(array_column($hasil, 'IdKategori'))));
        $kategori = Kategori::query()->whereKey($idKategori)->orderBy('Urutan')->orderBy('Nama')->get(['Id', 'Uuid', 'Nama']);
        $uuidKategori = $kategori->pluck('Uuid', 'Id');

        return [
            'Kategori' => array_values($kategori->map(fn (Kategori $k): array => ['Uuid' => $k->Uuid, 'Nama' => $k->Nama])->all()),
            'Produk' => array_map(function (array $p) use ($uuidKategori): array {
                $idKategori = $p['IdKategori'];
                unset($p['IdKategori']);

                return array_slice($p, 0, 3, true) + ['UuidKategori' => $idKategori === null ? null : (string) $uuidKategori->get($idKategori)] + $p;
            }, $hasil),
        ];
    }

    /**
     * Harga server untuk baris pesanan tamu. Produk di luar menu → `ProdukTidakTersedia`; pilihan bukan milik
     * kelompok produk, ganda, nonaktif, atau jumlah per kelompok di luar Minimal/Maksimal → `PilihanTidakValid`.
     * Induk varian wajib `UuidVarian` (`VarianWajibDipilih`); varian bukan anak induk itu, nonaktif, tidak tampil di
     * POS, atau tanpa harga (juga `UuidVarian` pada produk tanpa varian) → `VarianTidakValid`. Baris varian memakai
     * anak sebagai `UuidProduk` (harga, satuan, pajak anak) dengan kelompok pilihan induk. Kunci pajak & kategori
     * (`IdKelompokPajak`, `HargaTermasukPajak`, `UuidKategori`) untuk estimasi total (PRD v2.06). `$tier` (F-17 bagian 3)
     * = kode tier pembeli toko online yang sudah masuk, supaya harga tiernya berlaku seperti di kasir; null = tamu.
     *
     * @param  list<array{UuidProduk: string, Jumlah: int, Pilihan: list<string>, UuidVarian?: string|null}>  $baris
     * @return list<array{UuidProduk: string, UuidProdukSatuan: string, NamaProduk: string, UuidProdukInduk: string|null, NamaVarian: string|null, HargaSatuan: Uang, HargaPilihan: Uang, Pilihan: list<array{UuidPilihan: string, Nama: string, Harga: string}>, IdKelompokPajak: int|null, HargaTermasukPajak: bool|null, UuidKategori: string|null}>
     */
    public function HitungBaris(int $idOutlet, array $baris, KanalPenjualan $kanal = KanalPenjualan::MakanDiTempat, bool $tampilOnline = false, ?string $tier = null): array
    {
        $uuid = array_values(array_unique(array_column($baris, 'UuidProduk')));
        $uuidVarian = array_values(array_unique(array_filter(array_map(fn (array $b): ?string => $b['UuidVarian'] ?? null, $baris), 'is_string')));
        $produk = $this->KueriProduk($tampilOnline, $idOutlet)->whereIn('Uuid', $uuid)->get()->keyBy('Uuid');
        $anak = $uuidVarian === [] ? new Collection : $this->KueriAnak($tampilOnline, $idOutlet)->whereIn('Uuid', $uuidVarian)->get()->keyBy('Uuid');
        $semua = collect([...$produk->values()->all(), ...$anak->values()->all()]);
        $satuan = $this->AmbilSatuanJual($semua);
        $kelompok = $this->AmbilKelompok(array_values($produk->map(fn (Produk $p): int => $p->Id)->all()));
        $idKategori = array_values(array_unique(array_filter($semua->map(fn (Produk $p): ?int => $p->IdKategori)->all(), 'is_int')));
        $uuidKategori = $idKategori === [] ? [] : Kategori::query()->whereKey($idKategori)->pluck('Uuid', 'Id')->all();
        $waktu = CarbonImmutable::now();
        $hasil = [];

        foreach ($baris as $i => $b) {
            $p = $produk->get($b['UuidProduk']);

            if (! $p instanceof Produk) {
                throw self::GalatTidakTersedia($i, $b['UuidProduk']);
            }

            $dijual = $this->TentukanProdukDijual($p, $b['UuidVarian'] ?? null, $anak, $i);
            $varian = $dijual !== $p;
            $s = $satuan[$dijual->Id] ?? null;
            $harga = $s instanceof ProdukSatuan
                ? $this->harga->Tentukan($dijual, $s, Kuantitas::Dari($b['Jumlah']), $idOutlet, $kanal, $tier, $waktu)
                : null;

            if (! $s instanceof ProdukSatuan || $harga === null) {
                throw $varian
                    ? self::GalatVarian($i, "Varian yang dipilih untuk {$p->Nama} sedang tidak tersedia. Pilih varian lain.")
                    : self::GalatTidakTersedia($i, $b['UuidProduk']);
            }

            $pilihan = $this->PeriksaPilihan($kelompok[$p->Id] ?? [], $b['Pilihan'], $p->Nama, $i);
            $hargaPilihan = Uang::Nol();

            foreach ($pilihan as $pl) {
                $hargaPilihan = $hargaPilihan->Tambah(Uang::Dari($pl->Harga));
            }

            $namaVarian = $varian ? self::AmbilNamaVarian($p, $dijual) : null;
            $hasil[] = [
                'UuidProduk' => $dijual->Uuid,
                'UuidProdukSatuan' => $s->Uuid,
                'NamaProduk' => $namaVarian === null ? $p->Nama : $p->Nama.self::PEMISAH_VARIAN.$namaVarian,
                'UuidProdukInduk' => $varian ? $p->Uuid : null,
                'NamaVarian' => $namaVarian,
                'HargaSatuan' => $harga->harga,
                'HargaPilihan' => $hargaPilihan,
                'Pilihan' => array_map(fn (Pilihan $pl): array => ['UuidPilihan' => $pl->Uuid, 'Nama' => $pl->Nama, 'Harga' => Uang::Dari($pl->Harga)->KeString()], $pilihan),
                'IdKelompokPajak' => $dijual->IdKelompokPajak,
                'HargaTermasukPajak' => $dijual->HargaTermasukPajak,
                'UuidKategori' => $dijual->IdKategori === null ? null : ($uuidKategori[$dijual->IdKategori] ?? null),
            ];
        }

        return $hasil;
    }

    /** Produk menu dengan gambar (untuk rute gambar publik, termasuk induk varian); null bila bukan menu. */
    public function CariProduk(string $uuid, bool $tampilOnline = false): ?Produk
    {
        return $this->KueriProduk($tampilOnline)->where('Uuid', $uuid)->first();
    }

    /**
     * `$idOutlet` diisi = produk yang ditandai habis di outlet itu (BR-17.2, 86) untuk kanal ini (toko online bila
     * `$tampilOnline`, selain itu self-order/kios; baris `Semua` berlaku untuk keduanya, PRD-11) ikut dikeluarkan. Dikosongkan hanya
     * untuk rute gambar, yang tidak punya konteks outlet dan tidak membocorkan apa pun selain gambar produk menu.
     *
     * @return Builder<Produk>
     */
    private function KueriProduk(bool $tampilOnline = false, ?int $idOutlet = null): Builder
    {
        return Produk::query()
            ->where('Aktif', true)
            ->where($tampilOnline ? 'TampilOnline' : 'TampilDiPos', true)
            ->whereNull('IdInduk')
            ->whereNotIn('Jenis', self::JenisTidakDijual(JenisProduk::IndukVarian))
            ->when($idOutlet !== null, fn (Builder $k): Builder => $k->whereNotIn('Id', ProdukHabis::query()->where('IdOutlet', $idOutlet)->whereIn('Kanal', [KanalKetersediaan::Semua->value, ($tampilOnline ? KanalKetersediaan::TokoOnline : KanalKetersediaan::SelfOrder)->value])->select('IdProduk')));
    }

    /**
     * Anak varian yang boleh dipilih tamu: aktif, `TampilDiPos`, dan jenisnya bisa dijual.
     *
     * @return Builder<Produk>
     */
    private function KueriAnak(bool $tampilOnline = false, ?int $idOutlet = null): Builder
    {
        return Produk::query()
            ->where('Aktif', true)
            ->where($tampilOnline ? 'TampilOnline' : 'TampilDiPos', true)
            ->whereNotNull('IdInduk')
            ->whereNotIn('Jenis', self::JenisTidakDijual())
            ->when($idOutlet !== null, fn (Builder $k): Builder => $k->whereNotIn('Id', ProdukHabis::query()->where('IdOutlet', $idOutlet)->whereIn('Kanal', [KanalKetersediaan::Semua->value, ($tampilOnline ? KanalKetersediaan::TokoOnline : KanalKetersediaan::SelfOrder)->value])->select('IdProduk')));
    }

    /**
     * @return list<string>
     */
    private static function JenisTidakDijual(?JenisProduk $kecuali = null): array
    {
        return array_values(array_map(
            fn (JenisProduk $j): string => $j->value,
            array_filter(JenisProduk::cases(), fn (JenisProduk $j): bool => ! $j->CekBisaDijual() && $j !== $kecuali),
        ));
    }

    /**
     * Produk yang benar-benar dijual untuk satu baris: produk itu sendiri, atau anak varian pilihan tamu.
     *
     * @param  Collection<array-key, Produk>  $anak  kunci = Uuid
     */
    private function TentukanProdukDijual(Produk $p, ?string $uuidVarian, Collection $anak, int $i): Produk
    {
        if ($p->Jenis !== JenisProduk::IndukVarian) {
            if ($uuidVarian !== null) {
                throw self::GalatVarian($i, "{$p->Nama} tidak punya varian. Muat ulang menu lalu pilih lagi.");
            }

            return $p;
        }

        if ($uuidVarian === null) {
            throw new PelanggaranAturanBisnis('VarianWajibDipilih', "Pilih varian {$p->Nama} dulu.", "Baris.{$i}.UuidVarian");
        }

        $a = $anak->get($uuidVarian);

        if (! $a instanceof Produk || $a->IdInduk !== $p->Id) {
            throw self::GalatVarian($i, "Varian yang dipilih untuk {$p->Nama} sedang tidak tersedia. Pilih varian lain.");
        }

        return $a;
    }

    private static function GalatTidakTersedia(int $i, string $uuidProduk): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis('ProdukTidakTersedia', 'Ada menu yang sudah tidak tersedia. Hapus dari keranjang lalu coba lagi.', "Baris.{$i}.UuidProduk", 422, ['UuidProduk' => $uuidProduk]);
    }

    private static function GalatVarian(int $i, string $pesan): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis('VarianTidakValid', $pesan, "Baris.{$i}.UuidVarian");
    }

    /**
     * Atribut anak varian `[{Nama, Nilai}]` (urutan definisi induk).
     *
     * @return list<array{Nama: string, Nilai: string}>
     */
    private static function AmbilAtribut(Produk $anak): array
    {
        $hasil = [];

        foreach ($anak->AtributVarian ?? [] as $a) {
            if (is_string($a['Nama'] ?? null) && is_scalar($a['Nilai'] ?? null)) {
                $hasil[] = ['Nama' => $a['Nama'], 'Nilai' => (string) $a['Nilai']];
            }
        }

        return $hasil;
    }

    /** Nama varian "Besar" / "Besar / Dingin" dari nilai atribut; tanpa atribut = nama anak tanpa awalan nama induk. */
    private static function AmbilNamaVarian(Produk $induk, Produk $anak): string
    {
        $nilai = array_column(self::AmbilAtribut($anak), 'Nilai');

        if ($nilai !== []) {
            return implode(' / ', $nilai);
        }

        $nama = str_starts_with($anak->Nama, $induk->Nama.' ') ? trim(mb_substr($anak->Nama, mb_strlen($induk->Nama))) : '';

        return $nama === '' ? $anak->Nama : $nama;
    }

    /** Label pemilih varian dari definisi atribut induk ("Ukuran", "Ukuran / Suhu"); tanpa definisi = "Varian". */
    private static function AmbilNamaAtribut(Produk $induk): string
    {
        $nama = [];

        foreach ($induk->AtributVarian ?? [] as $a) {
            if (is_string($a['Nama'] ?? null) && trim($a['Nama']) !== '') {
                $nama[] = trim($a['Nama']);
            }
        }

        return $nama === [] ? 'Varian' : implode(' / ', $nama);
    }

    /**
     * Harga satuan jual semua produk yang akan tampil (jumlah 1), dihitung sekali untuk seluruh menu. Menu publik
     * dipolling tiap 15-30 detik per tamu, jadi harga tidak boleh dihitung dengan kueri tersendiri per produk.
     *
     * @param  Collection<array-key, Produk>  $produk
     * @param  Collection<array-key, Produk>  $anak  anak varian semua induk
     * @param  array<int, ProdukSatuan>  $satuan  kunci = `Produk.Id`
     * @param  array<int, list<array{Kelompok: KelompokPilihan, Pilihan: list<Pilihan>}>>  $kelompok
     * @return array<int, HasilHarga|null> kunci = `ProdukSatuan.Id`
     */
    private function HitungHargaSatuan(Collection $produk, Collection $anak, array $satuan, array $kelompok, int $idOutlet, KanalPenjualan $kanal, CarbonImmutable $waktu): array
    {
        $pasangan = [];

        foreach ($produk as $p) {
            if (! self::CekKelompokBisaDipenuhi($kelompok[$p->Id] ?? [])) {
                continue;
            }

            if ($p->Jenis === JenisProduk::IndukVarian) {
                foreach ($anak->where('IdInduk', $p->Id) as $a) {
                    if (isset($satuan[$a->Id])) {
                        $pasangan[] = [$a, $satuan[$a->Id]];
                    }
                }
            } elseif (isset($satuan[$p->Id])) {
                $pasangan[] = [$p, $satuan[$p->Id]];
            }
        }

        return $this->harga->TentukanBanyak($pasangan, Kuantitas::Dari(1), $idOutlet, $kanal, null, $waktu);
    }

    /**
     * Satuan jual bawaan per produk; tanpa satuan jual bawaan → satuan dasar.
     *
     * @param  Collection<array-key, Produk>  $produk
     * @return array<int, ProdukSatuan>
     */
    private function AmbilSatuanJual(Collection $produk): array
    {
        $dasar = [];

        foreach ($produk as $p) {
            $dasar[$p->Id] = $p->IdSatuanDasar;
        }

        $hasil = [];

        if ($dasar === []) {
            return $hasil;
        }

        foreach (ProdukSatuan::query()->whereIn('IdProduk', array_keys($dasar))->orderBy('Id')->get() as $s) {
            $lama = $hasil[$s->IdProduk] ?? null;

            if ($s->DefaultJual && ($lama === null || ! $lama->DefaultJual)) {
                $hasil[$s->IdProduk] = $s;
            } elseif ($lama === null && $s->IdSatuan === ($dasar[$s->IdProduk] ?? null)) {
                $hasil[$s->IdProduk] = $s;
            }
        }

        return $hasil;
    }

    /**
     * Kelompok pilihan terpasang per produk (berurutan) beserta pilihan aktifnya.
     *
     * @param  list<int>  $idProduk
     * @return array<int, list<array{Kelompok: KelompokPilihan, Pilihan: list<Pilihan>}>>
     */
    private function AmbilKelompok(array $idProduk): array
    {
        if ($idProduk === []) {
            return [];
        }

        $tautan = ProdukKelompokPilihan::query()->whereIn('IdProduk', $idProduk)->orderBy('Urutan')->orderBy('Id')->get();
        $idKelompok = $tautan->pluck('IdKelompokPilihan')->unique()->values()->all();
        $kelompok = KelompokPilihan::query()->whereKey($idKelompok)->get()->keyBy('Id');
        $pilihan = Pilihan::query()->whereIn('IdKelompokPilihan', $idKelompok)->where('Aktif', true)->orderBy('Urutan')->orderBy('Id')->get()->groupBy('IdKelompokPilihan');
        $hasil = [];

        foreach ($tautan as $t) {
            $k = $kelompok->get($t->IdKelompokPilihan);

            if ($k instanceof KelompokPilihan) {
                $hasil[$t->IdProduk][] = ['Kelompok' => $k, 'Pilihan' => array_values($pilihan->get($k->Id, new Collection)->all())];
            }
        }

        return $hasil;
    }

    /**
     * @param  list<array{Kelompok: KelompokPilihan, Pilihan: list<Pilihan>}>  $grup
     */
    private static function CekKelompokBisaDipenuhi(array $grup): bool
    {
        foreach ($grup as $g) {
            if (count($g['Pilihan']) < $g['Kelompok']->MinimalPilih) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{Kelompok: KelompokPilihan, Pilihan: list<Pilihan>}>  $grup
     * @param  list<string>  $uuidDipilih
     * @return list<Pilihan> pilihan terpilih, urut kelompok lalu urutan pilihan
     */
    private function PeriksaPilihan(array $grup, array $uuidDipilih, string $namaProduk, int $i): array
    {
        $bidang = "Baris.{$i}.Pilihan";

        if (count($uuidDipilih) !== count(array_unique($uuidDipilih))) {
            throw new PelanggaranAturanBisnis('PilihanTidakValid', "Pilihan untuk {$namaProduk} ganda.", $bidang);
        }

        $sisa = array_flip($uuidDipilih);
        $hasil = [];

        foreach ($grup as $g) {
            $terpilih = array_values(array_filter($g['Pilihan'], fn (Pilihan $pl): bool => isset($sisa[$pl->Uuid])));
            $jumlah = count($terpilih);
            $k = $g['Kelompok'];

            if ($jumlah < $k->MinimalPilih || $jumlah > $k->MaksimalPilih) {
                $syarat = $k->MinimalPilih === $k->MaksimalPilih
                    ? "tepat {$k->MinimalPilih}"
                    : ($k->MinimalPilih > 0 ? "{$k->MinimalPilih} sampai {$k->MaksimalPilih}" : "paling banyak {$k->MaksimalPilih}");

                throw new PelanggaranAturanBisnis('PilihanTidakValid', "Pilih {$syarat} {$k->Nama} untuk {$namaProduk}.", $bidang);
            }

            foreach ($terpilih as $pl) {
                unset($sisa[$pl->Uuid]);
                $hasil[] = $pl;
            }
        }

        if ($sisa !== []) {
            throw new PelanggaranAturanBisnis('PilihanTidakValid', "Ada pilihan yang tidak berlaku untuk {$namaProduk}. Muat ulang menu lalu pilih lagi.", $bidang);
        }

        return $hasil;
    }
}
