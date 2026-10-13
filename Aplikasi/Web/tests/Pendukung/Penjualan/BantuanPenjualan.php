<?php

declare(strict_types=1);

namespace Tests\Pendukung\Penjualan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pajak\Enum\DasarPengenaanPajak;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Pajak\Model\KelompokPajak;
use App\Domain\Pajak\Model\KelompokPajakDetail;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Penjualan\Enum\ArahPembulatan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Kalkulasi\DataBarisKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPajakKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPembayaranKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPembulatanTunai;
use App\Domain\Penjualan\Kalkulasi\DataPotongan;
use App\Domain\Penjualan\Kalkulasi\MesinKalkulasi;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturPenjualanDetail;
use App\Domain\Promo\Model\Promo;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\TestCase;

/**
 * Prasyarat test F-07b penjualan dari POS: tenant ber-COA template retail (lewat `BantuanKasir`), perangkat kasir
 * aktif dengan shift terbuka, metode pembayaran tiap jenis, dan pembuat item outbox `Penjualan.Buat` yang menghitung
 * `Ringkasan` seperti aplikasi kasir (mesin kalkulasi F-07a yang sama). Panggil
 * `BantuanPendaftaran::SiapkanPrasyarat()` dulu.
 *
 * Baris masukan `Item()`: `['Produk' => Produk, 'Jumlah' => '2', 'Harga' => '38500.00', 'Satuan' => ProdukSatuan|null,
 * 'Pilihan' => list<Pilihan>, 'DiskonManual' => ['Persen' => '5']|['Jumlah' => '1000.00']|null, 'KodePajak' => list|null,
 * 'HargaTermasukPajak' => bool|null]`. Pembayaran: `[['Metode' => MetodePembayaran, 'Jumlah' => '...'|null]]`
 * (tunai tanpa jumlah = uang pas; bawaan satu pembayaran tunai uang pas).
 *
 * F-09: `Jual()` (kirim `Penjualan.Buat` lalu kembalikan `Penjualan`), `ItemVoid()` (`Penjualan.Void`), dan
 * `ItemRetur()` (`ReturPenjualan.Buat`, `Ringkasan.TotalRefund` dihitung seperti aplikasi kasir: bagian proporsional
 * `TotalBaris` dibulatkan ke sen, retur yang menghabiskan sisa baris mengambil sisa nilai).
 */
final class BantuanPenjualan
{
    private static int $urutanNomor = 0;

    /**
     * @return array{Tenant: Tenant, Pemilik: Pengguna, Outlet: Outlet, Gudang: Gudang, Perangkat: Perangkat, Token: string, Kasir: Pengguna, Supervisor: Pengguna, UuidShift: string, Tunai: MetodePembayaran, Qris: MetodePembayaran, Edc: MetodePembayaran, Transfer: MetodePembayaran, Tempo: MetodePembayaran}
     */
    public static function Siapkan(TestCase $tes, string $namaUsaha = 'Toko Kelontong Berkah Solo'): array
    {
        $k = BantuanKasir::Siapkan($tes, $namaUsaha);
        $shift = BantuanKasir::ItemBukaShift($k['Kasir'], '500000.00');
        $hasil = BantuanKasir::KirimRingkas($tes, $k['Token'], [$shift]);

        if ($hasil !== [['Diterima', null]]) {
            throw new RuntimeException('Shift uji gagal dibuka: '.json_encode($hasil));
        }

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        return $k + [
            'Gudang' => Gudang::query()->where('IdOutlet', $k['Outlet']->Id)->orderBy('Id')->firstOrFail(),
            'UuidShift' => $shift['Uuid'],
            'Tunai' => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tunai->value)->firstOrFail(),
            'Qris' => self::BuatMetode(JenisMetodePembayaran::QrisStatis, 'QRIS Toko Berkah'),
            'Edc' => self::BuatMetode(JenisMetodePembayaran::Edc, 'EDC BCA'),
            'Transfer' => self::BuatMetode(JenisMetodePembayaran::Transfer, 'Transfer BRI'),
            'Tempo' => self::BuatMetode(JenisMetodePembayaran::Tempo, 'Tempo 30 hari'),
        ];
    }

    public static function BuatMetode(JenisMetodePembayaran $jenis, string $nama, bool $aktif = true): MetodePembayaran
    {
        return MetodePembayaran::query()->create(['Jenis' => $jenis, 'Nama' => $nama, 'Aktif' => $aktif, 'Urutan' => 10]);
    }

    /**
     * Profil pajak outlet uji (`Outlet.ProfilPajak`, PRD v1.46): `Pkp`, `PungutPbjt`, `HargaTermasukPajak`,
     * `BiayaLayanan` (persen string atau null = tidak aktif).
     *
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     */
    public static function AturProfilPajak(array $k, bool $pkp = false, bool $pungutPbjt = false, bool $hargaTermasukPajak = false, ?string $persenBiayaLayanan = null): void
    {
        /** @var Outlet $outlet */
        $outlet = $k['Outlet'];
        $outlet->refresh()->forceFill(['ProfilPajak' => [
            ...($outlet->ProfilPajak ?? []),
            'Pkp' => $pkp,
            'PungutPbjt' => $pungutPbjt,
            'HargaTermasukPajak' => $hargaTermasukPajak,
            'BiayaLayanan' => ['Aktif' => $persenBiayaLayanan !== null, 'Persen' => $persenBiayaLayanan ?? '0.00'],
        ]])->save();
    }

    /**
     * Pembulatan tunai pengaturan kasir tenant (null = tanpa pembulatan).
     *
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     * @param  array{0: int, 1: string}|null  $pembulatan
     */
    public static function AturPembulatanTunai(array $k, ?array $pembulatan): void
    {
        /** @var Tenant $tenant */
        $tenant = $k['Tenant'];
        $tenant->refresh();
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'PembulatanTunai' => $pembulatan === null ? null : ['Kelipatan' => $pembulatan[0], 'Arah' => $pembulatan[1]]];
        $tenant->save();
    }

    /**
     * Kelompok pajak tenant konteks aktif berisi jenis pajak (kode → dasar pengenaan), lalu dipasang ke produk.
     * Nilai boleh `[dasar, kenaBiayaKirim]` untuk menandai ongkir ikut DPP pajak itu (F-17 bagian 3).
     *
     * @param  array<string, string|array{0: string, 1: bool}>  $pajak  kode jenis pajak → `Subtotal`|`SubtotalPlusLayanan`
     */
    public static function PasangKelompokPajak(string $nama, array $pajak, Produk ...$produk): KelompokPajak
    {
        app(SiapkanPajakBawaan::class)->Jalankan();
        $kelompok = KelompokPajak::query()->create(['Nama' => $nama]);
        $urutan = 0;

        foreach ($pajak as $kode => $nilai) {
            [$dasar, $kenaBiayaKirim] = is_array($nilai) ? $nilai : [$nilai, false];

            KelompokPajakDetail::query()->create([
                'IdKelompokPajak' => $kelompok->Id,
                'IdJenisPajak' => JenisPajak::query()->where('Kode', $kode)->value('Id'),
                'DasarPengenaan' => DasarPengenaanPajak::from($dasar),
                'KenaBiayaKirim' => $kenaBiayaKirim,
                'Urutan' => ++$urutan,
            ]);
        }

        foreach ($produk as $p) {
            $p->forceFill(['IdKelompokPajak' => $kelompok->Id])->save();
        }

        return $kelompok;
    }

    /** Produk barang stok (satuan pcs) dengan stok awal terposting (jurnal persediaan ikut tercatat). */
    public static function BuatProdukBerstok(Gudang $gudang, int $idPengguna, string $nama = 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter', string $jumlah = '10', string $hpp = '30000', string $harga = '38500.00'): Produk
    {
        $produk = BantuanKatalog::BuatProduk(['Nama' => $nama], $harga);

        if (BigDecimal::of($jumlah)->isPositive()) {
            BantuanStokAwal::BuatDanPosting($gudang, [BantuanStokAwal::Baris($produk, $jumlah, $hpp)], $idPengguna);
        }

        return $produk;
    }

    /** Stok awal terposting untuk produk yang sudah ada (bahan baku, komponen). */
    public static function IsiStok(Gudang $gudang, Produk $produk, string $jumlah, string $hpp, int $idPengguna): void
    {
        BantuanStokAwal::BuatDanPosting($gudang, [BantuanStokAwal::Baris($produk, $jumlah, $hpp)], $idPengguna);
    }

    /**
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     */
    public static function Nomor(array $k, ?int $urutan = null, ?CarbonImmutable $waktu = null): string
    {
        $urutan ??= ++self::$urutanNomor;
        /** @var Outlet $outlet */
        $outlet = $k['Outlet'];
        /** @var Perangkat $perangkat */
        $perangkat = $k['Perangkat'];
        $tanggal = ($waktu ?? CarbonImmutable::now()->subMinutes(5))->setTimezone($outlet->ZonaWaktu)->format('ymd');

        return "INV/{$outlet->Kode}/{$tanggal}/{$perangkat->Kode}-".str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Item outbox `Penjualan.Buat` lengkap. `opsi`: `Baris`, `Pembayaran`, `Pajak` (list `[Kode, Tarif, Pembilang,
     * Penyebut, DasarPengenaan?, KenaBiayaKirim?]`), `BiayaKirim`, `DiskonKirim` (F-17 bagian 3),
     * `HargaTermasukPajak`, `PersenBiayaLayanan`, `PembulatanTunai`
     * (`[Kelipatan, Arah]`), `DiskonManualPesanan`, `TukarPoin` (`['Poin' => 50, 'Nilai' => '5000']`), `Promo`
     * (`[['Promo' => Promo, 'Baris' => [indeks => '7700.00'], 'Pesanan' => '0.00']]`, potongan promo perangkat), `Kasir` (Pengguna), `Penyetuju` (Pengguna), `PenyetujuHarga` (Pengguna), `DibuatPada`; `timpa` =
     * kunci `Data` yang ditimpa setelah dihitung (misal `Ringkasan` palsu).
     *
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     * @param  array<string, mixed>  $opsi
     * @param  array<string, mixed>  $timpa
     * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
     */
    public static function Item(array $k, array $opsi, array $timpa = [], ?string $uuid = null): array
    {
        $dibuatPada = $opsi['DibuatPada'] ?? CarbonImmutable::now()->subMinutes(5);
        $hargaTermasukPajak = (bool) ($opsi['HargaTermasukPajak'] ?? false);
        $persenLayanan = (string) ($opsi['PersenBiayaLayanan'] ?? '0');
        $pembulatan = $opsi['PembulatanTunai'] ?? null;
        $pajak = array_values(array_map(fn (array $p): array => [
            'Kode' => $p[0],
            'Tarif' => $p[1],
            'PengaliDppPembilang' => $p[2] ?? 1,
            'PengaliDppPenyebut' => $p[3] ?? 1,
            'DasarPengenaan' => $p[4] ?? DasarPengenaanPajak::Subtotal->value,
            'KenaBiayaKirim' => (bool) ($p[5] ?? false),
        ], $opsi['Pajak'] ?? []));
        $biayaKirim = isset($opsi['BiayaKirim']) ? (string) $opsi['BiayaKirim'] : null;
        $diskonKirim = isset($opsi['DiskonKirim']) ? (string) $opsi['DiskonKirim'] : null;

        $baris = [];

        foreach ($opsi['Baris'] as $b) {
            /** @var Produk $produk */
            $produk = $b['Produk'];
            /** @var list<Pilihan> $pilihan */
            $pilihan = $b['Pilihan'] ?? [];
            $hargaPilihan = array_reduce($pilihan, fn (Uang $t, Pilihan $p): Uang => $t->Tambah(Uang::Dari($p->Harga)), Uang::Nol());
            /** @var ProdukSatuan|null $satuan */
            $satuan = $b['Satuan'] ?? null;
            $baris[] = [
                'Uuid' => BantuanKasir::Uuid(),
                'UuidProduk' => $produk->Uuid,
                'UuidProdukSatuan' => $satuan?->Uuid,
                'Jumlah' => (string) ($b['Jumlah'] ?? '1'),
                'HargaSatuan' => (string) ($b['Harga'] ?? '10000.00'),
                'HargaPilihan' => $hargaPilihan->KeString(),
                'Pilihan' => array_map(fn (Pilihan $p): array => ['UuidPilihan' => $p->Uuid, 'Nama' => $p->Nama, 'Harga' => Uang::Dari($p->Harga)->KeString()], $pilihan),
                'HargaTermasukPajak' => $b['HargaTermasukPajak'] ?? null,
                'KodePajak' => $b['KodePajak'] ?? null,
                'DiskonManual' => $b['DiskonManual'] ?? null,
                'Catatan' => $b['Catatan'] ?? null,
                // Harga manual kasir (`pos.harga.timpa`): `['HargaAsli' => '40000.00', 'Alasan' => '...']`.
                ...(isset($b['HargaManual']) ? ['HargaManual' => $b['HargaManual']] : []),
                // F-18: staf yang melayani baris (Uuid karyawan).
                ...(isset($b['Staf']) ? ['Staf' => $b['Staf']] : []),
                // F-05h: nomor seri/IMEI unit yang dijual.
                ...(isset($b['NomorSeri']) ? ['NomorSeri' => $b['NomorSeri']] : []),
                // Apotek (§9.5): baris yang ditutup resep.
                ...(isset($b['DenganResep']) ? ['DenganResep' => $b['DenganResep']] : []),
                // Apotek bagian 3: racikan pada baris jasa racik.
                ...(isset($b['Racikan']) ? ['Racikan' => $b['Racikan']] : []),
            ];
        }

        $pembayaranMasukan = $opsi['Pembayaran'] ?? [['Metode' => $k['Tunai'], 'Jumlah' => null]];
        /** @var array{Poin: int, Nilai: string}|null $tukarPoin */
        $tukarPoin = $opsi['TukarPoin'] ?? null;
        /** @var list<array{Promo: Promo, Baris?: array<int, string>, Pesanan?: string}> $promo */
        $promo = $opsi['Promo'] ?? [];
        $hasil = self::Hitung($hargaTermasukPajak, $persenLayanan, $pembulatan, $pajak, $baris, $opsi['DiskonManualPesanan'] ?? null, $pembayaranMasukan, $tukarPoin['Nilai'] ?? null, $promo, $biayaKirim, $diskonKirim);
        $pembayaran = [];

        foreach ($pembayaranMasukan as $p) {
            /** @var MetodePembayaran $metode */
            $metode = $p['Metode'];
            $jumlah = $p['Jumlah'] ?? null;

            if ($jumlah === null) {
                // Uang pas: sisa tagihan setelah non-tunai lain.
                $nonTunai = array_reduce($pembayaranMasukan, fn (Uang $t, array $x): Uang => $x['Metode']->Jenis === JenisMetodePembayaran::Tunai || ($x['Jumlah'] ?? null) === null ? $t : $t->Tambah(Uang::Dari($x['Jumlah'])), Uang::Nol());
                $jumlah = $hasil['TotalAkhir']->Kurangi($nonTunai)->KeString();
            }

            $pembayaran[] = ['Uuid' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => $metode->Uuid, 'Jumlah' => $jumlah, 'Referensi' => $p['Referensi'] ?? null];
        }

        $hasilAkhir = self::Hitung($hargaTermasukPajak, $persenLayanan, $pembulatan, $pajak, $baris, $opsi['DiskonManualPesanan'] ?? null, array_map(
            fn (array $p, array $b): array => ['Metode' => $p['Metode'], 'Jumlah' => $b['Jumlah']],
            $pembayaranMasukan,
            $pembayaran,
        ), $tukarPoin['Nilai'] ?? null, $promo, $biayaKirim, $diskonKirim);

        /** @var Pengguna $kasir */
        $kasir = $opsi['Kasir'] ?? $k['Kasir'];
        /** @var Pengguna|null $penyetuju */
        $penyetuju = $opsi['Penyetuju'] ?? null;

        return [
            'Jenis' => 'Penjualan.Buat',
            'Uuid' => $uuid ?? BantuanKasir::Uuid(),
            'Data' => array_replace([
                'UuidShift' => $k['UuidShift'],
                'UuidPengguna' => $kasir->Uuid,
                'Nomor' => self::Nomor($k, waktu: $dibuatPada),
                'Kanal' => 'BawaPulang',
                'DibuatPada' => $dibuatPada->utc()->toIso8601ZuluString(),
                'HargaTermasukPajak' => $hargaTermasukPajak,
                'PersenBiayaLayanan' => $persenLayanan,
                'PembulatanTunai' => $pembulatan === null ? null : ['Kelipatan' => $pembulatan[0], 'Arah' => $pembulatan[1]],
                'Pajak' => $pajak,
                ...($biayaKirim === null ? [] : ['BiayaKirim' => $biayaKirim]),
                ...($diskonKirim === null ? [] : ['DiskonKirim' => $diskonKirim]),
                'Baris' => $baris,
                'DiskonManualPesanan' => $opsi['DiskonManualPesanan'] ?? null,
                'UuidPenyetujuDiskon' => $penyetuju?->Uuid,
                ...(isset($opsi['PenyetujuHarga']) ? ['UuidPenyetujuHarga' => $opsi['PenyetujuHarga']->Uuid] : []),
                'Pembayaran' => $pembayaran,
                'Ringkasan' => [
                    'Subtotal' => $hasilAkhir['Subtotal']->KeString(),
                    'TotalPajak' => $hasilAkhir['TotalPajak']->KeString(),
                    'Pembulatan' => $hasilAkhir['Pembulatan']->KeString(),
                    'TotalAkhir' => $hasilAkhir['TotalAkhir']->KeString(),
                    'Kembalian' => $hasilAkhir['Kembalian']->KeString(),
                ],
                'Catatan' => 'Pelanggan minta struk digital',
                ...($tukarPoin === null ? [] : ['TukarPoin' => $tukarPoin]),
                ...($promo === [] ? [] : ['Promo' => array_map(fn (array $p): array => [
                    'UuidPromo' => $p['Promo']->Uuid,
                    'Kode' => $p['Promo']->Kode,
                    'DiskonBaris' => array_values(array_map(fn (int $i, string $j): array => ['UuidBaris' => $baris[$i]['Uuid'], 'Jumlah' => $j], array_keys($p['Baris'] ?? []), array_values($p['Baris'] ?? []))),
                    'DiskonPesanan' => $p['Pesanan'] ?? '0.00',
                ], $promo)]),
            ], $timpa),
        ];
    }

    /**
     * Kirim satu penjualan lunas lewat sinkron lalu kembalikan dokumennya (konteks tenant diatur ulang).
     *
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     * @param  array<string, mixed>  $opsi  lihat `Item()`
     */
    public static function Jual(TestCase $tes, array $k, array $opsi, ?string $token = null): Penjualan
    {
        $item = self::Item($k, $opsi);
        $hasil = BantuanKasir::KirimRingkas($tes, $token ?? $k['Token'], [$item]);

        if ($hasil !== [['Diterima', null]]) {
            throw new RuntimeException('Penjualan uji gagal: '.json_encode($hasil));
        }

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        return Penjualan::query()->where('Uuid', $item['Uuid'])->firstOrFail();
    }

    /**
     * Item outbox `Penjualan.Void`. `opsi`: `Kasir`, `Penyetuju` (Pengguna; bawaan Supervisor), `Alasan`, `DivoidPada`.
     *
     * @param  array<string, mixed>  $k
     * @param  array<string, mixed>  $opsi
     * @param  array<string, mixed>  $timpa
     * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
     */
    public static function ItemVoid(array $k, Penjualan $penjualan, array $opsi = [], array $timpa = [], ?string $uuid = null): array
    {
        /** @var Pengguna $kasir */
        $kasir = $opsi['Kasir'] ?? $k['Kasir'];
        /** @var Pengguna $penyetuju */
        $penyetuju = $opsi['Penyetuju'] ?? $k['Supervisor'];
        /** @var CarbonImmutable $waktu */
        $waktu = $opsi['DivoidPada'] ?? CarbonImmutable::now()->subMinute();

        return [
            'Jenis' => 'Penjualan.Void',
            'Uuid' => $uuid ?? BantuanKasir::Uuid(),
            'Data' => array_replace([
                'UuidPenjualan' => $penjualan->Uuid,
                'UuidPengguna' => $kasir->Uuid,
                'UuidPenyetuju' => $penyetuju->Uuid,
                'Alasan' => $opsi['Alasan'] ?? 'Pelanggan batal membeli, salah input barang',
                'DivoidPada' => $waktu->utc()->toIso8601ZuluString(),
            ], $timpa),
        ];
    }

    /**
     * @param  array<string, mixed>  $k
     */
    public static function NomorRetur(array $k, ?int $urutan = null, ?CarbonImmutable $waktu = null): string
    {
        return str_replace('INV/', 'RJ/', self::Nomor($k, $urutan, $waktu ?? CarbonImmutable::now()->subMinute()));
    }

    /**
     * Item outbox `ReturPenjualan.Buat`. `baris`: `[['Detail' => PenjualanDetail, 'Jumlah' => '1', 'Kondisi' =>
     * 'LayakJual'|'Rusak']]`. `opsi`: `Refund` (`[['Metode' => MetodePembayaran, 'Jumlah' => '...'|null]]`, bawaan satu
     * refund tunai sebesar total), `Kasir`, `Penyetuju` (bawaan Supervisor), `Alasan`, `DibuatPada`, `UuidShift`.
     *
     * @param  array<string, mixed>  $k
     * @param  list<array<string, mixed>>  $baris
     * @param  array<string, mixed>  $opsi
     * @param  array<string, mixed>  $timpa
     * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
     */
    public static function ItemRetur(array $k, Penjualan $penjualan, array $baris, array $opsi = [], array $timpa = [], ?string $uuid = null): array
    {
        /** @var CarbonImmutable $waktu */
        $waktu = $opsi['DibuatPada'] ?? CarbonImmutable::now()->subMinute();
        $total = Uang::Nol();
        $dataBaris = [];

        foreach ($baris as $b) {
            /** @var PenjualanDetail $d */
            $d = $b['Detail'];
            $jumlah = BigDecimal::of((string) ($b['Jumlah'] ?? $d->Jumlah));
            $total = $total->Tambah(self::NilaiRetur($d, $jumlah));
            $dataBaris[] = [
                'Uuid' => BantuanKasir::Uuid(),
                'UuidPenjualanDetail' => $d->Uuid,
                'Jumlah' => (string) $jumlah,
                'Kondisi' => $b['Kondisi'] ?? 'LayakJual',
                ...(isset($b['NomorSeri']) ? ['NomorSeri' => $b['NomorSeri']] : []),
            ];
        }

        $refund = [];

        foreach ($opsi['Refund'] ?? [['Metode' => $k['Tunai'], 'Jumlah' => null]] as $r) {
            /** @var MetodePembayaran $metode */
            $metode = $r['Metode'];
            $refund[] = ['Uuid' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => $metode->Uuid, 'Jumlah' => $r['Jumlah'] ?? $total->KeString()];
        }

        /** @var Pengguna $kasir */
        $kasir = $opsi['Kasir'] ?? $k['Kasir'];
        /** @var Pengguna $penyetuju */
        $penyetuju = $opsi['Penyetuju'] ?? $k['Supervisor'];

        return [
            'Jenis' => 'ReturPenjualan.Buat',
            'Uuid' => $uuid ?? BantuanKasir::Uuid(),
            'Data' => array_replace([
                'UuidPenjualanAsal' => $penjualan->Uuid,
                'UuidShift' => $opsi['UuidShift'] ?? $k['UuidShift'],
                'UuidPengguna' => $kasir->Uuid,
                'UuidPenyetuju' => $penyetuju->Uuid,
                'Nomor' => self::NomorRetur($k, waktu: $waktu),
                'Alasan' => $opsi['Alasan'] ?? 'Kemasan bocor saat dibuka pelanggan di rumah',
                'DibuatPada' => $waktu->utc()->toIso8601ZuluString(),
                'Baris' => $dataBaris,
                'Refund' => $refund,
                'Ringkasan' => ['TotalRefund' => $total->KeString()],
            ], $timpa),
        ];
    }

    /** Nilai retur seperti aplikasi kasir: proporsional ke sen HalfUp; menghabiskan sisa = sisa nilai baris. */
    private static function NilaiRetur(PenjualanDetail $d, BigDecimal $jumlah): Uang
    {
        $sudahJumlah = BigDecimal::of((string) (ReturPenjualanDetail::query()->where('IdPenjualanDetail', $d->Id)->sum('Jumlah') ?: '0'));
        $sudahNilai = Uang::Dari((string) (ReturPenjualanDetail::query()->where('IdPenjualanDetail', $d->Id)->sum('NilaiBaris') ?: '0'));

        if ($sudahJumlah->plus($jumlah)->isEqualTo(BigDecimal::of($d->Jumlah))) {
            return Uang::Dari($d->TotalBaris)->Kurangi($sudahNilai);
        }

        return Uang::Dari(BigDecimal::of($d->TotalBaris)->multipliedBy($jumlah)->dividedBy($d->Jumlah, 2, RoundingMode::HalfUp));
    }

    /**
     * @param  array{0: int, 1: string}|null  $pembulatan
     * @param  list<array<string, mixed>>  $pajak
     * @param  list<array<string, mixed>>  $baris
     * @param  array<string, string>|null  $diskonPesanan
     * @param  list<array<string, mixed>>  $pembayaran
     * @param  list<array{Promo: Promo, Baris?: array<int, string>, Pesanan?: string}>  $promo
     * @return array{Subtotal: Uang, TotalPajak: Uang, Pembulatan: Uang, TotalAkhir: Uang, Kembalian: Uang}
     */
    private static function Hitung(bool $termasukPajak, string $persenLayanan, ?array $pembulatan, array $pajak, array $baris, ?array $diskonPesanan, array $pembayaran, ?string $tukarPoin = null, array $promo = [], ?string $biayaKirim = null, ?string $diskonKirim = null): array
    {
        foreach ($promo as $p) {
            foreach ($p['Baris'] ?? [] as $i => $jumlah) {
                $baris[$i]['PromoTambahan'][] = $jumlah;
            }
        }

        $hasil = (new MesinKalkulasi)->Hitung(new DataKalkulasi(
            hargaTermasukPajak: $termasukPajak,
            baris: array_map(fn (array $b): DataBarisKalkulasi => new DataBarisKalkulasi(
                Kuantitas::Dari((string) $b['Jumlah']),
                Uang::Dari((string) $b['HargaSatuan']),
                Uang::Dari((string) $b['HargaPilihan']),
                is_bool($b['HargaTermasukPajak']) ? $b['HargaTermasukPajak'] : null,
                is_array($b['KodePajak']) ? array_values(array_map('strval', $b['KodePajak'])) : null,
                [
                    ...(is_array($b['DiskonManual']) ? [self::Potongan($b['DiskonManual'])] : []),
                    ...array_values(array_map(fn (string $j): DataPotongan => DataPotongan::BuatNominal(Uang::Dari($j)), $b['PromoTambahan'] ?? [])),
                ],
            ), $baris),
            pajak: array_map(fn (array $p): DataPajakKalkulasi => new DataPajakKalkulasi(
                (string) $p['Kode'],
                (string) $p['Tarif'],
                DasarPengenaanPajak::from((string) $p['DasarPengenaan']),
                (int) $p['PengaliDppPembilang'],
                (int) $p['PengaliDppPenyebut'],
                (bool) ($p['KenaBiayaKirim'] ?? false),
            ), $pajak),
            persenBiayaLayanan: $persenLayanan,
            pembulatanTunai: $pembulatan === null ? null : new DataPembulatanTunai($pembulatan[0], ArahPembulatan::from($pembulatan[1])),
            potonganPesanan: [
                ...($diskonPesanan === null ? [] : [self::Potongan($diskonPesanan)]),
                ...array_values(array_filter(array_map(fn (array $p): ?DataPotongan => ($p['Pesanan'] ?? '0.00') === '0.00' ? null : DataPotongan::BuatNominal(Uang::Dari($p['Pesanan'])), $promo))),
            ],
            pembayaran: array_map(fn (array $p): DataPembayaranKalkulasi => new DataPembayaranKalkulasi(
                $p['Metode']->Jenis === JenisMetodePembayaran::Tunai,
                ($p['Jumlah'] ?? null) === null ? null : Uang::Dari((string) $p['Jumlah']),
            ), $pembayaran),
            tukarPoin: $tukarPoin === null ? null : Uang::Dari($tukarPoin),
            biayaKirim: $biayaKirim === null ? null : Uang::Dari($biayaKirim),
            diskonKirim: $diskonKirim === null ? null : Uang::Dari($diskonKirim),
        ));

        return [
            'Subtotal' => $hasil->subtotal,
            'TotalPajak' => $hasil->totalPajak,
            'Pembulatan' => $hasil->pembulatan,
            'TotalAkhir' => $hasil->totalAkhir,
            'Kembalian' => $hasil->kembalian ?? Uang::Nol(),
        ];
    }

    /**
     * @param  array<string, string>  $diskon
     */
    private static function Potongan(array $diskon): DataPotongan
    {
        return isset($diskon['Persen']) ? DataPotongan::BuatPersen($diskon['Persen']) : DataPotongan::BuatNominal(Uang::Dari($diskon['Jumlah']));
    }
}
