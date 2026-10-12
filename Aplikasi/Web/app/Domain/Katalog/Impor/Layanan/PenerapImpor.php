<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Aksi\ArsipkanProduk;
use App\Domain\Katalog\Aksi\PastikanJalurKategori;
use App\Domain\Katalog\Aksi\PastikanSatuan;
use App\Domain\Katalog\Aksi\PastikanSatuanProduk;
use App\Domain\Katalog\Aksi\PerbaruiProdukSebagian;
use App\Domain\Katalog\Aksi\SimpanProduk;
use App\Domain\Katalog\Aksi\TambahBarcodeProduk;
use App\Domain\Katalog\Aksi\TambahVarianAnak;
use App\Domain\Katalog\Data\DataAtributVarian;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Data\DataProduk;
use App\Domain\Katalog\Data\DataSatuanProduk;
use App\Domain\Katalog\Data\DataVarianAnak;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\StatusProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaProduk;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Impor\Data\DataOpsiImpor;
use App\Domain\Katalog\Impor\Enum\AksiBarisImpor;
use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Impor\Enum\ModeImpor;
use App\Domain\Katalog\Impor\Enum\StatusBarisImpor;
use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Katalog\Impor\Model\ImporProdukBaris;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penerapan impor produk (F-03 BR-03.6), dijalankan `TerapkanImporProdukTugas`. Baris `Valid` dengan aksi Buat/Perbarui
 * diproses per potongan `katalog.Impor.UkuranPotongan` (urut NomorBaris), **satu transaksi DB per potongan**:
 * kunci Tenant → baris impor (FOR UPDATE) → per baris savepoint sendiri yang memanggil Aksi publik Tim 1/Tim 2
 * (`SimpanProduk`, `PerbaruiProdukSebagian`, `TambahVarianAnak`, `PastikanJalurKategori`, `PastikanSatuan`,
 * `PastikanSatuanProduk`, `TambahBarcodeProduk`, `SimpanHargaProduk` sumber Impor). Baris ditandai `Diterapkan` +
 * `IdProduk` di transaksi yang sama → tepat sekali; menjalankan ulang hanya memproses baris yang belum diterapkan.
 * - Galat aturan bisnis satu baris → baris itu `GagalDiterapkan` (baris lain tetap jalan).
 * - `BR-02.1` (batas SKU paket habis) → potongan disimpan sampai baris sebelumnya, impor berhenti rapi (Gagal +
 *   pesan batas); setelah paket ditingkatkan, "Lanjutkan" meneruskan dari baris yang belum diterapkan.
 * - Izin `produk.harga.ubah` pengunggah diperiksa ulang per potongan; tanpa izin kolom harga diabaikan.
 * - Harga: harga dasar (jumlah minimum 1) diganti bila Harga Jual diisi; baris grosir yang ada dipertahankan kecuali
 *   kolom grosir dipetakan (maka set grosir = isi baris). Satuan alternatif hanya ditambah; barcode hanya ditambah.
 * - Status "Diarsipkan" mengarsipkan produk; memulihkan produk arsip tidak lewat impor.
 * Audit: satu `produk.impor.terapkan` per potongan, `produk.impor.selesai` di akhir.
 */
final class PenerapImpor
{
    public function __construct(
        private readonly PenguncianTenant $penguncian,
        private readonly AksesPengguna $akses,
        private readonly SimpanProduk $simpanProduk,
        private readonly PerbaruiProdukSebagian $perbaruiProduk,
        private readonly TambahVarianAnak $tambahVarian,
        private readonly PastikanJalurKategori $pastikanKategori,
        private readonly PastikanSatuan $pastikanSatuan,
        private readonly PastikanSatuanProduk $pastikanSatuanProduk,
        private readonly TambahBarcodeProduk $tambahBarcode,
        private readonly SimpanHargaProduk $simpanHarga,
        private readonly ArsipkanProduk $arsipkan,
        private readonly PencatatAudit $audit,
    ) {}

    /** True bila tidak ada pekerjaan lagi (Selesai, berhenti, atau bukan Menerapkan); false bila waktu habis. */
    public function Jalankan(ImporProduk $impor, int $batasDetik): bool
    {
        $mulai = hrtime(true);

        while (true) {
            if (! $this->TerapkanPotongan($impor->IdTenant, $impor->Id)) {
                return true;
            }

            if (hrtime(true) - $mulai >= $batasDetik * 1_000_000_000) {
                return false;
            }
        }
    }

    /** True bila masih ada potongan berikutnya. */
    private function TerapkanPotongan(int $idTenant, int $idImporProduk): bool
    {
        return DB::transaction(function () use ($idTenant, $idImporProduk): bool {
            $this->penguncian->Kunci($idTenant);
            $impor = ImporProduk::query()->whereKey($idImporProduk)->lockForUpdate()->first();

            if ($impor === null || $impor->Status !== StatusImporProduk::Menerapkan) {
                return false;
            }

            $potongan = ImporProdukBaris::query()
                ->where('IdImporProduk', $impor->Id)
                ->where('Status', StatusBarisImpor::Valid->value)
                ->whereIn('Aksi', [AksiBarisImpor::Buat->value, AksiBarisImpor::Perbarui->value])
                ->orderBy('NomorBaris')
                ->limit(max(1, (int) config('katalog.Impor.UkuranPotongan', 50)))
                ->lockForUpdate()
                ->get();

            if ($potongan->isEmpty()) {
                $this->Selesaikan($impor);

                return false;
            }

            $opsi = DataOpsiImpor::DariArray($impor->Opsi ?? []);
            $bolehHarga = $this->akses->CekIzin($idTenant, $impor->IdPengguna, IzinTenant::ProdukHargaUbah);
            $idProduk = [];
            $pesanBatas = null;

            foreach ($potongan as $baris) {
                try {
                    $id = DB::transaction(fn (): int => $this->TerapkanBaris($impor, $baris, $opsi, $bolehHarga));
                    $baris->fill(['Status' => StatusBarisImpor::Diterapkan, 'IdProduk' => $id, 'DiterapkanPada' => now()])->save();
                    $idProduk[] = $id;
                } catch (PelanggaranAturanBisnis $galat) {
                    if ($galat->kode === 'BR-02.1') {
                        $pesanBatas = $galat->getMessage();
                        break;
                    }

                    $baris->fill([
                        'Status' => StatusBarisImpor::GagalDiterapkan,
                        'Galat' => [...($baris->Galat ?? []), ['Bidang' => self::LabelBidang($galat->bidang), 'Pesan' => $galat->getMessage()]],
                    ])->save();
                }
            }

            $this->audit->Catat('produk.impor.terapkan', $impor, null, [
                'IdImporProduk' => $impor->Id,
                'NomorBarisAwal' => $potongan->first()->NomorBaris,
                'NomorBarisAkhir' => $potongan->last()->NomorBaris,
                'IdProduk' => $idProduk,
                'HargaDiabaikan' => ! $bolehHarga,
            ]);
            $this->PerbaruiPenghitung($impor);

            if ($pesanBatas !== null) {
                $impor->UbahStatus(StatusImporProduk::Gagal);
                $impor->PesanGalat = mb_substr($pesanBatas.' Setelah paket ditambah, klik Lanjutkan impor.', 0, 500);
                $impor->save();

                return false;
            }

            $impor->save();

            return true;
        });
    }

    private function TerapkanBaris(ImporProduk $impor, ImporProdukBaris $baris, DataOpsiImpor $opsi, bool $bolehHarga): int
    {
        $data = $baris->Data;
        $target = $this->CariTarget($data);

        if ($target !== null) {
            if ($baris->Aksi === AksiBarisImpor::Buat && $opsi->mode === ModeImpor::TambahSaja && ! $this->CekDibuatImporIni($impor, $target)) {
                throw new PelanggaranAturanBisnis('ProdukSudahAda', 'Produk ini sudah ada (dibuat setelah pemeriksaan). Baris dilewati pada mode tambah saja.', 'Nama');
            }

            return $this->Perbarui($target, $data, $opsi, $bolehHarga);
        }

        if ($baris->Aksi === AksiBarisImpor::Perbarui) {
            throw new PelanggaranAturanBisnis('ProdukTidakDitemukan', 'Produk yang akan diperbarui sudah tidak ada.', 'Nama');
        }

        return is_string($data['NamaInduk'] ?? null)
            ? $this->BuatVarian($impor, $data, $opsi, $bolehHarga)
            : $this->Buat($data, $opsi, $bolehHarga);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function CariTarget(array $data): ?Produk
    {
        $sku = is_string($data['Sku'] ?? null) ? $data['Sku'] : null;

        if ($sku !== null) {
            $produk = Produk::query()->where('Sku', $sku)->first();

            if ($produk !== null || ! is_string($data['NamaInduk'] ?? null)) {
                return $produk;
            }
        }

        if (is_string($data['NamaInduk'] ?? null)) {
            $induk = $this->CariInduk($data['NamaInduk']);

            if ($induk === null) {
                return null;
            }

            $kunci = PemvalidasiImpor::KunciAtribut((array) ($data['Varian'] ?? []));

            return Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->get()
                ->first(fn (Produk $anak): bool => PemvalidasiImpor::KunciAtribut((array) ($anak->AtributVarian ?? [])) === $kunci);
        }

        if (($data['JenisAkhir'] ?? null) === JenisProduk::IndukVarian->value) {
            return $this->CariInduk((string) $data['Nama']);
        }

        return Produk::query()
            ->whereNull('IdInduk')
            ->where('Jenis', '!=', JenisProduk::IndukVarian->value)
            ->whereRaw('LOWER(Nama) = ?', [mb_strtolower((string) $data['Nama'])])
            ->orderBy('Id')
            ->first();
    }

    private function CariInduk(string $nama): ?Produk
    {
        return Produk::query()
            ->whereNull('IdInduk')
            ->where('Jenis', JenisProduk::IndukVarian->value)
            ->whereRaw('LOWER(Nama) = ?', [mb_strtolower($nama)])
            ->orderBy('Id')
            ->first();
    }

    private function CekDibuatImporIni(ImporProduk $impor, Produk $produk): bool
    {
        return ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('Status', StatusBarisImpor::Diterapkan->value)
            ->where(fn ($kueri) => $kueri->where('IdProduk', $produk->Id)->orWhere('IdProduk', $produk->IdInduk ?? 0))
            ->exists() || ($produk->Jenis === JenisProduk::IndukVarian && ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)
            ->where('Status', StatusBarisImpor::Diterapkan->value)
            ->whereIn('IdProduk', Produk::query()->where('IdInduk', $produk->Id)->select('Id'))->exists());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function Buat(array $data, DataOpsiImpor $opsi, bool $bolehHarga): int
    {
        $jenis = JenisProduk::tryFrom((string) ($data['JenisAkhir'] ?? '')) ?? $opsi->jenisBawaan;
        $induk = $jenis === JenisProduk::IndukVarian;
        $satuanDasar = $this->AmbilSatuan(is_string($data['Satuan'] ?? null) ? $data['Satuan'] : 'pcs', $opsi, BidangImpor::Satuan);
        $satuan = [new DataSatuanProduk(
            null,
            $satuanDasar->Id,
            Kuantitas::Dari(1),
            true,
            true,
            $induk ? [] : array_values(array_map('strval', (array) ($data['Barcode'] ?? []))),
            [],
        )];
        $hargaAlternatif = [];

        if (! $induk) {
            foreach ((array) ($data['SatuanAlternatif'] ?? []) as $alternatif) {
                $unit = $this->AmbilSatuan((string) $alternatif['Satuan'], $opsi, BidangImpor::SatuanAlternatif((int) $alternatif['Nomor']));

                if ($unit->Id === $satuanDasar->Id) {
                    throw new PelanggaranAturanBisnis('SatuanProdukGanda', "Satuan {$unit->Nama} sama dengan satuan dasar.", BidangImpor::SatuanAlternatif((int) $alternatif['Nomor'])->value);
                }

                $satuan[] = new DataSatuanProduk(
                    null,
                    $unit->Id,
                    Kuantitas::Dari((string) $alternatif['Konversi']),
                    false,
                    false,
                    array_values(array_map('strval', (array) ($alternatif['Barcode'] ?? []))),
                    [],
                );

                if (is_string($alternatif['Harga'] ?? null)) {
                    $hargaAlternatif[$unit->Id] = $alternatif['Harga'];
                }
            }
        }

        $produk = $this->simpanProduk->Jalankan(null, new DataProduk(
            uuid: (string) Str::ulid(),
            nama: (string) $data['Nama'],
            namaStruk: is_string($data['NamaStruk'] ?? null) ? $data['NamaStruk'] : null,
            sku: is_string($data['Sku'] ?? null) ? $data['Sku'] : null,
            jenis: $jenis,
            idKategori: $this->AmbilIdKategori($data, $opsi),
            merek: is_string($data['Merek'] ?? null) ? $data['Merek'] : null,
            idSatuanDasar: $satuanDasar->Id,
            pelacakan: PelacakanProduk::tryFrom((string) ($data['Pelacakan'] ?? '')) ?? PelacakanProduk::Tidak,
            idKelompokPajak: is_int($data['IdKelompokPajak'] ?? null) ? $data['IdKelompokPajak'] : $opsi->idKelompokPajakBawaan,
            hargaTermasukPajak: self::AmbilHargaTermasukPajak($data),
            bolehMinus: is_bool($data['BolehMinus'] ?? null) ? $data['BolehMinus'] : null,
            tampilDiPos: is_bool($data['TampilDiPos'] ?? null) ? $data['TampilDiPos'] : true,
            tampilOnline: is_bool($data['TampilOnline'] ?? null) ? $data['TampilOnline'] : false,
            favorit: is_bool($data['Favorit'] ?? null) ? $data['Favorit'] : null,
            satuan: $satuan,
            atributVarian: [],
            bolehUbahHarga: $bolehHarga,
            sumber: SumberPerubahanKatalog::Impor,
        ));

        if ($bolehHarga && ! $induk) {
            $satuanProduk = ProdukSatuan::query()->where('IdProduk', $produk->Id)->get()->keyBy('IdSatuan');
            $pasangan = [];

            foreach ($hargaAlternatif as $idSatuan => $harga) {
                $alternatif = $satuanProduk->get($idSatuan);

                if ($alternatif !== null) {
                    $pasangan[] = [$alternatif, $harga];
                }
            }

            $dasar = $satuanProduk->get($satuanDasar->Id) ?? throw new PelanggaranAturanBisnis('SatuanDasarWajib', 'Satuan dasar produk tidak ditemukan.', 'Satuan');
            $this->SimpanHarga($produk, $dasar, $data, $pasangan);
        }

        $this->ArsipkanBilaPerlu($produk, $data);

        return $produk->Id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function BuatVarian(ImporProduk $impor, array $data, DataOpsiImpor $opsi, bool $bolehHarga): int
    {
        /** @var list<array{Nama: string, Nilai: string}> $atribut */
        $atribut = array_values(array_map(fn (array $a): array => ['Nama' => (string) $a['Nama'], 'Nilai' => (string) $a['Nilai']], (array) ($data['Varian'] ?? [])));
        $induk = $this->CariInduk((string) $data['NamaInduk']) ?? $this->BuatInduk($impor, $data, $atribut, $opsi, $bolehHarga);
        $jenis = JenisProduk::tryFrom((string) ($data['JenisAkhir'] ?? '')) ?? $opsi->jenisBawaan;
        $anak = $this->tambahVarian->Jalankan($induk, new DataVarianAnak($atribut, is_string($data['Sku'] ?? null) ? $data['Sku'] : null, $jenis, null, $bolehHarga, SumberPerubahanKatalog::Impor));

        return $this->Perbarui($anak, $data, $opsi, $bolehHarga);
    }

    /**
     * Induk varian baru. Datanya (SKU, kategori, merek, satuan dasar, pajak, tampilan) diambil dari baris induk
     * eksplisit di berkas yang sama bila ada (baris itu nanti memperbarui induk ini), selain itu dari baris anak.
     *
     * @param  array<string, mixed>  $dataAnak
     * @param  list<array{Nama: string, Nilai: string}>  $atribut
     */
    private function BuatInduk(ImporProduk $impor, array $dataAnak, array $atribut, DataOpsiImpor $opsi, bool $bolehHarga): Produk
    {
        $barisInduk = ImporProdukBaris::query()
            ->where('IdImporProduk', $impor->Id)
            ->where('KunciProduk', mb_substr('induk:'.mb_strtolower((string) $dataAnak['NamaInduk']), 0, 191))
            ->whereIn('Status', [StatusBarisImpor::Valid->value, StatusBarisImpor::Diterapkan->value])
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(`Data`, '$.NamaInduk')) = 'NULL'")
            ->orderBy('NomorBaris')
            ->first();
        $data = $barisInduk === null ? $dataAnak : $barisInduk->Data;
        $satuanDasar = $this->AmbilSatuan(is_string($data['Satuan'] ?? null) ? $data['Satuan'] : 'pcs', $opsi, BidangImpor::Satuan);

        return $this->simpanProduk->Jalankan(null, new DataProduk(
            uuid: (string) Str::ulid(),
            nama: (string) $dataAnak['NamaInduk'],
            namaStruk: $barisInduk === null || ! is_string($data['NamaStruk'] ?? null) ? null : $data['NamaStruk'],
            sku: $barisInduk === null || ! is_string($data['Sku'] ?? null) ? null : $data['Sku'],
            jenis: JenisProduk::IndukVarian,
            idKategori: $this->AmbilIdKategori($data, $opsi),
            merek: is_string($data['Merek'] ?? null) ? $data['Merek'] : null,
            idSatuanDasar: $satuanDasar->Id,
            pelacakan: PelacakanProduk::Tidak,
            idKelompokPajak: is_int($data['IdKelompokPajak'] ?? null) ? $data['IdKelompokPajak'] : $opsi->idKelompokPajakBawaan,
            hargaTermasukPajak: self::AmbilHargaTermasukPajak($data),
            bolehMinus: is_bool($data['BolehMinus'] ?? null) ? $data['BolehMinus'] : null,
            tampilDiPos: is_bool($data['TampilDiPos'] ?? null) ? $data['TampilDiPos'] : true,
            tampilOnline: is_bool($data['TampilOnline'] ?? null) ? $data['TampilOnline'] : false,
            favorit: is_bool($data['Favorit'] ?? null) ? $data['Favorit'] : null,
            satuan: [new DataSatuanProduk(null, $satuanDasar->Id, Kuantitas::Dari(1), true, true, [], [])],
            atributVarian: array_map(fn (array $a): DataAtributVarian => new DataAtributVarian($a['Nama'], [$a['Nilai']]), $atribut),
            bolehUbahHarga: $bolehHarga,
            sumber: SumberPerubahanKatalog::Impor,
        ));
    }

    /**
     * Perbarui sebagian: hanya sel yang diisi. Anak varian tidak diganti nama/kategori/merek/pajak (ikut induk).
     *
     * @param  array<string, mixed>  $data
     */
    private function Perbarui(Produk $produk, array $data, DataOpsiImpor $opsi, bool $bolehHarga): int
    {
        $anak = $produk->IdInduk !== null;
        $hargaTermasukPajak = $data['HargaTermasukPajak'] ?? null;
        $produk = $this->perbaruiProduk->Jalankan($produk, new DataPerubahanProduk(
            nama: $anak || is_string($data['NamaInduk'] ?? null) ? null : (string) $data['Nama'],
            namaStruk: is_string($data['NamaStruk'] ?? null) ? $data['NamaStruk'] : null,
            jenis: is_string($data['Jenis'] ?? null) ? JenisProduk::tryFrom($data['Jenis']) : null,
            idKategori: $anak ? null : $this->AmbilIdKategori($data, $opsi),
            merek: $anak || ! is_string($data['Merek'] ?? null) ? null : $data['Merek'],
            idKelompokPajak: $anak || ! is_int($data['IdKelompokPajak'] ?? null) ? null : $data['IdKelompokPajak'],
            hargaTermasukPajak: $anak ? null : self::AmbilHargaTermasukPajak($data),
            kosongkanHargaTermasukPajak: ! $anak && $hargaTermasukPajak === 'Ikut',
            tampilDiPos: is_bool($data['TampilDiPos'] ?? null) ? $data['TampilDiPos'] : null,
            tampilOnline: $anak || ! is_bool($data['TampilOnline'] ?? null) ? null : $data['TampilOnline'],
            favorit: is_bool($data['Favorit'] ?? null) ? $data['Favorit'] : null,
            bolehMinus: is_bool($data['BolehMinus'] ?? null) ? $data['BolehMinus'] : null,
            pelacakan: is_string($data['Pelacakan'] ?? null) ? PelacakanProduk::tryFrom($data['Pelacakan']) : null,
        ), SumberPerubahanKatalog::Impor);

        if ($produk->Jenis !== JenisProduk::IndukVarian) {
            $this->PerbaruiSatuanBarcodeHarga($produk, $data, $opsi, $bolehHarga);
        }

        $this->ArsipkanBilaPerlu($produk, $data);

        return $produk->Id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function PerbaruiSatuanBarcodeHarga(Produk $produk, array $data, DataOpsiImpor $opsi, bool $bolehHarga): void
    {
        $satuanDasar = ProdukSatuan::query()->where('IdProduk', $produk->Id)->where('IdSatuan', $produk->IdSatuanDasar)->firstOrFail();

        foreach ((array) ($data['Barcode'] ?? []) as $barcode) {
            $this->tambahBarcode->Jalankan($satuanDasar, (string) $barcode);
        }

        $hargaAlternatif = [];

        foreach ((array) ($data['SatuanAlternatif'] ?? []) as $alternatif) {
            $bidang = BidangImpor::SatuanAlternatif((int) $alternatif['Nomor']);
            $unit = $this->AmbilSatuan((string) $alternatif['Satuan'], $opsi, $bidang);

            if ($unit->Id === $produk->IdSatuanDasar) {
                throw new PelanggaranAturanBisnis('SatuanProdukGanda', "Satuan {$unit->Nama} sama dengan satuan dasar.", $bidang->value);
            }

            $satuanProduk = $this->pastikanSatuanProduk->Jalankan($produk, $unit, Kuantitas::Dari((string) $alternatif['Konversi']));

            foreach ((array) ($alternatif['Barcode'] ?? []) as $barcode) {
                $this->tambahBarcode->Jalankan($satuanProduk, (string) $barcode);
            }

            if (is_string($alternatif['Harga'] ?? null)) {
                $hargaAlternatif[] = [$satuanProduk, $alternatif['Harga']];
            }
        }

        if ($bolehHarga) {
            $this->SimpanHarga($produk, $satuanDasar, $data, $hargaAlternatif);
        }
    }

    /**
     * Harga lewat `SimpanHargaProduk` Tim 2 (sumber Impor → `RiwayatHarga`, BR-03.3): harga dasar satuan dasar diganti
     * bila Harga Jual diisi; set grosir diganti bila kolom grosir dipetakan; satuan alternatif hanya harga dasarnya.
     * Tanpa perubahan = tanpa tulis.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{0: ProdukSatuan, 1: string}>  $hargaAlternatif
     */
    private function SimpanHarga(Produk $produk, ProdukSatuan $satuanDasar, array $data, array $hargaAlternatif): void
    {
        $perSatuan = [];

        if (is_string($data['HargaJual'] ?? null) || (is_array($data['Grosir'] ?? null) && $data['Grosir'] !== [])) {
            $baris = self::AmbilHargaDasar($satuanDasar);

            if (is_string($data['HargaJual'] ?? null)) {
                $baris[Kuantitas::Dari(1)->KeString()] = Uang::Dari($data['HargaJual'])->KeString();
            }

            if (is_array($data['Grosir'] ?? null)) {
                $baris = array_filter($baris, fn (string $jumlah): bool => Kuantitas::Dari($jumlah)->SamaDengan(Kuantitas::Dari(1)), ARRAY_FILTER_USE_KEY);

                foreach ($data['Grosir'] as $grosir) {
                    $baris[Kuantitas::Dari((string) $grosir['JumlahMinimum'])->KeString()] = Uang::Dari((string) $grosir['Harga'])->KeString();
                }
            }

            $perSatuan[$satuanDasar->Id] = self::KeBarisHarga($baris);
        }

        foreach ($hargaAlternatif as [$satuanProduk, $harga]) {
            $baris = self::AmbilHargaDasar($satuanProduk);
            $baris[Kuantitas::Dari(1)->KeString()] = Uang::Dari($harga)->KeString();
            $perSatuan[$satuanProduk->Id] = self::KeBarisHarga($baris);
        }

        if ($perSatuan !== []) {
            $this->simpanHarga->Jalankan($produk, $perSatuan, SumberPerubahanHarga::Impor);
        }
    }

    /**
     * Harga dasar (tanpa daftar harga) satuan produk saat ini: jumlah minimum → harga.
     *
     * @return array<string, string>
     */
    private static function AmbilHargaDasar(ProdukSatuan $satuan): array
    {
        $hasil = [];

        foreach (ProdukHarga::query()->where('IdProdukSatuan', $satuan->Id)->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->get() as $harga) {
            $hasil[Kuantitas::Dari($harga->JumlahMinimum)->KeString()] = Uang::Dari($harga->Harga)->KeString();
        }

        return $hasil;
    }

    /**
     * @param  array<string, string>  $baris
     * @return list<DataBarisHarga>
     */
    private static function KeBarisHarga(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $jumlah => $harga) {
            $hasil[] = new DataBarisHarga(Kuantitas::Dari((string) $jumlah), Uang::Dari($harga));
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function AmbilIdKategori(array $data, DataOpsiImpor $opsi): ?int
    {
        if (! is_array($data['Kategori'] ?? null) || $data['Kategori'] === []) {
            return null;
        }

        $jalur = array_values(array_map('strval', $data['Kategori']));
        $kategori = $this->pastikanKategori->Jalankan($jalur, $opsi->buatKategoriBaru);

        if (! $kategori instanceof Kategori) {
            throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Kategori '.implode(' > ', $jalur).' belum ada.', BidangImpor::Kategori->value);
        }

        return $kategori->Id;
    }

    private function AmbilSatuan(string $teks, DataOpsiImpor $opsi, BidangImpor $bidang): Satuan
    {
        return $this->pastikanSatuan->Jalankan($teks, $opsi->buatSatuanBaru)
            ?? throw new PelanggaranAturanBisnis('SatuanTidakDikenal', "Satuan {$teks} belum ada.", $bidang->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function AmbilHargaTermasukPajak(array $data): ?bool
    {
        return match ($data['HargaTermasukPajak'] ?? null) {
            'Ya' => true,
            'Tidak' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ArsipkanBilaPerlu(Produk $produk, array $data): void
    {
        if (($data['Status'] ?? null) === StatusProduk::Diarsipkan->value && $produk->DiarsipkanPada === null) {
            $this->arsipkan->Jalankan($produk);
        }
    }

    private function PerbaruiPenghitung(ImporProduk $impor): void
    {
        $jumlah = PemvalidasiImpor::HitungStatus($impor->Id);
        $diterapkan = ImporProdukBaris::query()->where('IdImporProduk', $impor->Id)->where('Status', StatusBarisImpor::Diterapkan->value)
            ->selectRaw('Aksi, COUNT(*) AS Jumlah')->groupBy('Aksi')->pluck('Jumlah', 'Aksi')->all();

        $impor->fill([
            'JumlahDiterapkan' => $jumlah[StatusBarisImpor::Diterapkan->value] ?? 0,
            'JumlahGagal' => $jumlah[StatusBarisImpor::GagalDiterapkan->value] ?? 0,
            'JumlahDibuat' => (int) ($diterapkan[AksiBarisImpor::Buat->value] ?? 0),
            'JumlahDiperbarui' => (int) ($diterapkan[AksiBarisImpor::Perbarui->value] ?? 0),
        ]);
    }

    private function Selesaikan(ImporProduk $impor): void
    {
        $this->PerbaruiPenghitung($impor);
        $impor->UbahStatus(StatusImporProduk::Selesai);
        $impor->SelesaiPada = now();
        $impor->PesanGalat = null;
        $impor->save();
        $this->audit->Catat('produk.impor.selesai', $impor, null, [
            'JumlahDibuat' => $impor->JumlahDibuat,
            'JumlahDiperbarui' => $impor->JumlahDiperbarui,
            'JumlahDilewati' => $impor->JumlahDilewati,
            'JumlahGagal' => $impor->JumlahGagal,
        ]);
    }

    /** Nama bidang galat Aksi (misal `Sku`, `Satuan.0.Barcode.1`) → judul kolom impor. */
    private static function LabelBidang(string $bidang): string
    {
        $akar = explode('.', $bidang)[0];

        return BidangImpor::tryFrom($akar)?->AmbilJudul() ?? match ($akar) {
            'Satuan', 'UuidSatuanDasar' => str_contains($bidang, 'Barcode') ? 'Barcode' : 'Satuan',
            'JenisAnak' => 'Jenis',
            'HargaDasar', 'HargaAwal' => 'Harga Jual',
            'AtributVarian' => 'Varian',
            'KonversiKeDasar' => 'Isi Satuan Alternatif',
            'Umum' => 'Baris',
            default => $akar,
        };
    }
}
