<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Layanan\PenomorGrosir;
use App\Domain\Penjualan\Layanan\PenyusunAngkaPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menerbitkan draf penawaran (F-21 bagian 3, D-168): angka dihitung ulang, dinomori `PNW/…`, lalu terkunci (aturan #8).
 * Tidak ada jurnal, piutang, atau stok. Penawaran yang masa berlakunya sudah lewat ditolak (tidak ada gunanya
 * menawarkan harga yang sudah gugur). Idempoten: menerbitkan yang sudah terbit mengembalikannya tanpa nomor kedua.
 */
final class TerbitkanPenawaran
{
    public function __construct(
        private readonly PenyusunAngkaPenawaran $penyusunAngka,
        private readonly PenomorGrosir $penomor,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PenawaranTidakDitemukan, PenawaranTidakBisaDiterbitkan, PenawaranKedaluwarsa, PelangganNonaktif
     */
    public function Jalankan(string $uuidPenawaran, int $idPengguna): Penawaran
    {
        return DB::transaction(function () use ($uuidPenawaran, $idPengguna): Penawaran {
            $penawaran = Penawaran::query()->where('Uuid', $uuidPenawaran)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PenawaranTidakDitemukan', 'Penawaran tidak ditemukan.');

            if ($penawaran->Status === StatusPenawaran::Terbit) {
                return $penawaran;
            }

            if ($penawaran->Status !== StatusPenawaran::Draf) {
                throw new PelanggaranAturanBisnis('PenawaranTidakBisaDiterbitkan', "Penawaran yang sudah {$penawaran->Status->AmbilLabel()} tidak bisa diterbitkan. Buat penawaran baru.");
            }

            $hariIni = $this->tanggalBisnis->Hitung($penawaran->IdOutlet);

            if ($penawaran->BerlakuSampai->toDateString() < $hariIni->toDateString()) {
                throw new PelanggaranAturanBisnis('PenawaranKedaluwarsa', 'Masa berlaku penawaran ini sudah lewat. Ubah tanggal "Berlaku sampai" lalu terbitkan lagi.', 'BerlakuSampai');
            }

            $pelanggan = Pelanggan::query()->whereKey($penawaran->IdPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan penawaran ini tidak ditemukan.');

            if ($pelanggan->Status !== StatusPelanggan::Aktif) {
                throw new PelanggaranAturanBisnis('PelangganNonaktif', "Pelanggan {$pelanggan->Nama} sudah diarsipkan. Aktifkan lagi sebelum menerbitkan penawaran.", 'UuidPelanggan');
            }

            $this->penyusunAngka->Terapkan($penawaran);
            $nomor = $this->penomor->AmbilNomorOutlet(JenisDokumenBernomor::Penawaran, CarbonImmutable::parse($penawaran->Tanggal->format('Y-m-d')), $penawaran->IdOutlet);
            $penawaran->Nomor = $nomor;
            $penawaran->UbahStatus(StatusPenawaran::Terbit);
            $penawaran->fill(['DiterbitkanPada' => CarbonImmutable::now(), 'DiubahOleh' => $idPengguna])->save();

            $this->riwayat->Catat(Penawaran::JENIS_DOKUMEN, $penawaran->Id, StatusPenawaran::Draf->value, StatusPenawaran::Terbit->value, $idPengguna);
            $this->audit->Catat('penawaran.terbit', $penawaran, nilaiLama: ['Status' => StatusPenawaran::Draf->value], nilaiBaru: [
                'Status' => StatusPenawaran::Terbit->value,
                'Nomor' => $nomor,
                'IdPelanggan' => $penawaran->IdPelanggan,
                'Total' => $penawaran->Total,
                'BerlakuSampai' => $penawaran->BerlakuSampai->toDateString(),
            ], idPengguna: $idPengguna);

            return $penawaran->refresh();
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
