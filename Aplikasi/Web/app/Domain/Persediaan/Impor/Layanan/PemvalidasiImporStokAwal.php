<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Impor\Layanan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Impor\Layanan\PembacaBerkasTabel;
use App\Domain\Katalog\Impor\Layanan\PenguraiNilaiImpor;
use App\Domain\Katalog\Impor\Layanan\PenyimpanBerkasImpor;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Enum\BidangImporStokAwal;
use App\Domain\Persediaan\Enum\StatusBarisImporStokAwal;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Layanan\PemvalidasiPelacakan;
use App\Domain\Persediaan\Model\ImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwalBaris;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Validasi impor stok awal (DesainF05a C.7), dijalankan `ValidasiImporStokAwalTugas`.
 *
 * Fase A (per baris): berkas dibaca ulang secara streaming; baris dengan `NomorBaris` ≤ baris tersimpan terakhir
 * dilewati (lanjutan setelah anggaran waktu habis). Setiap baris diurai `PenguraiBarisImporStokAwal` lalu disimpan
 * per 500 (`insertOrIgnore` + indeks unik → aman bila dua tugas berjalan). Lebih dari `persediaan.Impor.MaksimalBaris`
 * → Gagal.
 *
 * Fase B (per potongan 1000 baris valid, lewat API baca domain lain): produk dicocokkan (SKU → barcode → nama;
 * `ProdukTidakDikenal`/`ProdukAmbigu`), harus berstok, bukan konsinyasi, tidak diarsipkan; lokasi stok dicocokkan
 * (kode → nama; `LokasiTidakDikenal`/`LokasiAmbigu`), harus di outlet yang boleh diakses pengunggah dan aktif;
 * jumlah desimal hanya untuk satuan desimal; batch/seri lewat `PemvalidasiPelacakan`; tanggal tidak melewati hari
 * ini di outlet lokasi (`TanggalDiMasaDepan`); stok awal Diposting untuk produk & lokasi yang sama sudah ada
 * (`StokAwalSudahAda`). Lalu (produk, lokasi, batch) yang muncul lebih dari sekali → semua baris itu `BarisGanda`.
 * Baris valid menyimpan `IdProduk`, `IdGudang`, dan `Nilai` = (Jumlah × HargaModal) skala 2 HalfUp.
 */
final class PemvalidasiImporStokAwal
{
    private const UKURAN_SISIP = 500;

    private const UKURAN_POTONGAN = 1000;

    public function __construct(
        private readonly PembacaBerkasTabel $pembaca,
        private readonly PenyimpanBerkasImpor $penyimpan,
        private readonly PenguraiBarisImporStokAwal $pengurai,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly AksesPengguna $akses,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PemvalidasiPelacakan $pelacakan,
    ) {}

    /** True bila validasi selesai (Pratinjau atau Gagal); false bila anggaran waktu habis dan perlu dilanjutkan. */
    public function Jalankan(ImporStokAwal $impor, int $batasDetik): bool
    {
        $mulai = hrtime(true);
        $habis = fn (): bool => hrtime(true) - $mulai >= $batasDetik * 1_000_000_000;

        $selesaiFaseA = $this->penyimpan->DenganPathLokal($impor->PathBerkas, fn (string $path): ?bool => $this->JalankanFaseA($impor, $path, $habis));

        if ($selesaiFaseA === false) {
            return true;
        }

        if ($selesaiFaseA === null) {
            return false;
        }

        $this->JalankanFaseB($impor);

        return true;
    }

    /**
     * @param  Closure(): bool  $habis
     * @return bool|null true = selesai, false = impor digagalkan, null = anggaran waktu habis
     */
    private function JalankanFaseA(ImporStokAwal $impor, string $path, Closure $habis): ?bool
    {
        $pembaca = PemetaKolomImporStokAwal::AmbilOpsiPembaca($impor);
        $pemetaan = $impor->Pemetaan ?? [];
        $maksimal = (int) config('persediaan.Impor.MaksimalBaris', 20000);
        $terakhir = (int) ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->max('NomorBaris');
        $jumlah = ImporStokAwalBaris::query()->where('IdImporStokAwal', $impor->Id)->count();
        $judul = array_column($impor->KolomSumber ?? [], 'Judul', 'Indeks');
        $penampung = [];

        foreach ($this->pembaca->BacaBaris($path, $impor->Format, $pembaca['PemisahCsv'], $pembaca['Lembar']) as $nomor => $sel) {
            if ($nomor <= $pembaca['BarisJudul'] || $nomor <= $terakhir) {
                continue;
            }

            $jumlah++;

            if ($jumlah > $maksimal) {
                PengirimTugasImporStokAwal::Gagalkan($impor->Id, 'Berkas berisi lebih dari '.PenguraiNilaiImpor::FormatRibuan($maksimal).' baris. Bagi berkas menjadi beberapa bagian.');

                return false;
            }

            $hasil = $this->pengurai->Urai($sel, $pemetaan);
            $asli = [];

            foreach ($judul as $indeks => $namaKolom) {
                $asli[$namaKolom] = $sel[$indeks] ?? '';
            }

            $penampung[] = [
                'IdTenant' => $impor->IdTenant,
                'IdImporStokAwal' => $impor->Id,
                'NomorBaris' => $nomor,
                'Status' => $hasil['Galat'] === [] ? StatusBarisImporStokAwal::Valid->value : StatusBarisImporStokAwal::Galat->value,
                'Data' => self::KeJson($hasil['Data']),
                'DataAsli' => self::KeJson($asli),
                'Galat' => $hasil['Galat'] === [] ? null : self::KeJson($hasil['Galat']),
                'DibuatPada' => now(),
                'DiubahPada' => now(),
            ];

            if (count($penampung) >= self::UKURAN_SISIP) {
                ImporStokAwalBaris::query()->insertOrIgnore($penampung);
                $penampung = [];

                if ($habis()) {
                    return null;
                }
            }
        }

        if ($penampung !== []) {
            ImporStokAwalBaris::query()->insertOrIgnore($penampung);
        }

        return true;
    }

    private function JalankanFaseB(ImporStokAwal $impor): void
    {
        $idOutletBoleh = $this->akses->AmbilIdOutlet($impor->IdTenant, $impor->IdPengguna);
        $tanggal = CarbonImmutable::parse($impor->Tanggal?->toDateString() ?? now()->toDateString())->startOfDay();
        $hariIni = [];

        ImporStokAwalBaris::query()
            ->where('IdImporStokAwal', $impor->Id)
            ->where('Status', StatusBarisImporStokAwal::Valid->value)
            ->chunkById(self::UKURAN_POTONGAN, /** @param Collection<int, ImporStokAwalBaris> $potongan */ function (Collection $potongan) use ($impor, $idOutletBoleh, $tanggal, &$hariIni): void {
                DB::transaction(fn () => $this->PeriksaPotongan($potongan, $impor, $idOutletBoleh, $tanggal, $hariIni));
            }, 'Id');

        $this->TandaiGanda($impor);

        DB::transaction(function () use ($impor): void {
            $impor = ImporStokAwal::query()->whereKey($impor->Id)->lockForUpdate()->firstOrFail();

            if ($impor->Status !== StatusImporStokAwal::Memvalidasi) {
                return;
            }

            $jumlah = self::HitungStatus($impor->Id);
            $impor->fill([
                'JumlahBaris' => array_sum($jumlah),
                'JumlahValid' => $jumlah[StatusBarisImporStokAwal::Valid->value] ?? 0,
                'JumlahGalat' => $jumlah[StatusBarisImporStokAwal::Galat->value] ?? 0,
                'DivalidasiPada' => now(),
                'PesanGalat' => null,
            ]);
            $impor->UbahStatus(StatusImporStokAwal::Pratinjau);
            $impor->save();
        });
    }

    /**
     * @param  Collection<int, ImporStokAwalBaris>  $potongan
     * @param  list<int>|null  $idOutletBoleh
     * @param  array<int, CarbonImmutable>  $hariIni  tanggal bisnis hari ini per IdOutlet (0 = tanpa outlet)
     */
    private function PeriksaPotongan(Collection $potongan, ImporStokAwal $impor, ?array $idOutletBoleh, CarbonImmutable $tanggal, array &$hariIni): void
    {
        $kunciProduk = [];
        $kunciLokasi = [];

        foreach ($potongan as $baris) {
            foreach (['Sku', 'Barcode', 'NamaProduk'] as $kolom) {
                if (is_string($baris->Data[$kolom] ?? null)) {
                    $kunciProduk[] = $baris->Data[$kolom];
                }
            }

            if (is_string($baris->Data['Lokasi'] ?? null)) {
                $kunciLokasi[] = $baris->Data['Lokasi'];
            }
        }

        $cocokProduk = $this->infoProduk->CariKunciImpor(array_values(array_unique($kunciProduk)));
        $cocokLokasi = $kunciLokasi === [] ? [] : $this->infoGudang->CariKunciImpor(array_values(array_unique($kunciLokasi)));

        $idProdukSemua = [];
        $idGudangSemua = $impor->IdGudangBawaan === null ? [] : [$impor->IdGudangBawaan];

        foreach ($cocokProduk as $id) {
            $idProdukSemua = [...$idProdukSemua, ...$id];
        }

        foreach ($cocokLokasi as $id) {
            $idGudangSemua = [...$idGudangSemua, ...$id];
        }

        $produk = $idProdukSemua === [] ? [] : $this->infoProduk->AmbilBanyak(array_values(array_unique($idProdukSemua)));
        $gudang = $idGudangSemua === [] ? [] : $this->infoGudang->AmbilBanyak(array_values(array_unique($idGudangSemua)));
        $sudahAda = $this->AmbilPasanganSudahDiposting(array_keys($produk), array_keys($gudang));

        foreach ($potongan as $baris) {
            $data = $baris->Data ?? [];
            $galat = [];
            $infoProduk = $this->ResolusiProduk($data, $cocokProduk, $produk, $galat);
            $infoGudang = $this->ResolusiLokasi($data, $cocokLokasi, $gudang, $impor->IdGudangBawaan, $idOutletBoleh, $galat);

            if ($infoProduk !== null) {
                $galat = [...$galat, ...$this->PeriksaProduk($infoProduk, $data)];
            }

            if ($infoGudang !== null) {
                $kunciOutlet = $infoGudang->idOutlet ?? 0;
                $hariIni[$kunciOutlet] ??= $this->tanggalBisnis->Hitung($infoGudang->idOutlet);

                if ($tanggal->gt($hariIni[$kunciOutlet])) {
                    $galat[] = self::Galat('Tanggal', "Tanggal stok awal {$tanggal->format('d/m/Y')} melewati hari ini di lokasi {$infoGudang->nama}.");
                }
            }

            if ($infoProduk !== null && $infoGudang !== null && isset($sudahAda[$infoProduk->id.'|'.$infoGudang->id])) {
                $galat[] = self::Galat('Produk', "Stok awal {$infoProduk->nama} di {$infoGudang->nama} sudah dicatat. Koreksi lewat penyesuaian stok.");
            }

            $perubahan = ['Status' => StatusBarisImporStokAwal::Valid->value, 'Galat' => null];

            if ($galat === [] && $infoProduk !== null && $infoGudang !== null) {
                $jumlah = BigDecimal::of((string) $data['Jumlah']);
                $hpp = BigDecimal::of((string) $data['HargaModal']);
                $data['IdProduk'] = $infoProduk->id;
                $data['IdGudang'] = $infoGudang->id;
                $data['Nilai'] = (string) $jumlah->multipliedBy($hpp)->toScale(2, RoundingMode::HalfUp);
            } else {
                $perubahan = ['Status' => StatusBarisImporStokAwal::Galat->value, 'Galat' => self::KeJson($galat)];
                unset($data['IdProduk'], $data['IdGudang'], $data['Nilai']);
            }

            ImporStokAwalBaris::query()->whereKey($baris->Id)->update($perubahan + ['Data' => self::KeJson($data)]);
        }
    }

    /**
     * Pencocokan produk: kolom pertama yang terisi dan cocok (SKU, lalu Barcode, lalu Nama Produk) menentukan.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<int>>  $cocok
     * @param  array<int, DataInfoProdukStok>  $produk
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     */
    private function ResolusiProduk(array $data, array $cocok, array $produk, array &$galat): ?DataInfoProdukStok
    {
        $teksPertama = null;

        foreach (['Sku', 'Barcode', 'NamaProduk'] as $kolom) {
            $teks = is_string($data[$kolom] ?? null) ? $data[$kolom] : null;

            if ($teks === null) {
                continue;
            }

            $teksPertama ??= $teks;
            $id = $cocok[$teks] ?? [];

            if (count($id) > 1) {
                $galat[] = self::Galat('Produk', "\"{$teks}\" cocok dengan ".count($id).' produk. Isi SKU agar produk tidak ambigu.');

                return null;
            }

            if (count($id) === 1) {
                $info = $produk[$id[0]] ?? null;

                if ($info === null || $info->dihapus) {
                    break;
                }

                return $info;
            }
        }

        $galat[] = self::Galat('Produk', $teksPertama === null
            ? 'Isi SKU, barcode, atau nama produk.'
            : "Produk \"{$teksPertama}\" tidak ditemukan. Periksa SKU, barcode, atau nama produk.");

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<int>>  $cocok
     * @param  array<int, DataInfoGudang>  $gudang
     * @param  list<int>|null  $idOutletBoleh
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     */
    private function ResolusiLokasi(array $data, array $cocok, array $gudang, ?int $idGudangBawaan, ?array $idOutletBoleh, array &$galat): ?DataInfoGudang
    {
        $label = BidangImporStokAwal::Lokasi->AmbilLabel();
        $teks = is_string($data['Lokasi'] ?? null) ? $data['Lokasi'] : null;

        if ($teks === null) {
            if ($idGudangBawaan === null) {
                $galat[] = self::Galat($label, 'Lokasi stok kosong dan tidak ada lokasi stok bawaan.');

                return null;
            }

            $info = $gudang[$idGudangBawaan] ?? null;
        } else {
            $id = $cocok[$teks] ?? [];

            if (count($id) > 1) {
                $galat[] = self::Galat($label, "Lokasi \"{$teks}\" cocok dengan ".count($id).' lokasi stok. Pakai kode lokasi.');

                return null;
            }

            $info = $id === [] ? null : ($gudang[$id[0]] ?? null);
        }

        $boleh = $info !== null && ($idOutletBoleh === null || ($info->idOutlet !== null && in_array($info->idOutlet, $idOutletBoleh, true)));

        if ($info === null || ! $boleh) {
            $galat[] = self::Galat($label, "Lokasi stok \"{$teks}\" tidak ditemukan atau di luar outlet yang boleh Anda akses.");

            return null;
        }

        if (! $info->aktif) {
            $galat[] = self::Galat($label, "Lokasi stok {$info->nama} sudah diarsipkan.");

            return null;
        }

        return $info;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{Bidang: string, Pesan: string}>
     */
    private function PeriksaProduk(DataInfoProdukStok $produk, array $data): array
    {
        if ($produk->jenis === JenisProduk::Konsinyasi) {
            return [self::Galat('Produk', "{$produk->nama} adalah barang konsinyasi; stoknya dicatat lewat penerimaan konsinyasi, bukan stok awal.")];
        }

        if (! $produk->jenis->CekPunyaStok()) {
            return [self::Galat('Produk', "{$produk->nama} berjenis {$produk->jenis->AmbilLabel()} yang tidak punya stok.")];
        }

        if ($produk->diarsipkan) {
            return [self::Galat('Produk', "{$produk->nama} sudah diarsipkan. Aktifkan lagi produknya bila stoknya masih ada.")];
        }

        if (! is_string($data['Jumlah'] ?? null) || ! is_string($data['HargaModal'] ?? null)) {
            return [];
        }

        $jumlah = Kuantitas::Dari($data['Jumlah']);

        if (! $produk->bolehDesimal && ! $jumlah->KeDesimal()->getFractionalPart()->isZero()) {
            return [self::Galat(BidangImporStokAwal::Jumlah->AmbilLabel(), "Satuan {$produk->simbolSatuan} untuk {$produk->nama} tidak boleh desimal.")];
        }

        $tanggalKedaluwarsa = is_string($data['TanggalKedaluwarsa'] ?? null) ? CarbonImmutable::parse($data['TanggalKedaluwarsa']) : null;
        $nomorSeri = array_values(array_map('strval', (array) ($data['NomorSeri'] ?? [])));

        // Bidang galat pelacakan (`NomorBatch`, `Jumlah`, …) ditampilkan sebagai judul kolom templat.
        return array_values(array_map(
            fn (array $g): array => self::Galat(BidangImporStokAwal::tryFrom((string) $g['Bidang'])?->AmbilLabel() ?? (string) $g['Bidang'], (string) $g['Pesan']),
            $this->pelacakan->PeriksaBaris($produk, new DataBarisStokAwal(
                $produk->id,
                $jumlah,
                BigDecimal::of($data['HargaModal']),
                is_string($data['NomorBatch'] ?? null) ? $data['NomorBatch'] : null,
                $tanggalKedaluwarsa,
                $nomorSeri,
            )),
        ));
    }

    /**
     * Pasangan (IdProduk|IdGudang) yang sudah punya baris stok awal Diposting.
     *
     * @param  list<int>  $idProduk
     * @param  list<int>  $idGudang
     * @return array<string, true>
     */
    private function AmbilPasanganSudahDiposting(array $idProduk, array $idGudang): array
    {
        if ($idProduk === [] || $idGudang === []) {
            return [];
        }

        $gudangDokumen = StokAwal::query()
            ->where('Status', StatusStokAwal::Diposting->value)
            ->whereIn('IdGudang', $idGudang)
            ->pluck('IdGudang', 'Id')
            ->all();

        if ($gudangDokumen === []) {
            return [];
        }

        $hasil = [];

        foreach (array_chunk(array_keys($gudangDokumen), self::UKURAN_POTONGAN) as $potonganId) {
            $detail = StokAwalDetail::query()->whereIn('IdStokAwal', $potonganId)->whereIn('IdProduk', $idProduk)->get(['IdStokAwal', 'IdProduk']);

            foreach ($detail as $satu) {
                $hasil[$satu->IdProduk.'|'.$gudangDokumen[$satu->IdStokAwal]] = true;
            }
        }

        return $hasil;
    }

    /** (produk, lokasi, batch) yang sama di lebih dari satu baris valid → semua baris itu Galat `BarisGanda`. */
    private function TandaiGanda(ImporStokAwal $impor): void
    {
        $peta = [];

        ImporStokAwalBaris::query()
            ->where('IdImporStokAwal', $impor->Id)
            ->where('Status', StatusBarisImporStokAwal::Valid->value)
            ->select(['Id', 'NomorBaris', 'Data', 'Galat'])
            ->chunkById(self::UKURAN_POTONGAN, /** @param Collection<int, ImporStokAwalBaris> $potongan */ function (Collection $potongan) use (&$peta): void {
                foreach ($potongan as $baris) {
                    $batch = is_string($baris->Data['NomorBatch'] ?? null) ? mb_strtolower($baris->Data['NomorBatch']) : '';
                    $peta[((int) ($baris->Data['IdProduk'] ?? 0)).'|'.((int) ($baris->Data['IdGudang'] ?? 0)).'|'.$batch][] = [$baris->Id, $baris->NomorBaris, $baris->Galat];
                }
            }, 'Id');

        foreach ($peta as $daftar) {
            if (count($daftar) < 2) {
                continue;
            }

            $nomor = array_column($daftar, 1);
            $teksNomor = implode(', ', array_slice($nomor, 0, 10)).(count($nomor) > 10 ? ', …' : '');

            foreach ($daftar as [$id, , $galatLama]) {
                ImporStokAwalBaris::query()->whereKey($id)->update([
                    'Status' => StatusBarisImporStokAwal::Galat->value,
                    'Galat' => self::KeJson([...($galatLama ?? []), self::Galat('Produk', "Produk, lokasi stok, dan batch yang sama ada di beberapa baris (baris {$teksNomor}). Gabungkan menjadi satu baris.")]),
                ]);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    public static function HitungStatus(int $idImporStokAwal): array
    {
        return ImporStokAwalBaris::query()
            ->where('IdImporStokAwal', $idImporStokAwal)
            ->selectRaw('Status, COUNT(*) AS Jumlah')
            ->groupBy('Status')
            ->pluck('Jumlah', 'Status')
            ->map(fn (mixed $jumlah): int => (int) $jumlah)
            ->all();
    }

    /**
     * @return array{Bidang: string, Pesan: string}
     */
    private static function Galat(string $bidang, string $pesan): array
    {
        return ['Bidang' => $bidang, 'Pesan' => $pesan];
    }

    /**
     * @param  array<mixed>  $nilai
     */
    private static function KeJson(array $nilai): string
    {
        return (string) json_encode($nilai, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
