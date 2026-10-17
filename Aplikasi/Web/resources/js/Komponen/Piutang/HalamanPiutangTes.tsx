import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPiutangPelanggan from '@/Halaman/Kelola/Piutang/Daftar';
import HalamanDaftarHapusBuku from '@/Halaman/Kelola/Piutang/HapusBuku/Daftar';
import HalamanDaftarPelunasan from '@/Halaman/Kelola/Piutang/Pelunasan/Daftar';
import HalamanDetailPelunasan from '@/Halaman/Kelola/Piutang/Pelunasan/Detail';
import HalamanFormPelunasan, { PeriksaAlokasiPiutang } from '@/Halaman/Kelola/Piutang/Pelunasan/Form';
import DialogHapusBukuPiutang from '@/Komponen/Piutang/DialogHapusBukuPiutang';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';
import type { BarisPiutang, IzinPiutang, PiutangTerbuka } from '@/Tipe/Piutang';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const IzinPenuh: IzinPiutang = { Kelola: true, LihatJurnal: true };
const IzinLihat: IzinPiutang = { Kelola: false, LihatJurnal: false };
const Toko = { Uuid: '01J9PLG0000000000000000001', Nama: 'Toko Makmur Jaya Sentosa Abadi Grosir Sembako' };
const Akun = { Uuid: '01J9AKN0000000000000000001', Kode: '1-1200', Nama: 'Bank BCA' };

function Piutang(perubahan: Partial<PiutangTerbuka> = {}): PiutangTerbuka {
    return {
        Uuid: '01J9PTG0000000000000000001',
        Nomor: 'UTM-K01-260924-0001',
        TanggalBisnis: '2026-09-01',
        JatuhTempo: '2026-10-01',
        Jumlah: '1250000000.00',
        Sisa: '1250000000.00',
        ...perubahan,
    };
}

describe('Halaman piutang pelanggan (F-12)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/piutang');
        window.history.replaceState({}, '', '/kelola/piutang');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('alokasi pelunasan: kosong = dilewati; tidak boleh melebihi sisa piutang', () => {
        expect(PeriksaAlokasiPiutang('', { Sisa: '77000.00' })).toBeNull();
        expect(PeriksaAlokasiPiutang('77000.01', { Sisa: '77000.00' })).toBe('Maksimal Rp 77.000 (sisa piutang).');
        expect(PeriksaAlokasiPiutang('-1', { Sisa: '77000.00' })).toBe('Isi jumlah yang valid.');
        expect(PeriksaAlokasiPiutang('77000', { Sisa: '77000.00' })).toBeNull();
    });

    it('daftar piutang: ringkasan umur tampil; tombol pelunasan hanya untuk akuntansi.kelola', () => {
        const props = {
            Piutang: {
                ...BuatHasilTabel([]),
                Ringkasan: {
                    Total: '1250000000.00',
                    Kelompok: [{ Kunci: 'LebihDari90' as const, Label: '> 90 hari', Sisa: '1250000000.00', Jumlah: 3 }],
                },
            },
            OpsiUmur: [{ Nilai: 'LebihDari90', Label: '> 90 hari' }],
            OpsiPelanggan: [Toko],
            HariIni: '2026-09-24',
        };
        RenderUji(<HalamanDaftarPiutangPelanggan {...props} Izin={IzinPenuh} />);
        expect(screen.getByText('> 90 hari', { selector: 'p' })).toBeTruthy();
        expect(screen.getByText('3 penjualan')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'Terima pelunasan' }).length).toBeGreaterThan(0);
        cleanup();

        RenderUji(<HalamanDaftarPiutangPelanggan {...props} Izin={IzinLihat} />);
        expect(screen.queryByRole('link', { name: 'Terima pelunasan' })).toBeNull();
        cleanup();

        window.history.replaceState({}, '', '/kelola/piutang/pelunasan');
        RenderUji(
            <HalamanDaftarPelunasan
                Pelunasan={BuatHasilTabel([])}
                OpsiStatus={[{ Nilai: 'Diposting', Label: 'Diposting' }]}
                Izin={IzinLihat}
            />,
        );
        expect(screen.getByText('Belum ada pelunasan piutang.')).toBeTruthy();
    });

    it('D-23 D: pengingat terakhir tampil; pengaturan pengingat otomatis dikirim; tanpa izin tidak tampil', () => {
        const baris: BarisPiutang = {
            Uuid: '01J9PTG0000000000000000001',
            Nomor: 'UTM-K01-260924-0001',
            Tanggal: '2026-09-01',
            JatuhTempo: '2026-10-01',
            HariLewat: 0,
            Umur: 'BelumJatuhTempo',
            LabelUmur: 'Belum jatuh tempo',
            UuidPelanggan: Toko.Uuid,
            NamaPelanggan: Toko.Nama,
            Jumlah: '1250000000.00',
            Sisa: '1250000000.00',
            Status: 'BelumLunas',
            LabelStatus: 'Belum lunas',
            PengingatTerakhir: {
                Waktu: '2026-09-28T02:00:00Z',
                Kanal: 'Whatsapp',
                Status: 'Terkirim',
                LabelStatus: 'Terkirim',
            },
        };
        const props = {
            Piutang: { ...BuatHasilTabel([baris]), Ringkasan: { Total: '1250000000.00', Kelompok: [] } },
            OpsiUmur: [],
            OpsiPelanggan: [Toko],
            HariIni: '2026-09-28',
            Pengingat: { Aktif: false, HariSebelum: 3, IngatkanSaatLewat: true },
        };
        kirimanForm.length = 0;
        RenderUji(<HalamanDaftarPiutangPelanggan {...props} Izin={{ ...IzinLihat, Ingatkan: true }} />);
        expect(screen.getAllByText('Terkirim').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/WhatsApp/).length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Pengingat otomatis: mati' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Kirim pengingat otomatis' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(kirimanForm).toEqual([
            {
                metode: 'put',
                url: '/kelola/piutang/pengingat-otomatis',
                data: { Aktif: true, HariSebelum: 3, IngatkanSaatLewat: true },
            },
        ]);
        cleanup();

        RenderUji(<HalamanDaftarPiutangPelanggan {...props} Izin={IzinLihat} />);
        expect(screen.queryByRole('button', { name: /Pengingat otomatis/ })).toBeNull();
    });

    it('form pelunasan: menolak jumlah di atas sisa, lalu mengirim alokasi per piutang', () => {
        const kedua = Piutang({ Uuid: '01J9PTG0000000000000000002', Nomor: 'UTM-K01-260924-0002', Sisa: '46999.50' });
        RenderUji(
            <HalamanFormPelunasan
                OpsiPelanggan={[Toko]}
                OpsiAkun={[Akun]}
                UuidPelanggan={Toko.Uuid}
                NamaPelanggan={Toko.Nama}
                UuidPiutangAwal={null}
                Piutang={[Piutang(), kedua]}
                HariIni="2026-09-24"
            />,
        );
        const isian = screen.getByLabelText('Lunasi UTM-K01-260924-0001');
        expect((isian as HTMLInputElement).value).toBe('1.250.000.000');
        UbahNilai(isian, '1250000001');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelunasan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Maksimal Rp 1.250.000.000 (sisa piutang).')).toBeTruthy();

        UbahNilai(isian, '500000000');
        UbahNilai(screen.getByLabelText('Lunasi UTM-K01-260924-0002'), '');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelunasan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/piutang/pelunasan',
            expect.objectContaining({
                UuidPelanggan: Toko.Uuid,
                UuidAkun: Akun.Uuid,
                Tanggal: '2026-09-24',
                Alokasi: [{ UuidPiutang: '01J9PTG0000000000000000001', Jumlah: '500000000' }],
            }),
            expect.anything(),
        );
    });

    it('detail pelunasan: pembatalan hanya bila diizinkan; alasan batal tampil', () => {
        const props = {
            Pelunasan: {
                Uuid: '01J9BYR0000000000000000001',
                Nomor: 'BP/2609/0001',
                Tanggal: '2026-09-24',
                Status: 'Diposting' as const,
                LabelStatus: 'Diposting',
                Pelanggan: Toko,
                Akun: '1-1200 Bank BCA',
                Jumlah: '107000.50',
                PotonganPph: '0.00',
                NomorBuktiPotong: null,
                Catatan: null,
                AlasanBatal: null,
                DibuatOleh: 'Rina Kusuma',
            },
            Alokasi: [{ Nomor: 'UTM-K01-260924-0001', JatuhTempo: '2026-10-01', Sisa: '0.00', Jumlah: '77000.00' }],
            Jurnal: [],
            Riwayat: [],
            Izin: IzinPenuh,
        };
        RenderUji(<HalamanDetailPelunasan {...props} Tindakan={{ Batalkan: true, CatatBuktiPotong: false }} />);
        expect(screen.getByRole('button', { name: 'Batalkan pelunasan' })).toBeTruthy();
        expect(screen.getByText('Rp 107.000,50')).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanDetailPelunasan
                {...props}
                Pelunasan={{
                    ...props.Pelunasan,
                    Status: 'Dibatalkan',
                    LabelStatus: 'Dibatalkan',
                    AlasanBatal: 'Salah pilih pelanggan',
                }}
                Tindakan={{ Batalkan: false, CatatBuktiPotong: false }}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Batalkan pelunasan' })).toBeNull();
        expect(screen.getByText(/Salah pilih pelanggan/)).toBeTruthy();
    });

    it('detail pelunasan ber-PPh: menampilkan PPh dan status bukti, tombol catat bukti potong membuka dialog', () => {
        RenderUji(
            <HalamanDetailPelunasan
                Pelunasan={{
                    Uuid: '01J9BYR0000000000000000002',
                    Nomor: 'BP/2610/0002',
                    Tanggal: '2026-10-02',
                    Status: 'Diposting',
                    LabelStatus: 'Diposting',
                    Pelanggan: Toko,
                    Akun: '1-1200 Bank BCA',
                    Jumlah: '5000000.00',
                    PotonganPph: '100000.00',
                    NomorBuktiPotong: null,
                    Catatan: null,
                    AlasanBatal: null,
                    DibuatOleh: 'Rina Kusuma',
                }}
                Alokasi={[]}
                Jurnal={[]}
                Riwayat={[]}
                Izin={IzinPenuh}
                Tindakan={{ Batalkan: true, CatatBuktiPotong: true }}
            />,
        );
        expect(screen.getByText('Belum diterima dari pelanggan')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Catat bukti potong' }));
        expect(screen.getByRole('button', { name: 'Simpan nomor' })).toBeTruthy();
    });

    it('E8 hapus-buku: dialog mengirim tanggal & alasan; daftar menampilkan dokumen dan tombol "Riwayat hapus-buku"', () => {
        const baris: BarisPiutang = {
            Uuid: '01J9PTG0000000000000000009',
            Nomor: 'UTM-K01-260801-0009',
            Tanggal: '2026-08-01',
            JatuhTempo: '2026-08-31',
            HariLewat: 28,
            Umur: 'Hari0Sampai30',
            LabelUmur: '0–30 hari',
            UuidPelanggan: Toko.Uuid,
            NamaPelanggan: Toko.Nama,
            Jumlah: '77000.00',
            Sisa: '77000.00',
            Status: 'BelumLunas',
            LabelStatus: 'Belum lunas',
        };
        kirimanForm.length = 0;
        RenderUji(<DialogHapusBukuPiutang piutang={baris} hariIni="2026-09-28" saatTutup={vi.fn()} />);
        expect(screen.getByText(/Sisa Rp 77\.000/)).toBeTruthy();
        const kirim = screen.getByRole('button', { name: 'Hapus-buku piutang' });
        expect((kirim as HTMLButtonElement).disabled).toBe(true);
        fireEvent.change(screen.getByRole('textbox', { name: /Alasan/ }), {
            target: { value: 'Pelanggan tutup usaha' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Hapus-buku piutang' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/piutang/01J9PTG0000000000000000009/hapus-buku',
            { Tanggal: '2026-09-28', Alasan: 'Pelanggan tutup usaha' },
            expect.anything(),
        );
        cleanup();

        window.history.replaceState({}, '', '/kelola/piutang/hapus-buku');
        RenderUji(
            <HalamanDaftarHapusBuku
                HapusBuku={BuatHasilTabel([
                    {
                        Uuid: '01J9HBP0000000000000000001',
                        Nomor: 'HB/2609/0001',
                        NomorPiutang: baris.Nomor,
                        Tanggal: '2026-09-28',
                        NamaPelanggan: Toko.Nama,
                        Jumlah: '77000.00',
                        Alasan: 'Pelanggan tutup usaha',
                        Status: 'Diposting' as const,
                        LabelStatus: 'Diposting',
                        AlasanBatal: null,
                    },
                ])}
                OpsiStatus={[{ Nilai: 'Diposting', Label: 'Diposting' }]}
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getAllByText('HB/2609/0001').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Pelanggan tutup usaha/).length).toBeGreaterThan(0);
    });
});
