<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Penjualan\Enum\JenisPemenuhanOnline;
use App\Domain\Penjualan\Enum\MetodePembayaranOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Layanan\PenghitungPesanSendiri;
use App\Domain\Penjualan\Layanan\PenghitungTokoOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\PesananOnlineDetail;
use App\Domain\Persediaan\Layanan\PencadangStok;
use App\Domain\Promo\Aksi\PesanVoucherPos;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-17 toko online: checkout tamu atau pembeli yang sudah masuk. `$idPelanggan` (F-17 bagian 3) = pembeli yang masuk
 * lewat kode WhatsApp: pesanan tertaut ke `Pelanggan`-nya, nomor HP diambil dari pelanggan (nomor ketikan diabaikan,
 * karena nomor itulah yang sudah diverifikasi), dan harga tier/promo pelanggan ikut dihitung. Tanpa masuk, pesanan
 * tidak pernah ditautkan ke pelanggan walau nomornya cocok: nomor ketikan tamu belum terbukti miliknya.
 * `KodeVoucher` (v3.46) opsional: diperiksa saat menghitung lalu dipesan untuk pesanan ini di transaksi yang sama.
 * Stok (v3.48) dicadangkan untuk pesanan ini di transaksi yang sama; stok yang direbut checkout lain = checkout gagal utuh.
 */
final class BuatPesananOnline
{
    public const BATAS_AKTIF_PER_IP = 10;

    public function __construct(
        private readonly PenghitungTokoOnline $penghitung,
        private readonly PenomorDokumen $penomor,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PesanVoucherPos $pesanVoucher,
        private readonly PencadangStok $pencadang,
        private readonly KesiapanQrisOnline $qris,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: PesananOnline, 1: bool}
     */
    public function Jalankan(DataKonteksPesanSendiri $konteks, array $data, ?string $hashIp, ?int $idPelanggan = null): array
    {
        $uuid = strtoupper((string) $data['Uuid']);
        $lama = PesananOnline::query()->where('Uuid', $uuid)->first();

        if ($lama instanceof PesananOnline) {
            return [$lama, false];
        }

        $noHp = $idPelanggan === null
            ? NomorHp::Normalisasi((string) $data['NoHp'])
            : ($this->pelanggan->AmbilKontak($idPelanggan)['NoHp'] ?? null);

        if ($noHp === null) {
            throw new PelanggaranAturanBisnis('NomorHpTidakValid', 'Nomor HP tidak valid.', 'NoHp');
        }

        $pemenuhan = JenisPemenuhanOnline::from((string) $data['JenisPemenuhan']);
        $pembayaran = MetodePembayaranOnline::from((string) $data['MetodePembayaran']);
        $pengaturan = PengaturanTokoOnline::query()->firstOrFail();

        $sesuaiPemenuhan = match ($pembayaran) {
            MetodePembayaranOnline::BayarSaatAmbil => $pemenuhan === JenisPemenuhanOnline::AmbilSendiri,
            MetodePembayaranOnline::Cod => $pemenuhan === JenisPemenuhanOnline::Kirim,
            // Bayar di muka tidak peduli barangnya diambil atau dikirim: uangnya masuk sebelum barang bergerak.
            MetodePembayaranOnline::QrisOnline => true,
        };

        if (! $sesuaiPemenuhan) {
            throw new PelanggaranAturanBisnis('MetodePembayaranTidakValid', 'Metode pembayaran tidak sesuai jenis pemenuhan.', 'MetodePembayaran');
        }

        $metodeAktif = match ($pembayaran) {
            MetodePembayaranOnline::BayarSaatAmbil => $pengaturan->BayarSaatAmbilAktif,
            MetodePembayaranOnline::Cod => $pengaturan->CodAktif,
            // Gerbang bisa dimatikan setelah sakelar QRIS dinyalakan: tolak di sini, jangan biarkan pesanan menggantung tanpa tagihan.
            MetodePembayaranOnline::QrisOnline => $pengaturan->QrisAktif && $this->qris->CekSiap(),
        };

        if (! $metodeAktif) {
            throw new PelanggaranAturanBisnis('MetodePembayaranTidakAktif', 'Metode pembayaran sedang tidak tersedia.', 'MetodePembayaran');
        }

        if ($pemenuhan === JenisPemenuhanOnline::Kirim && trim((string) ($data['Alamat'] ?? '')) === '') {
            throw new PelanggaranAturanBisnis('AlamatWajib', 'Alamat wajib diisi untuk pesanan kirim.', 'Alamat');
        }

        $barisMasukan = array_values((array) $data['Baris']);
        $hashNoHp = hash_hmac('sha256', $noHp, (string) config('app.key'));
        $hitung = $this->penghitung->Hitung($konteks, array_map(fn (array $b): array => [
            'UuidProduk' => strtoupper((string) $b['UuidProduk']), 'Jumlah' => (int) $b['Jumlah'],
            'Pilihan' => array_values((array) ($b['Pilihan'] ?? [])),
            'UuidVarian' => is_string($b['UuidVarian'] ?? null) ? strtoupper($b['UuidVarian']) : null,
        ], $barisMasukan), $pemenuhan, is_string($data['KodePos'] ?? null) ? $data['KodePos'] : null, $idPelanggan, is_string($data['KodeVoucher'] ?? null) ? $data['KodeVoucher'] : null);

        try {
            return DB::transaction(function () use ($konteks, $data, $uuid, $noHp, $hashNoHp, $pemenuhan, $pembayaran, $hashIp, $hitung, $barisMasukan, $idPelanggan): array {
                $statusFinal = StatusPesananOnline::NilaiFinal();
                if ($hashIp !== null && PesananOnline::query()->where('HashIp', $hashIp)->whereNotIn('Status', $statusFinal)->count() >= self::BATAS_AKTIF_PER_IP) {
                    throw new PelanggaranAturanBisnis('TerlaluBanyakPesanan', 'Terlalu banyak pesanan aktif. Tunggu pesanan sebelumnya selesai.', 'Umum', 429);
                }
                if (PesananOnline::query()->where('HashNoHp', $hashNoHp)->whereNotIn('Status', $statusFinal)->count() >= self::BATAS_AKTIF_PER_IP) {
                    throw new PelanggaranAturanBisnis('TerlaluBanyakPesanan', 'Nomor ini memiliki terlalu banyak pesanan aktif.', 'NoHp', 429);
                }

                $tanggal = CarbonImmutable::now()->setTimezone($konteks->zonaWaktu);
                $urut = $this->penomor->AmbilBerikutnyaHarian(JenisDokumenBernomor::PesananOnline, $tanggal->format('Y-m-d'), $konteks->idOutlet);
                $nomor = sprintf('ON/%s/%s-%s', $konteks->kodeOutlet, $tanggal->format('ymd'), str_pad((string) $urut, 4, '0', STR_PAD_LEFT));
                $perkiraan = PenghitungPesanSendiri::KeLarik($hitung['Perkiraan']);
                $pajak = array_reduce($perkiraan['Pajak'], fn (Uang $jumlah, array $p): Uang => $jumlah->Tambah(Uang::Dari($p['Jumlah'])), Uang::Nol());
                $pesanan = PesananOnline::query()->create([
                    'Uuid' => $uuid, 'IdOutlet' => $konteks->idOutlet, 'IdPelanggan' => $idPelanggan, 'KodeAkses' => Str::upper(Str::random(16)),
                    'Nomor' => $nomor,
                    'JenisPemenuhan' => $pemenuhan, 'MetodePembayaran' => $pembayaran,
                    'NamaPelanggan' => trim((string) $data['NamaPelanggan']), 'NoHp' => $noHp,
                    'Email' => self::Teks($data['Email'] ?? null), 'Alamat' => self::Teks($data['Alamat'] ?? null),
                    'Kelurahan' => self::Teks($data['Kelurahan'] ?? null), 'Kecamatan' => self::Teks($data['Kecamatan'] ?? null),
                    'Kota' => self::Teks($data['Kota'] ?? null), 'Provinsi' => self::Teks($data['Provinsi'] ?? null),
                    'KodePos' => self::Teks($data['KodePos'] ?? null), 'IdZonaPengiriman' => $hitung['Zona']?->Id,
                    'Catatan' => self::Teks($data['Catatan'] ?? null), 'Subtotal' => $hitung['Subtotal']->KeString(),
                    'Diskon' => $perkiraan['Diskon'], 'BiayaLayanan' => $perkiraan['BiayaLayanan'], 'Pajak' => $pajak->KeString(),
                    'Ongkir' => $hitung['Ongkir']->KeString(), 'DiskonOngkir' => $hitung['DiskonOngkir']->KeString(),
                    'KodeVoucher' => $hitung['Voucher']['Kode'] ?? null,
                    'Total' => $hitung['Total']->KeString(), 'Perkiraan' => $perkiraan,
                    'Status' => $pembayaran->CekBayarDiMuka() ? StatusPesananOnline::MenungguPembayaran : StatusPesananOnline::MenungguKonfirmasi,
                    'HashNoHp' => $hashNoHp, 'HashIp' => $hashIp,
                ]);

                $idProduk = Produk::query()->whereIn('Uuid', array_column($hitung['Baris'], 'UuidProduk'))->pluck('Id', 'Uuid');
                $idSatuan = ProdukSatuan::query()->whereIn('Uuid', array_column($hitung['Baris'], 'UuidProdukSatuan'))->pluck('Id', 'Uuid');

                foreach ($hitung['Baris'] as $i => $b) {
                    PesananOnlineDetail::query()->create([
                        'IdPesananOnline' => $pesanan->Id, 'Urutan' => $i + 1, 'IdProduk' => $idProduk[$b['UuidProduk']],
                        'UuidProduk' => $b['UuidProduk'], 'IdProdukSatuan' => $idSatuan[$b['UuidProdukSatuan']] ?? null,
                        'UuidProdukSatuan' => $b['UuidProdukSatuan'], 'NamaProduk' => $b['NamaProduk'],
                        'Jumlah' => $b['Jumlah']->KeString(), 'HargaSatuan' => $b['HargaSatuan']->KeString(),
                        'HargaPilihan' => $b['HargaPilihan']->KeString(), 'Pilihan' => $b['Pilihan'],
                        'Catatan' => self::Teks($barisMasukan[$i]['Catatan'] ?? null),
                        'SnapshotPajak' => ['IdKelompokPajak' => $b['IdKelompokPajak'], 'HargaTermasukPajak' => $b['HargaTermasukPajak']],
                        'TotalBaris' => $b['Total']->KeString(),
                    ]);
                }

                $this->pencadang->Cadangkan(PencadangStok::SUMBER_PESANAN_ONLINE, $pesanan->Uuid, $konteks->idOutlet, PenghitungTokoOnline::BarisCadangan($hitung['Baris']));

                // v3.46: voucher dipesan atas Uuid pesanan sampai kasir menagihnya (berpindah ke penjualan) atau pesanan
                // batal/kedaluwarsa (dilepas). Gagal memesan (habis direbut) = checkout gagal utuh.
                if ($pesanan->KodeVoucher !== null) {
                    $this->pesanVoucher->Jalankan($pesanan->KodeVoucher, $pesanan->Uuid, null, PesanVoucherPos::MENIT_PESAN_ONLINE);
                }

                return [$pesanan, true];
            });
        } catch (QueryException $galat) {
            $lama = str_contains($galat->getMessage(), 'UniqPesananOnlineIdTenantUuid') ? PesananOnline::query()->where('Uuid', $uuid)->first() : null;
            if (! $lama instanceof PesananOnline) {
                throw $galat;
            }

            return [$lama, false];
        }
    }

    private static function Teks(mixed $nilai): ?string
    {
        $teks = is_string($nilai) ? trim($nilai) : '';

        return $teks === '' ? null : $teks;
    }
}
