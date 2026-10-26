<?php

declare(strict_types=1);

use App\Domain\Lisensi\Galat\LisensiTidakSah;
use App\Domain\Lisensi\Layanan\PenandaLisensi;
use Tests\Pendukung\Lisensi\BantuanLisensi;

describe('Berkas lisensi bertanda tangan (D-35)', function (): void {
    it('berkas yang ditandatangani terbaca kembali utuh dengan kunci publik pasangannya', function (): void {
        $data = app(PenandaLisensi::class)->Baca(BantuanLisensi::Berkas(), BantuanLisensi::Kunci()['KunciPublik']);

        expect($data->KeArray())->toBe(BantuanLisensi::Data()->KeArray())
            ->and($data->CekDomainCocok('LOCALHOST'))->toBeTrue()
            ->and($data->CekDomainCocok('kasir.lain.id'))->toBeFalse();
    });

    it('spasi & indentasi berkas boleh berubah, isi tidak', function (): void {
        $berkas = json_decode(BantuanLisensi::Berkas(), true);
        $padat = json_encode($berkas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        expect(app(PenandaLisensi::class)->Baca((string) $padat, BantuanLisensi::Kunci()['KunciPublik'])->nomor)->toBe('PAYOUNG-L-2026-0001');
    });

    it('menolak berkas yang batasnya dinaikkan sendiri, domainnya diganti, atau kunci publiknya lain', function (): void {
        $publik = BantuanLisensi::Kunci()['KunciPublik'];
        $berkas = json_decode(BantuanLisensi::Berkas(), true);

        $naik = $berkas;
        $naik['Data']['BatasOutlet'] = 99;
        expect(fn () => app(PenandaLisensi::class)->Baca((string) json_encode($naik), $publik))->toThrow(LisensiTidakSah::class, 'Tanda tangan');

        $pindah = $berkas;
        $pindah['Data']['Domain'] = 'kasir.tokolain.id';
        expect(fn () => app(PenandaLisensi::class)->Baca((string) json_encode($pindah), $publik))->toThrow(LisensiTidakSah::class, 'Tanda tangan');

        $lain = app(PenandaLisensi::class)->BuatPasanganKunci()['KunciPublik'];
        expect(fn () => app(PenandaLisensi::class)->Baca(BantuanLisensi::Berkas(), $lain))->toThrow(LisensiTidakSah::class, 'Tanda tangan');
    });

    it('menolak berkas rusak, format asing, domain tidak sah, dan kunci publik kosong', function (): void {
        $publik = BantuanLisensi::Kunci()['KunciPublik'];
        $penanda = app(PenandaLisensi::class);

        expect(fn () => $penanda->Baca('bukan json', $publik))->toThrow(LisensiTidakSah::class, 'bukan JSON')
            ->and(fn () => $penanda->Baca('{"Format": 3, "Data": {}, "TandaTangan": ""}', $publik))->toThrow(LisensiTidakSah::class, 'format')
            ->and(fn () => $penanda->Baca(BantuanLisensi::Berkas(BantuanLisensi::Data(domain: 'https://kasir.toko.id/')), $publik))
            ->toThrow(LisensiTidakSah::class, 'nama host')
            ->and(fn () => $penanda->Baca(BantuanLisensi::Berkas(), ''))->toThrow(LisensiTidakSah::class, 'Kunci publik');
    });

    it('D-36 format 2: masa pembaruan ikut ditandatangani. Format 1 lama tetap sah tanpa batas pembaruan', function (): void {
        $publik = BantuanLisensi::Kunci()['KunciPublik'];
        $penanda = app(PenandaLisensi::class);
        $berkas = BantuanLisensi::Berkas(BantuanLisensi::Data(pembaruanSampai: '2027-10-02'));
        $isi = json_decode($berkas, true);

        expect($isi['Format'])->toBe(2)
            ->and($penanda->Baca($berkas, $publik)->pembaruanSampai)->toBe('2027-10-02')
            ->and(json_decode(BantuanLisensi::Berkas(), true)['Format'])->toBe(1)
            ->and($penanda->Baca(BantuanLisensi::Berkas(), $publik)->CekDalamMasaPembaruan('2099-01-01'))->toBeTrue();

        $diperpanjang = $isi;
        $diperpanjang['Data']['PembaruanSampai'] = '2030-12-31';
        expect(fn () => $penanda->Baca((string) json_encode($diperpanjang), $publik))->toThrow(LisensiTidakSah::class, 'Tanda tangan');

        // Turun ke format 1 dengan membuang tanggalnya mengubah isi yang ditandatangani.
        $diturunkan = $isi;
        $diturunkan['Format'] = 1;
        unset($diturunkan['Data']['PembaruanSampai']);
        expect(fn () => $penanda->Baca((string) json_encode($diturunkan), $publik))->toThrow(LisensiTidakSah::class, 'Tanda tangan');

        $campur = $isi;
        $campur['Format'] = 1;
        expect(fn () => $penanda->Baca((string) json_encode($campur), $publik))->toThrow(LisensiTidakSah::class, 'format 1')
            ->and(fn () => $penanda->Baca(BantuanLisensi::Berkas(BantuanLisensi::Data(pembaruanSampai: '2026-01-01')), $publik))
            ->toThrow(LisensiTidakSah::class, 'PembaruanSampai');
    });

    it('D-36 peringatan rilis hanya untuk rilis setelah masa pembaruan', function (): void {
        $data = BantuanLisensi::Data(pembaruanSampai: '2027-10-02');

        expect($data->AmbilPeringatanRilis(null))->toBeNull()
            ->and($data->AmbilPeringatanRilis('2027-10-02'))->toBeNull()
            ->and($data->AmbilPeringatanRilis('2027-10-03'))->toContain('2027-10-02')
            ->and(BantuanLisensi::Data()->AmbilPeringatanRilis('2099-01-01'))->toBeNull();
    });
});
