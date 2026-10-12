<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PembayaranHutangAlokasi;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use App\Domain\Pembelian\Model\ReturPembelian;
use App\Domain\Pembelian\Model\ReturPembelianDetail;
use Illuminate\Support\Collection;

/**
 * Props halaman detail & formulir turunan dokumen pembelian (F-04 fase 1; tipe FE di `Tipe/Pembelian.ts`): dokumen,
 * baris (3-way matching PO–GRN–faktur), dokumen terkait, jurnal (`JurnalSumber`), dan riwayat status. Uang sebagai
 * string desimal; `PathLampiran` tidak pernah dikirim.
 */
final class DetailPembelian
{
    public function __construct(
        private readonly PetaNamaPembelian $peta,
        private readonly JurnalSumber $jurnal,
        private readonly DaftarAkunPilihan $akun,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Pesanan(PesananPembelian $po): array
    {
        $gudang = $this->peta->Gudang([$po->IdGudang])[$po->IdGudang] ?? null;
        $pengguna = $this->peta->Pengguna([$po->DibuatOleh, $po->DisetujuiOleh, $po->DibatalkanOleh]);
        $pemasok = $po->Pemasok;

        return [
            'Pesanan' => [
                'Uuid' => $po->Uuid,
                'Nomor' => $po->Nomor,
                'Tanggal' => $po->Tanggal->format('Y-m-d'),
                'PerkiraanTiba' => $po->PerkiraanTiba?->format('Y-m-d'),
                'Status' => $po->Status->value,
                'LabelStatus' => $po->Status->AmbilLabel(),
                'Pemasok' => ['Uuid' => $pemasok->Uuid, 'Kode' => $pemasok->Kode, 'Nama' => $pemasok->Nama, 'Alamat' => $pemasok->Alamat, 'NoHp' => $pemasok->NoHp, 'Npwp' => $pemasok->Npwp, 'Pkp' => $po->Pkp],
                'UuidGudang' => $gudang?->uuid,
                'NamaGudang' => $gudang->nama ?? '',
                'NamaOutlet' => $gudang?->namaOutlet,
                'TerminHari' => $po->TerminHari,
                'TarifPpn' => $po->TarifPpn,
                'Subtotal' => $po->Subtotal,
                'Diskon' => $po->Diskon,
                'Pajak' => $po->Pajak,
                'Ongkir' => $po->Ongkir,
                'Total' => $po->Total,
                'Catatan' => $po->Catatan,
                'AlasanDitolak' => $po->AlasanDitolak,
                'AlasanBatal' => $po->AlasanBatal,
                'DibuatOleh' => $po->DibuatOleh === null ? null : ($pengguna[$po->DibuatOleh] ?? null),
                'DisetujuiOleh' => $po->DisetujuiOleh === null ? null : ($pengguna[$po->DisetujuiOleh] ?? null),
                'DisetujuiPada' => $po->DisetujuiPada?->toIso8601String(),
                'IdPembuat' => $po->DibuatOleh,
            ],
            'Baris' => array_values($po->Detail()->get()->map(fn (PesananPembelianDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Konversi' => $d->Konversi,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
                'JumlahDiterima' => $d->JumlahDiterima,
                'Sisa' => self::TidakNegatif(Kuantitas::Dari($d->Jumlah)->Kurangi(Kuantitas::Dari($d->JumlahDiterima)))->KeString(),
            ])->all()),
            'Penerimaan' => $this->RingkasPenerimaan(PenerimaanBarang::query()->where('IdPesananPembelian', $po->Id)->orderBy('Id')->get()),
            'Riwayat' => $this->Riwayat(PesananPembelian::JENIS_DOKUMEN, $po->Id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Penerimaan(PenerimaanBarang $grn): array
    {
        $gudang = $this->peta->Gudang([$grn->IdGudang])[$grn->IdGudang] ?? null;
        $pemasok = $grn->IdPemasok === null ? null : ($this->peta->Pemasok([$grn->IdPemasok])[$grn->IdPemasok] ?? null);
        $pengguna = $this->peta->Pengguna([$grn->DibuatOleh, $grn->DibatalkanOleh]);
        $po = $grn->IdPesananPembelian === null ? null : PesananPembelian::query()->whereKey($grn->IdPesananPembelian)->first();
        $detailPo = $po === null ? collect() : PesananPembelianDetail::query()->where('IdPesananPembelian', $po->Id)->get()->keyBy('Id');
        $faktur = $grn->IdFakturPembelian === null ? null : FakturPembelian::query()->whereKey($grn->IdFakturPembelian)->first();
        $seriDiretur = [];

        foreach (ReturPembelianDetail::query()
            ->whereIn('IdReturPembelian', ReturPembelian::query()->where('IdPenerimaanBarang', $grn->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->select('Id'))
            ->get(['IdPenerimaanBarangDetail', 'DaftarNomorSeri']) as $r) {
            $seriDiretur[$r->IdPenerimaanBarangDetail] = [...($seriDiretur[$r->IdPenerimaanBarangDetail] ?? []), ...($r->DaftarNomorSeri ?? [])];
        }

        return [
            'Penerimaan' => [
                'Uuid' => $grn->Uuid,
                'Nomor' => $grn->Nomor,
                'Tanggal' => $grn->Tanggal->format('Y-m-d'),
                'Status' => $grn->Status->value,
                'LabelStatus' => $grn->Status->AmbilLabel(),
                'Pemasok' => $pemasok,
                'Pesanan' => $po === null ? null : ['Uuid' => $po->Uuid, 'Nomor' => $po->Nomor],
                'Faktur' => $faktur === null ? null : ['Uuid' => $faktur->Uuid, 'Nomor' => $faktur->Nomor, 'Status' => $faktur->Status->value],
                'NamaGudang' => $gudang->nama ?? '',
                'NamaOutlet' => $gudang?->namaOutlet,
                'NomorSuratJalan' => $grn->NomorSuratJalan,
                'Catatan' => $grn->Catatan,
                'TarifPpn' => $grn->TarifPpn,
                'PpnDikreditkan' => $grn->PpnDikreditkan,
                'Subtotal' => $grn->Subtotal,
                'Ongkir' => $grn->Ongkir,
                'Pajak' => $grn->Pajak,
                'TotalNilai' => $grn->TotalNilai,
                'BelanjaStok' => $grn->BelanjaStok,
                'Lampiran' => $grn->PathLampiran === null ? null : ['Nama' => (string) $grn->NamaLampiran, 'Ukuran' => (int) $grn->UkuranLampiran],
                'AlasanBatal' => $grn->AlasanBatal,
                'DibuatOleh' => $grn->DibuatOleh === null ? null : ($pengguna[$grn->DibuatOleh] ?? null),
                'DibatalkanOleh' => $grn->DibatalkanOleh === null ? null : ($pengguna[$grn->DibatalkanOleh] ?? null),
            ],
            'Baris' => array_values($grn->Detail()->get()->map(function (PenerimaanBarangDetail $d) use ($detailPo, $seriDiretur): array {
                /** @var PesananPembelianDetail|null $p */
                $p = $d->IdPesananPembelianDetail === null ? null : $detailPo->get($d->IdPesananPembelianDetail);

                return [
                    'Id' => $d->Id,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'SimbolSatuan' => $d->SimbolSatuan,
                    'Konversi' => $d->Konversi,
                    'JumlahPesanan' => $p?->Jumlah,
                    'Jumlah' => $d->Jumlah,
                    'JumlahDasar' => $d->JumlahDasar,
                    'Harga' => $d->Harga,
                    'Diskon' => $d->Diskon,
                    'Subtotal' => $d->Subtotal,
                    'AlokasiBiaya' => $d->AlokasiBiaya,
                    'Nilai' => $d->Nilai,
                    'HppSatuan' => $d->HppSatuan,
                    'NomorBatch' => $d->NomorBatch,
                    'TanggalKedaluwarsa' => $d->TanggalKedaluwarsa?->format('Y-m-d'),
                    'NomorSeri' => array_values($d->DaftarNomorSeri ?? []),
                    'NomorSeriBisaDiretur' => array_values(array_diff($d->DaftarNomorSeri ?? [], $seriDiretur[$d->Id] ?? [])),
                    'JumlahDiretur' => $d->JumlahDiretur,
                    'SisaBisaDiretur' => self::TidakNegatif(Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur)))->KeString(),
                ];
            })->all()),
            'Retur' => array_values(ReturPembelian::query()->where('IdPenerimaanBarang', $grn->Id)->orderBy('Id')->get()->map(fn (ReturPembelian $r): array => [
                'Uuid' => $r->Uuid, 'Nomor' => $r->Nomor, 'Tanggal' => $r->Tanggal->format('Y-m-d'), 'Status' => $r->Status->value, 'LabelStatus' => $r->Status->AmbilLabel(),
                'Total' => Uang::Dari($r->NilaiHutang)->Tambah(Uang::Dari($r->Pajak))->KeString(),
            ])->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::PenerimaanBarang, $grn->Id),
            'Riwayat' => $this->Riwayat(PenerimaanBarang::JENIS_DOKUMEN, $grn->Id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Faktur(FakturPembelian $f): array
    {
        $pemasok = $f->IdPemasok === null ? null : ($this->peta->Pemasok([$f->IdPemasok])[$f->IdPemasok] ?? null);
        $pengguna = $this->peta->Pengguna([$f->DibuatOleh, $f->DibatalkanOleh]);
        $detail = FakturPembelianDetail::query()->where('IdFakturPembelian', $f->Id)->orderBy('Urutan')->get();
        $grnDetail = PenerimaanBarangDetail::query()->whereIn('Id', $detail->pluck('IdPenerimaanBarangDetail')->all())->get()->keyBy('Id');
        $grn = PenerimaanBarang::query()->whereIn('Id', $grnDetail->pluck('IdPenerimaanBarang')->unique()->values()->all())->get()->keyBy('Id');
        $poDetail = PesananPembelianDetail::query()->whereIn('Id', $grnDetail->pluck('IdPesananPembelianDetail')->filter()->values()->all())->get()->keyBy('Id');
        $po = PesananPembelian::query()->whereIn('Id', $grn->pluck('IdPesananPembelian')->filter()->unique()->values()->all())->get()->keyBy('Id');
        $alokasi = PembayaranHutangAlokasi::query()->where('IdFakturPembelian', $f->Id)->get();
        $pembayaran = PembayaranHutang::query()->whereIn('Id', $alokasi->pluck('IdPembayaranHutang')->all())->orderBy('Id')->get();

        return [
            'Faktur' => [
                'Uuid' => $f->Uuid,
                'Nomor' => $f->Nomor,
                'NomorFakturPemasok' => $f->NomorFakturPemasok,
                'Tanggal' => $f->Tanggal->format('Y-m-d'),
                'JatuhTempo' => $f->JatuhTempo->format('Y-m-d'),
                'TerminHari' => $f->TerminHari,
                'Status' => $f->Status->value,
                'LabelStatus' => $f->Status->AmbilLabel(),
                'Pemasok' => $pemasok,
                'TarifPpn' => $f->TarifPpn,
                'PpnDikreditkan' => $f->PpnDikreditkan,
                'NilaiPenerimaan' => $f->NilaiPenerimaan,
                'Subtotal' => $f->Subtotal,
                'Ongkir' => $f->Ongkir,
                'Pajak' => $f->Pajak,
                'SelisihHarga' => $f->SelisihHarga,
                'Total' => $f->Total,
                'JumlahDibayar' => $f->JumlahDibayar,
                'JumlahRetur' => $f->JumlahRetur,
                'Sisa' => $f->Status === StatusFakturPembelian::Dibatalkan ? '0.00' : $f->AmbilSisa()->KeString(),
                'BelanjaStok' => $f->BelanjaStok,
                'Catatan' => $f->Catatan,
                'Lampiran' => $f->PathLampiran === null ? null : ['Nama' => (string) $f->NamaLampiran, 'Ukuran' => (int) $f->UkuranLampiran],
                'AlasanBatal' => $f->AlasanBatal,
                'DibuatOleh' => $f->DibuatOleh === null ? null : ($pengguna[$f->DibuatOleh] ?? null),
            ],
            'Baris' => array_values($detail->map(function (FakturPembelianDetail $d) use ($grnDetail, $grn, $poDetail, $po): array {
                /** @var PenerimaanBarangDetail|null $g */
                $g = $grnDetail->get($d->IdPenerimaanBarangDetail);
                /** @var PesananPembelianDetail|null $p */
                $p = $g?->IdPesananPembelianDetail === null ? null : $poDetail->get($g->IdPesananPembelianDetail);
                $dokumenGrn = $g === null ? null : $grn->get($g->IdPenerimaanBarang);
                $dokumenPo = $dokumenGrn?->IdPesananPembelian === null ? null : $po->get($dokumenGrn->IdPesananPembelian);

                return [
                    'Id' => $d->Id,
                    'NamaProduk' => $d->NamaProduk,
                    'SimbolSatuan' => $d->SimbolSatuan,
                    'NomorPesanan' => $dokumenPo?->Nomor,
                    'NomorPenerimaan' => $dokumenGrn?->Nomor,
                    'JumlahPesanan' => $p?->Jumlah,
                    'HargaPesanan' => $p?->Harga,
                    'JumlahDiterima' => $g?->Jumlah,
                    'Jumlah' => $d->Jumlah,
                    'HargaPenerimaan' => $d->HargaPenerimaan,
                    'Harga' => $d->Harga,
                    'Diskon' => $d->Diskon,
                    'Subtotal' => $d->Subtotal,
                    'SelisihHarga' => Uang::Dari($d->Subtotal)->Kurangi(Uang::Dari($d->SubtotalPenerimaan))->KeString(),
                    'Pajak' => $d->Pajak,
                    'JumlahDiretur' => $d->JumlahDiretur,
                ];
            })->all()),
            'Penerimaan' => $this->RingkasPenerimaan($grn->values()),
            'Pembayaran' => array_values($pembayaran->map(fn (PembayaranHutang $p): array => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Jumlah' => (string) $alokasi->firstWhere('IdPembayaranHutang', $p->Id)?->Jumlah,
            ])->all()),
            'Retur' => array_values(ReturPembelian::query()->where('IdFakturPembelian', $f->Id)->orderBy('Id')->get()->map(fn (ReturPembelian $r): array => [
                'Uuid' => $r->Uuid, 'Nomor' => $r->Nomor, 'Tanggal' => $r->Tanggal->format('Y-m-d'), 'Status' => $r->Status->value, 'LabelStatus' => $r->Status->AmbilLabel(),
                'Total' => Uang::Dari($r->NilaiHutang)->Tambah(Uang::Dari($r->Pajak))->KeString(),
            ])->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::FakturPembelian, $f->Id),
            'Riwayat' => $this->Riwayat(FakturPembelian::JENIS_DOKUMEN, $f->Id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Pembayaran(PembayaranHutang $p): array
    {
        $pemasok = $p->IdPemasok === null ? null : ($this->peta->Pemasok([$p->IdPemasok])[$p->IdPemasok] ?? null);
        $akun = $this->akun->AmbilBanyak([$p->IdAkun])[$p->IdAkun] ?? null;
        $pengguna = $this->peta->Pengguna([$p->DibuatOleh, $p->DibatalkanOleh]);
        $alokasi = PembayaranHutangAlokasi::query()->where('IdPembayaranHutang', $p->Id)->orderBy('Id')->get();
        $faktur = FakturPembelian::query()->whereIn('Id', $alokasi->pluck('IdFakturPembelian')->all())->get()->keyBy('Id');

        return [
            'Pembayaran' => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Pemasok' => $pemasok,
                'Akun' => $akun === null ? null : "{$akun['Kode']} {$akun['Nama']}",
                'Jumlah' => $p->Jumlah,
                'BelanjaStok' => $p->BelanjaStok,
                'Kompensasi' => $p->Kompensasi,
                'Catatan' => $p->Catatan,
                'Lampiran' => $p->PathLampiran === null ? null : ['Nama' => (string) $p->NamaLampiran, 'Ukuran' => (int) $p->UkuranLampiran],
                'AlasanBatal' => $p->AlasanBatal,
                'DibuatOleh' => $p->DibuatOleh === null ? null : ($pengguna[$p->DibuatOleh] ?? null),
            ],
            'Alokasi' => array_values($alokasi->map(function (PembayaranHutangAlokasi $a) use ($faktur): array {
                /** @var FakturPembelian|null $f */
                $f = $faktur->get($a->IdFakturPembelian);

                return [
                    'UuidFaktur' => $f?->Uuid,
                    'NomorFaktur' => $f?->Nomor,
                    'NomorFakturPemasok' => $f?->NomorFakturPemasok,
                    'JatuhTempo' => $f?->JatuhTempo->format('Y-m-d'),
                    'Jumlah' => $a->Jumlah,
                ];
            })->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::PembayaranHutang, $p->Id),
            'Riwayat' => $this->Riwayat(PembayaranHutang::JENIS_DOKUMEN, $p->Id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Retur(ReturPembelian $r): array
    {
        $pemasok = $r->IdPemasok === null ? null : ($this->peta->Pemasok([$r->IdPemasok])[$r->IdPemasok] ?? null);
        $gudang = $this->peta->Gudang([$r->IdGudang])[$r->IdGudang] ?? null;
        $pengguna = $this->peta->Pengguna([$r->DibuatOleh]);
        $grn = PenerimaanBarang::query()->whereKey($r->IdPenerimaanBarang)->first();
        $faktur = $r->IdFakturPembelian === null ? null : FakturPembelian::query()->whereKey($r->IdFakturPembelian)->first();

        return [
            'Retur' => [
                'Uuid' => $r->Uuid,
                'Nomor' => $r->Nomor,
                'Tanggal' => $r->Tanggal->format('Y-m-d'),
                'Status' => $r->Status->value,
                'LabelStatus' => $r->Status->AmbilLabel(),
                'Alasan' => $r->Alasan,
                'Pemasok' => $pemasok,
                'Penerimaan' => $grn === null ? null : ['Uuid' => $grn->Uuid, 'Nomor' => $grn->Nomor],
                'Faktur' => $faktur === null ? null : ['Uuid' => $faktur->Uuid, 'Nomor' => $faktur->Nomor],
                'NamaGudang' => $gudang->nama ?? '',
                'NilaiBarang' => $r->NilaiBarang,
                'NilaiHutang' => $r->NilaiHutang,
                'Pajak' => $r->Pajak,
                'Total' => Uang::Dari($r->NilaiHutang)->Tambah(Uang::Dari($r->Pajak))->KeString(),
                'NilaiRefund' => $r->NilaiRefund,
                'AlasanBatal' => $r->AlasanBatal,
                'DibuatOleh' => $r->DibuatOleh === null ? null : ($pengguna[$r->DibuatOleh] ?? null),
            ],
            'Baris' => array_values(ReturPembelianDetail::query()->where('IdReturPembelian', $r->Id)->orderBy('Urutan')->get()->map(fn (ReturPembelianDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $d->NamaProduk,
                'SimbolSatuan' => $d->SimbolSatuan,
                'JumlahDasar' => $d->JumlahDasar,
                'Nilai' => $d->Nilai,
                'NilaiHutang' => $d->NilaiHutang,
                'Pajak' => $d->Pajak,
                'NomorSeri' => array_values($d->DaftarNomorSeri ?? []),
            ])->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::ReturPembelian, $r->Id),
            'Riwayat' => $this->Riwayat(ReturPembelian::JENIS_DOKUMEN, $r->Id),
        ];
    }

    /**
     * GRN Diposting (bukan belanja stok) pemasok ini yang belum difakturkan, di outlet yang boleh, untuk form faktur.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array<string, mixed>>
     */
    public function PenerimaanBelumDifakturkan(int $idPemasok, ?array $idOutletBoleh): array
    {
        $grn = PenerimaanBarang::query()
            ->where('IdPemasok', $idPemasok)
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->where('BelanjaStok', false)
            ->whereNull('IdFakturPembelian')
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->limit(200)
            ->get();
        $detail = PenerimaanBarangDetail::query()->whereIn('IdPenerimaanBarang', $grn->pluck('Id')->all())->orderBy('Urutan')->get()->groupBy('IdPenerimaanBarang');
        $gudang = $this->peta->Gudang($grn->pluck('IdGudang')->all());

        return array_values($grn->map(fn (PenerimaanBarang $g): array => [
            'Uuid' => $g->Uuid,
            'Nomor' => $g->Nomor,
            'Tanggal' => $g->Tanggal->format('Y-m-d'),
            'NamaOutlet' => $gudang[$g->IdGudang]->namaOutlet ?? null,
            'Ongkir' => $g->Ongkir,
            'Pkp' => $g->Pkp,
            'TerminHari' => $g->TerminHari,
            'Baris' => array_values(($detail->get($g->Id) ?? collect())->map(fn (PenerimaanBarangDetail $d): array => [
                'Id' => $d->Id,
                'NamaProduk' => $d->NamaProduk,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'JumlahDasar' => $d->JumlahDasar,
                'JumlahDiretur' => $d->JumlahDiretur,
                'Konversi' => $d->Konversi,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
            ])->all()),
        ])->all());
    }

    /**
     * Faktur terbuka (bukan belanja stok) pemasok ini di outlet yang boleh, urut jatuh tempo, untuk form pembayaran.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, NomorFakturPemasok: string, Tanggal: string, JatuhTempo: string, Total: string, Sisa: string}>
     */
    public function FakturTerbuka(int $idPemasok, ?array $idOutletBoleh): array
    {
        return array_values(FakturPembelian::query()
            ->where('IdPemasok', $idPemasok)
            ->whereIn('Status', [StatusFakturPembelian::BelumDibayar->value, StatusFakturPembelian::DibayarSebagian->value])
            ->where('BelanjaStok', false)
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('JatuhTempo')
            ->orderBy('Id')
            ->limit(200)
            ->get()
            ->map(fn (FakturPembelian $f): array => [
                'Uuid' => $f->Uuid,
                'Nomor' => $f->Nomor,
                'NomorFakturPemasok' => $f->NomorFakturPemasok,
                'Tanggal' => $f->Tanggal->format('Y-m-d'),
                'JatuhTempo' => $f->JatuhTempo->format('Y-m-d'),
                'Total' => $f->Total,
                'Sisa' => $f->AmbilSisa()->KeString(),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, PenerimaanBarang>  $grn
     * @return list<array{Uuid: string, Nomor: string, Tanggal: string, Status: string, LabelStatus: string, TotalNilai: string}>
     */
    private function RingkasPenerimaan(Collection $grn): array
    {
        return array_values($grn->map(fn (PenerimaanBarang $g): array => [
            'Uuid' => $g->Uuid,
            'Nomor' => $g->Nomor,
            'Tanggal' => $g->Tanggal->format('Y-m-d'),
            'Status' => $g->Status->value,
            'LabelStatus' => $g->Status->AmbilLabel(),
            'TotalNilai' => $g->TotalNilai,
        ])->all());
    }

    /**
     * @return list<array{StatusKe: string, Oleh: string|null, Pada: string, Alasan: string|null}>
     */
    private function Riwayat(string $jenis, int $id): array
    {
        $riwayat = RiwayatStatusDokumen::query()->where('JenisDokumen', $jenis)->where('IdDokumen', $id)->orderBy('Id')->get();
        $nama = $this->peta->Pengguna($riwayat->pluck('DiubahOleh')->all());

        return array_values($riwayat->map(fn (RiwayatStatusDokumen $r): array => [
            'StatusKe' => (string) $r->StatusKe,
            'Oleh' => $r->DiubahOleh === null ? null : ($nama[$r->DiubahOleh] ?? null),
            'Pada' => $r->DiubahPada?->toIso8601String() ?? '',
            'Alasan' => $r->Alasan,
        ])->all());
    }

    private static function TidakNegatif(Kuantitas $q): Kuantitas
    {
        return $q->BernilaiNegatif() ? Kuantitas::Nol() : $q;
    }
}
