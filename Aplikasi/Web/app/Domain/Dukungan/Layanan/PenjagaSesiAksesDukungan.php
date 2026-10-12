<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use App\Domain\Dukungan\Aksi\AkhiriSesiAksesDukungan;
use App\Domain\Dukungan\Data\DataSesiDukunganAktif;
use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Model\PenggunaDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Organisasi\Enum\IzinTenant;
use Carbon\CarbonImmutable;

/**
 * Memeriksa pada SETIAP permintaan bahwa sesi akses dukungan masih sah (PGL-16, BR-P09.2): sesi sudah ditukar dan belum
 * diakhiri, belum lewat batas waktunya, izin induknya belum dicabut atau kedaluwarsa, identitas yang login memang milik
 * petugas sesi itu, dan petugasnya masih aktif. Karena diperiksa tiap permintaan, mencabut izin berlaku seketika, bukan
 * menunggu sesi peramban habis. Sesi yang tidak lagi sah sekalian ditandai berakhir (alasan terdahulu dipertahankan).
 * Hanya boleh dipanggil dengan konteks tenant sudah ditetapkan (model sesi milik tenant).
 */
final class PenjagaSesiAksesDukungan
{
    public function __construct(
        private readonly GerbangPengelola $gerbang,
        private readonly AkhiriSesiAksesDukungan $akhiri,
    ) {}

    /**
     * @return array{0: DataSesiDukunganAktif|null, 1: AlasanAkhirSesiDukungan|null} sesi sah, atau alasan sesi tidak sah
     */
    public function Periksa(int $idSesi, int $idPengguna): array
    {
        $sesi = SesiAksesDukungan::query()->with('Akses')->whereKey($idSesi)->first();

        if (! $sesi instanceof SesiAksesDukungan || $sesi->TokenDipakaiPada === null) {
            return [null, AlasanAkhirSesiDukungan::Selesai];
        }

        if ($sesi->DiakhiriPada !== null) {
            return [null, $sesi->AlasanAkhir ?? AlasanAkhirSesiDukungan::Selesai];
        }

        $identitas = PenggunaDukungan::query()->where('IdPenggunaPengelola', $sesi->IdPenggunaPengelola)->value('IdPengguna');

        if ($identitas !== $idPengguna) {
            return [null, AlasanAkhirSesiDukungan::Selesai];
        }

        $akses = $sesi->Akses;
        $alasan = match (true) {
            $akses->DicabutPada !== null => AlasanAkhirSesiDukungan::Dicabut,
            ! $this->gerbang->CekPetugasAktif($sesi->IdPenggunaPengelola) => AlasanAkhirSesiDukungan::Dicabut,
            $sesi->BerakhirPada->lte(now()) || ! $akses->CekAktif() => AlasanAkhirSesiDukungan::Kedaluwarsa,
            default => null,
        };

        if ($alasan !== null) {
            $this->akhiri->Jalankan($sesi, $alasan);

            return [null, $alasan];
        }

        return [new DataSesiDukunganAktif(
            idSesi: $sesi->Id,
            uuidSesi: $sesi->Uuid,
            idAkses: $akses->Id,
            idTenant: $sesi->IdTenant,
            idPengguna: $idPengguna,
            idPenggunaPengelola: $sesi->IdPenggunaPengelola,
            namaPetugas: $sesi->NamaPetugas,
            jenis: $akses->Jenis,
            cakupan: $akses->Cakupan,
            dataPribadiTerbuka: $akses->DataPribadiTerbuka,
            berakhirPada: CarbonImmutable::instance($sesi->BerakhirPada),
            izin: self::AmbilKunciIzin(),
        ), null];
    }

    /**
     * Izin yang dipegang sesi dukungan: semua izin tenant kecuali yang dilarang saat dukungan (BR-P09.1). Cakupan
     * "Baca saja" ditegakkan di tingkat permintaan (metode HTTP), bukan dengan memangkas izin, karena banyak halaman
     * hanya-lihat memakai izin "kelola" untuk membukanya.
     *
     * @return list<string>
     */
    public static function AmbilKunciIzin(): array
    {
        return array_values(array_map(
            fn (IzinTenant $izin): string => $izin->value,
            array_filter(IzinTenant::cases(), fn (IzinTenant $izin): bool => ! $izin->CekDilarangSaatDukungan()),
        ));
    }
}
