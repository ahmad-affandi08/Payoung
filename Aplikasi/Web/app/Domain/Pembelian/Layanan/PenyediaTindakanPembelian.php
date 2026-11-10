<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Layanan;

use App\Domain\Akuntansi\Kueri\SaldoUangLaci;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;

/**
 * Kotak Tindakan domain Pembelian (D-23 C, dibatasi outlet akses):
 * - hutang pemasok (faktur belum lunas) yang jatuh tempo dalam [HARI_HUTANG] hari atau sudah lewat (izin
 *   `pembelian.kelola`);
 * - draf PO otomatis dari stok menipis yang belum diajukan (D-23 D, izin `pembelian.kelola`);
 * - pesanan pembelian yang menunggu persetujuan (izin `pembelian.po.setujui`);
 * - uang laci kasir yang dicatat keluar untuk belanja stok tetapi barangnya belum dicatat, per outlet (D-256, izin
 *   `pembelian.kelola`).
 */
final class PenyediaTindakanPembelian implements PenyediaTindakan
{
    public const HARI_HUTANG = 7;

    public function __construct(
        private readonly SaldoUangLaci $saldoLaci,
        private readonly NamaTampilUsaha $namaUsaha,
    ) {}

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        $idOutlet = $konteks->idOutletBoleh;
        $butir = [];

        if ($konteks->CekIzin('pembelian.kelola')) {
            $faktur = FakturPembelian::query()
                ->whereIn('Status', [StatusFakturPembelian::BelumDibayar->value, StatusFakturPembelian::DibayarSebagian->value])
                ->where('JatuhTempo', '<=', $konteks->hariIni->addDays(self::HARI_HUTANG)->toDateString())
                ->when($idOutlet !== null, fn ($k) => $k->where(fn ($q) => $q->whereNull('IdOutlet')->orWhereIn('IdOutlet', $idOutlet)))
                ->orderBy('JatuhTempo')
                ->get();
            $total = Uang::Nol();

            foreach ($faktur as $f) {
                $total = $total->Tambah($f->AmbilSisa());
            }

            $hari = $konteks->hariIni->toDateString();
            $lewat = $faktur->filter(fn (FakturPembelian $f): bool => $f->JatuhTempo->toDateString() < $hari)->count();
            $butir[] = new DataButirTindakan(
                'hutang.jatuh-tempo',
                'Pembelian',
                $lewat > 0 ? TingkatTindakan::Penting : TingkatTindakan::Perhatian,
                'Hutang pemasok jatuh tempo',
                ($lewat > 0 ? "{$lewat} sudah lewat jatuh tempo. " : '').'Total sisa '.$total->FormatRupiah().' jatuh tempo dalam '.self::HARI_HUTANG.' hari.',
                $faktur->count(),
                '/kelola/pembelian/hutang',
                'Bayar hutang',
                array_values($faktur->take(DataButirTindakan::BATAS_RINCIAN)->map(fn (FakturPembelian $f): DataRincianTindakan => new DataRincianTindakan(
                    $f->Uuid,
                    $f->Nomor,
                    'Sisa '.$f->AmbilSisa()->FormatRupiah().', jatuh tempo '.$f->JatuhTempo->toDateString(),
                    $f->JatuhTempo->toDateString(),
                    '/kelola/pembelian/faktur/'.$f->Uuid,
                ))->all()),
            );
        }

        if ($konteks->CekIzin('pembelian.kelola')) {
            // D-23 D: draf PO yang disiapkan sistem dari stok di bawah minimum, menunggu diperiksa & diajukan.
            $draf = PesananPembelian::query()
                ->where('Status', StatusPesananPembelian::Draf->value)
                ->where('DibuatOtomatis', true)
                ->when($idOutlet !== null, fn ($k) => $k->where(fn ($q) => $q->whereNull('IdOutlet')->orWhereIn('IdOutlet', $idOutlet)));
            $jumlahDraf = (clone $draf)->count();
            $butir[] = new DataButirTindakan(
                'pesanan-pembelian.draf-otomatis',
                'Pembelian',
                TingkatTindakan::Perhatian,
                'Draf pesanan untuk stok menipis',
                'Disiapkan otomatis ke pemasok & harga pembelian terakhir. Periksa jumlahnya lalu ajukan.',
                $jumlahDraf,
                '/kelola/pembelian/pesanan?saring[Status]=Draf',
                'Periksa draf',
                $jumlahDraf === 0 ? [] : array_values($draf->orderBy('Id')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                    fn (PesananPembelian $p): DataRincianTindakan => new DataRincianTindakan($p->Uuid, $p->Nomor, 'Total '.Uang::Dari($p->Total)->FormatRupiah(), $p->Tanggal->toDateString(), '/kelola/pembelian/pesanan/'.$p->Uuid),
                )->all()),
            );
        }

        if ($konteks->CekIzin('pembelian.kelola')) {
            // D-256: kasir mencatat uang laci yang dipakai belanja stok sebagai kas keluar. Selama saldo penampung belum
            // nol, ada belanja yang barangnya belum masuk stok dan HPP-nya belum terbentuk.
            $rincian = [];
            $total = Uang::Nol();

            foreach ($this->saldoLaci->PerOutlet() as $idOutletLaci => $saldo) {
                if ($idOutlet !== null && ! in_array($idOutletLaci, $idOutlet, true)) {
                    continue;
                }

                $total = $total->Tambah($saldo);
                $rincian[] = new DataRincianTindakan(
                    'outlet-'.$idOutletLaci,
                    $this->namaUsaha->UntukOutlet($idOutletLaci, $konteks->idTenant),
                    $saldo->FormatRupiah().' belum dicatat barangnya',
                    null,
                    '/kelola/pembelian/belanja-stok',
                );
            }

            $butir[] = new DataButirTindakan(
                'belanja-stok.uang-laci',
                'Pembelian',
                TingkatTindakan::Perhatian,
                'Uang laci dipakai belanja stok',
                $total->FormatRupiah().' sudah keluar dari laci kasir untuk belanja stok, tetapi barangnya belum dicatat. Catat lewat Belanja stok dengan pembayaran "Pakai uang laci kasir" supaya stok dan HPP-nya terbentuk.',
                count($rincian),
                '/kelola/pembelian/belanja-stok',
                'Catat barangnya',
                array_slice($rincian, 0, DataButirTindakan::BATAS_RINCIAN),
            );
        }

        if ($konteks->CekIzin('pembelian.po.setujui')) {
            $po = PesananPembelian::query()
                ->where('Status', StatusPesananPembelian::MenungguPersetujuan->value)
                ->when($idOutlet !== null, fn ($k) => $k->where(fn ($q) => $q->whereNull('IdOutlet')->orWhereIn('IdOutlet', $idOutlet)));
            $jumlah = (clone $po)->count();
            $butir[] = new DataButirTindakan(
                'pesanan-pembelian.menunggu-persetujuan',
                'Pembelian',
                TingkatTindakan::Perhatian,
                'Pesanan pembelian menunggu persetujuan',
                'Setujui atau tolak agar bisa dikirim ke pemasok.',
                $jumlah,
                '/kelola/pembelian/pesanan?saring[Status]=MenungguPersetujuan',
                'Lihat pesanan',
                $jumlah === 0 ? [] : array_values($po->orderBy('Id')->limit(DataButirTindakan::BATAS_RINCIAN)->get()->map(
                    fn (PesananPembelian $p): DataRincianTindakan => new DataRincianTindakan($p->Uuid, $p->Nomor, null, null, '/kelola/pembelian/pesanan/'.$p->Uuid),
                )->all()),
            );
        }

        return $butir;
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
