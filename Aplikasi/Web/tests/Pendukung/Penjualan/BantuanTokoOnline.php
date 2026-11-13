<?php

declare(strict_types=1);

namespace Tests\Pendukung\Penjualan;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\ZonaPengiriman;
use App\Domain\Promo\Kueri\PromoBerlaku;
use App\Domain\Promo\Model\Promo;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Support\Str;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

/**
 * Prasyarat test F-17 toko online: restoran `BantuanPesanSendiri::Siapkan()` + fitur `kanal.toko-online` (override
 * pengelola, karena add-on belum tersedia), sakelar outlet Ambil sendiri & Kirim hidup, dua produk ber-`TampilOnline`,
 * dan satu zona ongkir Bandung Tengah (Rp 12.000, gratis dari Rp 100.000). `AlamatToko` = URL publik toko.
 */
final class BantuanTokoOnline
{
    /**
     * @return array<string, mixed>
     */
    public static function Siapkan(TestCase $tes): array
    {
        $k = BantuanPesanSendiri::Siapkan($tes);
        OverrideTenant::query()->create([
            'IdTenant' => $k['Tenant']->Id,
            'Jenis' => JenisOverride::Fitur,
            'Kunci' => PemeriksaFiturTenant::KUNCI_TOKO_ONLINE,
            'BerakhirPada' => now()->addDays(30),
            'Alasan' => 'Uji toko online',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
        // D-299: akun pembeli (kode masuk WhatsApp) hanya ada bila paket memuat fitur WhatsApp. Layanan platform tetap
        // diatur tes lewat `config('integrasi.Whatsapp')`.
        OverrideTenant::query()->create([
            'IdTenant' => $k['Tenant']->Id,
            'Jenis' => JenisOverride::Fitur,
            'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
            'BerakhirPada' => now()->addDays(30),
            'Alasan' => 'Uji WhatsApp toko online',
            'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
        ]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        /** @var Outlet $outlet */
        $outlet = $k['Outlet'];
        $outlet->forceFill(['TokoOnlineAktif' => true, 'AmbilSendiriAktif' => true, 'KirimAktif' => true])->save();
        $k['Kopi']->forceFill(['TampilOnline' => true])->save();
        $k['Nasi']->forceFill(['TampilOnline' => true])->save();
        PengaturanTokoOnline::query()->create(['Aktif' => true, 'MinimalPesanan' => '10000.00']);
        ZonaPengiriman::query()->create([
            'IdOutlet' => $outlet->Id, 'Nama' => 'Bandung Tengah', 'KodePos' => ['40123', '40124'],
            'Ongkir' => '12000.00', 'GratisMulai' => '100000.00', 'EstimasiHariMin' => 0, 'EstimasiHariMaks' => 1,
        ]);

        return $k + ['AlamatToko' => '/'.$k['Slug']];
    }

    /**
     * Promo **gratis ongkir** aktif (F-16c + F-17 bagian 3). `batas` = `Jumlah` aksi (potongan ongkir maksimal; null =
     * seluruh ongkir), `minimal` = minimal subtotal barang. Fitur `promo.mesin` dinyalakan lewat override bila paket
     * uji belum memuatnya, supaya test ini tidak bergantung pada isi paket bawaan.
     *
     * @param  array<string, mixed>  $k  hasil `Siapkan()`
     */
    public static function BuatPromoGratisOngkir(array $k, ?string $batas = null, string $minimal = '0.00', string $kode = 'ONGKIR-GRATIS'): Promo
    {
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        if (! app(PromoBerlaku::class)->CekFiturAktif()) {
            OverrideTenant::query()->create([
                'IdTenant' => $k['Tenant']->Id,
                'Jenis' => JenisOverride::Fitur,
                'Kunci' => PromoBerlaku::KUNCI_FITUR,
                'BerakhirPada' => now()->addDays(30),
                'Alasan' => 'Uji gratis ongkir',
                'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
            ]);
        }

        return Promo::query()->create([
            'Kode' => $kode,
            'Nama' => "Promo {$kode}",
            'Prioritas' => 1,
            'Definisi' => [
                'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
                'MinimalSubtotal' => $minimal,
                'Kondisi' => ['Jenis' => 'Semua', 'Uuid' => [], 'JumlahMinimal' => '0.0000'],
                'Aksi' => ['Jenis' => 'GratisOngkir', ...($batas === null ? [] : ['Jumlah' => $batas])],
                'BatasPerTransaksi' => null,
            ],
        ]);
    }

    /**
     * Satu kiriman checkout yang sah: 2 kopi (Rp 30.000/porsi setelah pilihan) untuk konteks `Siapkan()`.
     *
     * @param  array<string, mixed>  $k
     * @return array<string, mixed>
     */
    public static function Kiriman(array $k, string $pemenuhan = 'AmbilSendiri', ?string $uuid = null): array
    {
        return [
            'Uuid' => $uuid ?? (string) Str::ulid(), 'JenisPemenuhan' => $pemenuhan,
            'Outlet' => $k['Outlet']->Uuid,
            'MetodePembayaran' => $pemenuhan === 'Kirim' ? 'Cod' : 'BayarSaatAmbil',
            'NamaPelanggan' => 'Bu Ratna', 'NoHp' => '081234567890', 'SetujuDataPribadi' => true,
            'Alamat' => $pemenuhan === 'Kirim' ? 'Jl. Merdeka 10' : null,
            'Kelurahan' => $pemenuhan === 'Kirim' ? 'Braga' : null,
            'Kecamatan' => $pemenuhan === 'Kirim' ? 'Sumur Bandung' : null,
            'Kota' => $pemenuhan === 'Kirim' ? 'Bandung' : null,
            'Provinsi' => $pemenuhan === 'Kirim' ? 'Jawa Barat' : null,
            'KodePos' => $pemenuhan === 'Kirim' ? '40123' : null,
            'Baris' => [[
                'Uuid' => (string) Str::ulid(), 'UuidProduk' => $k['Kopi']->Uuid, 'Jumlah' => 2,
                'Pilihan' => [$k['GulaNormal']->Uuid, $k['Boba']->Uuid], 'HargaSatuan' => '1.00',
            ]],
        ];
    }
}
