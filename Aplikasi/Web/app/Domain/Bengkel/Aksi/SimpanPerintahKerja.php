<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Aksi;

use App\Domain\Bengkel\Data\DataPerintahKerja;
use App\Domain\Bengkel\Enum\JenisUnitServis;
use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Layanan\PenyusunBarisPerintahKerja;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerjaDetail;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Simpan perintah kerja bengkel (§9.10): buat baru (status Diterima, nomor `WO/{OUTLET}/{YYMM}/{SEQ4}`) atau ubah isi
 * selama belum ada persetujuan yang berlaku (Diterima, Diagnosis, Ditolak). Harga baris dari price engine server
 * (tier pelanggan) di-snapshot; angka estimasi dihitung mesin kalkulasi kasir (`PenyusunBarisPerintahKerja`).
 *
 * - **Tidak menyentuh stok maupun jurnal**: sparepart baru keluar saat ditagih di kasir.
 * - Mengubah estimasi perintah kerja yang ditolak mengembalikannya ke Diagnosis (estimasi baru perlu persetujuan baru),
 *   dan tautan persetujuan lama tidak berlaku lagi.
 * - KM masuk yang lebih besar menaikkan `KmTerakhir` kendaraan.
 */
final class SimpanPerintahKerja
{
    public function __construct(
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PenyusunBarisPerintahKerja $penyusun,
        private readonly PenomorDokumen $penomor,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataPerintahKerja $data, int $idPengguna, ?string $uuidPerintahKerja = null): PerintahKerja
    {
        $keluhan = trim($data->keluhan);

        if ($keluhan === '') {
            throw new PelanggaranAturanBisnis('KeluhanWajib', 'Keluhan pelanggan wajib diisi.', 'Keluhan');
        }

        return DB::transaction(function () use ($data, $idPengguna, $uuidPerintahKerja, $keluhan): PerintahKerja {
            $idPelanggan = $this->pelanggan->CariId($data->uuidPelanggan);

            if ($idPelanggan === null || ! $this->pelanggan->CekAktif($idPelanggan)) {
                throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan atau sudah diarsipkan.', 'UuidPelanggan');
            }

            $kendaraan = Kendaraan::query()->where('Uuid', $data->uuidKendaraan)->lockForUpdate()->first();

            if ($kendaraan === null || ! $kendaraan->Aktif) {
                throw new PelanggaranAturanBisnis('KendaraanTidakDikenal', 'Unit servis tidak ditemukan atau sudah diarsipkan.', 'UuidKendaraan');
            }

            if ($kendaraan->IdPelanggan !== $idPelanggan) {
                throw new PelanggaranAturanBisnis('KendaraanBukanMilikPelanggan', ucfirst($kendaraan->AmbilSebutan()).' terdaftar atas pelanggan lain.', 'UuidKendaraan');
            }

            $outlet = Outlet::query()->whereKey($data->idOutlet)->first()
                ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'UuidOutlet');

            $pk = $uuidPerintahKerja === null
                ? null
                : (PerintahKerja::query()->where('Uuid', $uuidPerintahKerja)->lockForUpdate()->first()
                    ?? throw new PelanggaranAturanBisnis('PerintahKerjaTidakDikenal', 'Perintah kerja tidak ditemukan.', 'Umum', 404));

            if ($pk !== null && ! $pk->Status->CekBolehDiubah()) {
                throw new PelanggaranAturanBisnis(
                    'PerintahKerjaTidakBisaDiubah',
                    "Perintah kerja {$pk->Nomor} berstatus {$pk->Status->AmbilLabel()}. Kembalikan ke Diagnosis dulu untuk merevisi estimasi.",
                );
            }

            $baris = $this->penyusun->Susun($data->baris, $outlet->Id, $this->pelanggan->AmbilKodeTier($idPelanggan));
            $hasil = $this->penyusun->Hitung($outlet->Id, $outlet->KodeKota, $baris);
            $lama = $pk?->only(['IdPelanggan', 'IdKendaraan', 'Keluhan', 'Total', 'Status']);

            $nilai = [
                'IdOutlet' => $outlet->Id,
                'IdPelanggan' => $idPelanggan,
                'IdKendaraan' => $kendaraan->Id,
                'KmMasuk' => $kendaraan->Jenis === JenisUnitServis::Kendaraan ? $data->kmMasuk : null,
                'Keluhan' => mb_substr($keluhan, 0, 2000),
                'Diagnosis' => $data->diagnosis === null || trim($data->diagnosis) === '' ? null : mb_substr(trim($data->diagnosis), 0, 2000),
                'EstimasiSelesaiPada' => $data->estimasiSelesaiPada?->utc(),
                'Subtotal' => $hasil->subtotal->KeString(),
                'Diskon' => $hasil->diskon->KeString(),
                'Pajak' => $hasil->pajak->KeString(),
                'Total' => $hasil->total->KeString(),
                // Estimasi berubah = persetujuan lama tidak berlaku.
                'TotalDisetujui' => '0.00',
                'TokenPersetujuan' => null,
                'HashTokenPersetujuan' => null,
                'TokenPersetujuanKedaluwarsaPada' => null,
            ];

            if ($pk === null) {
                $sekarang = CarbonImmutable::now();
                $pk = PerintahKerja::query()->create([
                    ...$nilai,
                    'Nomor' => $this->AmbilNomor($outlet, $sekarang),
                    'Status' => StatusPerintahKerja::Diterima,
                    'DibuatOleh' => $idPengguna,
                ]);
                $this->riwayat->Catat(PerintahKerja::JENIS_DOKUMEN, $pk->Id, null, StatusPerintahKerja::Diterima->value, $idPengguna);
            } else {
                $pk->fill($nilai);

                if ($pk->Status === StatusPerintahKerja::Ditolak) {
                    $pk->UbahStatus(StatusPerintahKerja::Diagnosis);
                    $this->riwayat->Catat(PerintahKerja::JENIS_DOKUMEN, $pk->Id, StatusPerintahKerja::Ditolak->value, StatusPerintahKerja::Diagnosis->value, $idPengguna, 'Estimasi direvisi');
                }

                $pk->save();
                // Isi belum disetujui siapa pun: baris diganti seluruhnya supaya urutan & snapshot harga konsisten.
                PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->delete();
            }

            foreach ($baris as $urutan => $b) {
                PerintahKerjaDetail::query()->create([
                    'IdPerintahKerja' => $pk->Id,
                    'Urutan' => $urutan + 1,
                    'Jenis' => $b['Jenis'],
                    'IdProduk' => $b['IdProduk'],
                    'NamaProduk' => $b['NamaProduk'],
                    'Sku' => $b['Sku'],
                    'IdProdukSatuan' => $b['IdProdukSatuan'],
                    'SimbolSatuan' => $b['SimbolSatuan'],
                    'Jumlah' => $b['Jumlah']->KeString(),
                    'HargaSatuan' => $b['HargaSatuan']->KeString(),
                    'Diskon' => $b['Diskon']->KeString(),
                    'Subtotal' => $b['HargaSatuan']->Kali($b['Jumlah']->KeString())->KeString(),
                    'HargaTermasukPajak' => $b['HargaTermasukPajak'],
                    'IdKelompokPajak' => $b['IdKelompokPajak'],
                    'IdKaryawan' => $b['IdKaryawan'],
                    'Catatan' => $b['Catatan'],
                    'NomorSeri' => $b['NomorSeri'],
                    'Disetujui' => false,
                ]);
            }

            if ($kendaraan->Jenis === JenisUnitServis::Kendaraan && $data->kmMasuk !== null && ($kendaraan->KmTerakhir === null || $data->kmMasuk > $kendaraan->KmTerakhir)) {
                $kendaraan->KmTerakhir = $data->kmMasuk;
                $kendaraan->save();
            }

            $this->audit->Catat($lama === null ? 'bengkel.perintah-kerja-buat' : 'bengkel.perintah-kerja-ubah', $pk, $lama, [
                'Nomor' => $pk->Nomor,
                'IdPelanggan' => $idPelanggan,
                'IdKendaraan' => $kendaraan->Id,
                'JumlahBaris' => count($baris),
                'Total' => $pk->Total,
                'Status' => $pk->Status->value,
            ], idPengguna: $idPengguna);

            return $pk->load('Detail');
        });
    }

    private function AmbilNomor(Outlet $outlet, CarbonImmutable $tanggal): string
    {
        $jenis = JenisDokumenBernomor::PerintahKerja;
        $urut = $this->penomor->AmbilBerikutnya($jenis, $tanggal->format('Y-m'), $outlet->Id);

        return sprintf('%s/%s/%s/%s', $jenis->AmbilAwalan(), mb_strtoupper($outlet->Kode), $tanggal->format('ym'), str_pad((string) $urut, $jenis->AmbilPanjangUrut(), '0', STR_PAD_LEFT));
    }
}
