<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pemenuhan\Enum\StatusReservasiMeja;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FnB reservasi meja (D-145): daftar untuk `TabelData` (cari nomor/nama/HP, saring tanggal dalam zona waktu tenant,
 * status, outlet; urut jam mulai). Pengguna berbatas outlet hanya melihat outlet aksesnya. Memuat ringkasan uang muka
 * (dicatat, terpakai, dikembalikan, hangus, sisa) dan status berikutnya yang boleh dipilih.
 */
final class DaftarReservasiMeja
{
    public const KOLOM_URUT = ['MulaiPada', 'Nomor'];

    public const KOLOM_SARING = ['Tanggal', 'Status', 'Outlet'];

    public const URUT_BAWAAN = 'MulaiPada';

    public function __construct(
        private readonly PetaUuidOutlet $outlet,
        private readonly MejaOutlet $meja,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $p, ?array $idOutletBoleh, string $zona): array
    {
        $tanggal = $p->AmbilRentangTanggal('Tanggal');
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusReservasiMeja $s): string => $s->value, StatusReservasiMeja::cases()));
        $uuidOutlet = $p->AmbilDaftar('Outlet');
        $kata = $p->cari;
        $kueri = $this->Dasar($idOutletBoleh)
            ->when($tanggal['Dari'] !== null, fn (Builder $k) => $k->where('MulaiPada', '>=', CarbonImmutable::parse((string) $tanggal['Dari'], $zona)->startOfDay()->utc()))
            ->when($tanggal['Sampai'] !== null, fn (Builder $k) => $k->where('MulaiPada', '<', CarbonImmutable::parse((string) $tanggal['Sampai'], $zona)->startOfDay()->addDay()->utc()))
            ->when($status !== [], fn (Builder $k) => $k->whereIn('Status', $status))
            ->when($uuidOutlet !== [], fn (Builder $k) => $k->whereIn('IdOutlet', array_values($this->outlet->AmbilIdDariUuid($uuidOutlet)) ?: [0]))
            ->when($kata !== '', function (Builder $k) use ($kata): void {
                $pola = PenerapKueriTabel::PolaCari($kata);
                $hp = NomorHp::Normalisasi($kata);
                $k->where(fn (Builder $q) => $q->where('Nomor', 'like', $pola)->orWhere('NamaPemesan', 'like', $pola)->orWhere('Uuid', $kata)
                    ->when($hp !== null, fn (Builder $q2) => $q2->orWhere('NoHp', $hp)));
            });

        return PenerapKueriTabel::Terapkan($kueri, $p, ['MulaiPada' => 'MulaiPada', 'Nomor' => 'Nomor'], fn (Collection $baris): array => $this->Petakan($baris));
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Cari(string $uuid, ?array $idOutletBoleh): ?ReservasiMeja
    {
        return $this->Dasar($idOutletBoleh)->where('Uuid', $uuid)->first();
    }

    /**
     * @param  Collection<int, ReservasiMeja>  $baris
     * @return list<array<string, mixed>>
     */
    public function Petakan(Collection $baris): array
    {
        $meja = $this->meja->AmbilPerId(array_values(array_filter(array_unique($baris->pluck('IdMeja')->all()))));
        $area = $this->meja->AmbilAreaPerId(array_values(array_filter(array_unique($baris->pluck('IdAreaMeja')->all()))));
        $outlet = [];

        foreach ($this->outlet->AmbilRingkas(array_values(array_unique($baris->pluck('IdOutlet')->all()))) as $o) {
            $outlet[$o['Id']] = $o;
        }

        return array_values($baris->map(fn (ReservasiMeja $r): array => [
            'Uuid' => $r->Uuid,
            'Nomor' => $r->Nomor,
            'MulaiPada' => $r->MulaiPada->toIso8601ZuluString(),
            'SelesaiPada' => $r->AmbilSelesaiPada()->toIso8601ZuluString(),
            'DurasiMenit' => $r->DurasiMenit,
            'NamaPemesan' => $r->NamaPemesan,
            'NoHp' => NomorHp::Format($r->NoHp),
            'JumlahTamu' => $r->JumlahTamu,
            'Meja' => $r->IdMeja === null ? null : ['Uuid' => $meja[$r->IdMeja]['Uuid'] ?? null, 'Nama' => $meja[$r->IdMeja]['Nama'] ?? ''],
            'Area' => $r->IdAreaMeja === null ? null : ['Uuid' => $area[$r->IdAreaMeja]['Uuid'] ?? null, 'Nama' => $area[$r->IdAreaMeja]['Nama'] ?? ''],
            'Outlet' => ['Uuid' => $outlet[$r->IdOutlet]['Uuid'] ?? null, 'Nama' => $outlet[$r->IdOutlet]['Nama'] ?? ''],
            'Status' => $r->Status->value,
            'LabelStatus' => $r->Status->AmbilLabel(),
            'Sumber' => $r->Sumber->value,
            'LabelSumber' => $r->Sumber->AmbilLabel(),
            'Catatan' => $r->Catatan,
            'AlasanBatal' => $r->AlasanBatal,
            'BolehDiubah' => $r->Status->CekBolehDiubah(),
            'BolehCatatDp' => $r->Status->CekMemakaiMeja() && Uang::Dari($r->JumlahDp)->BernilaiNol(),
            'UangMuka' => [
                'Dicatat' => $r->JumlahDp,
                'Terpakai' => $r->DpTerpakai,
                'Dikembalikan' => $r->DpDikembalikan,
                'Hangus' => $r->DpHangus,
                'Sisa' => $r->AmbilSisaUangMuka()->KeString(),
            ],
            'StatusBerikutnya' => array_values(array_map(
                fn (StatusReservasiMeja $s): string => $s->value,
                array_filter(StatusReservasiMeja::cases(), fn (StatusReservasiMeja $s): bool => $r->Status->BisaBerubahKe($s)),
            )),
        ])->all());
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<ReservasiMeja>
     */
    private function Dasar(?array $idOutletBoleh): Builder
    {
        return ReservasiMeja::query()->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh === [] ? [0] : $idOutletBoleh));
    }
}
