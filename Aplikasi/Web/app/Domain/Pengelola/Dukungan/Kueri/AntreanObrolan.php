<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Kueri;

use App\Domain\Dukungan\Enum\JenisPengirimPesan;
use App\Domain\Dukungan\Enum\KanalTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Layanan\KehadiranPetugasObrolan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Dukungan\Model\TiketDukunganPesan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use Illuminate\Support\Str;

/**
 * Inbox obrolan langsung untuk petugas dukungan (PLT-12, D-163): obrolan terbuka lintas tenant, yang paling lama
 * menunggu balasan di atas, dan percakapan satu obrolan. Dipanggil berulang oleh layar waktu nyata, jadi akses lintas
 * tenantnya dicatat sekali per `MENIT_AUDIT` per petugas, bukan tiap polling. Catatan internal tim tidak ikut (ada di
 * halaman tiket).
 */
final class AntreanObrolan
{
    public const MENIT_AUDIT = 5;

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly RingkasanTenant $ringkasanTenant,
        private readonly KehadiranPetugasObrolan $kehadiran,
    ) {}

    /**
     * @return array{Siap: bool, PetugasOnline: int, Obrolan: list<array<string, mixed>>}
     */
    public function Ambil(PenggunaPengelola $pelaku): array
    {
        $obrolan = $this->konteks->JalankanLintasTenant('Membuka inbox obrolan dukungan', function (): array {
            $tiket = $this->konteks->KueriLintas(TiketDukungan::class)
                ->where('Kanal', KanalTiketDukungan::Obrolan->value)
                ->whereIn('Status', array_map(fn (StatusTiketDukungan $s): string => $s->value, StatusTiketDukungan::AmbilTerbuka()))
                ->orderBy('Id')
                ->limit((int) config('dukungan.Obrolan.MaksObrolanKonsol'))
                ->get();

            if ($tiket->isEmpty()) {
                return [];
            }

            $idTiket = $tiket->pluck('Id')->all();
            $idTerakhir = $this->konteks->KueriLintas(TiketDukunganPesan::class)
                ->whereIn('IdTiketDukungan', $idTiket)->where('CatatanInternal', false)
                ->selectRaw('max(Id) as IdTerakhir')->groupBy('IdTiketDukungan')->pluck('IdTerakhir')->all();
            $terakhir = $this->konteks->KueriLintas(TiketDukunganPesan::class)->whereIn('Id', $idTerakhir)->get()->keyBy('IdTiketDukungan');
            $tenant = collect($this->ringkasanTenant->Ambil(array_values(array_unique($tiket->pluck('IdTenant')->all()))))->keyBy('Id');
            $pelapor = Pengguna::query()->whereIn('Id', array_values(array_unique($tiket->pluck('IdPelapor')->all())))->pluck('Nama', 'Id');
            $petugas = PenggunaPengelola::query()->whereIn('Id', array_values(array_filter(array_unique($tiket->pluck('IdPenanggungJawab')->all()))))->pluck('Nama', 'Id');

            return array_values($tiket->map(function (TiketDukungan $baris) use ($terakhir, $tenant, $pelapor, $petugas): array {
                $pesan = $terakhir->get($baris->Id);

                return [
                    'Uuid' => $baris->Uuid,
                    'Nomor' => $baris->Nomor,
                    'Judul' => $baris->Judul,
                    'NamaTenant' => $tenant->get($baris->IdTenant)['Nama'] ?? "Tenant #{$baris->IdTenant}",
                    'NamaPelapor' => (string) ($pelapor[$baris->IdPelapor] ?? 'Pengguna'),
                    'Status' => $baris->Status->value,
                    'LabelStatus' => $baris->Status->AmbilLabel(),
                    'IdPenanggungJawab' => $baris->IdPenanggungJawab,
                    'Petugas' => $baris->IdPenanggungJawab === null ? null : (string) ($petugas[$baris->IdPenanggungJawab] ?? '-'),
                    'MenungguBalasan' => $pesan !== null && $pesan->JenisPengirim === JenisPengirimPesan::Pengguna,
                    'Pratinjau' => $pesan === null ? '' : Str::limit(trim((string) preg_replace('/\s+/u', ' ', $pesan->Isi)), 90, '...'),
                    'PesanTerakhirPada' => ($pesan !== null ? $pesan->DibuatPada : $baris->DibuatPada)->toIso8601String(),
                ];
            })->sortBy([['MenungguBalasan', 'desc'], ['PesanTerakhirPada', 'asc']])->all());
        }, auditSekaliPerMenit: self::MENIT_AUDIT);

        return [
            'Siap' => $this->kehadiran->CekSiap($pelaku->Id),
            'PetugasOnline' => $this->kehadiran->HitungOnline(),
            'Obrolan' => $obrolan,
        ];
    }

    /**
     * Percakapan satu obrolan (tanpa catatan internal). `$setelahUuid` = hanya pesan sesudah pesan itu.
     *
     * @return array<string, mixed>
     */
    public function AmbilPesan(string $uuidTiket, ?string $setelahUuid): array
    {
        return $this->konteks->JalankanLintasTenant("Membaca obrolan dukungan {$uuidTiket}", function () use ($uuidTiket, $setelahUuid): array {
            $tiket = $this->konteks->KueriLintas(TiketDukungan::class)->where('Uuid', $uuidTiket)->where('Kanal', KanalTiketDukungan::Obrolan->value)->firstOrFail();
            $kueri = $this->konteks->KueriLintas(TiketDukunganPesan::class)->where('IdTiketDukungan', $tiket->Id)->where('CatatanInternal', false)->orderBy('Id');

            if ($setelahUuid !== null && $setelahUuid !== '') {
                $patokan = $this->konteks->KueriLintas(TiketDukunganPesan::class)->where('IdTiketDukungan', $tiket->Id)->where('Uuid', $setelahUuid)->value('Id');

                if ($patokan !== null) {
                    $kueri->where('Id', '>', $patokan);
                }
            }

            return [
                'Uuid' => $tiket->Uuid,
                'Nomor' => $tiket->Nomor,
                'Status' => $tiket->Status->value,
                'LabelStatus' => $tiket->Status->AmbilLabel(),
                'BisaDibalas' => $tiket->Status->CekTerbuka(),
                'Pesan' => array_values($kueri->limit(300)->get()->map(fn (TiketDukunganPesan $baris): array => [
                    'Uuid' => $baris->Uuid,
                    'JenisPengirim' => $baris->JenisPengirim->value,
                    'NamaPengirim' => $baris->JenisPengirim === JenisPengirimPesan::Sistem ? 'Sistem' : ($baris->NamaPengirim ?? ($baris->JenisPengirim === JenisPengirimPesan::Pengelola ? 'Tim Dukungan' : 'Pengguna')),
                    'Isi' => $baris->Isi,
                    'DibuatPada' => $baris->DibuatPada->toIso8601String(),
                ])->all()),
            ];
        }, auditSekaliPerMenit: self::MENIT_AUDIT);
    }
}
