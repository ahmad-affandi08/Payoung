<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Penjualan\Enum\JenisPemenuhanOnline;
use App\Domain\Penjualan\Enum\JenisSantapKios;
use App\Domain\Penjualan\Enum\MetodePembayaranOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Layanan\KesiapanQrisOnline;
use App\Domain\Penjualan\Layanan\PenghitungKios;
use App\Domain\Penjualan\Layanan\PenghitungPesanSendiri;
use App\Domain\Penjualan\Layanan\PenghitungTokoOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\PesananOnlineDetail;
use App\Domain\Persediaan\Layanan\PencadangStok;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-17 bagian 4: pesanan dari kios outlet. Memakai jalur `PesananOnline` yang sudah ada (muncul di aplikasi Kasir,
 * bisa diterima, diproses, ditagih lewat `Penjualan.Buat`, dan jurnalnya sama), dengan tiga bedanya:
 * - tanpa data pribadi: tidak ada nama, nomor HP, atau persetujuan data (pelanggan hanya mendapat nomor antrian);
 * - selalu `AmbilSendiri`, dengan `JenisSantap` makan di tempat atau bawa pulang;
 * - `NomorAntrian` urut harian per outlet (tampil K001, K002, …).
 * Metode bayar: `BayarSaatAmbil` (di kios disebut "bayar di kasir", selalu boleh) atau `QrisOnline` (butuh sakelar
 * QRIS toko dan gerbang aktif; pesanan menunggu pembayaran dulu, sama dengan toko online).
 * Idempoten per Uuid dari peramban. Stok dicadangkan di transaksi yang sama.
 */
final class BuatPesananKios
{
    public const BATAS_ANTRE = 60;

    public function __construct(
        private readonly PenghitungKios $penghitung,
        private readonly PenomorDokumen $penomor,
        private readonly PencadangStok $pencadang,
        private readonly KesiapanQrisOnline $qris,
    ) {}

    public static function LabelAntrian(int $nomor): string
    {
        return 'K'.str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data  Uuid, JenisSantap, MetodePembayaran, Baris[], Catatan?
     * @return array{0: PesananOnline, 1: bool} [pesanan, baru dibuat?]
     */
    public function Jalankan(DataKonteksPesanSendiri $konteks, array $data): array
    {
        $uuid = strtoupper((string) $data['Uuid']);
        $lama = PesananOnline::query()->where('Uuid', $uuid)->first();

        if ($lama instanceof PesananOnline) {
            return [$lama, false];
        }

        $santap = JenisSantapKios::from((string) $data['JenisSantap']);
        $pembayaran = MetodePembayaranOnline::from((string) $data['MetodePembayaran']);

        if ($pembayaran === MetodePembayaranOnline::Cod) {
            throw new PelanggaranAturanBisnis('MetodePembayaranTidakValid', 'Kios tidak melayani bayar di tempat tujuan.', 'MetodePembayaran');
        }

        if ($pembayaran === MetodePembayaranOnline::QrisOnline && (PengaturanTokoOnline::query()->value('QrisAktif') !== true || ! $this->qris->CekSiap())) {
            throw new PelanggaranAturanBisnis('MetodePembayaranTidakAktif', 'Pembayaran QRIS di kios sedang tidak tersedia. Silakan bayar di kasir.', 'MetodePembayaran');
        }

        $barisMasukan = array_values((array) $data['Baris']);
        $hitung = $this->penghitung->Hitung($konteks, array_map(fn (array $b): array => [
            'UuidProduk' => strtoupper((string) $b['UuidProduk']), 'Jumlah' => (int) $b['Jumlah'],
            'Pilihan' => array_values(array_map(fn (mixed $u): string => strtoupper((string) $u), (array) ($b['Pilihan'] ?? []))),
            'UuidVarian' => is_string($b['UuidVarian'] ?? null) ? strtoupper($b['UuidVarian']) : null,
        ], $barisMasukan), $santap);

        try {
            return DB::transaction(function () use ($konteks, $data, $uuid, $santap, $pembayaran, $hitung, $barisMasukan): array {
                // Satu tablet kios berada di balik satu IP, jadi batasnya per outlet, bukan per IP: antrean pesanan yang
                // belum disentuh kasir tidak boleh menumpuk tanpa batas karena kios dipakai iseng atau rusak.
                $menunggu = PesananOnline::query()->where('IdOutlet', $konteks->idOutlet)->where('Sumber', SumberPesananOnline::Kios->value)
                    ->whereIn('Status', [StatusPesananOnline::MenungguKonfirmasi->value, StatusPesananOnline::MenungguPembayaran->value])->count();

                if ($menunggu >= self::BATAS_ANTRE) {
                    throw new PelanggaranAturanBisnis('KiosPenuh', 'Antrean pesanan sedang penuh. Silakan pesan di kasir.', 'Umum', 429);
                }

                $tanggal = CarbonImmutable::now()->setTimezone($konteks->zonaWaktu);
                $antrian = $this->penomor->AmbilBerikutnyaHarian(JenisDokumenBernomor::AntrianKios, $tanggal->format('Y-m-d'), $konteks->idOutlet);
                $label = self::LabelAntrian($antrian);
                $perkiraan = PenghitungPesanSendiri::KeLarik($hitung['Perkiraan']);
                $pajak = array_reduce($perkiraan['Pajak'], fn (Uang $jumlah, array $p): Uang => $jumlah->Tambah(Uang::Dari($p['Jumlah'])), Uang::Nol());
                $catatan = is_string($data['Catatan'] ?? null) ? trim($data['Catatan']) : '';
                $pesanan = PesananOnline::query()->create([
                    'Uuid' => $uuid, 'IdOutlet' => $konteks->idOutlet, 'KodeAkses' => Str::upper(Str::random(16)),
                    'Nomor' => sprintf('KI/%s/%s-%s', $konteks->kodeOutlet, $tanggal->format('ymd'), str_pad((string) $antrian, 3, '0', STR_PAD_LEFT)),
                    'Sumber' => SumberPesananOnline::Kios, 'JenisSantap' => $santap, 'NomorAntrian' => $antrian,
                    'JenisPemenuhan' => JenisPemenuhanOnline::AmbilSendiri, 'MetodePembayaran' => $pembayaran,
                    // Nama kasir-friendly: terbaca di daftar pesanan aplikasi Kasir tanpa data pribadi.
                    'NamaPelanggan' => "Kios {$label} | {$santap->AmbilLabel()}", 'NoHp' => '',
                    'Catatan' => $catatan === '' ? null : mb_substr($catatan, 0, 500),
                    'Subtotal' => $hitung['Subtotal']->KeString(),
                    'Diskon' => $perkiraan['Diskon'], 'BiayaLayanan' => $perkiraan['BiayaLayanan'], 'Pajak' => $pajak->KeString(),
                    'Ongkir' => '0.00', 'DiskonOngkir' => '0.00',
                    'Total' => $hitung['Total']->KeString(), 'Perkiraan' => $perkiraan,
                    'Status' => $pembayaran->CekBayarDiMuka() ? StatusPesananOnline::MenungguPembayaran : StatusPesananOnline::MenungguKonfirmasi,
                ]);

                $idProduk = Produk::query()->whereIn('Uuid', array_column($hitung['Baris'], 'UuidProduk'))->pluck('Id', 'Uuid');
                $idSatuan = ProdukSatuan::query()->whereIn('Uuid', array_column($hitung['Baris'], 'UuidProdukSatuan'))->pluck('Id', 'Uuid');

                foreach ($hitung['Baris'] as $i => $b) {
                    $catatanBaris = is_string($barisMasukan[$i]['Catatan'] ?? null) ? trim($barisMasukan[$i]['Catatan']) : '';
                    PesananOnlineDetail::query()->create([
                        'IdPesananOnline' => $pesanan->Id, 'Urutan' => $i + 1, 'IdProduk' => $idProduk[$b['UuidProduk']],
                        'UuidProduk' => $b['UuidProduk'], 'IdProdukSatuan' => $idSatuan[$b['UuidProdukSatuan']] ?? null,
                        'UuidProdukSatuan' => $b['UuidProdukSatuan'], 'NamaProduk' => $b['NamaProduk'],
                        'Jumlah' => $b['Jumlah']->KeString(), 'HargaSatuan' => $b['HargaSatuan']->KeString(),
                        'HargaPilihan' => $b['HargaPilihan']->KeString(), 'Pilihan' => $b['Pilihan'],
                        'Catatan' => $catatanBaris === '' ? null : mb_substr($catatanBaris, 0, 255),
                        'SnapshotPajak' => ['IdKelompokPajak' => $b['IdKelompokPajak'], 'HargaTermasukPajak' => $b['HargaTermasukPajak']],
                        'TotalBaris' => $b['Total']->KeString(),
                    ]);
                }

                $this->pencadang->Cadangkan(PencadangStok::SUMBER_PESANAN_ONLINE, $pesanan->Uuid, $konteks->idOutlet, PenghitungTokoOnline::BarisCadangan($hitung['Baris']));

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
}
