<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\SimpanPaketSesi;
use App\Domain\Katalog\Data\DataAtributVarian;
use App\Domain\Katalog\Data\DataObatProduk;
use App\Domain\Katalog\Data\DataProduk;
use App\Domain\Katalog\Data\DataSatuanProduk;
use App\Domain\Katalog\Enum\GolonganObat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Layanan\OpsiKelompokPajakKatalog;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanStokAwalPermintaan;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Form produk F-03 (tipe FE `FormProduk`, E.3). Uang & jumlah berupa string desimal; ID berupa ULID publik yang
 * dipetakan ke Id di dalam tenant aktif (tidak dikenal = galat di bidangnya).
 */
final class SimpanProdukPermintaan extends FormRequest
{
    public const POLA_UANG = 'regex:/^\d{1,16}(\.\d{1,2})?$/';

    public const POLA_KUANTITAS = 'regex:/^\d{1,14}(\.\d{1,4})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tigaKeadaan = Rule::in(['Ikut', 'Ya', 'Tidak']);

        return [
            'Uuid' => ['required', 'ulid'],
            'Nama' => ['required', 'string', 'max:150'],
            'NamaStruk' => ['nullable', 'string', 'max:40'],
            'Sku' => ['nullable', 'string', 'max:64'],
            'Jenis' => ['required', Rule::enum(JenisProduk::class)],
            'UuidKategori' => ['nullable', 'ulid'],
            'Merek' => ['nullable', 'string', 'max:60'],
            'UuidSatuanDasar' => ['required', 'ulid'],
            'Pelacakan' => ['required', Rule::enum(PelacakanProduk::class)],
            'UuidKelompokPajak' => ['nullable', 'ulid'],
            'HargaTermasukPajak' => ['required', $tigaKeadaan],
            'BolehMinus' => ['required', $tigaKeadaan],
            'TampilDiPos' => ['required', 'boolean'],
            'TampilOnline' => ['required', 'boolean'],
            // K-16: `sometimes` supaya klien lama (tanpa field ini) tidak mereset favorit saat mengedit.
            'Favorit' => ['sometimes', 'boolean'],
            'HargaTerbuka' => ['sometimes', 'boolean'],
            // Apotek (§9.5): golongan obat (null = bukan obat); `sometimes` supaya klien lama tidak menghapus isian obat.
            'GolonganObat' => ['sometimes', 'nullable', Rule::enum(GolonganObat::class)],
            'ObatWajibApotek' => ['sometimes', 'boolean'],
            'Prekursor' => ['sometimes', 'boolean'],
            'DurasiMenit' => ['nullable', 'integer', 'min:5', 'max:720'],
            'MasaGaransiBulan' => ['nullable', 'integer', 'min:1', 'max:240'],
            // Faktur Pajak Coretax (v3.11): kode barang/jasa 6 digit dan kode unit `UM.00xx` dari daftar DJP.
            'KodeBarangJasaCoretax' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'KodeUnitCoretax' => ['nullable', 'string', 'regex:/^UM\.\d{4}$/'],
            'Satuan' => ['required', 'array', 'min:1', 'max:10'],
            'Satuan.*.Uuid' => ['nullable', 'ulid'],
            'Satuan.*.UuidSatuan' => ['required', 'ulid'],
            'Satuan.*.KonversiKeDasar' => ['required', 'string', self::POLA_KUANTITAS],
            'Satuan.*.DefaultJual' => ['required', 'boolean'],
            'Satuan.*.DefaultBeli' => ['required', 'boolean'],
            'Satuan.*.Barcode' => ['present', 'array', 'max:20'],
            'Satuan.*.Barcode.*' => ['required', 'string', 'max:64'],
            'Satuan.*.HargaAwal' => ['present', 'array', 'max:10'],
            'Satuan.*.HargaAwal.*.JumlahMinimum' => ['required', 'string', self::POLA_KUANTITAS],
            'Satuan.*.HargaAwal.*.Harga' => ['required', 'string', self::POLA_UANG],
            'AtributVarian' => ['present', 'array', 'max:3'],
            'AtributVarian.*.Nama' => ['required', 'string', 'max:30'],
            'AtributVarian.*.Nilai' => ['required', 'array', 'min:1', 'max:20'],
            'AtributVarian.*.Nilai.*' => ['required', 'string', 'max:40'],
            // D-23 B: "Jual sebagai paket sesi" (hanya saat membuat produk Jasa).
            'PaketSesi' => ['nullable', 'array'],
            'PaketSesi.JumlahSesi' => ['required_with:PaketSesi', 'integer', 'min:1', 'max:'.SimpanPaketSesi::MAKS_SESI],
            'PaketSesi.MasaBerlakuHari' => ['nullable', 'integer', 'min:1', 'max:'.SimpanPaketSesi::MAKS_HARI],
            // Audit kemudahan pakai #11: stok sekarang + harga beli produk baru (opsional), diposting sebagai stok awal.
            'StokAwal' => ['nullable', 'array'],
            'StokAwal.Jumlah' => ['required_with:StokAwal', SimpanStokAwalPermintaan::POLA_JUMLAH],
            'StokAwal.HargaBeli' => ['nullable', SimpanStokAwalPermintaan::POLA_HPP],
            'StokAwal.UuidGudang' => ['required_with:StokAwal', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'Satuan.*.KonversiKeDasar.regex' => 'Isi satuan berupa angka, maksimal 4 angka desimal. Misal: 12 atau 0.5.',
            'Satuan.*.HargaAwal.*.JumlahMinimum.regex' => 'Jumlah minimum berupa angka, maksimal 4 angka desimal.',
            'Satuan.*.HargaAwal.*.Harga.regex' => 'Harga berupa angka tanpa titik ribuan, misal 15000 atau 15000.50.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'PaketSesi.JumlahSesi' => 'jumlah sesi',
            'PaketSesi.MasaBerlakuHari' => 'masa berlaku',
            'StokAwal.Jumlah' => 'stok sekarang',
            'StokAwal.HargaBeli' => 'harga beli',
            'StokAwal.UuidGudang' => 'lokasi stok',
        ];
    }

    /**
     * Audit kemudahan pakai #11: stok sekarang produk baru, `null` bila dikosongkan. Harga beli kosong = 0.
     *
     * @return array{Jumlah: Kuantitas, HargaBeli: BigDecimal, UuidGudang: string}|null
     */
    public function AmbilStokAwal(): ?array
    {
        if (! $this->filled('StokAwal.Jumlah')) {
            return null;
        }

        return [
            'Jumlah' => Kuantitas::Dari((string) $this->validated('StokAwal.Jumlah')),
            'HargaBeli' => BigDecimal::of($this->filled('StokAwal.HargaBeli') ? (string) $this->validated('StokAwal.HargaBeli') : '0'),
            'UuidGudang' => (string) $this->validated('StokAwal.UuidGudang'),
        ];
    }

    /**
     * D-23 B: isian paket sesi dari formulir produk, `null` bila tidak dicentang.
     *
     * @return array{JumlahSesi: int, MasaBerlakuHari: int|null}|null
     */
    public function AmbilPaketSesi(): ?array
    {
        if (! $this->filled('PaketSesi.JumlahSesi')) {
            return null;
        }

        return [
            'JumlahSesi' => $this->integer('PaketSesi.JumlahSesi'),
            'MasaBerlakuHari' => $this->filled('PaketSesi.MasaBerlakuHari') ? $this->integer('PaketSesi.MasaBerlakuHari') : null,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function AmbilData(?Produk $produk, bool $bolehUbahHarga): DataProduk
    {
        $idSatuan = Satuan::query()->pluck('Id', 'Uuid');
        $idProdukSatuan = $produk === null ? collect() : ProdukSatuan::query()->where('IdProduk', $produk->Id)->pluck('Id', 'Uuid');
        $satuan = [];

        /** @var list<array<string, mixed>> $barisSatuan */
        $barisSatuan = array_values((array) $this->input('Satuan', []));

        foreach ($barisSatuan as $i => $baris) {
            $uuidProdukSatuan = $baris['Uuid'] ?? null;
            $satuan[] = new DataSatuanProduk(
                idProdukSatuan: is_string($uuidProdukSatuan) ? (int) ($idProdukSatuan->get($uuidProdukSatuan) ?? self::Galat("Satuan.{$i}.Uuid", 'Satuan produk ini sudah berubah. Muat ulang halaman.')) : null,
                idSatuan: (int) ($idSatuan->get((string) $baris['UuidSatuan']) ?? self::Galat("Satuan.{$i}.UuidSatuan", 'Satuan tidak ditemukan.')),
                konversiKeDasar: Kuantitas::Dari((string) $baris['KonversiKeDasar']),
                defaultJual: (bool) $baris['DefaultJual'],
                defaultBeli: (bool) $baris['DefaultBeli'],
                barcode: array_values(array_map('strval', (array) ($baris['Barcode'] ?? []))),
                hargaAwal: array_values(array_map(fn (array $harga): DataBarisHarga => new DataBarisHarga(
                    Kuantitas::Dari((string) $harga['JumlahMinimum']),
                    Uang::Dari((string) $harga['Harga']),
                ), (array) ($baris['HargaAwal'] ?? []))),
            );
        }

        return new DataProduk(
            uuid: $produk === null ? $this->string('Uuid')->toString() : $produk->Uuid,
            nama: $this->string('Nama')->toString(),
            namaStruk: $this->filled('NamaStruk') ? $this->string('NamaStruk')->toString() : null,
            sku: $this->filled('Sku') ? $this->string('Sku')->toString() : null,
            jenis: JenisProduk::from($this->string('Jenis')->toString()),
            idKategori: $this->filled('UuidKategori')
                ? (int) (Kategori::query()->where('Uuid', $this->string('UuidKategori')->toString())->value('Id') ?? self::Galat('UuidKategori', 'Kategori tidak ditemukan.'))
                : null,
            merek: $this->filled('Merek') ? $this->string('Merek')->toString() : null,
            idSatuanDasar: (int) ($idSatuan->get($this->string('UuidSatuanDasar')->toString()) ?? self::Galat('UuidSatuanDasar', 'Satuan dasar tidak ditemukan.')),
            pelacakan: PelacakanProduk::from($this->string('Pelacakan')->toString()),
            idKelompokPajak: $this->filled('UuidKelompokPajak')
                ? (app(OpsiKelompokPajakKatalog::class)->CariIdBerdasarkanUuid($this->string('UuidKelompokPajak')->toString()) ?? self::Galat('UuidKelompokPajak', 'Kelompok pajak tidak ditemukan.'))
                : null,
            hargaTermasukPajak: self::DariTigaKeadaan($this->string('HargaTermasukPajak')->toString()),
            bolehMinus: self::DariTigaKeadaan($this->string('BolehMinus')->toString()),
            tampilDiPos: $this->boolean('TampilDiPos'),
            tampilOnline: $this->boolean('TampilOnline'),
            satuan: $satuan,
            atributVarian: array_values(array_map(fn (array $atribut): DataAtributVarian => new DataAtributVarian(
                (string) $atribut['Nama'],
                array_values(array_map('strval', (array) $atribut['Nilai'])),
            ), array_values((array) $this->input('AtributVarian', [])))),
            bolehUbahHarga: $bolehUbahHarga,
            durasiMenit: $this->filled('DurasiMenit') ? $this->integer('DurasiMenit') : null,
            masaGaransiBulan: $this->filled('MasaGaransiBulan') ? $this->integer('MasaGaransiBulan') : null,
            kodeBarangJasaCoretax: $this->filled('KodeBarangJasaCoretax') ? $this->string('KodeBarangJasaCoretax')->toString() : null,
            kodeUnitCoretax: $this->filled('KodeUnitCoretax') ? $this->string('KodeUnitCoretax')->toString() : null,
            favorit: $this->has('Favorit') ? $this->boolean('Favorit') : null,
            hargaTerbuka: $this->has('HargaTerbuka') ? $this->boolean('HargaTerbuka') : null,
            obat: $this->has('GolonganObat') ? new DataObatProduk(
                $this->filled('GolonganObat') ? GolonganObat::from($this->string('GolonganObat')->toString()) : null,
                $this->boolean('ObatWajibApotek'),
                $this->boolean('Prekursor'),
            ) : null,
        );
    }

    private static function DariTigaKeadaan(string $nilai): ?bool
    {
        return match ($nilai) {
            'Ya' => true,
            'Tidak' => false,
            default => null,
        };
    }

    /**
     * @throws ValidationException
     */
    private static function Galat(string $bidang, string $pesan): never
    {
        throw ValidationException::withMessages([$bidang => $pesan]);
    }
}
