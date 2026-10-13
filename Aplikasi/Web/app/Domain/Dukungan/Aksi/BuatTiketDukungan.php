<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Data\DataTiketBaru;
use App\Domain\Dukungan\Enum\JenisPengirimPesan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Layanan\PembuatNomorTiket;
use App\Domain\Dukungan\Layanan\PenghitungSlaTiket;
use App\Domain\Dukungan\Layanan\PenulisPesanTiket;
use App\Domain\Dukungan\Layanan\PenyimpanLampiran;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Peristiwa\TiketDukunganDibuat;
use App\Domain\Tenant\Kueri\PaketTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tenant membuat tiket dukungan dari back-office (P-09 "Tiket dukungan"). Nomor unik platform, batas SLA respons
 * pertama dari paket & prioritas, pesan pertama berisi uraian pelapor. Tim Dukungan diberi tahu setelah commit.
 */
final class BuatTiketDukungan
{
    public function __construct(
        private readonly KonteksTenant $konteksTenant,
        private readonly PaketTenant $paketTenant,
        private readonly PenghitungSlaTiket $penghitungSla,
        private readonly PembuatNomorTiket $pembuatNomor,
        private readonly PenulisPesanTiket $penulisPesan,
        private readonly PenyimpanLampiran $penyimpanLampiran,
    ) {}

    public function Jalankan(int $idPelapor, string $namaPelapor, DataTiketBaru $data): TiketDukungan
    {
        $idTenant = $this->konteksTenant->Wajib();
        $uuid = (string) Str::ulid();
        $kodePaket = $this->paketTenant->AmbilKode($idTenant);
        $jamSla = $this->penghitungSla->HitungJam($kodePaket, $data->prioritas);
        $kalender = $this->penghitungSla->CekKalender($kodePaket, $data->prioritas);
        $lampiran = $this->penyimpanLampiran->Simpan($idTenant, $uuid, $data->lampiran);

        try {
            return DB::transaction(function () use ($idTenant, $idPelapor, $namaPelapor, $data, $uuid, $jamSla, $kalender, $lampiran): TiketDukungan {
                $sekarang = now();
                $tiket = TiketDukungan::query()->create([
                    'Uuid' => $uuid,
                    'IdTenant' => $idTenant,
                    'Nomor' => $this->pembuatNomor->Buat($sekarang),
                    'IdPelapor' => $idPelapor,
                    'Kanal' => $data->kanal,
                    'Kategori' => $data->kategori,
                    'Prioritas' => $data->prioritas,
                    'Status' => StatusTiketDukungan::Baru,
                    'Judul' => $data->judul,
                    'JamSla' => $jamSla,
                    'SlaJamKalender' => $kalender,
                    'BatasSlaPada' => $this->penghitungSla->HitungBatas($sekarang, $jamSla, $kalender),
                    'Konteks' => $data->konteks === [] ? null : $data->konteks,
                ]);

                $this->penulisPesan->Tulis($tiket, JenisPengirimPesan::Pengguna, $data->isi, $namaPelapor, idPengguna: $idPelapor, lampiran: $lampiran);

                TiketDukunganDibuat::dispatch(
                    $idTenant,
                    $tiket->Id,
                    $tiket->Uuid,
                    $tiket->Nomor,
                    $tiket->Judul,
                    $tiket->Kategori->AmbilLabel(),
                    $tiket->Prioritas->AmbilLabel(),
                    $tiket->BatasSlaPada->toIso8601String(),
                );

                return $tiket;
            });
        } catch (Throwable $galat) {
            $this->penyimpanLampiran->Hapus($lampiran);

            throw $galat;
        }
    }
}
