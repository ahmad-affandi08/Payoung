import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBuatPelanggan from '@/Halaman/Kelola/Pelanggan/Buat';
import HalamanBuatTierPelanggan from '@/Halaman/Kelola/Pelanggan/BuatTier';
import HalamanDaftarPelanggan from '@/Halaman/Kelola/Pelanggan/Daftar';
import HalamanDetailPelanggan from '@/Halaman/Kelola/Pelanggan/Detail';
import HalamanIsiDeposit from '@/Halaman/Kelola/Pelanggan/IsiDeposit';
import HalamanPengaturanLoyalti from '@/Halaman/Kelola/Pelanggan/PengaturanLoyalti';
import HalamanTierPelanggan, { FormatPengali } from '@/Halaman/Kelola/Pelanggan/Tier';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import FormulirPelanggan from '@/Komponen/Pelanggan/FormulirPelanggan';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type { BarisPelanggan } from '@/Tipe/Pelanggan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Ani: BarisPelanggan = {
    Uuid: '01K5PELANGGAN0000000000001',
    Nama: 'Ani Rahmawati Kusumaningtyas Wulandari Putri',
    NoHp: '0812-3456-7890',
    Email: 'ani@contoh.id',
    TanggalLahir: '1990-05-17',
    Alamat: 'Jl. Slamet Riyadi No. 10, Solo',
    Tag: ['Langganan', 'Reseller'],
    Catatan: null,
    SetujuPemasaran: true,
    Status: 'Aktif',
    Tier: { Kode: 'GOLD', Nama: 'Gold' },
    TierTetap: false,
    SaldoPoin: 1250,
    LimitKredit: '5000000.00',
    TerminHari: 30,
    DibuatPada: '2026-09-20T02:00:00Z',
    JumlahTransaksi: 12,
    TotalBelanja: '12500000.00',
    TerakhirPada: '2026-09-24T05:30:00Z',
    SaldoDeposit: '350000.00',
};

const DepositKosong = { Saldo: '0.00', Berlaku: false, Riwayat: [], AkunKasBank: [] };
const PaketSesiKosong = { Berlaku: false, Daftar: [] };

const OpsiTierUji = [
    { Nilai: 'SILVER', Label: 'Silver (SILVER)', Uuid: '01K5T1ER000000000000S1LVER' },
    { Nilai: 'GOLD', Label: 'Gold (GOLD)', Uuid: '01K5T1ER0000000000000G0LD1' },
];

describe('Halaman pelanggan (F-16a)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pelanggan');
        window.history.replaceState({}, '', '/kelola/pelanggan');
    });
    afterEach(() => cleanup());

    it('daftar: ringkasan belanja & tag tampil. Tambah (halaman penuh /buat) hanya untuk pelanggan.kelola. Ubah di panel', () => {
        RenderUji(
            <HalamanDaftarPelanggan
                Pelanggan={BuatHasilTabel([Ani])}
                OpsiTag={['Langganan', 'Reseller']}
                OpsiTier={OpsiTierUji}
                Izin={{ Kelola: true, LihatPenjualan: true }}
            />,
        );
        expect(screen.getAllByText('Rp 12.500.000').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Langganan | Reseller').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Tambah pelanggan' }).getAttribute('href')).toBe(
            '/kelola/pelanggan/buat',
        );
        expect(screen.queryByRole('button', { name: 'Tambah pelanggan' })).toBeNull();

        cleanup();
        RenderUji(
            <HalamanDaftarPelanggan
                Pelanggan={BuatHasilTabel([])}
                OpsiTag={[]}
                OpsiTier={[]}
                Izin={{ Kelola: false, LihatPenjualan: false }}
            />,
        );
        expect(screen.queryByRole('link', { name: 'Tambah pelanggan' })).toBeNull();
    });

    it('halaman buat pelanggan: formulir mengirim POST; Batal kembali ke daftar', () => {
        window.history.replaceState({}, '', '/kelola/pelanggan/buat');
        RenderUji(<HalamanBuatPelanggan />);
        fireEvent.change(screen.getByLabelText('Nama pelanggan'), { target: { value: 'Budi Santoso' } });
        fireEvent.change(screen.getByLabelText('No. HP/WA'), { target: { value: '0813 1111 2222' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelanggan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pelanggan',
            expect.objectContaining({ Nama: 'Budi Santoso', NoHp: '0813 1111 2222', TanggalLahir: null, Tag: [] }),
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/pelanggan');
    });

    it('panel ubah pelanggan tetap di panel: kirim PUT; Batal menutup panel', () => {
        const TutupPanel = vi.fn();
        RenderUji(<FormulirPelanggan pelanggan={Ani} saatTutup={TutupPanel} />);
        expect(screen.getByText(`Ubah pelanggan ${Ani.Nama}`)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pelanggan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/pelanggan/${Ani.Uuid}`,
            expect.objectContaining({ Nama: Ani.Nama, LimitKredit: '5000000' }),
            expect.anything(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(TutupPanel).toHaveBeenCalled();
    });

    it('detail: profil, ringkasan, riwayat bertaut ke penjualan. Arsipkan mengirim POST', () => {
        RenderUji(
            <HalamanDetailPelanggan
                PesananOnline={[]}
                NoHpTerverifikasi={false}
                Pelanggan={Ani}
                Kredit={{ LimitKredit: '5000000.00', SisaPiutang: '1250000.00', HariLewatJatuhTempo: 12 }}
                Deposit={DepositKosong}
                PaketSesi={{
                    Berlaku: true,
                    Daftar: [
                        {
                            Uuid: '01K5SALDOSESI0000000000001',
                            NamaPaket: 'Paket Creambath Rambut Panjang 10x Sesi',
                            NomorPenjualan: 'INV/SLB/260924/POS-001-0008',
                            JumlahSesi: 10,
                            SisaSesi: 7,
                            NilaiAwal: '1000000.00',
                            NilaiTersisa: '700000.00',
                            TanggalBeli: '2026-09-24',
                            BerlakuSampai: '2026-12-23',
                            Status: 'Aktif',
                            LabelStatus: 'Aktif',
                        },
                    ],
                }}
                Riwayat={[
                    {
                        Uuid: '01K5JUAL000000000000000001',
                        Nomor: 'INV/SLB/260924/POS-001-0007',
                        TanggalBisnis: '2026-09-24',
                        DibuatPada: '2026-09-24T05:30:00Z',
                        Status: 'Lunas',
                        TotalAkhir: '1250000.00',
                    },
                ]}
                RiwayatPoin={[
                    {
                        Id: 1,
                        Jenis: 'Perolehan',
                        LabelJenis: 'Perolehan dari belanja',
                        Poin: 125,
                        Sisa: 125,
                        KedaluwarsaPada: '2027-09-24',
                        Keterangan: null,
                        DibuatPada: '2026-09-24T05:30:00Z',
                    },
                ]}
                OpsiTier={OpsiTierUji}
                LoyaltiBerlaku
                Izin={{ Kelola: true, LihatPenjualan: true, KelolaDeposit: false }}
            />,
        );
        expect(screen.getByText('0812-3456-7890')).toBeTruthy();
        expect(screen.getAllByText('+125').length).toBeGreaterThan(0);
        expect(screen.getByText('1.250')).toBeTruthy();
        expect(screen.getByText('Setuju menerima')).toBeTruthy();
        expect(screen.getByText('Rp 5.000.000')).toBeTruthy();
        expect(screen.getByText('12 hari')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Lihat piutang' })).toBeTruthy();
        // F-16d bagian 2: paket sesi pelanggan bertaut ke detail saldo sesi.
        expect(
            screen.getAllByRole('link', { name: 'Paket Creambath Rambut Panjang 10x Sesi' })[0]?.getAttribute('href'),
        ).toBe('/kelola/pelanggan/saldo-sesi/01K5SALDOSESI0000000000001');
        expect(screen.getAllByText('7 dari 10').length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: 'INV/SLB/260924/POS-001-0007' })[0]?.getAttribute('href')).toBe(
            '/kelola/penjualan/01K5JUAL000000000000000001',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Arsipkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(`/kelola/pelanggan/${Ani.Uuid}/arsipkan`, {}, expect.anything());

        // F-16b: penyesuaian poin manual.
        fireEvent.click(screen.getByRole('button', { name: 'Sesuaikan poin' }));
        fireEvent.change(screen.getByLabelText('Poin (+ tambah, − kurangi)'), { target: { value: '-50' } });
        fireEvent.change(screen.getByLabelText('Alasan'), { target: { value: 'Salah input kasir' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan penyesuaian' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pelanggan/${Ani.Uuid}/poin`,
            { Poin: -50, Alasan: 'Salah input kasir' },
            expect.anything(),
        );
    });

    it('F-16b tier: daftar tier dengan pengali. Tambah lewat halaman /buat, tanpa fitur tampil ajakan paket', () => {
        RenderUji(
            <HalamanTierPelanggan
                Tier={[
                    {
                        Uuid: '01K5T1ER0000000000000G0LD1',
                        Kode: 'GOLD',
                        Nama: 'Gold',
                        MinimalBelanja: '5000000.00',
                        PengaliPoin: '1.50',
                        Urutan: 2,
                        Status: 'Aktif',
                        JumlahPelanggan: 12,
                    },
                ]}
                FiturAktif={false}
                Izin={{ Kelola: true }}
            />,
        );
        expect(screen.getByText('Loyalti tersedia di paket Pro ke atas')).toBeTruthy();
        expect(screen.getAllByText('×1,5').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 5.000.000').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Tambah tier' }).getAttribute('href')).toBe(
            '/kelola/pelanggan/tier/buat',
        );
        expect(FormatPengali('2.00')).toBe('×2');
    });

    it('F-16b halaman buat tier: formulir mengirim POST; Batal kembali ke daftar tier', () => {
        window.history.replaceState({}, '', '/kelola/pelanggan/tier/buat');
        RenderUji(<HalamanBuatTierPelanggan FiturAktif={false} />);
        expect(screen.getByText('Loyalti tersedia di paket Pro ke atas')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Kode tier'), { target: { value: 'SILVER' } });
        fireEvent.change(screen.getByLabelText('Nama tier'), { target: { value: 'Silver' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan tier' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pelanggan/tier',
            expect.objectContaining({
                Kode: 'SILVER',
                Nama: 'Silver',
                MinimalBelanja: '0',
                PengaliPoin: '1',
                Urutan: 0,
            }),
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/pelanggan/tier');
    });

    it('F-16b pengaturan loyalti: contoh perhitungan & simpan mengirim PUT', () => {
        RenderUji(
            <HalamanPengaturanLoyalti
                Pengaturan={{
                    Aktif: false,
                    BelanjaPerPoin: '10000.00',
                    MasaBerlakuBulan: 12,
                    BulanEvaluasiTier: 12,
                    NilaiTukarPoin: '100.00',
                    MinimalTukarPoin: 10,
                }}
                FiturAktif
                Izin={{ Kelola: true }}
            />,
        );
        expect(screen.getByText(/mendapat 25 poin/)).toBeTruthy();
        expect(screen.getByText(/Menukar 100 poin memberi potongan Rp 10\.000/)).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Minimal poin sekali tukar'), { target: { value: '50' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /Aktifkan poin loyalti/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan loyalti' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/pelanggan/loyalti',
            {
                Aktif: true,
                BelanjaPerPoin: '10000',
                MasaBerlakuBulan: 12,
                BulanEvaluasiTier: 12,
                NilaiTukarPoin: '100',
                MinimalTukarPoin: 50,
            },
            expect.anything(),
        );
    });

    it('F-16d detail: saldo & riwayat deposit, tarik ke akun kas dan sesuaikan (kurangi) terkirim', () => {
        RenderUji(
            <HalamanDetailPelanggan
                PesananOnline={[]}
                NoHpTerverifikasi={false}
                Pelanggan={Ani}
                Riwayat={[]}
                RiwayatPoin={[]}
                OpsiTier={OpsiTierUji}
                LoyaltiBerlaku={false}
                Kredit={null}
                PaketSesi={PaketSesiKosong}
                Deposit={{
                    Saldo: '350000.00',
                    Berlaku: true,
                    Riwayat: [
                        {
                            Uuid: '01K5MUTASIDEPOSIT000000001',
                            Jenis: 'Isi',
                            LabelJenis: 'Isi deposit',
                            Jumlah: '350000.00',
                            SaldoSetelah: '350000.00',
                            NomorSumber: 'DEP/SLB/260924/POS-001-0001',
                            Tanggal: '2026-09-24',
                            Keterangan: null,
                            DibuatPada: '2026-09-24T05:30:00Z',
                        },
                    ],
                    AkunKasBank: [{ Uuid: '01K5AKUNKAS000000000000001', Kode: '1-1100', Nama: 'Kas Besar' }],
                }}
                Izin={{ Kelola: false, LihatPenjualan: false, KelolaDeposit: true }}
            />,
        );
        expect(screen.getAllByText('Rp 350.000').length).toBeGreaterThan(0);
        expect(screen.getAllByText('+Rp 350.000').length).toBeGreaterThan(0);
        expect(screen.getAllByText('DEP/SLB/260924/POS-001-0001').length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Tarik deposit' }));
        fireEvent.change(screen.getByLabelText('Jumlah ditarik'), { target: { value: '100000' } });
        fireEvent.change(screen.getByLabelText('Alasan'), { target: { value: 'Pelanggan pindah kota' } });
        fireEvent.submit(screen.getByRole('form', { name: 'Formulir tarik deposit' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pelanggan/${Ani.Uuid}/deposit/tarik`,
            { Jumlah: '100000', UuidAkun: '01K5AKUNKAS000000000000001', Alasan: 'Pelanggan pindah kota' },
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDetailPelanggan
                PesananOnline={[]}
                NoHpTerverifikasi={false}
                Pelanggan={Ani}
                Riwayat={[]}
                RiwayatPoin={[]}
                OpsiTier={OpsiTierUji}
                LoyaltiBerlaku={false}
                Kredit={null}
                PaketSesi={PaketSesiKosong}
                Deposit={{ Saldo: '-27000.00', Berlaku: true, Riwayat: [], AkunKasBank: [] }}
                Izin={{ Kelola: false, LihatPenjualan: false, KelolaDeposit: true }}
            />,
        );
        expect(screen.getByText(/Saldo minus/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Tarik deposit' }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Sesuaikan deposit' }));
        PilihOpsi(screen.getByRole('combobox', { name: 'Arah' }), 'Kurangi');
        fireEvent.change(screen.getByLabelText('Jumlah'), { target: { value: '5000' } });
        fireEvent.change(screen.getByLabelText('Alasan'), { target: { value: 'Koreksi salah input kasir' } });
        fireEvent.submit(screen.getByRole('form', { name: 'Formulir penyesuaian deposit' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pelanggan/${Ani.Uuid}/deposit/sesuaikan`,
            { Jumlah: '-5000', Alasan: 'Koreksi salah input kasir' },
            expect.anything(),
        );
    });

    it('F-16d daftar isi deposit: tinjauan tampil. Batal isi butuh alasan ≥ 5 karakter lalu terkirim', () => {
        window.history.replaceState({}, '', '/kelola/pelanggan/isi-deposit');
        RenderUji(
            <HalamanIsiDeposit
                IsiDeposit={BuatHasilTabel([
                    {
                        Uuid: '01K5ISIDEPOSIT000000000001',
                        Nomor: 'DEP/SLB/260924/POS-001-0001',
                        Pelanggan: { Uuid: Ani.Uuid, Nama: 'Ani Rahmawati' },
                        NamaMetode: 'Tunai',
                        Jumlah: '150000.00',
                        Status: 'Diterima',
                        LabelStatus: 'Diterima',
                        TanggalBisnis: '2026-09-24',
                        PerluTinjauan: true,
                        AlasanTinjauan: 'ShiftSudahDitutup: isi deposit diterima setelah shift ditutup',
                        AlasanBatal: null,
                        DiterimaPada: '2026-09-24T05:30:00Z',
                    },
                ])}
                Izin={{ KelolaDeposit: true }}
            />,
        );
        expect(screen.getAllByText('Perlu ditinjau').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Rp 150.000').length).toBeGreaterThan(0);
        BukaMenu(screen.getByRole('button', { name: 'Aksi isi deposit DEP/SLB/260924/POS-001-0001' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Batalkan isi deposit' }));
        const tombol = screen.getByRole('button', { name: 'Batalkan isi deposit' }) as HTMLButtonElement;
        expect(tombol.disabled).toBe(true);
        fireEvent.change(screen.getByLabelText('Alasan'), { target: { value: 'Salah pelanggan' } });
        fireEvent.click(tombol);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pelanggan/isi-deposit/01K5ISIDEPOSIT000000000001/batal',
            { Alasan: 'Salah pelanggan' },
            expect.anything(),
        );
    });
});
