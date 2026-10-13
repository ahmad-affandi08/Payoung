<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\PohonKategori;
use App\Domain\Katalog\Kueri\ProdukKategoriStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Aksi\LepasProdukRak;
use App\Domain\Persediaan\Aksi\SimpanRakGudang;
use App\Domain\Persediaan\Aksi\TetapkanProdukRak;
use App\Domain\Persediaan\Aksi\UbahStatusRakGudang;
use App\Domain\Persediaan\Kueri\DaftarRakGudang;
use App\Domain\Persediaan\Kueri\DetailRakGudang;
use App\Domain\Persediaan\Model\RakGudang;
use App\Http\Permintaan\Kelola\Persediaan\ProdukRakPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanRakGudangPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rak gudang (F-05c INV-07, `/kelola/persediaan/rak`): daftar + tambah/ubah/nonaktifkan rak per lokasi stok, dan isi rak
 * (menempatkan produk satu per satu atau satu kategori sekaligus, melepas produk). Rak di lokasi di luar outlet pelaku =
 * 404. Izin: lihat `persediaan.lihat`, ubah `persediaan.kelola`. Halaman ini hidup di Pengaturan (sekali atur).
 */
final class RakGudangKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT_DETAIL = 'kelola.persediaan.rak.detail';

    public function Daftar(Request $permintaan, DaftarRakGudang $daftar): Response
    {
        $gudang = $permintaan->query('gudang');

        return Inertia::render('Kelola/Persediaan/Rak/Daftar', [
            'Rak' => $daftar->AmbilDaftar($this->IdOutletBoleh(), is_string($gudang) && $gudang !== '' ? $gudang : null),
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(),
            'Saring' => ['Gudang' => is_string($gudang) ? $gudang : ''],
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Simpan(SimpanRakGudangPermintaan $permintaan, SimpanRakGudang $simpan): RedirectResponse
    {
        $gudang = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudang'));
        $rak = $simpan->Jalankan($gudang->id, null, (string) $permintaan->validated('Kode'), (string) $permintaan->validated('Nama'), (int) $permintaan->validated('Urutan', 0), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT_DETAIL, ['rak' => $rak->Uuid])->with('Kilat', "Rak {$rak->Nama} ditambahkan. Tempatkan produknya di sini.");
    }

    public function Ubah(SimpanRakGudangPermintaan $permintaan, string $rak, SimpanRakGudang $simpan): RedirectResponse
    {
        $baris = $simpan->Jalankan(0, $this->CariRak($rak), (string) $permintaan->validated('Kode'), (string) $permintaan->validated('Nama'), (int) $permintaan->validated('Urutan', 0), $this->Pelaku()->Id);

        return back()->with('Kilat', "Rak {$baris->Nama} disimpan.");
    }

    public function Nonaktifkan(string $rak, UbahStatusRakGudang $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->CariRak($rak), false, $this->Pelaku()->Id);

        return back()->with('Kilat', "Rak {$baris->Nama} dinonaktifkan.");
    }

    public function Aktifkan(string $rak, UbahStatusRakGudang $ubah): RedirectResponse
    {
        $baris = $ubah->Jalankan($this->CariRak($rak), true, $this->Pelaku()->Id);

        return back()->with('Kilat', "Rak {$baris->Nama} aktif kembali.");
    }

    public function Detail(Request $permintaan, string $rak, DetailRakGudang $detail, PohonKategori $kategori, DaftarRakGudang $daftar): Response|JsonResponse
    {
        $baris = $this->CariRak($rak);
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DetailRakGudang::KOLOM_URUT, 'Nama', []);
        $info = collect($daftar->AmbilDaftar($this->IdOutletBoleh()))->firstWhere('Uuid', $baris->Uuid);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Rak/Detail', 'Produk', fn (): array => $detail->AmbilTabel($baris, $tabel), fn (): array => [
            'Rak' => $info,
            'OpsiKategori' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama'], 'Jalur' => $k['Jalur']], $kategori->AmbilOpsi()),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function TetapkanProduk(ProdukRakPermintaan $permintaan, string $rak, TetapkanProdukRak $tetapkan, InfoProdukStok $infoProduk, ProdukKategoriStok $kategori): RedirectResponse
    {
        $baris = $this->CariRak($rak);
        $idProduk = array_values(array_map(fn ($p): int => $p->id, $infoProduk->AmbilDariUuid($permintaan->AmbilUuidProduk())));
        $uuidKategori = $permintaan->AmbilUuidKategori();

        if ($uuidKategori !== null) {
            $dataKategori = $kategori->AmbilKategori($uuidKategori) ?? throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Kategori tidak ditemukan.', 'UuidKategori');
            $dalamKategori = $infoProduk->AmbilBanyak($kategori->AmbilIdProduk($dataKategori['Id']));
            $idProduk = [...$idProduk, ...array_keys(array_filter($dalamKategori, fn ($p): bool => ! $p->dihapus && $p->jenis->CekPunyaStok() && $p->jenis !== JenisProduk::Konsinyasi))];
        }

        $hasil = $tetapkan->Jalankan($baris, $idProduk, $this->Pelaku()->Id);
        $pesan = [];

        foreach (['Ditambah' => 'ditambahkan', 'Dipindah' => 'dipindahkan dari rak lain', 'Dilewati' => 'sudah ada di rak ini'] as $kunci => $teks) {
            if ($hasil[$kunci] > 0) {
                $pesan[] = "{$hasil[$kunci]} produk {$teks}";
            }
        }

        return back()->with('Kilat', 'Isi rak diperbarui: '.implode(', ', $pesan).'.');
    }

    public function LepasProduk(ProdukRakPermintaan $permintaan, string $rak, LepasProdukRak $lepas, InfoProdukStok $infoProduk): RedirectResponse
    {
        $baris = $this->CariRak($rak);
        $idProduk = array_values(array_map(fn ($p): int => $p->id, $infoProduk->AmbilDariUuid($permintaan->AmbilUuidProduk())));
        $jumlah = $lepas->Jalankan($baris, $idProduk, $this->Pelaku()->Id);

        return back()->with('Kilat', "{$jumlah} produk dilepas dari rak {$baris->Nama}.");
    }

    private function CariRak(string $uuid): RakGudang
    {
        $rak = RakGudang::query()->where('Uuid', $uuid)->firstOrFail();
        $gudang = $this->CariGudangTenant($this->UuidGudangRak($rak));
        abort_unless($this->CekBolehOutlet($gudang->idOutlet), 404);

        return $rak;
    }

    private function UuidGudangRak(RakGudang $rak): string
    {
        $gudang = app(InfoGudang::class)->AmbilBanyak([$rak->IdGudang])[$rak->IdGudang] ?? null;
        abort_if($gudang === null, 404);

        return $gudang->uuid;
    }
}
