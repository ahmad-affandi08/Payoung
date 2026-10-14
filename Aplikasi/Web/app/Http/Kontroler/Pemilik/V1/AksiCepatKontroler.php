<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Aksi\CatatPengeluaranPemilik;
use App\Domain\Kasir\Kueri\OpsiPengeluaranPemilik;
use App\Domain\Katalog\Aksi\UbahKetersediaanProduk;
use App\Domain\Katalog\Harga\Aksi\TetapkanHargaDasarProduk;
use App\Domain\Katalog\Kueri\CariProdukAksiCepat;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Promo\Aksi\UbahStatusPromo;
use App\Domain\Promo\Enum\StatusPromo;
use App\Domain\Promo\Kueri\DaftarPromoAksiCepat;
use App\Domain\Promo\Model\Promo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Aksi cepat di Aplikasi Owner (OWN-07): ubah harga, tandai produk habis ("86") per outlet, nyalakan atau matikan promo,
 * dan catat pengeluaran dengan foto nota. Semuanya memakai Aksi back-office yang sama (validasi, riwayat harga, audit,
 * jurnal), jadi hasilnya identik dengan mengerjakannya di dashboard. Izin per rute: harga `produk.harga.ubah`, tandai
 * habis `produk.kelola`, promo `pelanggan.kelola`, pengeluaran `akuntansi.kelola`; dibatasi outlet akses pengguna.
 */
final class AksiCepatKontroler extends DasarPemilikKontroler
{
    private const MENIT_KUNCI_IDEMPOTEN = 10;

    public function Produk(Request $permintaan, CariProdukAksiCepat $cari): JsonResponse
    {
        $data = $permintaan->validate(['cari' => ['nullable', 'string', 'max:80'], 'outlet' => ['nullable', 'string', 'size:26']]);
        $idOutlet = $this->SatuOutlet($data['outlet'] ?? null, $permintaan, wajib: false);

        return response()->json(['Produk' => $cari->Cari((string) ($data['cari'] ?? ''), $idOutlet)]);
    }

    public function UbahHarga(Request $permintaan, string $produk, TetapkanHargaDasarProduk $tetapkan): JsonResponse
    {
        $data = $permintaan->validate(['Harga' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/']], ['Harga.regex' => 'Harga harus angka Rupiah, maksimal 2 angka di belakang koma.']);
        $baris = $this->CariProduk($produk);

        return response()->json(['Produk' => ['Uuid' => $baris->Uuid, 'Nama' => $baris->Nama], ...$tetapkan->Jalankan($baris, Uang::Dari($data['Harga']))]);
    }

    public function TandaiHabis(Request $permintaan, string $produk, UbahKetersediaanProduk $ubah): JsonResponse
    {
        $data = $permintaan->validate(['Outlet' => ['required', 'string', 'size:26'], 'Habis' => ['required', 'boolean']]);
        $idOutlet = $this->SatuOutlet($data['Outlet'], $permintaan, wajib: true) ?? 0;
        $baris = $this->CariProduk($produk);
        $habis = $ubah->Jalankan($idOutlet, $baris->Uuid, (bool) $data['Habis'], null, $this->Pengguna($permintaan)->Id);

        return response()->json(['Produk' => ['Uuid' => $baris->Uuid, 'Nama' => $baris->Nama], 'Habis' => $habis]);
    }

    public function Promo(Request $permintaan, DaftarPromoAksiCepat $daftar): JsonResponse
    {
        $data = $permintaan->validate(['cari' => ['nullable', 'string', 'max:80']]);

        return response()->json(['Promo' => $daftar->Ambil($data['cari'] ?? null)]);
    }

    public function UbahPromo(Request $permintaan, string $promo, UbahStatusPromo $ubah): JsonResponse
    {
        $data = $permintaan->validate(['Aktif' => ['required', 'boolean']]);
        $baris = Promo::query()->where('Uuid', strtoupper($promo))->first();
        abort_if($baris === null, 404);
        $hasil = $ubah->Jalankan($baris, (bool) $data['Aktif'] ? StatusPromo::Aktif : StatusPromo::Diarsipkan, $this->Pengguna($permintaan)->Id);

        return response()->json(['Promo' => ['Uuid' => $hasil->Uuid, 'Nama' => $hasil->Nama, 'Aktif' => $hasil->Status === StatusPromo::Aktif]]);
    }

    public function OpsiPengeluaran(OpsiPengeluaranPemilik $opsi): JsonResponse
    {
        return response()->json($opsi->Ambil());
    }

    public function CatatPengeluaran(Request $permintaan, CatatPengeluaranPemilik $catat): JsonResponse
    {
        $data = $permintaan->validate([
            'UuidKategori' => ['required', 'string', 'size:26'],
            'UuidAkunSumber' => ['required', 'string', 'size:26'],
            'Jumlah' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'Keterangan' => ['nullable', 'string', 'max:200'],
            'Outlet' => ['nullable', 'string', 'size:26'],
            'Foto' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('akuntansi.EkstensiLampiran')), 'max:'.(int) config('akuntansi.UkuranMaksimalLampiranKb')],
        ], ['Jumlah.regex' => 'Jumlah harus angka Rupiah, maksimal 2 angka di belakang koma.']);
        $idOutlet = $this->SatuOutlet($data['Outlet'] ?? null, $permintaan, wajib: false);

        // Ketuk ganda atau kirim ulang karena sinyal putus: kunci yang sama menghasilkan satu pengeluaran saja.
        $kunci = $permintaan->header('Idempotency-Key');
        $kunciCache = is_string($kunci) && $kunci !== '' ? 'pemilik.pengeluaran:'.$this->IdTenant().':'.$this->Pengguna($permintaan)->Id.':'.hash('sha256', $kunci) : null;

        if ($kunciCache !== null) {
            $tersimpan = Cache::get($kunciCache);

            if (is_array($tersimpan)) {
                return response()->json($tersimpan);
            }

            if (! Cache::add($kunciCache.':proses', true, 60)) {
                throw new PelanggaranAturanBisnis('SedangDiproses', 'Pengeluaran ini sedang diproses. Tunggu sebentar lalu cek riwayat.', 'Umum', 409);
            }
        }

        $transaksi = $catat->Jalankan(
            $data['UuidKategori'],
            $data['UuidAkunSumber'],
            Uang::Dari($data['Jumlah']),
            (string) ($data['Keterangan'] ?? ''),
            $this->TanggalHariIni($idOutlet === null ? null : [$idOutlet]),
            $idOutlet,
            $permintaan->file('Foto'),
            $this->Pengguna($permintaan)->Id,
        );
        $hasil = ['Pengeluaran' => ['Uuid' => $transaksi->Uuid, 'Nomor' => $transaksi->Nomor, 'Jumlah' => Uang::Dari($data['Jumlah'])->KeString(), 'BerLampiran' => $transaksi->PathLampiran !== null]];

        if ($kunciCache !== null) {
            Cache::put($kunciCache, $hasil, now()->addMinutes(self::MENIT_KUNCI_IDEMPOTEN));
        }

        return response()->json($hasil, 201);
    }

    private function CariProduk(string $uuid): Produk
    {
        $produk = Produk::query()->where('Uuid', strtoupper($uuid))->first();
        abort_if($produk === null, 404);

        return $produk;
    }

    /**
     * Satu outlet dari Uuid (harus dalam akses pengguna). Tanpa Uuid: outlet satu-satunya yang boleh diakses bila hanya
     * satu; selain itu null (atau galat bila `$wajib`).
     */
    private function SatuOutlet(?string $uuid, Request $permintaan, bool $wajib): ?int
    {
        $boleh = $this->IdOutletBoleh($permintaan);
        $dipilih = $this->SaringOutlet($uuid, $boleh);

        if ($dipilih !== null && count($dipilih) === 1) {
            return $dipilih[0];
        }

        if ($wajib) {
            throw new PelanggaranAturanBisnis('OutletWajib', 'Pilih outlet terlebih dulu.', 'Outlet');
        }

        return null;
    }
}
