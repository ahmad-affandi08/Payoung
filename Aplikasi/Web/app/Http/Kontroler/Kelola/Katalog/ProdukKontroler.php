<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Aksi\ArsipkanProduk;
use App\Domain\Katalog\Aksi\BuatBarcodeInternal;
use App\Domain\Katalog\Aksi\HapusBarcodeProduk;
use App\Domain\Katalog\Aksi\HapusPermanenProduk;
use App\Domain\Katalog\Aksi\HapusProduk;
use App\Domain\Katalog\Aksi\PulihkanProduk;
use App\Domain\Katalog\Aksi\SimpanProduk;
use App\Domain\Katalog\Aksi\SimpanProdukDenganPaketSesi;
use App\Domain\Katalog\Aksi\TambahBarcodeProduk;
use App\Domain\Katalog\Aksi\UbahProdukMassal;
use App\Domain\Katalog\Data\DataProduk;
use App\Domain\Katalog\Data\DataSaringProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\StatusProduk;
use App\Domain\Katalog\Harga\Aksi\UbahHargaProdukMassal;
use App\Domain\Katalog\Kueri\DaftarProduk;
use App\Domain\Katalog\Kueri\DaftarSatuan;
use App\Domain\Katalog\Kueri\DetailProduk;
use App\Domain\Katalog\Kueri\KepalaProduk;
use App\Domain\Katalog\Kueri\KetersediaanProdukPerOutlet;
use App\Domain\Katalog\Kueri\PemakaianSku;
use App\Domain\Katalog\Kueri\PohonKategori;
use App\Domain\Katalog\Layanan\OpsiKelompokPajakKatalog;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\OutletUtama;
use App\Domain\Organisasi\Kueri\ProfilPajakOutlet;
use App\Domain\Pelanggan\Kueri\PengaturanSesiTenant;
use App\Domain\Persediaan\Aksi\CatatStokAwalProdukBaru;
use App\Domain\Persediaan\Kueri\StokTersediaGudang;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Http\Permintaan\Kelola\Katalog\SimpanProdukPermintaan;
use App\Http\Permintaan\Kelola\Katalog\UbahHargaProdukMassalPermintaan;
use App\Http\Permintaan\Kelola\Katalog\UbahProdukMassalPermintaan;
use App\Http\Respons\ResponsTabel;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Produk F-03 (E.2–E.4, BR-03.1, BR-03.2): daftar, form buat/ubah, detail, arsip/pulihkan/hapus, barcode internal.
 * Harga awal satuan baru hanya dengan izin `produk.harga.ubah` (dikirim ke Aksi sebagai `bolehUbahHarga`).
 */
final class ProdukKontroler extends DasarKatalogKontroler
{
    public function Daftar(Request $permintaan, DaftarProduk $daftar, PohonKategori $pohon): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarProduk::KOLOM_URUT, 'Nama', DaftarProduk::KOLOM_SARING);
        $uuidKategori = $tabel->saring['Kategori'] ?? null;
        $statusTeks = $tabel->saring['Status'] ?? 'Aktif';
        $idKategori = $uuidKategori === null ? null : Kategori::query()->where('Uuid', $uuidKategori)->value('Id');
        // `kata` = nama parameter lama (tautan tersimpan), `cari` = kontrak TabelData.
        $kata = $tabel->cari !== '' ? $tabel->cari : trim($permintaan->string('kata')->toString());
        $saring = new DataSaringProduk(
            $kata,
            is_int($idKategori) ? $idKategori : ($uuidKategori === null ? null : 0),
            JenisProduk::tryFrom($tabel->saring['Jenis'] ?? ''),
            $statusTeks === 'Semua' ? null : (StatusProduk::tryFrom($statusTeks) ?? StatusProduk::Aktif),
        );

        return ResponsTabel::Kirim($permintaan, 'Kelola/Produk/Daftar', 'Produk', fn (): array => $daftar->AmbilTabel($saring, $tabel), fn (): array => [
            'Kategori' => $pohon->AmbilOpsi(),
            'Jenis' => JenisProduk::AmbilDaftarAturan(),
            'BatasSku' => $this->AmbilBatasSku(),
            'Izin' => $this->AmbilIzinKatalog(),
        ]);
    }

    public function Buat(DetailProduk $detail): Response
    {
        return $this->RenderForm('Buat', $detail->AmbilFormKosong(), null, false);
    }

    public function Simpan(SimpanProdukPermintaan $permintaan, SimpanProduk $simpan, SimpanProdukDenganPaketSesi $simpanPaket, PengaturanSesiTenant $sesi): RedirectResponse
    {
        $data = $permintaan->AmbilData(null, $this->CekIzin(IzinTenant::ProdukHargaUbah));
        $paket = $permintaan->AmbilPaketSesi();

        $stokAwal = $permintaan->AmbilStokAwal();

        if ($paket === null && $stokAwal !== null) {
            return $this->SimpanDenganStokAwal($simpan, $data, $stokAwal);
        }

        if ($paket === null) {
            $produk = $simpan->Jalankan(null, $data);

            // Produk yang belum lengkap tanpa resep atau isi paket langsung diarahkan ke langkah berikutnya.
            if ($produk->Jenis->CekBolehResep()) {
                return redirect()->route('kelola.produk.resep', ['produk' => $produk->Uuid])->with('Kilat', "Produk {$produk->Nama} disimpan. Lanjutkan dengan mengisi resepnya.");
            }

            if ($produk->Jenis->CekBolehKomponen()) {
                return redirect()->route('kelola.produk.komponen', ['produk' => $produk->Uuid])->with('Kilat', "Produk {$produk->Nama} disimpan. Lanjutkan dengan memilih isi paketnya.");
            }

            return redirect()->route('kelola.produk.detail', ['produk' => $produk->Uuid])->with('Kilat', "Produk {$produk->Nama} disimpan.");
        }

        // D-23 B: produk Jasa + paket sesinya sekaligus (fitur paket sesi harus termasuk paket langganan).
        if (! $sesi->CekBerlaku()) {
            throw new PelanggaranAturanBisnis('FiturTidakAktif', 'Paket usaha ini belum termasuk paket sesi. Tingkatkan paket langganan untuk memakainya.', 'PaketSesi.JumlahSesi');
        }

        if ($data->jenis !== JenisProduk::Jasa) {
            throw new PelanggaranAturanBisnis('ProdukBukanJasa', 'Paket sesi hanya untuk produk berjenis Jasa.', 'PaketSesi.JumlahSesi');
        }

        $produk = $simpanPaket->Jalankan($data, $paket['JumlahSesi'], $paket['MasaBerlakuHari'], $this->Pelaku()->Id);
        $masa = $paket['MasaBerlakuHari'] === null ? 'tanpa batas waktu' : "berlaku {$paket['MasaBerlakuHari']} hari";

        return redirect()->route('kelola.produk.detail', ['produk' => $produk->Uuid])
            ->with('Kilat', "Produk {$produk->Nama} disimpan sebagai paket {$paket['JumlahSesi']} sesi ({$masa}).");
    }

    /**
     * Audit kemudahan pakai #11: produk baru + stok sekarang dalam satu transaksi. Stok awal butuh izin kelola & posting
     * stok awal, produk berstok tanpa batch/seri (bukan konsinyasi), dan lokasi stok di outlet yang boleh diakses.
     * Galat posting (misal pemetaan akun belum siap) ditampilkan di isian stok dan produknya ikut tidak tersimpan.
     *
     * @param  array{Jumlah: Kuantitas, HargaBeli: BigDecimal, UuidGudang: string}  $stokAwal
     */
    private function SimpanDenganStokAwal(SimpanProduk $simpan, DataProduk $data, array $stokAwal): RedirectResponse
    {
        if (! $this->CekIzin(IzinTenant::PersediaanKelola) || ! $this->CekIzin(IzinTenant::PersediaanStokAwalPosting)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Anda belum punya izin mencatat stok awal. Kosongkan stok sekarang atau minta Pemilik.', 'StokAwal.Jumlah');
        }

        if (! $data->jenis->CekPunyaStok() || $data->jenis === JenisProduk::Konsinyasi || $data->pelacakan !== PelacakanProduk::Tidak) {
            throw new PelanggaranAturanBisnis('StokAwalTidakBerlaku', 'Stok sekarang hanya untuk barang stok tanpa batch atau nomor seri. Catat lewat halaman Stok awal.', 'StokAwal.Jumlah');
        }

        $gudang = app(InfoGudang::class)->AmbilDariUuid([$stokAwal['UuidGudang']])[$stokAwal['UuidGudang']] ?? null;
        $boleh = $this->IdOutletBoleh();

        if ($gudang === null || ($boleh !== null && ($gudang->idOutlet === null || ! in_array($gudang->idOutlet, $boleh, true)))) {
            throw new PelanggaranAturanBisnis('LokasiStokTidakDitemukan', 'Pilih lokasi stok.', 'StokAwal.UuidGudang');
        }

        $produk = DB::transaction(function () use ($simpan, $data, $stokAwal, $gudang) {
            $produk = $simpan->Jalankan(null, $data);

            try {
                app(CatatStokAwalProdukBaru::class)->Jalankan($produk->Id, $gudang, $stokAwal['Jumlah'], $stokAwal['HargaBeli'], $this->Pelaku()->Id);
            } catch (PelanggaranAturanBisnis $galat) {
                throw new PelanggaranAturanBisnis($galat->kode, 'Produk belum disimpan. Stok sekarang: '.$galat->getMessage(), 'StokAwal.Jumlah', $galat->statusHttp, $galat->detail);
            }

            return $produk;
        });

        $jumlah = str_replace('.', ',', (string) $stokAwal['Jumlah']->KeDesimal()->strippedOfTrailingZeros());

        return redirect()->route('kelola.produk.detail', ['produk' => $produk->Uuid])
            ->with('Kilat', "Produk {$produk->Nama} disimpan dengan stok {$jumlah} di {$gudang->nama}.");
    }

    public function Detail(string $produk, DetailProduk $detail, KepalaProduk $kepala, KetersediaanProdukPerOutlet $ketersediaan): Response
    {
        $baris = $this->CariProduk($produk);
        $varian = $detail->AmbilVarian($baris);

        return Inertia::render('Kelola/Produk/Detail', [
            'Kepala' => $kepala->Ambil($baris),
            'Produk' => $detail->Ambil($baris),
            'Varian' => $varian,
            'StokVarian' => $this->AmbilStokVarian($varian),
            'StokAwalVarian' => $varian === [] ? null : $this->AmbilOpsiStokAwal(),
            'BatasStok' => $detail->AmbilBatasStok($baris),
            'Ketersediaan' => $ketersediaan->Ambil($baris, $this->IdOutletBoleh()),
            'Riwayat' => $detail->AmbilRiwayat($baris),
            'Jenis' => JenisProduk::AmbilDaftarAturan(),
            'BatasSku' => $this->AmbilBatasSku(),
            'Izin' => $this->AmbilIzinKatalog(),
            // D-218: tombol "Hapus permanen beserta riwayat" hanya untuk Pemilik (`data.kelola`).
            'BolehHapusPermanen' => $this->CekIzin(IzinTenant::DataKelola),
        ]);
    }

    public function Ubah(string $produk, DetailProduk $detail, KepalaProduk $kepala): Response
    {
        $baris = $this->CariProduk($produk);

        return $this->RenderForm('Ubah', $detail->AmbilForm($baris), $kepala->Ambil($baris), $detail->CekJenisTerkunci($baris));
    }

    public function Perbarui(string $produk, SimpanProdukPermintaan $permintaan, SimpanProduk $simpan): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $baris = $simpan->Jalankan($baris, $permintaan->AmbilData($baris, $this->CekIzin(IzinTenant::ProdukHargaUbah)));

        return redirect()->route('kelola.produk.detail', ['produk' => $baris->Uuid])->with('Kilat', "Produk {$baris->Nama} disimpan.");
    }

    public function Arsipkan(string $produk, ArsipkanProduk $arsipkan): RedirectResponse
    {
        $baris = $arsipkan->Jalankan($this->CariProduk($produk));

        return back()->with('Kilat', "Produk {$baris->Nama} diarsipkan. Produk tidak tampil di kasir, riwayatnya tetap tersimpan.");
    }

    /** Audit kemudahan pakai #19: aksi massal produk terpilih di daftar produk. */
    public function Massal(UbahProdukMassalPermintaan $permintaan, UbahProdukMassal $ubah): RedirectResponse
    {
        $uuidKategori = $permintaan->validated('UuidKategori');
        $idKategori = is_string($uuidKategori) ? Kategori::query()->where('Uuid', $uuidKategori)->value('Id') : null;

        if (is_string($uuidKategori) && $idKategori === null) {
            return back()->withErrors(['UuidKategori' => 'Kategori tidak ditemukan.']);
        }

        /** @var list<string> $uuid */
        $uuid = array_values((array) $permintaan->validated('Uuid'));
        $aksi = (string) $permintaan->validated('Aksi');
        $jumlah = $ubah->Jalankan($aksi, $uuid, is_numeric($idKategori) ? (int) $idKategori : null);
        $kata = match ($aksi) {
            'Arsipkan' => 'diarsipkan',
            'Pulihkan' => 'diaktifkan kembali',
            'Kategori' => 'dipindah kategorinya',
            'TampilDiPos' => 'ditampilkan di kasir',
            'Favorit' => 'ditandai favorit di kasir',
            'HapusFavorit' => 'dilepas dari favorit kasir',
            default => 'disembunyikan dari kasir',
        };

        return back()->with('Kilat', "{$jumlah} produk {$kata}.");
    }

    /** Aksi massal harga: naik/turun persen atau nominal pada produk terpilih (izin `produk.harga.ubah`). */
    public function HargaMassal(UbahHargaProdukMassalPermintaan $permintaan, UbahHargaProdukMassal $ubah): RedirectResponse
    {
        /** @var list<string> $uuid */
        $uuid = array_values((array) $permintaan->validated('Uuid'));
        $hasil = $ubah->Jalankan(
            (string) $permintaan->validated('Mode'),
            (string) $permintaan->validated('Nilai'),
            (int) $permintaan->validated('Pembulatan'),
            $uuid,
        );

        if ($hasil['Produk'] === 0) {
            return back()->with('Kilat', 'Tidak ada harga yang berubah.');
        }

        return back()->with('Kilat', "Harga {$hasil['Produk']} produk diubah ({$hasil['Harga']} baris harga).");
    }

    public function Pulihkan(string $produk, PulihkanProduk $pulihkan): RedirectResponse
    {
        $baris = $pulihkan->Jalankan($this->CariProduk($produk));

        return back()->with('Kilat', "Produk {$baris->Nama} aktif kembali.");
    }

    public function Hapus(string $produk, HapusProduk $hapus): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $hapus->Jalankan($baris);

        return redirect()->route('kelola.produk.daftar')->with('Kilat', "Produk {$baris->Nama} dihapus.");
    }

    /** D-218: ringkasan riwayat yang ikut terhapus + penghalang, untuk dialog "Hapus permanen" (izin `data.kelola`). */
    public function PratinjauHapusPermanen(string $produk, HapusPermanenProduk $aksi): JsonResponse
    {
        return response()->json($aksi->Pratinjau($this->CariProduk($produk)));
    }

    /** D-218: hapus produk beserta seluruh riwayatnya; nama produk wajib diketik ulang. */
    public function HapusPermanen(Request $permintaan, string $produk, HapusPermanenProduk $aksi): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $hasil = $aksi->Jalankan($baris, $permintaan->string('KonfirmasiNama')->toString());

        return redirect()->route('kelola.produk.daftar')
            ->with('Kilat', "Produk {$baris->Nama} dihapus permanen beserta {$hasil['Total']} baris riwayatnya.");
    }

    public function BuatBarcodeInternal(string $produk, string $produkSatuan, BuatBarcodeInternal $buat): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $satuan = ProdukSatuan::query()->where('IdProduk', $baris->Id)->where('Uuid', $produkSatuan)->firstOrFail();
        $barcode = $buat->Jalankan($satuan);

        return back()->with('Kilat', "Barcode {$barcode->Barcode} dibuat.");
    }

    /** Barcode yang diketik atau dipindai dari halaman detail; barcode milik produk lain ditolak BR-03.1. */
    public function TambahBarcode(Request $permintaan, string $produk, string $produkSatuan, TambahBarcodeProduk $tambah): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $satuan = ProdukSatuan::query()->where('IdProduk', $baris->Id)->where('Uuid', $produkSatuan)->firstOrFail();
        $barcode = $tambah->Jalankan($satuan, $permintaan->string('Barcode')->toString());

        return back()->with('Kilat', $barcode->wasRecentlyCreated
            ? "Barcode {$barcode->Barcode} ditambahkan."
            : "Barcode {$barcode->Barcode} sudah terdaftar di produk ini.");
    }

    public function HapusBarcode(string $produk, string $produkBarcode, HapusBarcodeProduk $hapus): RedirectResponse
    {
        $baris = $this->CariProduk($produk);
        $barcode = ProdukBarcode::query()->where('IdProduk', $baris->Id)->where('Uuid', $produkBarcode)->firstOrFail();
        $hapus->Jalankan($barcode);

        return back()->with('Kilat', "Barcode {$barcode->Barcode} dihapus.");
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>|null  $kepala
     */
    private function RenderForm(string $mode, array $form, ?array $kepala, bool $jenisTerkunci): Response
    {
        return Inertia::render('Kelola/Produk/Form', [
            'Mode' => $mode,
            'Produk' => $form,
            'Kepala' => $kepala,
            'Kategori' => app(PohonKategori::class)->AmbilOpsi(),
            'Satuan' => app(DaftarSatuan::class)->AmbilOpsi(),
            'KelompokPajak' => app(OpsiKelompokPajakKatalog::class)->AmbilOpsiHalaman(),
            'Jenis' => JenisProduk::AmbilDaftarAturan(),
            'JenisTerkunci' => $jenisTerkunci,
            'BatasSku' => $this->AmbilBatasSku(),
            'Pengaturan' => $this->AmbilPengaturan(),
            'Izin' => $this->AmbilIzinKatalog(),
            'FiturPaketSesi' => $mode === 'Buat' && app(PengaturanSesiTenant::class)->CekBerlaku(),
            'StokAwal' => $mode === 'Buat' ? $this->AmbilOpsiStokAwal() : null,
        ]);
    }

    /**
     * Grid ukuran × warna: stok sekarang tiap varian (jumlah dijumlah di semua lokasi stok), kunci Uuid varian. Kosong
     * bila pelaku tidak boleh melihat persediaan.
     *
     * @param  list<array<string, mixed>>  $varian
     * @return array<string, string>
     */
    private function AmbilStokVarian(array $varian): array
    {
        if ($varian === [] || ! $this->CekIzin(IzinTenant::PersediaanLihat)) {
            return [];
        }

        $id = Produk::query()->whereIn('Uuid', array_column($varian, 'Uuid'))->pluck('Id', 'Uuid')->all();
        $total = app(StokTersediaGudang::class)->AmbilTotalProduk(array_values($id));
        $hasil = [];

        foreach ($id as $uuid => $idProduk) {
            $hasil[(string) $uuid] = $total[$idProduk] ?? '0';
        }

        return $hasil;
    }

    /**
     * @return array{Batas: int|null, Terpakai: int}
     */
    private function AmbilBatasSku(): array
    {
        return app(PastikanBatasPaket::class)->AmbilRingkasan($this->IdTenant(), 'BatasSku', app(PemakaianSku::class)->Hitung());
    }

    /**
     * @return array{HargaTermasukPajakOutlet: string, StokBolehMinus: bool}
     */
    private function AmbilPengaturan(): array
    {
        $profil = app(ProfilPajakOutlet::class)->Ambil((int) app(OutletUtama::class)->AmbilId());
        $pengaturan = app(ProfilTenant::class)->Ambil($this->IdTenant())['Pengaturan'];

        return [
            'HargaTermasukPajakOutlet' => $profil?->hargaTermasukPajak === true ? 'Ikut outlet: harga sudah termasuk pajak' : 'Ikut outlet: harga belum termasuk pajak',
            'StokBolehMinus' => ($pengaturan['StokBolehMinus'] ?? false) === true,
        ];
    }
}
