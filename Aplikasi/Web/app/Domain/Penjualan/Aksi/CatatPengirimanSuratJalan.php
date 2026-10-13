<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Penjualan\Data\DataPengirimanGrosir;
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use App\Domain\Penjualan\Model\Armada;
use App\Domain\Penjualan\Model\SuratJalan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat kabar pengiriman satu surat jalan grosir (F-12 §9.7): berangkat (pilih armada & pengemudi), diterima
 * (penerima + catatan), gagal kirim (alasan), atau batal berangkat. Ini kabar lapangan, **bukan** perubahan dokumen
 * akuntansi: angka, stok, dan jurnal surat jalan tidak tersentuh, dan statusnya (`StatusPengiriman`) dicatat di
 * `RiwayatStatusDokumen` sebagai jejak waktu. Surat jalan yang dibatalkan tidak punya pengiriman.
 * Nama pengemudi/nomor kendaraan/nama penerima disalin ke kolom yang tercetak di surat jalan.
 */
final class CatatPengirimanSuratJalan
{
    public const JENIS_RIWAYAT = 'PengirimanSuratJalan';

    public function __construct(
        private readonly ZonaWaktuOutlet $zona,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis SuratJalanTidakDitemukan, SuratJalanDibatalkan, StatusPengirimanTidakValid,
     *                                 ArmadaTidakDitemukan, PenerimaWajib, AlasanGagalWajib, WaktuTerimaTidakValid
     */
    public function Jalankan(DataPengirimanGrosir $data, int $idPengguna): SuratJalan
    {
        return DB::transaction(fn (): SuratJalan => $this->Catat($data, $idPengguna), 3);
    }

    private function Catat(DataPengirimanGrosir $data, int $idPengguna): SuratJalan
    {
        $suratJalan = SuratJalan::query()->where('Uuid', $data->uuidSuratJalan)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('SuratJalanTidakDitemukan', 'Surat jalan tidak ditemukan.');

        if ($suratJalan->Status !== StatusDokumenTerposting::Diposting) {
            throw new PelanggaranAturanBisnis('SuratJalanDibatalkan', "Surat jalan {$suratJalan->Nomor} sudah dibatalkan, jadi tidak ada pengiriman yang dicatat.");
        }

        $asal = $suratJalan->StatusPengiriman;

        if (! $asal->BisaBerubahKe($data->tujuan)) {
            throw new PelanggaranAturanBisnis(
                'StatusPengirimanTidakValid',
                "Pengiriman berstatus {$asal->AmbilLabel()} tidak bisa diubah menjadi {$data->tujuan->AmbilLabel()}.",
            );
        }

        $catatan = $data->catatan === null || trim($data->catatan) === '' ? null : mb_substr(trim($data->catatan), 0, 500);
        $isi = ['StatusPengiriman' => $data->tujuan, 'DiubahOleh' => $idPengguna];

        switch ($data->tujuan) {
            case StatusPengirimanGrosir::DalamPerjalanan:
                $armada = $data->uuidArmada === null || $data->uuidArmada === ''
                    ? null
                    : (Armada::query()->where('Uuid', $data->uuidArmada)->where('Aktif', true)->first()
                        ?? throw new PelanggaranAturanBisnis('ArmadaTidakDitemukan', 'Armada tidak ditemukan atau sudah dinonaktifkan.', 'UuidArmada'));
                $pengemudi = $data->namaPengemudi !== null && trim($data->namaPengemudi) !== '' ? trim($data->namaPengemudi) : $armada?->NamaPengemudi;
                $isi += [
                    'IdArmada' => $armada?->Id,
                    'BerangkatPada' => CarbonImmutable::now(),
                    'DiterimaPada' => null,
                    'NamaPengirim' => $pengemudi !== null ? mb_substr($pengemudi, 0, 100) : $suratJalan->NamaPengirim,
                    'NomorKendaraan' => $armada === null ? $suratJalan->NomorKendaraan : $armada->NomorKendaraan,
                    'CatatanPengiriman' => $catatan,
                ];

                break;
            case StatusPengirimanGrosir::Diterima:
                $penerima = $data->namaPenerima === null ? '' : trim($data->namaPenerima);

                if ($penerima === '') {
                    throw new PelanggaranAturanBisnis('PenerimaWajib', 'Isi nama penerima barang.', 'NamaPenerima');
                }

                $waktu = $data->waktuLokal === null || trim($data->waktuLokal) === ''
                    ? CarbonImmutable::now()
                    : CarbonImmutable::createFromFormat('!Y-m-d H:i', trim($data->waktuLokal), $this->zona->Ambil($suratJalan->IdOutlet));

                if (! $waktu instanceof CarbonImmutable || $waktu->isFuture()) {
                    throw new PelanggaranAturanBisnis('WaktuTerimaTidakValid', 'Waktu diterima tidak boleh di masa depan.', 'Waktu');
                }

                $isi += ['DiterimaPada' => $waktu, 'NamaPenerima' => mb_substr($penerima, 0, 100), 'CatatanPengiriman' => $catatan];

                break;
            case StatusPengirimanGrosir::Gagal:
                if ($catatan === null || mb_strlen($catatan) < 5) {
                    throw new PelanggaranAturanBisnis('AlasanGagalWajib', 'Tulis alasan gagal kirim, minimal 5 karakter.', 'Catatan');
                }

                $isi += ['CatatanPengiriman' => $catatan];

                break;
            case StatusPengirimanGrosir::Disiapkan:
                $isi += ['BerangkatPada' => null, 'CatatanPengiriman' => $catatan];

                break;
        }

        $suratJalan->fill($isi)->save();
        $this->riwayat->Catat(self::JENIS_RIWAYAT, $suratJalan->Id, $asal->value, $data->tujuan->value, $idPengguna, $catatan);
        $this->audit->Catat('grosir.pengiriman-ubah', $suratJalan, ['StatusPengiriman' => $asal->value], [
            'StatusPengiriman' => $data->tujuan->value,
            'Nomor' => $suratJalan->Nomor,
            'IdArmada' => $suratJalan->IdArmada,
            'NamaPenerima' => $data->tujuan === StatusPengirimanGrosir::Diterima ? $suratJalan->NamaPenerima : null,
        ], idPengguna: $idPengguna);

        return $suratJalan;
    }
}
