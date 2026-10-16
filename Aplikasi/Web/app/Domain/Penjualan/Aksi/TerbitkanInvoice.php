<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Layanan\PenomorGrosir;
use App\Domain\Penjualan\Layanan\PenyusunAngkaInvoice;
use App\Domain\Penjualan\Layanan\PenyusunJurnalInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menerbitkan draf invoice (F-21, D-166, J-21.1/J-21.2): angka dihitung ulang, dinomori, dijurnal, dan dibuatkan piutang
 * dalam satu transaksi DB. Setelah terbit invoice terkunci (aturan #8) dan hanya bisa dikoreksi lewat pembatalan.
 *
 * Syarat: tanggal bukan di masa depan dan di periode yang belum dikunci; pelanggan masih aktif; invoice uang muka (DP)
 * yang dipotong masih terbit. **Piutang hanya dibuat bila ada yang ditagih**: invoice yang seluruhnya tertutup uang muka
 * (Tagihan = 0) tidak berpiutang, karena piutang bernilai nol tidak punya arti untuk aging maupun pelunasan.
 *
 * Idempoten: menerbitkan invoice yang sudah terbit mengembalikan invoice itu tanpa jurnal kedua.
 */
final class TerbitkanInvoice
{
    public function __construct(
        private readonly PenyusunAngkaInvoice $penyusunAngka,
        private readonly PenyusunJurnalInvoice $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenomorGrosir $penomor,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PencatatPiutangPenjualan $pencatatPiutang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis InvoiceTidakDitemukan, InvoiceSudahDibatalkan, TanggalMasaDepan, PeriodeTerkunci,
     *                                 PelangganNonaktif, UangMukaTidakValid, UangMukaMelebihiInvoice
     */
    public function Jalankan(string $uuidInvoice, int $idPengguna): Invoice
    {
        return DB::transaction(fn (): Invoice => $this->Terbitkan($uuidInvoice, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Terbitkan(string $uuid, int $idPengguna): Invoice
    {
        $invoice = Invoice::query()->where('Uuid', $uuid)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('InvoiceTidakDitemukan', 'Invoice tidak ditemukan.');

        if ($invoice->Status === StatusInvoice::Terbit) {
            return $invoice;
        }

        if ($invoice->Status === StatusInvoice::Dibatalkan) {
            throw new PelanggaranAturanBisnis('InvoiceSudahDibatalkan', 'Invoice yang sudah dibatalkan tidak bisa diterbitkan. Buat invoice baru.');
        }

        $tanggal = CarbonImmutable::parse($invoice->Tanggal->format('Y-m-d'));
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $invoice->IdOutlet);
        $this->penjagaPeriode->PastikanTerbuka($tanggal);

        $pelanggan = Pelanggan::query()->whereKey($invoice->IdPelanggan)->first()
            ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan invoice ini tidak ditemukan.');

        if ($pelanggan->Status !== StatusPelanggan::Aktif) {
            throw new PelanggaranAturanBisnis('PelangganNonaktif', "Pelanggan {$pelanggan->Nama} sudah diarsipkan. Aktifkan lagi sebelum menerbitkan invoice.", 'UuidPelanggan');
        }

        $uangMuka = Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->lockForUpdate()->get();

        foreach ($uangMuka as $dp) {
            if ($dp->Status !== StatusInvoice::Terbit || $dp->Jenis !== JenisInvoice::UangMuka) {
                throw new PelanggaranAturanBisnis('UangMukaTidakValid', "Invoice uang muka {$dp->Nomor} sudah tidak berlaku. Lepas dari invoice ini lalu terbitkan lagi.", 'UuidUangMuka');
            }
        }

        $this->penyusunAngka->Terapkan($invoice);

        $nomor = $this->penomor->AmbilNomorOutlet(JenisDokumenBernomor::Invoice, $tanggal, $invoice->IdOutlet);
        $invoice->Nomor = $nomor;
        $total = Uang::Dari($invoice->Total);
        $tagihan = Uang::Dari($invoice->Tagihan);
        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::Invoice,
            idSumber: $invoice->Id,
            uuidSumber: $invoice->Uuid,
            nomorSumber: $nomor,
            tanggal: $tanggal,
            keterangan: mb_substr(($invoice->Jenis === JenisInvoice::UangMuka ? 'Invoice uang muka' : 'Invoice')." {$nomor} kepada {$pelanggan->Nama}", 0, 255),
            baris: $invoice->Jenis === JenisInvoice::UangMuka
                ? $this->penyusunJurnal->BarisUangMuka($invoice->IdOutlet, $total, Uang::Dari($invoice->DasarPengenaanPajak), $this->RincianPajak($invoice))
                : $this->penyusunJurnal->BarisInvoice(
                    $invoice->IdOutlet,
                    $tagihan,
                    Uang::Dari($invoice->Diskon),
                    $this->PendapatanPerPeran($invoice),
                    $this->penyusunAngka->AmbilPajakNeto($invoice),
                    array_values($uangMuka->map(fn (Invoice $dp): array => ['IdOutlet' => $dp->IdOutlet, 'Dpp' => Uang::Dari($dp->DasarPengenaanPajak)])->all()),
                ),
            idPengguna: $idPengguna,
        ));

        $invoice->UbahStatus(StatusInvoice::Terbit);
        $invoice->fill([
            'IdJurnal' => $jurnal->idJurnal,
            'DiterbitkanPada' => CarbonImmutable::now(),
            'DiubahOleh' => $idPengguna,
        ])->save();

        $piutang = $tagihan->BernilaiNol() ? null : $this->pencatatPiutang->CatatInvoice(
            $invoice->IdPelanggan,
            $invoice->Id,
            $invoice->IdOutlet,
            $nomor,
            $tanggal,
            $tagihan,
            $invoice->TerminHari,
        );

        $this->riwayat->Catat(Invoice::JENIS_DOKUMEN, $invoice->Id, StatusInvoice::Draf->value, StatusInvoice::Terbit->value, $idPengguna);
        $this->audit->Catat('invoice.terbit', $invoice, nilaiLama: ['Status' => StatusInvoice::Draf->value], nilaiBaru: [
            'Status' => StatusInvoice::Terbit->value,
            'Nomor' => $nomor,
            'Jenis' => $invoice->Jenis->value,
            'IdPelanggan' => $invoice->IdPelanggan,
            'Total' => $invoice->Total,
            'Tagihan' => $invoice->Tagihan,
            'JatuhTempo' => $invoice->JatuhTempo->toDateString(),
            'NomorJurnal' => $jurnal->nomor,
            'NomorPiutang' => $piutang?->Nomor,
            'UangMukaDipotong' => array_values($uangMuka->pluck('Nomor')->all()),
        ], idPengguna: $idPengguna);

        return $invoice->refresh();
    }

    /**
     * @return array<string, Uang>
     */
    private function RincianPajak(Invoice $invoice): array
    {
        return array_map(fn (string $n): Uang => Uang::Dari($n), $invoice->RincianPajak ?? []);
    }

    /**
     * Σ pendapatan baris per peran akun (Jasa → Pendapatan Jasa, Barang → Penjualan).
     *
     * @return array<string, Uang>
     */
    private function PendapatanPerPeran(Invoice $invoice): array
    {
        $hasil = [];

        foreach ($invoice->Detail()->get() as $baris) {
            $peran = $baris->JenisBaris->AmbilPeranPendapatan()->value;
            $hasil[$peran] = ($hasil[$peran] ?? Uang::Nol())->Tambah(Uang::Dari($baris->Pendapatan));
        }

        return $hasil;
    }
}
