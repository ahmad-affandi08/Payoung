<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Enum\JenisPengirimPesan;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Kueri\ObrolanTenant;
use App\Domain\Dukungan\Layanan\PenulisPesanTiket;
use App\Domain\Dukungan\Layanan\PenyimpanLampiran;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Dukungan\Surel\BalasanTiketDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Aksi\KirimNotifikasiKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tim internal membalas tiket (P-09). Dua jenis:
 * - Balasan ke tenant: mengisi respons pertama (SLA), menetapkan penanggung jawab bila belum ada, `Baru` menjadi
 *   `Ditangani` (atau status pilihan: Ditangani/MenungguPelanggan/Selesai), lalu email ke pelapor setelah commit.
 * - Catatan internal: hanya terlihat tim, tidak mengubah status/SLA dan tidak dikirim ke tenant.
 */
final class BalasTiketDukunganPengelola
{
    public const STATUS_SETELAH_BALAS = [StatusTiketDukungan::Ditangani, StatusTiketDukungan::MenungguPelanggan, StatusTiketDukungan::Selesai];

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenulisPesanTiket $penulisPesan,
        private readonly PenyimpanLampiran $penyimpanLampiran,
        private readonly PencatatAuditPengelola $audit,
        private readonly KirimNotifikasiKomunikasi $notifikasi,
    ) {}

    /**
     * @param  list<UploadedFile>  $berkas
     */
    public function Jalankan(
        PenggunaPengelola $pelaku,
        string $uuidTiket,
        string $isi,
        bool $catatanInternal,
        array $berkas = [],
        ?StatusTiketDukungan $statusBaru = null,
    ): TiketDukungan {
        if ($catatanInternal && $statusBaru !== null) {
            throw new PelanggaranAturanBisnis('P-09', 'Catatan internal tidak mengubah status. Kirim balasan ke tenant atau ubah status terpisah.', 'Status');
        }

        if ($statusBaru !== null && ! in_array($statusBaru, self::STATUS_SETELAH_BALAS, true)) {
            throw new PelanggaranAturanBisnis('P-09', 'Status setelah membalas hanya Ditangani, Menunggu pelanggan, atau Selesai.', 'Status');
        }

        $hasil = $this->konteks->JalankanLintasTenant(
            $catatanInternal ? "Menulis catatan internal tiket {$uuidTiket}" : "Membalas tiket dukungan {$uuidTiket}",
            function () use ($pelaku, $uuidTiket, $isi, $catatanInternal, $berkas, $statusBaru): array {
                $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->where('Uuid', $uuidTiket)->firstOrFail();
                $this->PastikanBisaDibalas($tiket, $catatanInternal);
                $lampiran = $this->penyimpanLampiran->Simpan($tiket->IdTenant, $tiket->Uuid, $berkas);

                try {
                    return DB::transaction(function () use ($pelaku, $tiket, $isi, $catatanInternal, $lampiran, $statusBaru): array {
                        $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->lockForUpdate()->findOrFail($tiket->Id);
                        $this->PastikanBisaDibalas($tiket, $catatanInternal);
                        $lama = ['Status' => $tiket->Status->value, 'IdPenanggungJawab' => $tiket->IdPenanggungJawab];

                        if (! $catatanInternal) {
                            $this->TerapkanEfekBalasan($tiket, $pelaku, $statusBaru);
                        }

                        $pesan = $this->penulisPesan->Tulis(
                            $tiket,
                            JenisPengirimPesan::Pengelola,
                            $isi,
                            $pelaku->Nama,
                            idPenggunaPengelola: $pelaku->Id,
                            catatanInternal: $catatanInternal,
                            lampiran: $lampiran,
                        );

                        $this->audit->Catat(
                            $catatanInternal ? 'dukungan.tiket.catatan-internal' : 'dukungan.tiket.balas',
                            $tiket,
                            nilaiLama: $catatanInternal ? null : $lama,
                            nilaiBaru: [
                                'IdPesan' => $pesan->Id,
                                'Status' => $tiket->Status->value,
                                'IdPenanggungJawab' => $tiket->IdPenanggungJawab,
                                'JumlahLampiran' => count($lampiran),
                            ],
                            idPelaku: $pelaku->Id,
                            idTenant: $tiket->IdTenant,
                        );

                        $pelapor = $catatanInternal ? null : Pengguna::query()->whereKey($tiket->IdPelapor)->first(['Nama', 'Email']);

                        return ['Tiket' => $tiket, 'Email' => $pelapor?->Email, 'NamaPelapor' => $pelapor?->Nama, 'IdPesan' => $pesan->Id];
                    });
                } catch (Throwable $galat) {
                    $this->penyimpanLampiran->Hapus($lampiran);

                    throw $galat;
                }
            },
        );

        /** @var TiketDukungan $tiket */
        $tiket = $hasil['Tiket'];

        // PLT-12 (D-163): pelapor yang sedang membaca obrolannya melihat balasan langsung; email dan notifikasi hanya
        // untuk balasan yang datang saat ia tidak membuka obrolan.
        if ($tiket->Kanal === KanalTiketDukungan::Obrolan && ! $catatanInternal && Cache::has(ObrolanTenant::KunciDilihat($tiket->Uuid))) {
            return $tiket;
        }

        if (is_string($hasil['Email'])) {
            Mail::to($hasil['Email'])->queue(new BalasanTiketDukungan($tiket->Nomor, $tiket->Judul, $tiket->Uuid, $pelaku->Nama, $isi, $tiket->Status->AmbilLabel()));
        }

        // PGL-07: notifikasi aplikasi ke pelapor (tanpa isi balasan; pelapor membukanya di halaman tiket).
        if (is_string($hasil['NamaPelapor'])) {
            $tautan = "/kelola/bantuan/{$tiket->Uuid}";
            $this->notifikasi->Jalankan(
                $tiket->IdTenant,
                [$tiket->IdPelapor],
                PeristiwaKomunikasi::TiketBalasanTim,
                ['NamaPelapor' => $hasil['NamaPelapor'], 'NomorTiket' => $tiket->Nomor, 'JudulTiket' => $tiket->Judul, 'NamaPetugas' => $pelaku->Nama, 'Tautan' => rtrim((string) config('app.url'), '/').$tautan],
                "{$tiket->Nomor}:{$hasil['IdPesan']}",
                $tautan,
            );
        }

        return $tiket;
    }

    private function PastikanBisaDibalas(TiketDukungan $tiket, bool $catatanInternal): void
    {
        if (! $catatanInternal && ! $tiket->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis(
                'P-09',
                "Tiket berstatus {$tiket->Status->AmbilLabel()}. Buka lagi tiket (ubah status ke Ditangani) sebelum membalas tenant.",
                'Isi',
            );
        }
    }

    private function TerapkanEfekBalasan(TiketDukungan $tiket, PenggunaPengelola $pelaku, ?StatusTiketDukungan $statusBaru): void
    {
        $tiket->ResponsPertamaPada ??= now();
        $tiket->IdPenanggungJawab ??= $pelaku->Id;
        $tujuan = $statusBaru ?? ($tiket->Status === StatusTiketDukungan::Baru ? StatusTiketDukungan::Ditangani : $tiket->Status);

        if ($tujuan !== $tiket->Status) {
            $tiket->Status = $tujuan;
            $tiket->DiselesaikanPada = $tujuan === StatusTiketDukungan::Selesai ? now() : null;
        }

        $tiket->save();
    }
}
