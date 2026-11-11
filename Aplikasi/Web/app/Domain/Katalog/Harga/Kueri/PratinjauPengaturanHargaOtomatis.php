<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Kueri;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Aksi\UbahPengaturanHargaOtomatis;
use App\Domain\Katalog\Harga\Data\DataAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Layanan\PenentuAturanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungHargaDariModal;
use App\Domain\Katalog\Harga\Layanan\PerencanaHargaDariModal;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Pembelian\Kueri\HargaBeliTerakhirProduk;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pratinjau pengaturan Harga otomatis yang BELUM disimpan (D-268): seandainya isian di layar disimpan sekarang, berapa
 * harga jual yang akan berubah, berapa yang ditahan menunggu persetujuan, dan berapa yang tetap, beserta beberapa contoh
 * produk. Murni baca: tidak menyimpan, tidak membuat usulan, tidak menyentuh harga. Memakai perencana, penentu aturan,
 * dan penilaian yang sama dengan `PenerapHargaOtomatis`, jadi hitungannya persis yang kelak diterapkan. Saklar utama
 * tidak diperiksa: pratinjau tetap menjawab "kalau dinyalakan, apa yang terjadi".
 */
final class PratinjauPengaturanHargaOtomatis
{
    public const BERUBAH = 'Berubah';

    public const MENUNGGU = 'Menunggu';

    public const TETAP = 'Tetap';

    /** Harga beli turun, tetapi pengaturan "Harga jual tetap" menahannya. */
    public const DITAHAN = 'Ditahan';

    private const POTONGAN = 300;

    /** Contoh yang ditampilkan: paling banyak per jenis, urut menurut besarnya perubahan. */
    private const MAKS_MENUNGGU = 2;

    private const MAKS_BERUBAH = 4;

    private const MAKS_TETAP = 1;

    public function __construct(
        private readonly HargaBeliTerakhirProduk $hargaBeli,
        private readonly PerencanaHargaDariModal $perencana,
    ) {}

    /**
     * @param  list<DataAturanHargaOtomatis>  $khusus  hanya cakupan Kategori atau Produk, pembulatan dan arahnya mengikuti pengaturan utama
     * @return array{Ringkasan: array<string, int>, Contoh: list<array<string, mixed>>}
     *
     * @throws PelanggaranAturanBisnis BatasTidakValid, UntungTidakValid, PembulatanTidakDikenal, KategoriTidakDikenal, ProdukTidakDikenal
     */
    public function Ambil(string $persenUntung, int $pembulatan, ArahHargaOtomatis $arah, string $batasPersen, array $khusus): array
    {
        $batas = UbahPengaturanHargaOtomatis::AmbilBatas($batasPersen);
        PenghitungHargaDariModal::PastikanPembulatanSah($pembulatan);
        $penentu = PenentuAturanHarga::DariAturan($this->SusunAturan($persenUntung, $pembulatan, $arah, $khusus));

        $ringkasan = [self::BERUBAH => 0, self::MENUNGGU => 0, self::TETAP => 0, 'TanpaHargaBeli' => 0];
        $contoh = [self::MENUNGGU => [], self::BERUBAH => [], self::TETAP => []];

        Produk::query()
            ->where('Jenis', JenisProduk::Stok->value)
            ->where('HargaTerbuka', false)
            ->where('Aktif', true)
            ->whereNull('DiarsipkanPada')
            ->orderBy('Id')
            ->chunkById(self::POTONGAN, function ($produk) use ($penentu, $batas, &$ringkasan, &$contoh): void {
                $modal = $this->hargaBeli->Ambil(array_values($produk->map(fn (Produk $p): int => $p->Id)->all()));

                foreach ($produk as $p) {
                    $aturan = $penentu->Tentukan($p);

                    if ($aturan === null) {
                        continue;
                    }

                    $rencana = $this->perencana->Rencanakan($p, $modal[$p->Id] ?? null, BigDecimal::of($aturan->PersenUntung), $aturan->Pembulatan);

                    if (! in_array($rencana['Status'], [PerencanaHargaDariModal::SAMA, PerencanaHargaDariModal::BERUBAH], true)) {
                        $ringkasan['TanpaHargaBeli']++;

                        continue;
                    }

                    /** @var Uang $lama */
                    $lama = $rencana['Lama'];
                    /** @var Uang $baru */
                    $baru = $rencana['Baru'];
                    $selisih = $baru->Bandingkan($lama);
                    $persen = PenghitungHargaDariModal::HitungPerubahanPersen($lama, $baru);

                    // Urutan keputusan sama dengan PenerapHargaOtomatis: sama = tetap, turun saat "tetap" = ditahan, lewat batas = menunggu.
                    $status = match (true) {
                        $selisih === 0 => self::TETAP,
                        $selisih < 0 && $aturan->Arah === ArahHargaOtomatis::HanyaNaik => self::DITAHAN,
                        $persen->abs()->isGreaterThan($batas) => self::MENUNGGU,
                        default => self::BERUBAH,
                    };
                    $jenis = $status === self::DITAHAN ? self::TETAP : $status;
                    $ringkasan[$jenis]++;

                    $this->Catat($contoh[$jenis], $jenis, [
                        'Uuid' => $p->Uuid,
                        'Nama' => $p->Nama,
                        'Sku' => $p->Sku,
                        'Status' => $status,
                        'Modal' => (string) ($rencana['ModalJual'] ?? BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
                        'HargaLama' => $lama->KeString(),
                        'HargaBaru' => $baru->KeString(),
                        'PerubahanPersen' => (string) $persen,
                    ]);
                }
            }, 'Id');

        return [
            'Ringkasan' => $ringkasan,
            'Contoh' => [...$contoh[self::MENUNGGU], ...$contoh[self::BERUBAH], ...$contoh[self::TETAP]],
        ];
    }

    /**
     * Aturan sementara di memori (tidak disimpan) dari isian layar: aturan utama "Semua" ditambah untung khusus.
     *
     * @param  list<DataAturanHargaOtomatis>  $khusus
     * @return list<AturanHargaOtomatis>
     */
    private function SusunAturan(string $persenUntung, int $pembulatan, ArahHargaOtomatis $arah, array $khusus): array
    {
        $dasar = ['Pembulatan' => $pembulatan, 'Arah' => $arah, 'Aktif' => true];
        $daftar = [(new AturanHargaOtomatis)->forceFill([...$dasar, 'Cakupan' => CakupanAturanHarga::Semua, 'PersenUntung' => (string) PenghitungHargaDariModal::AmbilPersen($persenUntung)])];

        $uuidKategori = array_values(array_filter(array_map(fn (DataAturanHargaOtomatis $k): ?string => $k->uuidKategori, $khusus)));
        $uuidProduk = array_values(array_filter(array_map(fn (DataAturanHargaOtomatis $k): ?string => $k->uuidProduk, $khusus)));
        $idKategori = Kategori::query()->whereIn('Uuid', $uuidKategori)->pluck('Id', 'Uuid');
        $idProduk = Produk::query()->whereIn('Uuid', $uuidProduk)->pluck('Id', 'Uuid');
        $dipakai = [];

        foreach ($khusus as $k) {
            $uuidK = $k->uuidKategori ?? '';
            $uuidP = $k->uuidProduk ?? '';
            $kunci = $k->cakupan->value.':'.($k->cakupan === CakupanAturanHarga::Kategori ? $uuidK : $uuidP);

            if ($k->cakupan === CakupanAturanHarga::Semua || isset($dipakai[$kunci])) {
                throw new PelanggaranAturanBisnis('AturanSudahAda', 'Satu kategori atau produk hanya boleh punya satu untung khusus.', 'Khusus');
            }

            $dipakai[$kunci] = true;

            if ($k->cakupan === CakupanAturanHarga::Kategori && ! isset($idKategori[$uuidK])) {
                throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Pilih kategori yang ada.', 'UuidKategori');
            }

            if ($k->cakupan === CakupanAturanHarga::Produk && ! isset($idProduk[$uuidP])) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Pilih produk yang ada.', 'UuidProduk');
            }

            $daftar[] = (new AturanHargaOtomatis)->forceFill([
                ...$dasar,
                'Cakupan' => $k->cakupan,
                'IdKategori' => $k->cakupan === CakupanAturanHarga::Kategori ? $idKategori[$uuidK] : null,
                'IdProduk' => $k->cakupan === CakupanAturanHarga::Produk ? $idProduk[$uuidP] : null,
                'PersenUntung' => (string) PenghitungHargaDariModal::AmbilPersen($k->persenUntung),
            ]);
        }

        return $daftar;
    }

    /**
     * Menyimpan contoh yang paling besar perubahannya per jenis, tanpa menahan seluruh katalog di memori.
     *
     * @param  list<array<string, mixed>>  $daftar
     * @param  array<string, mixed>  $baris
     */
    private function Catat(array &$daftar, string $jenis, array $baris): void
    {
        $maks = match ($jenis) {
            self::MENUNGGU => self::MAKS_MENUNGGU,
            self::BERUBAH => self::MAKS_BERUBAH,
            default => self::MAKS_TETAP,
        };

        $daftar[] = $baris;
        usort($daftar, fn (array $a, array $b): int => BigDecimal::of((string) $b['PerubahanPersen'])->abs()->compareTo(BigDecimal::of((string) $a['PerubahanPersen'])->abs()));
        $daftar = array_slice($daftar, 0, $maks);
    }
}
