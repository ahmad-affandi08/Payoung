<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Kueri\IsianFormInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PenawaranDetail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menjadikan penawaran sebagai **draf invoice** (F-21 bagian 3, D-168). Isi baris, harga, diskon, pajak, termin, perihal,
 * catatan, dan syarat disalin; tanggal invoice = hari ini dan angkanya dihitung ulang oleh `SimpanInvoice` pada tanggal itu
 * (tarif pajak yang berlaku hari ini, bukan tarif saat penawaran). Draf itu diperiksa penagih lalu diterbitkan lewat jalur
 * invoice biasa, jadi tidak ada jurnal di sini.
 *
 * - Penawaran `Terbit` yang dijadikan invoice otomatis dianggap **diterima** oleh staf (diterima lewat telepon/email).
 * - Sudah punya invoice yang masih draf: mengembalikan draf itu (klik ganda tidak membuat dua invoice). Sudah punya
 *   invoice terbit: ditolak. Invoice sebelumnya dibatalkan: boleh dibuatkan invoice baru (penawaran menunjuk yang baru).
 */
final class BuatInvoiceDariPenawaran
{
    public function __construct(
        private readonly SimpanInvoice $simpanInvoice,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly IsianFormInvoice $isianInvoice,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PenawaranTidakDitemukan, PenawaranTidakBisaDiinvoicekan, PenawaranSudahDiInvoicekan,
     *                                 serta galat validasi invoice (ProdukBerstok, PelangganNonaktif, dst.)
     */
    public function Jalankan(string $uuidPenawaran, int $idPengguna): Invoice
    {
        return DB::transaction(function () use ($uuidPenawaran, $idPengguna): Invoice {
            $penawaran = Penawaran::query()->where('Uuid', $uuidPenawaran)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PenawaranTidakDitemukan', 'Penawaran tidak ditemukan.');

            if (! in_array($penawaran->Status, [StatusPenawaran::Terbit, StatusPenawaran::Diterima], true)) {
                throw new PelanggaranAturanBisnis('PenawaranTidakBisaDiinvoicekan', "Penawaran yang {$penawaran->Status->AmbilLabel()} tidak bisa dijadikan invoice.", 'Umum', 409);
            }

            if ($penawaran->IdInvoice !== null) {
                $lama = Invoice::query()->whereKey($penawaran->IdInvoice)->first();

                if ($lama !== null && $lama->Status === StatusInvoice::Draf) {
                    return $lama;
                }

                if ($lama !== null && $lama->Status === StatusInvoice::Terbit) {
                    throw new PelanggaranAturanBisnis('PenawaranSudahDiInvoicekan', "Penawaran ini sudah dijadikan invoice {$lama->Nomor}.", 'Umum', 409);
                }
            }

            $pelanggan = Pelanggan::query()->whereKey($penawaran->IdPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan penawaran ini tidak ditemukan.');
            $invoice = $this->simpanInvoice->Jalankan($this->SusunData($penawaran, $pelanggan->Uuid), $idPengguna);

            $asal = $penawaran->Status;

            if ($asal === StatusPenawaran::Terbit) {
                $penawaran->UbahStatus(StatusPenawaran::Diterima);
                $penawaran->fill([
                    'SumberKeputusan' => Penawaran::SUMBER_STAF,
                    'DiputuskanPada' => CarbonImmutable::now(),
                ]);
                $this->riwayat->Catat(Penawaran::JENIS_DOKUMEN, $penawaran->Id, $asal->value, StatusPenawaran::Diterima->value, $idPengguna, 'Dijadikan invoice');
            }

            $penawaran->fill(['IdInvoice' => $invoice->Id, 'DiubahOleh' => $idPengguna])->save();
            $this->audit->Catat('penawaran.diinvoicekan', $penawaran, nilaiBaru: [
                'Nomor' => $penawaran->Nomor,
                'UuidInvoice' => $invoice->Uuid,
                'Total' => $penawaran->Total,
            ], idPengguna: $idPengguna);

            return $invoice;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function SusunData(Penawaran $penawaran, string $uuidPelanggan): DataInvoice
    {
        $detail = PenawaranDetail::query()->where('IdPenawaran', $penawaran->Id)->orderBy('Urutan')->get();
        $idProduk = array_values(array_filter($detail->pluck('IdProduk')->all(), 'is_int'));
        $uuidProduk = $idProduk === [] ? [] : Produk::query()->whereIn('Id', $idProduk)->pluck('Uuid', 'Id')->all();
        $uuidKelompok = [];

        foreach ($this->kelompokPajak->AmbilOpsi() as $opsi) {
            $uuidKelompok[$opsi['Id']] = $opsi['Uuid'];
        }

        return new DataInvoice(
            jenis: JenisInvoice::Penjualan,
            uuidPelanggan: $uuidPelanggan,
            idOutlet: $penawaran->IdOutlet,
            tanggal: $this->tanggalBisnis->Hitung($penawaran->IdOutlet),
            terminHari: $penawaran->TerminHari,
            baris: array_values($detail->map(fn (PenawaranDetail $d): DataBarisInvoice => new DataBarisInvoice(
                uuidProduk: $d->IdProduk === null ? null : ($uuidProduk[$d->IdProduk] ?? null),
                nama: $d->Nama,
                keterangan: $d->Keterangan,
                jenisBaris: $d->JenisBaris,
                simbolSatuan: $d->SimbolSatuan,
                jumlah: Kuantitas::Dari($d->Jumlah),
                harga: Uang::Dari($d->Harga),
                diskon: Uang::Dari($d->Diskon),
                uuidKelompokPajak: $d->IdKelompokPajak === null ? null : ($uuidKelompok[$d->IdKelompokPajak] ?? null),
                hargaTermasukPajak: $d->HargaTermasukPajak,
            ))->all()),
            hargaTermasukPajak: $penawaran->HargaTermasukPajak,
            perihal: $penawaran->Perihal,
            nomorReferensi: $penawaran->NomorReferensi,
            catatan: $penawaran->Catatan,
            syarat: $penawaran->Syarat,
            infoPembayaran: $this->isianInvoice->Kebiasaan()['InfoPembayaran'],
        );
    }
}
