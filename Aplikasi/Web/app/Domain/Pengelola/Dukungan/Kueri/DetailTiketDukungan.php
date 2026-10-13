<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Kueri;

use App\Domain\Dukungan\Enum\JenisPengirimPesan;
use App\Domain\Dukungan\Kueri\TiketDukunganTenant;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Model\TiketDukunganPesan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detail tiket untuk tim internal (P-09): percakapan lengkap termasuk catatan internal, tenant, pelapor, dan anggota
 * yang bisa ditugaskan. Dibaca lewat KonteksPengelola dengan tenant tiket sebagai tenant aktif sementara.
 */
final class DetailTiketDukungan
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly RingkasanTenant $ringkasanTenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(string $uuid): array
    {
        $idTenant = $this->AmbilIdTenant($uuid);

        return $this->konteks->JalankanLintasTenant("Membuka tiket dukungan {$uuid}", function () use ($uuid, $idTenant): array {
            $tiket = TiketDukungan::query()->where('Uuid', $uuid)->firstOrFail();
            $pesan = TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->orderBy('Id')->get();
            $pelapor = Pengguna::query()->find($tiket->IdPelapor, ['Id', 'Nama', 'Email']);
            $tenant = $this->ringkasanTenant->Ambil([$idTenant])[0] ?? null;

            return [
                ...TiketDukunganTenant::PetakanRingkas($tiket),
                'Kanal' => $tiket->Kanal->AmbilLabel(),
                'JamSla' => $tiket->JamSla,
                'SlaJamKalender' => $tiket->SlaJamKalender,
                'TingkatEskalasi' => $tiket->TingkatEskalasi->value,
                'LabelEskalasi' => $tiket->TingkatEskalasi->AmbilLabel(),
                'DieskalasiPada' => $tiket->DieskalasiPada?->toIso8601String(),
                'BatasSlaPada' => $tiket->BatasSlaPada->toIso8601String(),
                'ResponsPertamaPada' => $tiket->ResponsPertamaPada?->toIso8601String(),
                'LewatSla' => $tiket->CekLewatSla(),
                'BisaDibukaLagi' => $tiket->CekBisaDibukaLagi(),
                'Konteks' => array_map(fn (mixed $nilai): string => is_scalar($nilai) ? (string) $nilai : '', $tiket->Konteks ?? []),
                'Tenant' => $tenant === null ? null : ['Nama' => $tenant['Nama'], 'Slug' => $tenant['Slug']],
                'Pelapor' => $pelapor === null ? null : ['Nama' => $pelapor->Nama, 'Email' => $pelapor->Email],
                'UuidPenanggungJawab' => $tiket->IdPenanggungJawab === null ? null
                    : PenggunaPengelola::query()->whereKey($tiket->IdPenanggungJawab)->value('Uuid'),
                'Pesan' => array_values($pesan->map(fn (TiketDukunganPesan $baris): array => [
                    'Uuid' => $baris->Uuid,
                    'JenisPengirim' => $baris->JenisPengirim->value,
                    'NamaPengirim' => $baris->JenisPengirim === JenisPengirimPesan::Sistem ? 'Sistem' : ($baris->NamaPengirim ?? '-'),
                    'CatatanInternal' => $baris->CatatanInternal,
                    'Isi' => $baris->Isi,
                    'Lampiran' => TiketDukunganTenant::PetakanLampiran($baris),
                    'DibuatPada' => $baris->DibuatPada->toIso8601String(),
                ])->all()),
            ];
        }, $idTenant);
    }

    /**
     * Lampiran pesan mana pun di tiket (termasuk catatan internal).
     *
     * @return array{NamaAsli: string, Mime: string, Path: string}|null
     */
    public function CariLampiran(string $uuid, string $uuidLampiran): ?array
    {
        $idTenant = $this->AmbilIdTenant($uuid);

        return $this->konteks->JalankanLintasTenant("Mengunduh lampiran tiket dukungan {$uuid}", function () use ($uuid, $uuidLampiran): ?array {
            $tiket = TiketDukungan::query()->where('Uuid', $uuid)->firstOrFail();

            foreach (TiketDukunganPesan::query()->where('IdTiketDukungan', $tiket->Id)->whereNotNull('Lampiran')->get() as $pesan) {
                $lampiran = $pesan->CariLampiran($uuidLampiran);

                if ($lampiran !== null) {
                    return $lampiran;
                }
            }

            return null;
        }, $idTenant);
    }

    /**
     * Anggota aktif yang boleh menangani tiket, untuk pilihan penugasan.
     *
     * @return list<array{Uuid: string, Nama: string}>
     */
    public function AmbilPenangan(): array
    {
        return array_values(PenggunaPengelola::query()
            ->where('Aktif', true)
            ->whereHas('Peran.Izin', fn (Builder $kueri) => $kueri->where('KunciIzin', IzinPengelola::DukunganTiketTangani->value))
            ->orderBy('Nama')
            ->get(['Id', 'Uuid', 'Nama'])
            ->map(fn (PenggunaPengelola $anggota): array => ['Uuid' => $anggota->Uuid, 'Nama' => $anggota->Nama])
            ->all());
    }

    /** Tenant pemilik tiket dicari tanpa membaca isi tiket, agar akses berikutnya tercatat dengan tenant yang tepat. */
    private function AmbilIdTenant(string $uuid): int
    {
        return $this->konteks->JalankanLintasTenant(
            "Mencari tenant pemilik tiket dukungan {$uuid}",
            fn (): int => (int) $this->konteks->KueriLintas(TiketDukungan::class)->where('Uuid', $uuid)->valueOrFail('IdTenant'),
        );
    }
}
