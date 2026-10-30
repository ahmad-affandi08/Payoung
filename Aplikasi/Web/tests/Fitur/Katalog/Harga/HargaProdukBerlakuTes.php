<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Harga\Kueri\HargaProdukBerlaku;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-03 C.3: HargaProdukBerlaku (server, dari DB) memberi hasil yang sama dengan test vector harga bersama. Setiap
 * vektor dimuat ke database (produk, satuan, outlet, daftar harga, baris harga) lalu setiap kasus ditentukan lewat
 * kueri server dan dibandingkan dengan `Harapan` (Uuid vektor dipetakan ke Uuid database).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

final class MuatanVektorHarga
{
    /** @var array<string, Produk> */
    public array $produk = [];

    /** @var array<string, ProdukSatuan> */
    public array $satuan = [];

    /** @var array<string, Outlet> */
    public array $outlet = [];

    /** @var array<string, DaftarHarga> */
    public array $daftar = [];

    public function Satuan(string $uuidProduk, string $uuidSatuan): ProdukSatuan
    {
        if (isset($this->satuan[$uuidSatuan])) {
            return $this->satuan[$uuidSatuan];
        }

        $unit = BantuanKatalog::BuatSatuan("Satuan {$uuidSatuan}", strtolower(substr($uuidSatuan, -3)), true);

        if (! isset($this->produk[$uuidProduk])) {
            $this->produk[$uuidProduk] = BantuanKatalog::BuatProduk(['Nama' => "Produk {$uuidProduk}", 'IdSatuanDasar' => $unit->Id], null, $unit);

            return $this->satuan[$uuidSatuan] = BantuanHarga::SatuanDasar($this->produk[$uuidProduk]);
        }

        return $this->satuan[$uuidSatuan] = BantuanHarga::TambahSatuan($this->produk[$uuidProduk], $unit, '10');
    }

    public function Outlet(string $uuid): Outlet
    {
        return $this->outlet[$uuid] ??= BantuanHarga::BuatOutlet(substr(str_replace('-', '', $uuid), 0, 10), "Outlet {$uuid}");
    }
}

it('HargaProdukBerlaku dari database sama dengan test vector', function (string $berkas): void {
    BantuanKatalog::SiapkanTenantProduk();
    /** @var array{Katalog: array{DaftarHarga: list<array<string, mixed>>, ProdukHarga: list<array<string, mixed>>}, Kasus: list<array<string, mixed>>} $vektor */
    $vektor = json_decode((string) file_get_contents($berkas), true, flags: JSON_THROW_ON_ERROR);
    $m = new MuatanVektorHarga;

    // Uuid database diberikan menurut urutan Uuid vektor agar pemecah seri "Uuid terkecil" tetap sama.
    $daftarVektor = $vektor['Katalog']['DaftarHarga'];
    usort($daftarVektor, fn (array $a, array $b): int => strcmp((string) $a['Uuid'], (string) $b['Uuid']));

    foreach ($daftarVektor as $urutan => $d) {
        $m->daftar[(string) $d['Uuid']] = BantuanHarga::BuatDaftarHarga((string) $d['Nama'], [
            'Uuid' => '01JCDAFTAR'.str_pad((string) $urutan, 16, '0', STR_PAD_LEFT),
            'Aktif' => $d['Aktif'],
            'IdOutlet' => $d['UuidOutlet'] === null ? null : array_map(fn (string $u): int => $m->Outlet($u)->Id, (array) $d['UuidOutlet']),
            'Kanal' => $d['Kanal'] === null ? null : KanalPenjualan::from((string) $d['Kanal']),
            'TierPelanggan' => $d['TierPelanggan'],
            'MulaiPada' => $d['MulaiPada'] === null ? null : CarbonImmutable::parse((string) $d['MulaiPada']),
            'SelesaiPada' => $d['SelesaiPada'] === null ? null : CarbonImmutable::parse((string) $d['SelesaiPada']),
            'Prioritas' => $d['Prioritas'],
        ]);
    }

    foreach ($vektor['Katalog']['ProdukHarga'] as $b) {
        $satuan = $m->Satuan((string) $b['UuidProduk'], (string) $b['UuidProdukSatuan']);
        ProdukHarga::query()->create([
            'IdProduk' => $satuan->IdProduk,
            'IdProdukSatuan' => $satuan->Id,
            'IdDaftarHarga' => $b['UuidDaftarHarga'] === null ? null : $m->daftar[(string) $b['UuidDaftarHarga']]->Id,
            'JumlahMinimum' => $b['JumlahMinimum'],
            'Harga' => $b['Harga'],
        ]);
    }

    $uuidVektorDaftar = [];

    foreach ($m->daftar as $uuidVektor => $daftar) {
        $uuidVektorDaftar[$daftar->Uuid] = $uuidVektor;
    }

    foreach ($vektor['Kasus'] as $kasus) {
        /** @var array{UuidProduk: string, UuidProdukSatuan: string, Jumlah: string, UuidOutlet: string|null, Kanal: string|null, TierPelanggan: string|null, Waktu: string} $masukan */
        $masukan = $kasus['Masukan'];
        $satuan = $m->Satuan($masukan['UuidProduk'], $masukan['UuidProdukSatuan']);
        $produk = $m->produk[$masukan['UuidProduk']] ?? Produk::query()->findOrFail($satuan->IdProduk);
        $hasil = app(HargaProdukBerlaku::class)->Tentukan(
            $produk,
            $satuan,
            Kuantitas::Dari($masukan['Jumlah']),
            $masukan['UuidOutlet'] === null ? null : $m->Outlet($masukan['UuidOutlet'])->Id,
            $masukan['Kanal'] === null ? null : KanalPenjualan::from($masukan['Kanal']),
            $masukan['TierPelanggan'],
            CarbonImmutable::parse($masukan['Waktu']),
        );
        // TentukanBanyak (dipakai menu publik) harus memberi hasil yang persis sama dengan Tentukan per satuan.
        $banyak = app(HargaProdukBerlaku::class)->TentukanBanyak(
            [[$produk, $satuan]],
            Kuantitas::Dari($masukan['Jumlah']),
            $masukan['UuidOutlet'] === null ? null : $m->Outlet($masukan['UuidOutlet'])->Id,
            $masukan['Kanal'] === null ? null : KanalPenjualan::from($masukan['Kanal']),
            $masukan['TierPelanggan'],
            CarbonImmutable::parse($masukan['Waktu']),
        );
        expect($banyak[$satuan->Id] ?? null)->toEqual($hasil, "TentukanBanyak: {$kasus['Nama']}");

        /** @var array<string, string|null> $harapan */
        $harapan = $kasus['Harapan'];
        $aktual = $hasil === null ? ['Galat' => 'HargaTidakDitemukan'] : [
            'Harga' => $hasil->harga->KeString(),
            'Sumber' => $hasil->sumber->value,
            'UuidDaftarHarga' => $hasil->uuidDaftarHarga === null ? null : ($uuidVektorDaftar[$hasil->uuidDaftarHarga] ?? $hasil->uuidDaftarHarga),
            'JumlahMinimum' => $hasil->jumlahMinimum->KeString(),
        ];

        expect($aktual)->toBe($harapan, "{$vektor['Katalog']['ProdukHarga'][0]['UuidProduk']}: {$kasus['Nama']}");
    }
})->with(fn (): array => array_combine(
    array_map('basename', glob(dirname(__DIR__, 6).'/Spesifikasi/VektorUjiKalkulasi/Harga/*.json') ?: []),
    array_map(fn (string $berkas): array => [$berkas], glob(dirname(__DIR__, 6).'/Spesifikasi/VektorUjiKalkulasi/Harga/*.json') ?: []),
));
