<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\KanalKetersediaan;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHabis;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F-17 BR-17.2 + PRD-11: tandai produk habis ("86") atau tersedia lagi di satu outlet, untuk semua kanal atau satu kanal
 * (`Kasir`, `TokoOnline`, `SelfOrder`). Produk habis hilang dari menu kanal itu di outlet itu (self-order QR meja, kios,
 * toko online) atau tidak bisa ditambahkan di layar Jual (kasir), dan keranjang yang masih memuatnya ditolak saat dihitung
 * ulang/checkout (`ProdukTidakTersedia`). Pesanan yang sudah masuk tidak disentuh. Tidak ada efek stok maupun jurnal.
 *
 * Penyimpanan: baris `Semua` = habis di semua kanal, baris kanal tunggal = habis hanya di kanal itu. Menandai `Semua`
 * menggantikan baris kanal tunggal; menandai satu kanal saat sudah `Semua` tidak mengubah apa pun; menandai ketiga kanal
 * tunggal digabung menjadi `Semua`; menandai satu kanal tersedia saat `Semua` memecah `Semua` menjadi dua kanal lain yang
 * tetap habis.
 *
 * Idempoten: menandai habis yang sudah habis (atau tersedia yang sudah tersedia) di kanal itu tidak menulis apa pun dan
 * tidak mencatat audit. Pelaku dari POS wajib anggota outlet dengan izin `produk.kelola`, `penjualan.buat`, atau
 * `pesanan.meja.catat`; dari back-office, kontrolernya sudah menegakkan izin dan melewatkan `$uuidPengguna` null.
 * Hanya produk yang bisa dijual langsung (bukan induk varian) yang boleh ditandai; anak varian ditandai satu per satu.
 */
final class UbahKetersediaanProduk
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return bool keadaan akhir: true = habis
     */
    public function Jalankan(int $idOutlet, string $uuidProduk, bool $habis, ?string $uuidPengguna = null, ?int $idPenggunaBackOffice = null, KanalKetersediaan $kanal = KanalKetersediaan::Semua): bool
    {
        $idTenant = $this->konteks->Wajib();
        $idPengguna = $idPenggunaBackOffice;

        if ($uuidPengguna !== null) {
            $pelaku = $this->anggota->Cari($idTenant, $uuidPengguna, $idOutlet)
                ?? throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet ini.', 'UuidPengguna');

            if (! $pelaku->CekIzin(IzinTenant::ProdukKelola->value) && ! $pelaku->CekIzin(IzinTenant::PenjualanBuat->value) && ! $pelaku->CekIzin(IzinTenant::PesananMejaCatat->value)) {
                throw new PelanggaranAturanBisnis('TanpaIzin', "{$pelaku->nama} tidak punya izin menandai produk habis.", 'UuidPengguna', 403);
            }

            $idPengguna = $pelaku->id;
        }

        return DB::transaction(function () use ($idOutlet, $uuidProduk, $habis, $idPengguna, $kanal): bool {
            $produk = Produk::query()->where('Uuid', $uuidProduk)->first()
                ?? throw new PelanggaranAturanBisnis('ProdukTidakDitemukan', 'Produk tidak ditemukan.', 'UuidProduk', 404);

            if ($produk->Jenis === JenisProduk::IndukVarian) {
                throw new PelanggaranAturanBisnis('ProdukIndukVarian', 'Tandai habis per varian, bukan produk induknya.', 'UuidProduk');
            }

            $baris = ProdukHabis::query()->where('IdProduk', $produk->Id)->where('IdOutlet', $idOutlet)->lockForUpdate()->get()->keyBy(fn (ProdukHabis $b): string => $b->Kanal->value)->toBase();
            $sebelum = self::AmbilEfektif($baris->keys()->all());

            $this->Terapkan($baris, $produk->Id, $idOutlet, $idPengguna, $habis, $kanal);

            $sesudah = self::AmbilEfektif(ProdukHabis::query()->where('IdProduk', $produk->Id)->where('IdOutlet', $idOutlet)->pluck('Kanal')->map(fn ($k): string => $k instanceof KanalKetersediaan ? $k->value : (string) $k)->all());

            if ($sebelum !== $sesudah) {
                $this->audit->Catat($habis ? 'produk.habis' : 'produk.tersedia', $produk, nilaiBaru: [
                    'IdOutlet' => $idOutlet,
                    'Kanal' => $kanal->value,
                    'Habis' => $habis,
                    'KanalHabis' => array_keys(array_filter($sesudah)),
                ], idPengguna: $idPengguna);
            }

            return $habis;
        });
    }

    /**
     * @param  Collection<string, ProdukHabis>  $baris  baris yang ada, kunci = nilai kanal
     */
    private function Terapkan(Collection $baris, int $idProduk, int $idOutlet, ?int $idPengguna, bool $habis, KanalKetersediaan $kanal): void
    {
        $buat = fn (KanalKetersediaan $k) => ProdukHabis::query()->create(['IdProduk' => $idProduk, 'IdOutlet' => $idOutlet, 'Kanal' => $k->value, 'IdPengguna' => $idPengguna]);
        $semua = $baris->get(KanalKetersediaan::Semua->value);

        if ($kanal === KanalKetersediaan::Semua) {
            if (! $habis) {
                $baris->each(fn (ProdukHabis $b) => $b->delete());

                return;
            }

            $baris->except([KanalKetersediaan::Semua->value])->each(fn (ProdukHabis $b) => $b->delete());

            if ($semua === null) {
                $buat(KanalKetersediaan::Semua);
            }

            return;
        }

        if ($habis) {
            if ($semua !== null || $baris->has($kanal->value)) {
                return;
            }

            $buat($kanal);

            if ($baris->count() + 1 === count(KanalKetersediaan::Tunggal())) {
                ProdukHabis::query()->where('IdProduk', $idProduk)->where('IdOutlet', $idOutlet)->get()->each(fn (ProdukHabis $b) => $b->delete());
                $buat(KanalKetersediaan::Semua);
            }

            return;
        }

        if ($semua !== null) {
            $semua->delete();

            foreach (KanalKetersediaan::Tunggal() as $lain) {
                if ($lain !== $kanal) {
                    $buat($lain);
                }
            }

            return;
        }

        $baris->get($kanal->value)?->delete();
    }

    /**
     * Habis efektif per kanal tunggal: ada baris `Semua` atau baris kanal itu.
     *
     * @param  array<array-key, string>  $kanalAda  nilai kanal yang punya baris
     * @return array<string, bool>
     */
    public static function AmbilEfektif(array $kanalAda): array
    {
        $semua = in_array(KanalKetersediaan::Semua->value, $kanalAda, true);
        $hasil = [];

        foreach (KanalKetersediaan::Tunggal() as $k) {
            $hasil[$k->value] = $semua || in_array($k->value, $kanalAda, true);
        }

        return $hasil;
    }
}
