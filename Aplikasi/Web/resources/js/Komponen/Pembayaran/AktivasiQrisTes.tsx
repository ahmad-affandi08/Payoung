import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AktivasiQris, {
    PenjelasanStatus,
    PeriksaLangkah,
    type IsianAktivasi,
} from '@/Halaman/Kelola/Pembayaran/AktivasiQris';
import { HitungKeadaanLangkah } from '@/Komponen/Pembayaran/LiniMasaPendaftaran';
import type { PendaftaranMerchantTenant, PropsAktivasiQris } from '@/Tipe/AktivasiQris';

/*
 * Aktivasi QRIS otomatis (DOKU Partner): wizard 3 langkah, foto dari kamera HP, status berupa linimasa, NIK & rekening
 * hanya tersamar, kirim ulang saat Ditolak/Gagal.
 */

const uji = vi.hoisted(() => ({
    kiriman: [] as { url: string; data: Record<string, unknown> }[],
    router: [] as { url: string }[],
    muatUlang: 0,
    halaman: { props: { errors: {} } as Record<string, unknown>, url: '/kelola/pembayaran/aktivasi-qris' },
}));

vi.mock('@inertiajs/react', () => {
    function useForm<T extends object>(awal: T) {
        const [data, AturData] = useState<T>(awal);

        return {
            data,
            setData: (kunci: keyof T | ((lama: T) => T), nilai?: unknown) =>
                AturData((lama) => (typeof kunci === 'function' ? kunci(lama) : { ...lama, [kunci]: nilai })),
            errors: {},
            processing: false,
            post: (url: string) => uji.kiriman.push({ url, data: data as Record<string, unknown> }),
        };
    }

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
        router: {
            post: (url: string) => uji.router.push({ url }),
            reload: () => {
                uji.muatUlang += 1;
            },
        },
        usePage: () => uji.halaman,
        useForm,
    };
});

vi.mock('@/TataLetak/TataLetakAplikasi', () => ({
    default: ({ judul, children }: { judul: string; children: ReactNode }) => (
        <main>
            <h1>{judul}</h1>
            {children}
        </main>
    ),
}));

beforeEach(() => {
    URL.createObjectURL = vi.fn(() => 'blob:pratinjau');
    URL.revokeObjectURL = vi.fn();
    uji.kiriman.length = 0;
    uji.router.length = 0;
    uji.muatUlang = 0;
    uji.halaman.props = { errors: {} };
});

afterEach(() => cleanup());

function Pendaftaran(ubah: Partial<PendaftaranMerchantTenant> = {}): PendaftaranMerchantTenant {
    return {
        Uuid: '01K5MERCHANT00000000000001',
        Status: 'Draf',
        LabelStatus: 'Draf',
        BisaDiubah: true,
        NamaPemilik: 'Budi Santoso',
        NikTersamar: '••••0001',
        Email: 'budi@kelontongberkah.id',
        NomorHp: '6281234567890',
        NamaUsaha: 'Toko Kelontong Berkah Solo',
        AlamatUsaha: 'Jl. Slamet Riyadi No. 120, Surakarta',
        IdReferensiBank: 1,
        NamaBank: 'Bank Central Asia',
        NamaPemilikRekening: 'Budi Santoso',
        RekeningTersamar: '••••0123',
        FotoTersimpan: { Ktp: false, Swafoto: false, BuktiUsaha: false },
        PesanGalat: null,
        AlasanPenolakan: null,
        DikirimPada: null,
        DisetujuiPada: null,
        ...ubah,
    };
}

function Props(ubah: Partial<PropsAktivasiQris> = {}): PropsAktivasiQris {
    return {
        LayananTersedia: true,
        BatasFotoMb: 5,
        DaftarBank: [{ Id: 1, Nama: 'Bank Central Asia' }],
        Awal: {
            NamaPemilik: 'Budi Santoso',
            Email: 'budi@kelontongberkah.id',
            NamaUsaha: 'Toko Kelontong Berkah Solo',
        },
        Pendaftaran: null,
        ...ubah,
    };
}

const isianKosong: IsianAktivasi = {
    NamaPemilik: '',
    Nik: '',
    Email: '',
    NomorHp: '',
    NamaUsaha: '',
    AlamatUsaha: '',
    IdReferensiBank: '',
    NamaPemilikRekening: '',
    NomorRekening: '',
    FotoKtp: null,
    FotoSwafoto: null,
    FotoBuktiUsaha: null,
};

function Foto(nama: string, jenis = 'image/jpeg', ukuran = 1024): File {
    const berkas = new File(['x'], nama, { type: jenis });
    Object.defineProperty(berkas, 'size', { value: ukuran });

    return berkas;
}

describe('PeriksaLangkah (pemeriksaan awal di peramban)', () => {
    it('langkah 1: NIK harus 16 angka, nomor HP dan alamat diperiksa; NIK kosong boleh bila sudah tersimpan', () => {
        const galat = PeriksaLangkah(0, { ...isianKosong, Nik: '1234', NomorHp: '123', AlamatUsaha: 'pendek' }, null);

        expect(Object.keys(galat).sort()).toEqual([
            'AlamatUsaha',
            'Email',
            'NamaPemilik',
            'NamaUsaha',
            'Nik',
            'NomorHp',
        ]);

        const lengkap = {
            ...isianKosong,
            NamaPemilik: 'Budi',
            Nik: '3372010101900001',
            Email: 'budi@contoh.id',
            NomorHp: '0812-3456-7890',
            NamaUsaha: 'Toko',
            AlamatUsaha: 'Jl. Slamet Riyadi 120',
        };
        expect(PeriksaLangkah(0, lengkap, null)).toEqual({});
        expect(PeriksaLangkah(0, { ...lengkap, Nik: '' }, null).Nik).toBeTruthy();
        expect(PeriksaLangkah(0, { ...lengkap, Nik: '' }, Pendaftaran()).Nik).toBeUndefined();
    });

    it('langkah 2: bank, nama, dan nomor rekening 5 sampai 20 angka. Kosong boleh bila sudah tersimpan', () => {
        expect(Object.keys(PeriksaLangkah(1, isianKosong, null)).sort()).toEqual([
            'IdReferensiBank',
            'NamaPemilikRekening',
            'NomorRekening',
        ]);
        expect(
            PeriksaLangkah(
                1,
                { ...isianKosong, IdReferensiBank: '1', NamaPemilikRekening: 'Budi', NomorRekening: '1234 5678' },
                null,
            ),
        ).toEqual({});
        expect(
            PeriksaLangkah(
                1,
                { ...isianKosong, IdReferensiBank: '1', NamaPemilikRekening: 'Budi', NomorRekening: '' },
                Pendaftaran(),
            ).NomorRekening,
        ).toBeUndefined();
    });

    it('langkah 3: ketiga foto wajib, kecuali yang sudah tersimpan', () => {
        expect(Object.keys(PeriksaLangkah(2, isianKosong, null)).sort()).toEqual([
            'FotoBuktiUsaha',
            'FotoKtp',
            'FotoSwafoto',
        ]);
        expect(
            PeriksaLangkah(
                2,
                isianKosong,
                Pendaftaran({ FotoTersimpan: { Ktp: true, Swafoto: true, BuktiUsaha: true } }),
            ),
        ).toEqual({});
    });
});

describe('linimasa dan penjelasan status', () => {
    it('posisi langkah menurut status, termasuk berhenti di Kirim (Gagal) dan Tinjau (Ditolak)', () => {
        expect(HitungKeadaanLangkah('Draf')).toEqual(['berjalan', 'menunggu', 'menunggu', 'menunggu']);
        expect(HitungKeadaanLangkah('Ditinjau')).toEqual(['selesai', 'selesai', 'berjalan', 'menunggu']);
        expect(HitungKeadaanLangkah('Aktif')).toEqual(['selesai', 'selesai', 'selesai', 'selesai']);
        expect(HitungKeadaanLangkah('Gagal')).toEqual(['selesai', 'gagal', 'menunggu', 'menunggu']);
        expect(HitungKeadaanLangkah('Ditolak')).toEqual(['selesai', 'selesai', 'gagal', 'menunggu']);
    });

    it('penjelasan Ditinjau menyebut 1 sampai 2 hari kerja', () => {
        expect(PenjelasanStatus('Ditinjau')).toContain('1 sampai 2 hari kerja');
    });
});

describe('halaman Aktivasi QRIS', () => {
    it('kosong: wizard langkah 1 dari 3 terisi awal dari akun, tanpa status', () => {
        render(<AktivasiQris {...Props()} />);

        expect(screen.getByRole('heading', { name: 'Aktivasi QRIS' })).toBeTruthy();
        expect(screen.getByText('Langkah 1 dari 3: Data pemilik dan usaha')).toBeTruthy();
        expect((screen.getByLabelText(/^Nama usaha/) as HTMLInputElement).value).toBe('Toko Kelontong Berkah Solo');
        expect(screen.queryByText('Status pendaftaran')).toBeNull();
        expect(screen.getByRole('button', { name: 'Lanjut' })).toBeTruthy();
    });

    it('Lanjut dengan isian kurang menampilkan masalah dan tetap di langkah 1', () => {
        render(<AktivasiQris {...Props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));

        expect(screen.getByText('NIK harus 16 angka.')).toBeTruthy();
        expect(screen.getByText('Tulis alamat usaha selengkapnya.')).toBeTruthy();
        expect(screen.getByText('Langkah 1 dari 3: Data pemilik dan usaha')).toBeTruthy();
    });

    it('draf lengkap: tiga langkah berurutan, foto dari kamera (capture), kirim hanya setelah ketiga foto dipilih', () => {
        render(<AktivasiQris {...Props({ Pendaftaran: Pendaftaran() })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByText('Langkah 2 dari 3: Rekening toko')).toBeTruthy();
        expect(screen.getByText('Tersimpan ••••0123. Kosongkan bila tidak diganti.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(screen.getByText('Langkah 3 dari 3: Foto')).toBeTruthy();
        expect(screen.getByLabelText('Foto KTP').getAttribute('capture')).toBe('environment');
        expect(screen.getByLabelText('Foto selfie sambil memegang KTP').getAttribute('capture')).toBe('user');
        expect(screen.getByLabelText('Foto tempat usaha').getAttribute('accept')).toBe('image/jpeg,image/png');

        fireEvent.click(screen.getByRole('button', { name: 'Kirim pendaftaran' }));
        expect(screen.getByText('Ambil atau pilih foto KTP.')).toBeTruthy();
        expect(uji.kiriman).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('Foto KTP'), { target: { files: [Foto('ktp.jpg')] } });
        fireEvent.change(screen.getByLabelText('Foto selfie sambil memegang KTP'), {
            target: { files: [Foto('selfie.png', 'image/png')] },
        });
        fireEvent.change(screen.getByLabelText('Foto tempat usaha'), { target: { files: [Foto('toko.jpg')] } });
        fireEvent.click(screen.getByRole('button', { name: 'Kirim pendaftaran' }));

        expect(uji.kiriman).toHaveLength(1);
        expect(uji.kiriman[0]?.url).toBe('/kelola/pembayaran/aktivasi-qris/kirim');
        expect(uji.kiriman[0]?.data.NamaPemilik).toBe('Budi Santoso');
    });

    it('foto bukan JPG/PNG atau terlalu besar ditolak di peramban sebelum dikirim', () => {
        render(<AktivasiQris {...Props({ Pendaftaran: Pendaftaran() })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));

        fireEvent.change(screen.getByLabelText('Foto KTP'), {
            target: { files: [Foto('ktp.pdf', 'application/pdf')] },
        });
        expect(screen.getByText(/Format ktp\.pdf tidak didukung/)).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Foto tempat usaha'), {
            target: { files: [Foto('toko.jpg', 'image/jpeg', 8 * 1024 * 1024)] },
        });
        expect(screen.getByText(/melebihi batas/)).toBeTruthy();
    });

    it('Simpan draf mengirim ke rute draf; Kembali ke langkah sebelumnya', () => {
        render(<AktivasiQris {...Props({ Pendaftaran: Pendaftaran() })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        expect(screen.getByText('Langkah 1 dari 3: Data pemilik dan usaha')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));

        expect(uji.kiriman[0]?.url).toBe('/kelola/pembayaran/aktivasi-qris/draf');
    });

    it('Ditinjau: linimasa dan ringkasan tersamar, tanpa formulir, tombol Perbarui status', () => {
        render(
            <AktivasiQris
                {...Props({
                    Pendaftaran: Pendaftaran({
                        Status: 'Ditinjau',
                        LabelStatus: 'Sedang ditinjau',
                        BisaDiubah: false,
                        FotoTersimpan: { Ktp: true, Swafoto: true, BuktiUsaha: true },
                    }),
                })}
            />,
        );

        expect(screen.getByText('Sedang ditinjau')).toBeTruthy();
        expect(screen.getByRole('list', { name: 'Tahap pendaftaran' })).toBeTruthy();
        expect(screen.getByText('••••0001')).toBeTruthy();
        expect(screen.getByText('••••0123')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kirim pendaftaran' })).toBeNull();
        expect(screen.queryByText(/Langkah \d dari 3/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Perbarui status' }));
        expect(uji.muatUlang).toBe(1);
    });

    it('Disetujui: pesan jelas tanpa menjanjikan QRIS langsung aktif', () => {
        render(
            <AktivasiQris
                {...Props({
                    Pendaftaran: Pendaftaran({ Status: 'Aktif', LabelStatus: 'Disetujui', BisaDiubah: false }),
                })}
            />,
        );

        expect(screen.getByText(/Pendaftaran Anda disetujui DOKU/)).toBeTruthy();
        expect(screen.getByText(/menyiapkan langkah berikutnya/)).toBeTruthy();
    });

    it('Ditolak: menampilkan alasan DOKU, formulir terbuka lagi, dan foto wajib diambil ulang', () => {
        render(
            <AktivasiQris
                {...Props({
                    Pendaftaran: Pendaftaran({
                        Status: 'Ditolak',
                        LabelStatus: 'Ditolak',
                        AlasanPenolakan: 'Foto KTP buram, mohon unggah ulang.',
                    }),
                })}
            />,
        );

        expect(screen.getByText('Foto KTP buram, mohon unggah ulang.')).toBeTruthy();
        expect(screen.getByText('Foto lama sudah dihapus, jadi foto perlu diambil lagi.')).toBeTruthy();
        expect(screen.getByText('Langkah 1 dari 3: Data pemilik dan usaha')).toBeTruthy();
    });

    it('Gagal: pesan masalah terfilter tampil dan pendaftaran bisa dikirim ulang', () => {
        render(
            <AktivasiQris
                {...Props({
                    Pendaftaran: Pendaftaran({
                        Status: 'Gagal',
                        LabelStatus: 'Gagal dikirim',
                        PesanGalat: 'DOKU menolak saat mendaftarkan bisnis (HTTP 422): NIK tidak valid.',
                    }),
                })}
            />,
        );

        expect(screen.getByText(/NIK tidak valid/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Lanjut' })).toBeTruthy();
    });

    it('layanan belum tersedia: peringatan jelas dan tidak ada formulir', () => {
        render(<AktivasiQris {...Props({ LayananTersedia: false })} />);

        expect(screen.getByText('Aktivasi QRIS otomatis belum tersedia. Hubungi dukungan Payoung.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Lanjut' })).toBeNull();
    });

    it('batalkan pendaftaran meminta konfirmasi lalu memanggil rute batal', () => {
        render(<AktivasiQris {...Props({ Pendaftaran: Pendaftaran() })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan pendaftaran' }));
        expect(screen.getByText('Data dan foto sementara akan dihapus. Lanjutkan?')).toBeTruthy();
        expect(uji.router).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Ya, batalkan pendaftaran' }));
        expect(uji.router[0]?.url).toBe('/kelola/pembayaran/aktivasi-qris/batal');
    });
});
