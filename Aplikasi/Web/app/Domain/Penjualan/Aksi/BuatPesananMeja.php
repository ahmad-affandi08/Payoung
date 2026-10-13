<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Penjualan\Data\DataBarisPesanSendiri;
use App\Domain\Penjualan\Data\DataKonteksPesanSendiri;
use App\Domain\Penjualan\Data\DataPesanSendiri;
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
 * F-17 SLS-04: pesanan QR meja yang **dibayar QRIS dari HP tamu**. Pesanan QR meja biasa (`PesananSendiri`, bayar di
 * kasir) tidak berubah. Yang dibayar di muka memakai jalur `PesananOnline` yang sudah punya QRIS, uang muka J-17.1,
 * polling, kedaluwarsa, dan penagihan di aplikasi Kasir (`Sumber` = Meja, `IdMeja` = meja asal, `NamaPelanggan` = "Meja
 * 5 | nama pemesan"), seperti kios tetapi dengan meja alih-alih nomor antrian. Pesanan baru menunggu pembayaran; begitu
 * lunas ia menunggu kasir menerimanya. Harga, pajak, promo, dan stok dihitung server (mesin yang sama dengan kios), stok
 * dicadangkan di transaksi yang sama, idempoten per Uuid, paling banyak 5 pesanan menunggu bayar per meja.
 */
final class BuatPesananMeja
{
    public const BATAS_MENUNGGU_BAYAR_PER_MEJA = 5;

    public function __construct(
        private readonly PenghitungKios $penghitung,
        private readonly PenomorDokumen $penomor,
        private readonly PencadangStok $pencadang,
        private readonly KesiapanQrisOnline $qris,
    ) {}

    /**
     * @return array{0: PesananOnline, 1: bool} [pesanan, baru dibuat?]
     */
    public function Jalankan(DataKonteksPesanSendiri $konteks, DataPesanSendiri $data, ?string $hashIp = null): array
    {
        $lama = PesananOnline::query()->where('Uuid', $data->uuid)->first();

        if ($lama instanceof PesananOnline) {
            if ($lama->IdMeja !== $konteks->idMeja) {
                throw new PelanggaranAturanBisnis('UuidDipakai', 'Kode pesanan ini sudah dipakai. Muat ulang halaman lalu kirim lagi.', 'Uuid', 409);
            }

            return [$lama, false];
        }

        if (PengaturanTokoOnline::query()->value('QrisAktif') !== true || ! $this->qris->CekSiap()) {
            throw new PelanggaranAturanBisnis('MetodePembayaranTidakAktif', 'Pembayaran QRIS dari HP sedang tidak tersedia. Silakan bayar di kasir.', 'Umum');
        }

        $barisMasukan = $data->baris;
        $hitung = $this->penghitung->Hitung($konteks, array_map(fn (DataBarisPesanSendiri $b): array => [
            'UuidProduk' => $b->uuidProduk, 'Jumlah' => $b->jumlah, 'Pilihan' => $b->pilihan, 'UuidVarian' => $b->uuidVarian,
        ], $barisMasukan), JenisSantapKios::MakanDiTempat);

        try {
            return DB::transaction(function () use ($konteks, $data, $hitung, $barisMasukan, $hashIp): array {
                $menunggu = PesananOnline::query()->where('IdMeja', $konteks->idMeja)->where('Status', StatusPesananOnline::MenungguPembayaran->value)->count();

                if ($menunggu >= self::BATAS_MENUNGGU_BAYAR_PER_MEJA) {
                    throw new PelanggaranAturanBisnis('TerlaluBanyakPesanan', 'Masih ada '.self::BATAS_MENUNGGU_BAYAR_PER_MEJA.' pesanan meja ini yang menunggu pembayaran. Selesaikan atau tunggu sebentar.', 'Uuid', 429);
                }

                $tanggal = CarbonImmutable::now()->setTimezone($konteks->zonaWaktu);
                $urut = $this->penomor->AmbilBerikutnyaHarian(JenisDokumenBernomor::PesananMeja, $tanggal->format('Y-m-d'), $konteks->idOutlet);
                $perkiraan = PenghitungPesanSendiri::KeLarik($hitung['Perkiraan']);
                $pajak = array_reduce($perkiraan['Pajak'], fn (Uang $jumlah, array $p): Uang => $jumlah->Tambah(Uang::Dari($p['Jumlah'])), Uang::Nol());
                $pesanan = PesananOnline::query()->create([
                    'Uuid' => $data->uuid, 'IdOutlet' => $konteks->idOutlet, 'IdMeja' => $konteks->idMeja, 'KodeAkses' => Str::upper(Str::random(16)),
                    'Nomor' => sprintf('%s/%s/%s-%s', JenisDokumenBernomor::PesananMeja->AmbilAwalan(), $konteks->kodeOutlet, $tanggal->format('ymd'), str_pad((string) $urut, 4, '0', STR_PAD_LEFT)),
                    'Sumber' => SumberPesananOnline::Meja, 'JenisSantap' => JenisSantapKios::MakanDiTempat, 'NomorAntrian' => null,
                    'JenisPemenuhan' => JenisPemenuhanOnline::AmbilSendiri, 'MetodePembayaran' => MetodePembayaranOnline::QrisOnline,
                    // Terbaca di daftar pesanan aplikasi Kasir: meja asal + nama panggilan opsional, tanpa nomor HP.
                    'NamaPelanggan' => mb_substr("Meja {$konteks->namaMeja}".($data->namaPemesan === null ? '' : " | {$data->namaPemesan}"), 0, 120),
                    'NoHp' => '', 'Catatan' => $data->catatan === null ? null : mb_substr($data->catatan, 0, 500),
                    'Subtotal' => $hitung['Subtotal']->KeString(),
                    'Diskon' => $perkiraan['Diskon'], 'BiayaLayanan' => $perkiraan['BiayaLayanan'], 'Pajak' => $pajak->KeString(),
                    'Ongkir' => '0.00', 'DiskonOngkir' => '0.00',
                    'Total' => $hitung['Total']->KeString(), 'Perkiraan' => $perkiraan,
                    'Status' => StatusPesananOnline::MenungguPembayaran,
                    'HashIp' => $hashIp,
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
                        'Catatan' => $barisMasukan[$i]->catatan === null ? null : mb_substr($barisMasukan[$i]->catatan, 0, 255),
                        'SnapshotPajak' => ['IdKelompokPajak' => $b['IdKelompokPajak'], 'HargaTermasukPajak' => $b['HargaTermasukPajak']],
                        'TotalBaris' => $b['Total']->KeString(),
                    ]);
                }

                $this->pencadang->Cadangkan(PencadangStok::SUMBER_PESANAN_ONLINE, $pesanan->Uuid, $konteks->idOutlet, PenghitungTokoOnline::BarisCadangan($hitung['Baris']));

                return [$pesanan, true];
            });
        } catch (QueryException $galat) {
            $lama = str_contains($galat->getMessage(), 'UniqPesananOnlineIdTenantUuid') ? PesananOnline::query()->where('Uuid', $data->uuid)->first() : null;

            if (! $lama instanceof PesananOnline) {
                throw $galat;
            }

            return [$lama, false];
        }
    }
}
