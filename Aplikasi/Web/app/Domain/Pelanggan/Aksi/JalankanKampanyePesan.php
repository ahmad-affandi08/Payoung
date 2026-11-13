<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Pelanggan\Enum\KanalKampanye;
use App\Domain\Pelanggan\Enum\StatusKampanye;
use App\Domain\Pelanggan\Kueri\PemilihPenerimaKampanye;
use App\Domain\Pelanggan\Model\KampanyePesan;
use App\Domain\Pelanggan\Model\PenerimaKampanye;
use App\Domain\Pelanggan\Tugas\KirimKampanyePesanTugas;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CRM-07: kirim kampanye sekarang atau jadwalkan (`Draf` → `Berjalan` | `Dijadwalkan`). Saat mulai, penerima dipotret
 * dari `PemilihPenerimaKampanye` (hanya yang setuju pemasaran), maks. `MAKS_PENERIMA`, satu kampanye berjalan per usaha
 * (tidak membanjiri penyedia & pelanggan). Kanal harus aktif; WhatsApp resmi wajib templat promosi yang disetujui Meta.
 * Tugas kirim berjalan setelah commit. Audit `kampanye-pesan.jalankan|jadwalkan` (jumlah saja).
 *
 * Urutan kunci: kampanye.
 */
final class JalankanKampanyePesan
{
    public const MAKS_PENERIMA = 5000;

    public const MAKS_HARI_JADWAL = 60;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemilihPenerimaKampanye $pemilih,
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(KampanyePesan $kampanye, ?CarbonImmutable $jadwal, int $idPengguna): KampanyePesan
    {
        $this->PastikanKanalAktif($kampanye->Kanal);

        if ($jadwal !== null && $jadwal->greaterThan(now()->addMinutes(5))) {
            if ($jadwal->greaterThan(now()->addDays(self::MAKS_HARI_JADWAL))) {
                throw new PelanggaranAturanBisnis('JadwalTerlaluJauh', 'Jadwal kirim paling lama '.self::MAKS_HARI_JADWAL.' hari dari sekarang.', 'DijadwalkanPada');
            }

            return DB::transaction(function () use ($kampanye, $jadwal, $idPengguna): KampanyePesan {
                $terkunci = $this->KunciDraf($kampanye);
                $terkunci->UbahStatus(StatusKampanye::Dijadwalkan);
                $terkunci->fill(['DijadwalkanPada' => $jadwal, 'DijalankanOleh' => $idPengguna])->save();
                $this->audit->Catat('kampanye-pesan.jadwalkan', $terkunci, ['Status' => StatusKampanye::Draf->value], ['Status' => $terkunci->Status->value, 'DijadwalkanPada' => $jadwal->toIso8601ZuluString()], idPengguna: $idPengguna);

                return $terkunci;
            });
        }

        return $this->Mulai($kampanye, $idPengguna, CarbonImmutable::now());
    }

    /** Mulai mengirim (dari tombol atau jadwal). `$hariIni` = acuan segmen RFM. */
    public function Mulai(KampanyePesan $kampanye, ?int $idPengguna, CarbonImmutable $hariIni): KampanyePesan
    {
        $this->PastikanKanalAktif($kampanye->Kanal);
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($kampanye, $idPengguna, $hariIni, $idTenant): KampanyePesan {
            $terkunci = KampanyePesan::query()->whereKey($kampanye->Id)->lockForUpdate()->firstOrFail();
            $asal = $terkunci->Status;

            if (! in_array($asal, [StatusKampanye::Draf, StatusKampanye::Dijadwalkan], true)) {
                throw new PelanggaranAturanBisnis('BukanDraf', 'Kampanye ini sudah dikirim atau dibatalkan.', 'Umum', 409);
            }

            if (KampanyePesan::query()->where('Status', StatusKampanye::Berjalan->value)->whereKeyNot($terkunci->Id)->exists()) {
                throw new PelanggaranAturanBisnis('KampanyeLainBerjalan', 'Masih ada kampanye lain yang sedang dikirim. Tunggu sampai selesai atau batalkan dulu.', 'Umum', 409);
            }

            $hasil = $this->pemilih->Susun($terkunci->Segmen, $terkunci->Kanal, $hariIni);
            $jumlah = count($hasil['Penerima']);

            if ($jumlah === 0) {
                throw new PelanggaranAturanBisnis('TanpaPenerima', 'Tidak ada pelanggan yang cocok, setuju menerima promosi, dan punya kontak untuk kanal ini.', 'Umum', 422);
            }

            if ($jumlah > self::MAKS_PENERIMA) {
                throw new PelanggaranAturanBisnis('PenerimaTerlaluBanyak', "Penerima {$jumlah} orang, paling banyak ".self::MAKS_PENERIMA.' per kampanye. Persempit segmennya.', 'Umum', 422);
            }

            $sekarang = now();

            foreach (array_chunk($hasil['Penerima'], 500) as $potong) {
                foreach ($potong as $p) {
                    PenerimaKampanye::query()->create([
                        'IdKampanyePesan' => $terkunci->Id,
                        'IdPelanggan' => $p['IdPelanggan'],
                        'Tujuan' => $p['Tujuan'],
                    ]);
                }
            }

            $terkunci->UbahStatus(StatusKampanye::Berjalan);
            $terkunci->fill([
                'MulaiPada' => $sekarang,
                'JumlahPenerima' => $jumlah,
                'DijalankanOleh' => $terkunci->DijalankanOleh ?? $idPengguna,
            ])->save();

            KirimKampanyePesanTugas::dispatch($idTenant, $terkunci->Id)->afterCommit();
            $this->audit->Catat('kampanye-pesan.jalankan', $terkunci, ['Status' => $asal->value], [
                'Status' => $terkunci->Status->value,
                'JumlahPenerima' => $jumlah,
                'TanpaKontak' => $hasil['TanpaKontak'],
            ], idTenant: $idTenant, idPengguna: $idPengguna);

            return $terkunci;
        });
    }

    public function PastikanKanalAktif(KanalKampanye $kanal): void
    {
        if ($kanal === KanalKampanye::Whatsapp) {
            $pengirim = $this->whatsapp->AmbilAktif(fungsi: FungsiNomorWhatsapp::Broadcast);

            if ($pengirim === null || ! $this->fitur->CekAktif($this->konteks->Wajib(), PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
                throw new PelanggaranAturanBisnis('KanalTidakAktif', 'Pengiriman WhatsApp belum aktif untuk usaha ini.', 'Kanal', 422);
            }

            if ($pengirim->CekResmi() && $this->whatsapp->AmbilTemplatPromosi() === null) {
                throw new PelanggaranAturanBisnis('TemplatPromosiBelumAda', 'WhatsApp resmi mewajibkan templat promosi yang disetujui Meta. Minta pengelola platform mengisi "Nama templat promosi".', 'Kanal', 422);
            }

            return;
        }

        // D-33: kampanye email lama (draf/terjadwal) tidak dikirim lagi; yang terjadwal dibatalkan penjadwal.
        throw new PelanggaranAturanBisnis('KanalTidakTersedia', 'Kampanye lewat email tidak tersedia lagi. Ubah ke WhatsApp.', 'Kanal', 422);
    }

    private function KunciDraf(KampanyePesan $kampanye): KampanyePesan
    {
        $terkunci = KampanyePesan::query()->whereKey($kampanye->Id)->lockForUpdate()->firstOrFail();

        if ($terkunci->Status !== StatusKampanye::Draf) {
            throw new PelanggaranAturanBisnis('BukanDraf', 'Kampanye ini sudah dijadwalkan, dikirim, atau dibatalkan.', 'Umum', 409);
        }

        return $terkunci;
    }
}
