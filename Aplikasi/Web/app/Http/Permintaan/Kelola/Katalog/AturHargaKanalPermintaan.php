<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Katalog;

use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Atur harga ojol" (`POST /kelola/daftar-harga/atur-kanal[/pratinjau]`): `{Kanal[], Mode, Nilai, Pembulatan, Timpa,
 * UuidOutlet[]}`. `UuidOutlet` kosong = semua outlet. Izin `produk.harga.ubah` dijaga rute.
 */
final class AturHargaKanalPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Kanal' => ['required', 'array', 'min:1', 'max:'.count(KanalPenjualan::AmbilPlatform())],
            'Kanal.*' => ['required', 'string', 'distinct', Rule::in(array_map(fn (KanalPenjualan $k): string => $k->value, KanalPenjualan::AmbilPlatform()))],
            'Mode' => ['required', 'string', Rule::in(PenghitungPerubahanHarga::MODE)],
            'Nilai' => ['required', 'string', 'max:15'],
            'Pembulatan' => ['required', 'integer', Rule::in(PenghitungPerubahanHarga::PEMBULATAN)],
            'Timpa' => ['required', 'boolean'],
            'UuidOutlet' => ['present', 'array', 'max:200'],
            'UuidOutlet.*' => ['required', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Kanal' => 'platform', 'Nilai' => 'besar perubahan', 'Mode' => 'cara mengubah', 'UuidOutlet' => 'outlet'];
    }

    /**
     * @return list<string>
     */
    public function AmbilKanal(): array
    {
        return array_values(array_map('strval', (array) $this->validated('Kanal')));
    }

    /**
     * Id dan nama outlet terpilih, urut sama. Kosong = semua outlet.
     *
     * @return array{Id: list<int>|null, Nama: list<string>}
     *
     * @throws ValidationException
     */
    public function AmbilOutlet(): array
    {
        /** @var list<string> $uuid */
        $uuid = array_values(array_map('strval', (array) $this->input('UuidOutlet', [])));

        if ($uuid === []) {
            return ['Id' => null, 'Nama' => []];
        }

        $peta = app(PetaUuidOutlet::class);
        $idDariUuid = $peta->AmbilIdDariUuid($uuid);

        foreach ($uuid as $i => $satu) {
            if (! isset($idDariUuid[$satu])) {
                throw ValidationException::withMessages(["UuidOutlet.{$i}" => 'Outlet tidak ditemukan.', 'UuidOutlet' => 'Outlet tidak ditemukan.']);
            }
        }

        $id = array_values(array_map(fn (string $satu): int => $idDariUuid[$satu], $uuid));
        $nama = array_map(fn (array $outlet): string => $outlet['Nama'], $peta->AmbilRingkas($id));

        return ['Id' => $id, 'Nama' => $nama];
    }
}
