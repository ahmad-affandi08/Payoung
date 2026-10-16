<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Layanan\PenyusunAngkaInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceDetail;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simpan draf invoice (F-21, D-166): buat baru atau ganti seluruh isi draf yang ada. Draf belum bernomor, belum berjurnal,
 * belum berpiutang; semua itu baru terjadi saat `TerbitkanInvoice`.
 *
 * - **Hanya jasa & barang tanpa stok** (`JenisProduk::Jasa`/`NonStok`) atau baris bebas yang diketik. Barang berstok
 *   ditolak dengan alasannya: menagihnya berarti menggerakkan stok & HPP, dan itu urusan surat jalan grosir (BR-12.2).
 * - Angka dihitung `PenyusunAngkaInvoice` (mesin kalkulasi bersama); klien tidak pernah mengirim total, pajak, atau PPh.
 * - Invoice DP (`UangMuka`) tidak boleh berdiskon, tidak ber-PPh, dan tidak memotong DP lain.
 * - DP yang dipilih untuk invoice akhir **dipesan** lewat `IdInvoiceAkhir` sejak draf, supaya tidak dipakai dua draf
 *   sekaligus; draf yang dibatalkan/diganti melepasnya.
 * - Hanya draf yang boleh diubah; setelah terbit kolomnya dikunci model (aturan #8).
 */
final class SimpanInvoice
{
    public const MAKS_BARIS = 200;

    public const MAKS_UANG_MUKA = 20;

    public function __construct(
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly PenyusunAngkaInvoice $penyusun,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis InvoiceTanpaBaris, TerlaluBanyakBaris, PelangganTidakDikenal, PelangganNonaktif,
     *                                 OutletTidakDikenal, InvoiceTidakDitemukan, InvoiceTidakBisaDiubah, BarisTidakValid,
     *                                 ProdukTidakDikenal, ProdukBerstok, KelompokPajakTidakDikenal, PphTidakValid,
     *                                 UangMukaTidakValid, UangMukaMelebihiInvoice
     */
    public function Jalankan(DataInvoice $data, int $idPengguna, ?string $uuidInvoice = null): Invoice
    {
        if ($data->baris === []) {
            throw new PelanggaranAturanBisnis('InvoiceTanpaBaris', 'Invoice harus punya minimal satu baris.', 'Baris');
        }

        if (count($data->baris) > self::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyakBaris', 'Satu invoice paling banyak '.self::MAKS_BARIS.' baris.', 'Baris');
        }

        $tarifPph = $this->PeriksaPph($data);

        return DB::transaction(function () use ($data, $idPengguna, $uuidInvoice, $tarifPph): Invoice {
            $pelanggan = Pelanggan::query()->where('Uuid', $data->uuidPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');

            if ($pelanggan->Status !== StatusPelanggan::Aktif) {
                throw new PelanggaranAturanBisnis('PelangganNonaktif', "Pelanggan {$pelanggan->Nama} sudah diarsipkan. Aktifkan lagi atau pilih pelanggan lain.", 'UuidPelanggan');
            }

            $outlet = Outlet::query()->whereKey($data->idOutlet)->first()
                ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'IdOutlet');
            $invoice = $uuidInvoice === null
                ? null
                : (Invoice::query()->where('Uuid', $uuidInvoice)->lockForUpdate()->first()
                    ?? throw new PelanggaranAturanBisnis('InvoiceTidakDitemukan', 'Invoice tidak ditemukan.'));

            if ($invoice !== null && ! $invoice->Status->CekBolehDiubah()) {
                throw new PelanggaranAturanBisnis(
                    'InvoiceTidakBisaDiubah',
                    "Invoice {$invoice->Nomor} sudah {$invoice->Status->AmbilLabel()} dan tidak bisa diubah. Batalkan lalu buat invoice baru.",
                );
            }

            if ($invoice !== null && $invoice->Jenis !== $data->jenis) {
                throw new PelanggaranAturanBisnis('JenisInvoiceTidakBisaBerubah', 'Jenis invoice tidak bisa diganti setelah dibuat. Buat invoice baru.', 'Jenis');
            }

            $baris = $this->SusunBaris($data);
            $termin = $data->terminHari ?? (int) $pelanggan->TerminHari;

            if ($termin < 0 || $termin > 365) {
                throw new PelanggaranAturanBisnis('TerminTidakValid', 'Termin pembayaran 0 sampai 365 hari.', 'TerminHari');
            }

            $nilai = [
                'IdPelanggan' => $pelanggan->Id,
                'IdOutlet' => $outlet->Id,
                'Tanggal' => $data->tanggal->toDateString(),
                'JatuhTempo' => $data->tanggal->addDays($termin)->toDateString(),
                'TerminHari' => $termin,
                'Perihal' => $this->Bersihkan($data->perihal, 150),
                'NomorReferensi' => $this->Bersihkan($data->nomorReferensi, 60),
                'HargaTermasukPajak' => $data->hargaTermasukPajak,
                'KodePph' => $tarifPph === null ? null : $this->Bersihkan($data->kodePph, 20),
                'TarifPph' => $tarifPph,
                'Catatan' => $this->Bersihkan($data->catatan, 1000),
                'Syarat' => $this->Bersihkan($data->syarat, 1000),
                'InfoPembayaran' => $this->Bersihkan($data->infoPembayaran, 500),
                'DiubahOleh' => $idPengguna,
            ];

            if ($invoice === null) {
                $invoice = Invoice::query()->create([
                    ...$nilai,
                    'KodePublik' => Str::random(32),
                    'Jenis' => $data->jenis,
                    'Status' => StatusInvoice::Draf,
                    'DibuatOleh' => $idPengguna,
                ]);
            } else {
                $invoice->update($nilai);
                InvoiceDetail::query()->where('IdInvoice', $invoice->Id)->delete();
            }

            foreach ($baris as $urutan => $satuBaris) {
                InvoiceDetail::query()->create([...$satuBaris, 'IdInvoice' => $invoice->Id, 'Urutan' => $urutan + 1]);
            }

            $this->PesanUangMuka($invoice, $data, $pelanggan);
            $this->penyusun->Terapkan($invoice);

            $this->audit->Catat($uuidInvoice === null ? 'invoice.buat' : 'invoice.ubah', $invoice, nilaiBaru: [
                'Jenis' => $invoice->Jenis->value,
                'IdPelanggan' => $pelanggan->Id,
                'JumlahBaris' => count($baris),
                'Total' => $invoice->Total,
                'Tagihan' => $invoice->Tagihan,
            ], idPengguna: $idPengguna);

            return $invoice->refresh()->load('Detail');
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /**
     * PPh hanya untuk invoice penjualan; tarif (persen) diisi pengguna, 0 < tarif ≤ 100. Mengembalikan tarif yang sudah
     * dinormalkan, atau null bila PPh tidak dipakai.
     */
    private function PeriksaPph(DataInvoice $data): ?string
    {
        $kode = $this->Bersihkan($data->kodePph, 20);
        $tarif = $data->tarifPph === null ? '' : trim($data->tarifPph);

        if ($kode === null && $tarif === '') {
            return null;
        }

        if ($data->jenis === JenisInvoice::UangMuka) {
            throw new PelanggaranAturanBisnis('PphTidakValid', 'Invoice uang muka tidak memakai PPh; PPh dihitung di invoice akhir.', 'KodePph');
        }

        if ($kode === null || $tarif === '' || preg_match('/^\d{1,3}([.,]\d{1,4})?$/', $tarif) !== 1) {
            throw new PelanggaranAturanBisnis('PphTidakValid', 'Isi jenis PPh dan tarifnya (persen, maksimal 4 desimal), misalnya 2 untuk 2%.', 'TarifPph');
        }

        $desimal = BigDecimal::of(str_replace(',', '.', $tarif));

        if ($desimal->isLessThanOrEqualTo(0) || $desimal->isGreaterThan(100)) {
            throw new PelanggaranAturanBisnis('PphTidakValid', 'Tarif PPh harus lebih dari 0 dan paling banyak 100 persen.', 'TarifPph');
        }

        return (string) $desimal->toScale(6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function SusunBaris(DataInvoice $data): array
    {
        $hasil = [];

        foreach ($data->baris as $indeks => $b) {
            $kunci = 'Baris.'.$indeks;
            $nama = trim($b->nama);

            if ($nama === '' || mb_strlen($nama) > 200) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', 'Nama baris wajib diisi, paling banyak 200 karakter.', $kunci.'.Nama');
            }

            if ($b->jumlah->BernilaiNol() || $b->jumlah->BernilaiNegatif()) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', "Jumlah baris \"{$nama}\" harus lebih dari 0.", $kunci.'.Jumlah');
            }

            if ($b->harga->BernilaiNegatif() || $b->diskon->BernilaiNegatif()) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', "Harga dan diskon baris \"{$nama}\" tidak boleh negatif.", $kunci.'.Harga');
            }

            $subtotal = $b->harga->Kali($b->jumlah->KeString());

            if ($b->diskon->Bandingkan($subtotal) > 0) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', "Diskon baris \"{$nama}\" melebihi nilai barisnya.", $kunci.'.Diskon');
            }

            if ($data->jenis === JenisInvoice::UangMuka && ! $b->diskon->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', 'Invoice uang muka tidak memakai diskon.', $kunci.'.Diskon');
            }

            $produk = $this->CariProduk($b, $kunci);
            $idKelompok = null;

            if ($b->uuidKelompokPajak !== null && $b->uuidKelompokPajak !== '') {
                $idKelompok = $this->kelompokPajak->CariIdBerdasarkanUuid($b->uuidKelompokPajak)
                    ?? throw new PelanggaranAturanBisnis('KelompokPajakTidakDikenal', "Kelompok pajak baris \"{$nama}\" tidak ditemukan.", $kunci.'.UuidKelompokPajak');
            }

            $hasil[] = [
                'IdProduk' => $produk?->Id,
                'Nama' => $nama,
                'Keterangan' => $this->Bersihkan($b->keterangan, 255),
                'JenisBaris' => $b->jenisBaris,
                'SimbolSatuan' => $this->Bersihkan($b->simbolSatuan, 20),
                'Jumlah' => $b->jumlah->KeString(),
                'Harga' => $b->harga->KeString(),
                'Diskon' => $b->diskon->KeString(),
                'Subtotal' => $subtotal->KeString(),
                'HargaTermasukPajak' => $b->hargaTermasukPajak,
                'IdKelompokPajak' => $idKelompok,
            ];
        }

        return $hasil;
    }

    private function CariProduk(DataBarisInvoice $baris, string $kunci): ?Produk
    {
        if ($baris->uuidProduk === null || $baris->uuidProduk === '') {
            return null;
        }

        $produk = Produk::query()->where('Uuid', $baris->uuidProduk)->first()
            ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', "Produk baris \"{$baris->nama}\" tidak ditemukan.", $kunci.'.UuidProduk');

        if ($produk->Jenis !== JenisProduk::Jasa && $produk->Jenis !== JenisProduk::NonStok) {
            throw new PelanggaranAturanBisnis(
                'ProdukBerstok',
                "Invoice hanya untuk jasa atau produk tanpa stok; {$produk->Nama} berjenis {$produk->Jenis->AmbilLabel()}. Barang berstok ditagih lewat pesanan grosir dan surat jalan supaya stok & HPP-nya tercatat.",
                $kunci.'.UuidProduk',
            );
        }

        return $produk;
    }

    /**
     * Memesan DP untuk invoice akhir ini dan melepas DP yang tidak lagi dipilih.
     */
    private function PesanUangMuka(Invoice $invoice, DataInvoice $data, Pelanggan $pelanggan): void
    {
        $uuid = array_values(array_unique(array_filter($data->uuidUangMuka, fn (string $u): bool => $u !== '')));

        if ($data->jenis === JenisInvoice::UangMuka) {
            if ($uuid !== []) {
                throw new PelanggaranAturanBisnis('UangMukaTidakValid', 'Invoice uang muka tidak memotong uang muka lain.', 'UuidUangMuka');
            }

            return;
        }

        if (count($uuid) > self::MAKS_UANG_MUKA) {
            throw new PelanggaranAturanBisnis('UangMukaTidakValid', 'Paling banyak '.self::MAKS_UANG_MUKA.' invoice uang muka per invoice.', 'UuidUangMuka');
        }

        // Lepas dulu semua DP milik draf ini, lalu pesan ulang yang dipilih; urut Id supaya urutan kunci konsisten.
        Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->lockForUpdate()->get()
            ->each(function (Invoice $dp): void {
                $dp->IdInvoiceAkhir = null;
                $dp->save();
            });

        if ($uuid === []) {
            return;
        }

        $dp = Invoice::query()->whereIn('Uuid', $uuid)->orderBy('Id')->lockForUpdate()->get();

        if ($dp->count() !== count($uuid)) {
            throw new PelanggaranAturanBisnis('UangMukaTidakValid', 'Ada invoice uang muka yang tidak ditemukan.', 'UuidUangMuka');
        }

        foreach ($dp as $satu) {
            if ($satu->Jenis !== JenisInvoice::UangMuka || $satu->Status !== StatusInvoice::Terbit || $satu->IdPelanggan !== $pelanggan->Id) {
                throw new PelanggaranAturanBisnis('UangMukaTidakValid', "Invoice {$satu->Nomor} bukan invoice uang muka terbit milik {$pelanggan->Nama}.", 'UuidUangMuka');
            }

            if ($satu->IdInvoiceAkhir !== null) {
                throw new PelanggaranAturanBisnis('UangMukaTidakValid', "Invoice uang muka {$satu->Nomor} sudah dipakai di invoice lain.", 'UuidUangMuka');
            }

            $satu->IdInvoiceAkhir = $invoice->Id;
            $satu->save();
        }
    }

    private function Bersihkan(?string $nilai, int $panjang): ?string
    {
        $bersih = $nilai === null ? '' : trim($nilai);

        return $bersih === '' ? null : mb_substr($bersih, 0, $panjang);
    }
}
