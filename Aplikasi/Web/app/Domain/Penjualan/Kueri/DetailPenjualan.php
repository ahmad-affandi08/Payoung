<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Kasir\Kueri\InfoShift;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\KomposisiPenjualan;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\DaftarPerangkat;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Data\DataSudahDiretur;
use App\Domain\Penjualan\Enum\KodeAlasanTinjauan;
use App\Domain\Penjualan\Layanan\PenghitungNilaiRetur;
use App\Domain\Penjualan\Layanan\PetaMutasiPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPajak;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Penjualan\Model\VoidPenjualan;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Promo\Kueri\PemakaianPromo;

/**
 * Detail penjualan back-office (F-07b, izin `laporan.penjualan.lihat`): ringkasan dokumen, baris (snapshot harga,
 * diskon, pajak, HPP), rincian pajak, pembayaran, mutasi stok (tautan kartu stok), shift, dan jurnal. F-09: void
 * (alasan, kasir, penyetuju, refund, jeda sejak bayar), daftar retur, jumlah diretur per baris, dan mutasi pembalik
 * void. Penjualan tenant lain atau di outlet di luar akses = null (404).
 */
final class DetailPenjualan
{
    public function __construct(
        private readonly PetaUuidOutlet $outlet,
        private readonly DaftarPerangkat $perangkat,
        private readonly AnggotaOutlet $anggota,
        private readonly InfoShift $shift,
        private readonly JurnalSumber $jurnal,
        private readonly MutasiDokumen $mutasi,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly KomposisiPenjualan $komposisi,
        private readonly PenghitungNilaiRetur $penghitungRetur,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PemakaianPromo $pemakaianPromo,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>|null
     */
    public function Ambil(string $uuid, ?array $idOutletBoleh): ?array
    {
        $p = Penjualan::query()->where('Uuid', $uuid)->first();

        if ($p === null || ($idOutletBoleh !== null && ! in_array($p->IdOutlet, $idOutletBoleh, true))) {
            return null;
        }

        $detail = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();
        $void = VoidPenjualan::query()->where('IdPenjualan', $p->Id)->first();
        $retur = ReturPenjualan::query()->where('IdPenjualanAsal', $p->Id)->orderBy('Id')->get();
        $nama = $this->anggota->AmbilNama(array_values(array_filter([
            $p->IdPengguna,
            $p->IdPenyetujuDiskon,
            $void?->DivoidOleh,
            $void?->DisetujuiOleh,
            ...$retur->pluck('IdPengguna')->all(),
        ], fn (mixed $id): bool => is_int($id))));
        $sudah = $this->penghitungRetur->AmbilSudahDiretur(array_values(array_map('intval', $detail->pluck('Id')->all())));
        $jurnal = $this->jurnal->Ambil(JenisSumberJurnal::Penjualan, $p->Id);
        $simbol = $this->komposisi->AmbilSimbolSatuan(array_values($detail->pluck('IdSatuan')->all()));

        return [
            'Penjualan' => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Kanal' => $p->Kanal->value,
                'LabelKanal' => $p->Kanal->AmbilLabel(),
                'NamaOutlet' => $this->outlet->AmbilRingkas([$p->IdOutlet])[0]['Nama'] ?? '',
                'Perangkat' => $this->perangkat->AmbilLabel([$p->IdPerangkat])[$p->IdPerangkat] ?? '',
                'NamaKasir' => $nama[$p->IdPengguna]['Nama'] ?? '',
                // F-16a: pelanggan penjualan (null = tanpa pelanggan).
                'Pelanggan' => $this->pelanggan->AmbilRingkas($p->IdPelanggan),
                'NamaPenyetujuDiskon' => $p->IdPenyetujuDiskon === null ? null : ($nama[$p->IdPenyetujuDiskon]['Nama'] ?? ''),
                'DibuatOfflinePada' => $p->DibuatOfflinePada->toIso8601String(),
                'DiterimaPada' => $p->DiterimaPada->toIso8601String(),
                'TanggalBisnis' => $p->TanggalBisnis->toDateString(),
                'HargaTermasukPajak' => $p->HargaTermasukPajak,
                'PersenBiayaLayanan' => $p->PersenBiayaLayanan,
                'Subtotal' => $p->Subtotal,
                'DiskonBaris' => $p->DiskonBaris,
                'DiskonPesanan' => $p->DiskonPesanan,
                'PoinDitukar' => $p->PoinDitukar,
                'Promo' => $this->pemakaianPromo->AmbilPerPenjualan($p->Id),
                'DiskonPoin' => $p->DiskonPoin,
                'TotalDiskon' => $p->TotalDiskon,
                'BiayaLayanan' => $p->BiayaLayanan,
                'TotalPajak' => $p->TotalPajak,
                'Pembulatan' => $p->Pembulatan,
                'TotalAkhir' => $p->TotalAkhir,
                'TotalDibayar' => $p->TotalDibayar,
                'Kembalian' => $p->Kembalian,
                'TotalHpp' => $p->TotalHpp,
                'Catatan' => $p->Catatan,
                'NomorAntrian' => $p->NomorAntrian,
                'NamaPemesan' => $p->NamaPemesan,
                'PerluTinjauan' => $p->PerluTinjauan,
                'AlasanTinjauan' => $p->AlasanTinjauan,
                // PRD v1.46: alasan tinjauan dalam label manusiawi (kode mesin tidak ditampilkan).
                'DaftarAlasanTinjauan' => KodeAlasanTinjauan::Urai($p->AlasanTinjauan),
                'UuidShift' => $this->shift->AmbilBanyak([$p->IdShift])[$p->IdShift]->uuid ?? null,
            ],
            'Baris' => array_values($detail->map(fn (PenjualanDetail $d): array => [
                'Uuid' => $d->Uuid,
                'NamaProduk' => $d->NamaProduk,
                'Jumlah' => $d->Jumlah,
                'SimbolSatuan' => $simbol[$d->IdSatuan] ?? '',
                'HargaSatuan' => $d->HargaSatuan,
                // Price engine lapis 1: harga sebelum diubah kasir + alasannya (null = harga price engine).
                'HargaAsli' => $d->HargaAsli,
                'AlasanHargaManual' => $d->AlasanHargaManual,
                'HargaPilihan' => $d->HargaPilihan,
                'Pilihan' => array_values(array_map(fn (array $p): string => $p['Nama'], $d->Pilihan ?? [])),
                'Bruto' => $d->Bruto,
                'JumlahDiskon' => $d->JumlahDiskon,
                'JumlahDiskonPesanan' => $d->JumlahDiskonPesanan,
                'JumlahPajak' => $d->JumlahPajak,
                'TotalBaris' => $d->TotalBaris,
                'JumlahDiretur' => ($sudah[$d->Id] ?? DataSudahDiretur::Kosong())->jumlah->KeString(),
                'HppSatuan' => $d->HppSatuan,
                'TotalHpp' => $d->TotalHpp,
                'Catatan' => $d->Catatan,
                // Apotek bagian 3: komposisi racikan (jumlah per satu racikan, satuan dasar komponen).
                'Racikan' => $d->Racikan === null ? null : [
                    'Nama' => (string) ($d->Racikan['Nama'] ?? ''),
                    'JumlahKemasan' => (int) ($d->Racikan['JumlahKemasan'] ?? 0),
                    'AturanPakai' => $d->Racikan['AturanPakai'] ?? null,
                    'Komponen' => array_values(array_map(fn (array $k): array => [
                        'NamaProduk' => (string) $k['NamaProduk'],
                        'Jumlah' => (string) $k['JumlahDasar'],
                        'GolonganObat' => $k['GolonganObat'] ?? null,
                    ], (array) ($d->Racikan['Komponen'] ?? []))),
                ],
            ])->all()),
            'Pajak' => array_values(PenjualanPajak::query()->where('IdPenjualan', $p->Id)->orderBy('Id')->get()->map(fn (PenjualanPajak $pajak): array => [
                'KodeJenisPajak' => $pajak->KodeJenisPajak,
                'Tarif' => $pajak->Tarif,
                'DasarPengenaan' => $pajak->DasarPengenaan->AmbilLabel(),
                'Dpp' => $pajak->Dpp,
                'Jumlah' => $pajak->Jumlah,
            ])->all()),
            'Pembayaran' => array_values(PenjualanPembayaran::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get()->map(fn (PenjualanPembayaran $b): array => [
                'Uuid' => $b->Uuid,
                'NamaMetode' => $b->NamaMetode,
                'LabelJenis' => $b->JenisMetode->AmbilLabel(),
                'Jumlah' => $b->Jumlah,
                'Referensi' => $b->Referensi,
            ])->all()),
            'Void' => $void === null ? null : [
                'Uuid' => $void->Uuid,
                'Alasan' => $void->Alasan,
                'NamaKasir' => $nama[$void->DivoidOleh]['Nama'] ?? '',
                'NamaPenyetuju' => $nama[$void->DisetujuiOleh]['Nama'] ?? '',
                'DivoidPada' => $void->DivoidPada->toIso8601String(),
                'RefundTunai' => $void->RefundTunai,
                'RefundNonTunai' => $void->RefundNonTunai,
                'JedaDetik' => max(0, (int) $p->DibuatOfflinePada->diffInSeconds($void->DivoidPada, true)),
            ],
            'Retur' => array_values($retur->map(fn (ReturPenjualan $r): array => [
                'Uuid' => $r->Uuid,
                'Nomor' => $r->Nomor,
                'DibuatOfflinePada' => $r->DibuatOfflinePada->toIso8601String(),
                'NamaKasir' => $nama[$r->IdPengguna]['Nama'] ?? '',
                'Alasan' => $r->Alasan,
                'LabelMetodeRefund' => $r->MetodeRefund->AmbilLabel(),
                'TotalRefund' => $r->TotalRefund,
            ])->all()),
            'MutasiStok' => $this->AmbilMutasi($p->Id),
            'Jurnal' => array_map(fn (array $j): array => ['Uuid' => $j['Uuid'], 'Nomor' => $j['Nomor']], $jurnal),
        ];
    }

    /**
     * Mutasi penjualan diikuti mutasi pembalik void (retur punya halaman sendiri).
     *
     * @return list<array{Kunci: string, NamaProduk: string, NamaGudang: string, Jumlah: string, SimbolSatuan: string, TotalHpp: string, TautanKartuStok: string|null}>
     */
    private function AmbilMutasi(int $idPenjualan): array
    {
        return PetaMutasiPenjualan::Petakan([
            ...$this->mutasi->AmbilRingkasan(JenisReferensiMutasi::Penjualan, $idPenjualan),
            ...$this->mutasi->AmbilRingkasan(JenisReferensiMutasi::VoidPenjualan, $idPenjualan),
        ], $this->infoProduk, $this->infoGudang);
    }
}
