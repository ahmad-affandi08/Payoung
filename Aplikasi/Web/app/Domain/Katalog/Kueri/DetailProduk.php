<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Layanan\OpsiKelompokPajakKatalog;
use App\Domain\Katalog\Layanan\PenilaiPemakaianProduk;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\GudangTenant;
use Illuminate\Support\Str;

/**
 * Isi halaman detail produk (F-03 E.4: `DetailProduk`, `BarisVarian`, `BarisBatasStok`, riwayat audit) dan form
 * produk (E.3: `FormProduk`). Uang & jumlah sebagai string; kolom tiga keadaan null → "Ikut".
 */
final class DetailProduk
{
    public function __construct(
        private readonly PenilaiPemakaianProduk $penilai,
        private readonly OpsiKelompokPajakKatalog $kelompokPajak,
        private readonly DaftarProduk $daftarProduk,
        private readonly GudangTenant $gudangTenant,
        private readonly DaftarAnggota $anggota,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Produk $produk): array
    {
        $satuanDasar = Satuan::query()->findOrFail($produk->IdSatuanDasar);

        return [
            'Uuid' => $produk->Uuid,
            'Nama' => $produk->Nama,
            'NamaStruk' => $produk->NamaStruk,
            'Sku' => $produk->Sku,
            'Jenis' => $produk->Jenis->value,
            'LabelJenis' => $produk->Jenis->AmbilLabel(),
            'NamaKategori' => $produk->IdKategori === null ? null : Kategori::query()->whereKey($produk->IdKategori)->value('Nama'),
            'Merek' => $produk->Merek,
            'SatuanDasar' => DaftarSatuan::PetakanOpsi($satuanDasar),
            'Pelacakan' => $produk->Pelacakan->value,
            'LabelPelacakan' => $produk->Pelacakan->AmbilLabel(),
            'KelompokPajak' => $this->kelompokPajak->CariBerdasarkanId($produk->IdKelompokPajak),
            'HargaTermasukPajak' => self::KeTigaKeadaan($produk->HargaTermasukPajak),
            'BolehMinus' => self::KeTigaKeadaan($produk->BolehMinus),
            'TampilDiPos' => $produk->TampilDiPos,
            'TampilOnline' => $produk->TampilOnline,
            'Favorit' => $produk->Favorit,
            'HargaTerbuka' => $produk->HargaTerbuka,
            'GolonganObat' => $produk->GolonganObat?->value,
            'ObatWajibApotek' => $produk->ObatWajibApotek,
            'Prekursor' => $produk->Prekursor,
            'DurasiMenit' => $produk->DurasiMenit,
            'MasaGaransiBulan' => $produk->MasaGaransiBulan,
            'LokasiProduksi' => $produk->LokasiProduksi?->value,
            'KodeBarangJasaCoretax' => $produk->KodeBarangJasaCoretax,
            'KodeUnitCoretax' => $produk->KodeUnitCoretax,
            'UrlGambar' => PenyimpanGambarProduk::BuatUrl($produk, 'besar'),
            'UrlGambarKecil' => PenyimpanGambarProduk::BuatUrl($produk, 'kecil'),
            'Satuan' => $this->AmbilSatuan($produk),
            'AtributVarian' => $produk->Jenis === JenisProduk::IndukVarian ? self::AmbilDefinisiVarian($produk) : [],
            'AlasanTidakBisaDihapus' => $this->AmbilAlasanTidakBisaDihapus($produk),
            'DibuatPada' => $produk->DibuatPada?->toIso8601String() ?? '',
            'DiubahPada' => $produk->DiubahPada?->toIso8601String() ?? '',
            'DiarsipkanPada' => $produk->DiarsipkanPada?->toIso8601String(),
        ];
    }

    /**
     * Anak varian induk (tipe FE `BarisVarian`), urut nama.
     *
     * @return list<array{Uuid: string, Nama: string, Sku: string|null, Atribut: list<array{Nama: string, Nilai: string}>, HargaDasar: string|null, Status: string}>
     */
    public function AmbilVarian(Produk $produk): array
    {
        if ($produk->Jenis !== JenisProduk::IndukVarian) {
            return [];
        }

        $anak = Produk::query()->where('IdInduk', $produk->Id)->orderBy('Nama')->orderBy('Id')->get();
        $harga = $this->daftarProduk->AmbilHargaDasar(array_values(array_map(fn (Produk $satu): int => $satu->Id, $anak->all())));
        // Audit kemudahan pakai #18: barcode pertama tiap varian untuk tabel varian yang bisa diedit.
        $barcode = ProdukBarcode::query()->whereIn('IdProduk', $anak->modelKeys())->orderBy('Id')->get(['IdProduk', 'Barcode'])
            ->groupBy('IdProduk')->map(fn ($k): string => (string) $k->first()?->Barcode);

        return array_values($anak->map(fn (Produk $satu): array => [
            'Uuid' => $satu->Uuid,
            'Nama' => $satu->Nama,
            'Sku' => $satu->Sku,
            'Atribut' => array_values(array_map(fn (array $a): array => ['Nama' => (string) ($a['Nama'] ?? ''), 'Nilai' => (string) ($a['Nilai'] ?? '')], $satu->AtributVarian ?? [])),
            'HargaDasar' => $harga[$satu->Id] ?? null,
            'Barcode' => $barcode->get($satu->Id),
            'Status' => $satu->AmbilStatus()->value,
        ])->all());
    }

    /**
     * Batas stok per lokasi stok aktif (tipe FE `BarisBatasStok`); null bila jenis produk tidak punya stok.
     *
     * @return list<array{UuidGudang: string, NamaGudang: string, NamaOutlet: string, StokMinimum: string, StokMaksimum: string}>|null
     */
    public function AmbilBatasStok(Produk $produk): ?array
    {
        if (! $produk->Jenis->CekPunyaStok()) {
            return null;
        }

        $batas = ProdukGudang::query()->where('IdProduk', $produk->Id)->get()->keyBy('IdGudang');

        return array_map(function (array $gudang) use ($batas): array {
            $baris = $batas->get($gudang['Id']);

            return [
                'UuidGudang' => $gudang['Uuid'],
                'NamaGudang' => $gudang['Nama'],
                'NamaOutlet' => $gudang['NamaOutlet'],
                'StokMinimum' => $baris instanceof ProdukGudang ? (string) $baris->StokMinimum : '',
                'StokMaksimum' => $baris instanceof ProdukGudang ? (string) $baris->StokMaksimum : '',
            ];
        }, $this->gudangTenant->AmbilAktif());
    }

    /**
     * Riwayat perubahan produk dari log audit, terbaru di atas (maks. 20).
     *
     * @return list<array{Peristiwa: string, NamaPengguna: string|null, DibuatPada: string}>
     */
    public function AmbilRiwayat(Produk $produk): array
    {
        $log = LogAudit::query()->where('JenisObjek', 'Produk')->where('IdObjek', $produk->Id)->orderByDesc('Id')->limit(20)->get();
        $idPengguna = array_values(array_unique(array_filter($log->pluck('IdPengguna')->all(), 'is_int')));
        $nama = $this->anggota->AmbilNamaPengguna($produk->IdTenant, $idPengguna);

        return array_values($log->map(fn (LogAudit $baris): array => [
            'Peristiwa' => $baris->Peristiwa,
            'NamaPengguna' => $baris->IdPengguna === null ? null : ($nama[$baris->IdPengguna] ?? null),
            'DibuatPada' => $baris->DibuatPada->toIso8601String(),
        ])->all());
    }

    /**
     * Isi form ubah (tipe FE `FormProduk`). `HargaAwal` selalu kosong untuk satuan yang sudah ada.
     *
     * @return array<string, mixed>
     */
    public function AmbilForm(Produk $produk): array
    {
        $uuidSatuan = Satuan::query()->pluck('Uuid', 'Id');
        $barcode = ProdukBarcode::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get()->groupBy('IdProdukSatuan');

        return [
            'Uuid' => $produk->Uuid,
            'Nama' => $produk->Nama,
            'NamaStruk' => (string) $produk->NamaStruk,
            'Sku' => (string) $produk->Sku,
            'Jenis' => $produk->Jenis->value,
            'UuidKategori' => $produk->IdKategori === null ? null : Kategori::query()->whereKey($produk->IdKategori)->value('Uuid'),
            'Merek' => (string) $produk->Merek,
            'UuidSatuanDasar' => (string) $uuidSatuan->get($produk->IdSatuanDasar),
            'Pelacakan' => $produk->Pelacakan->value,
            'UuidKelompokPajak' => $this->kelompokPajak->CariBerdasarkanId($produk->IdKelompokPajak)['Uuid'] ?? null,
            'HargaTermasukPajak' => self::KeTigaKeadaan($produk->HargaTermasukPajak),
            'BolehMinus' => self::KeTigaKeadaan($produk->BolehMinus),
            'TampilDiPos' => $produk->TampilDiPos,
            'TampilOnline' => $produk->TampilOnline,
            'Favorit' => $produk->Favorit,
            'HargaTerbuka' => $produk->HargaTerbuka,
            'GolonganObat' => $produk->GolonganObat?->value,
            'ObatWajibApotek' => $produk->ObatWajibApotek,
            'Prekursor' => $produk->Prekursor,
            'DurasiMenit' => $produk->DurasiMenit,
            'MasaGaransiBulan' => $produk->MasaGaransiBulan,
            'LokasiProduksi' => $produk->LokasiProduksi?->value,
            'KodeBarangJasaCoretax' => $produk->KodeBarangJasaCoretax,
            'KodeUnitCoretax' => $produk->KodeUnitCoretax,
            'Satuan' => array_values(ProdukSatuan::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get()
                ->sortBy(fn (ProdukSatuan $s): int => $s->IdSatuan === $produk->IdSatuanDasar ? 0 : 1)
                ->map(fn (ProdukSatuan $s): array => [
                    'Uuid' => $s->Uuid,
                    'UuidSatuan' => (string) $uuidSatuan->get($s->IdSatuan),
                    'KonversiKeDasar' => $s->KonversiKeDasar,
                    'DefaultJual' => $s->DefaultJual,
                    'DefaultBeli' => $s->DefaultBeli,
                    'Barcode' => array_values(array_map('strval', $barcode->get($s->Id)?->pluck('Barcode')->all() ?? [])),
                    'HargaAwal' => [],
                ])->all()),
            'AtributVarian' => $produk->Jenis === JenisProduk::IndukVarian ? self::AmbilDefinisiVarian($produk) : [],
        ];
    }

    /**
     * Form buat produk baru: Uuid ULID baru dari server (kunci idempotensi), satuan dasar pcs bila ada.
     *
     * @return array<string, mixed>
     */
    public function AmbilFormKosong(): array
    {
        $satuan = Satuan::query()->where('KodeStandar', 'PCS')->first() ?? Satuan::query()->orderBy('Id')->first();
        $uuidSatuan = $satuan === null ? '' : $satuan->Uuid;

        return [
            'Uuid' => (string) Str::ulid(),
            'Nama' => '',
            'NamaStruk' => '',
            'Sku' => '',
            'Jenis' => JenisProduk::Stok->value,
            'UuidKategori' => null,
            'Merek' => '',
            'UuidSatuanDasar' => $uuidSatuan,
            'Pelacakan' => PelacakanProduk::Tidak->value,
            'UuidKelompokPajak' => $this->kelompokPajak->AmbilOpsi()[0]['Uuid'] ?? null,
            'HargaTermasukPajak' => 'Ikut',
            'BolehMinus' => 'Ikut',
            'TampilDiPos' => true,
            'TampilOnline' => false,
            'Favorit' => false,
            'HargaTerbuka' => false,
            'GolonganObat' => null,
            'ObatWajibApotek' => false,
            'Prekursor' => false,
            'DurasiMenit' => null,
            'MasaGaransiBulan' => null,
            'LokasiProduksi' => null,
            'KodeBarangJasaCoretax' => null,
            'KodeUnitCoretax' => null,
            'Satuan' => [[
                'Uuid' => null,
                'UuidSatuan' => $uuidSatuan,
                'KonversiKeDasar' => '1',
                'DefaultJual' => true,
                'DefaultBeli' => true,
                'Barcode' => [],
                'HargaAwal' => [],
            ]],
            'AtributVarian' => [],
        ];
    }

    /** Jenis tidak bisa diubah: induk varian, atau produk sudah dipakai (C.1). */
    public function CekJenisTerkunci(Produk $produk): bool
    {
        return $produk->Jenis === JenisProduk::IndukVarian || $this->penilai->AmbilAlasan($produk) !== null;
    }

    /**
     * @return list<array{Uuid: string, Nama: string, Simbol: string, KonversiKeDasar: string, DefaultJual: bool, DefaultBeli: bool, BisaDijual: bool, Barcode: list<array{Uuid: string, Barcode: string}>}>
     */
    private function AmbilSatuan(Produk $produk): array
    {
        $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->with('SatuanUnit')->orderBy('KonversiKeDasar')->orderBy('Id')->get();
        $barcode = ProdukBarcode::query()->where('IdProduk', $produk->Id)->orderBy('Id')->get()->groupBy('IdProdukSatuan');
        $bisaDijual = ProdukHarga::query()->whereIn('IdProdukSatuan', $satuan->modelKeys())->whereNull('IdDaftarHarga')
            ->where('JumlahMinimum', '1')->pluck('IdProdukSatuan')->all();

        return array_values($satuan->map(fn (ProdukSatuan $s): array => [
            'Uuid' => $s->Uuid,
            'Nama' => $s->SatuanUnit->Nama,
            'Simbol' => $s->SatuanUnit->Simbol,
            'KonversiKeDasar' => $s->KonversiKeDasar,
            'DefaultJual' => $s->DefaultJual,
            'DefaultBeli' => $s->DefaultBeli,
            'BisaDijual' => $produk->Jenis->CekBisaDijual() && in_array($s->Id, $bisaDijual, true),
            'Barcode' => array_values(($barcode->get($s->Id) ?? collect())->map(fn (ProdukBarcode $b): array => ['Uuid' => $b->Uuid, 'Barcode' => $b->Barcode])->all()),
        ])->all());
    }

    private function AmbilAlasanTidakBisaDihapus(Produk $produk): ?string
    {
        if ($produk->Jenis !== JenisProduk::IndukVarian) {
            $alasan = $this->penilai->AmbilAlasan($produk);

            return $alasan === null ? null : "Produk ini {$alasan}.";
        }

        foreach (Produk::query()->where('IdInduk', $produk->Id)->get() as $anak) {
            $alasan = $this->penilai->AmbilAlasan($anak);

            if ($alasan !== null) {
                return "Varian {$anak->Nama} {$alasan}.";
            }
        }

        return null;
    }

    /**
     * @return list<array{Nama: string, Nilai: list<string>}>
     */
    private static function AmbilDefinisiVarian(Produk $produk): array
    {
        return array_values(array_map(fn (array $a): array => [
            'Nama' => (string) ($a['Nama'] ?? ''),
            'Nilai' => array_values(array_map('strval', (array) ($a['Nilai'] ?? []))),
        ], $produk->AtributVarian ?? []));
    }

    private static function KeTigaKeadaan(?bool $nilai): string
    {
        return match ($nilai) {
            null => 'Ikut',
            true => 'Ya',
            false => 'Tidak',
        };
    }
}
