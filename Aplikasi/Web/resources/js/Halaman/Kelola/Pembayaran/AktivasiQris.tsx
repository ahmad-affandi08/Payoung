import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import BidangFotoKyc from '@/Komponen/Pembayaran/BidangFotoKyc';
import LiniMasaPendaftaran from '@/Komponen/Pembayaran/LiniMasaPendaftaran';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PendaftaranMerchantTenant, PropsAktivasiQris, StatusPendaftaranMerchant } from '@/Tipe/AktivasiQris';

const alamat = '/kelola/pembayaran/aktivasi-qris';

const jenisStatus = {
    Draf: 'netral',
    Dikirim: 'peringatan',
    Ditinjau: 'peringatan',
    Aktif: 'sukses',
    Ditolak: 'bahaya',
    Gagal: 'bahaya',
} as const satisfies Record<StatusPendaftaranMerchant, string>;

const JUDUL_LANGKAH = ['Data pemilik dan usaha', 'Rekening toko', 'Foto'] as const;

export type IsianAktivasi = {
    NamaPemilik: string;
    Nik: string;
    Email: string;
    NomorHp: string;
    NamaUsaha: string;
    AlamatUsaha: string;
    IdReferensiBank: string;
    NamaPemilikRekening: string;
    NomorRekening: string;
    FotoKtp: File | null;
    FotoSwafoto: File | null;
    FotoBuktiUsaha: File | null;
};

const HanyaAngka = (nilai: string) => nilai.replace(/\D+/g, '');

/** Isian kosong untuk memetakan nama kolom ke langkahnya (lihat `LangkahDenganGalat`). */
const KOSONG: IsianAktivasi = {
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

/**
 * Pemeriksaan awal per langkah di peramban; server tetap memvalidasi ulang. NIK dan nomor rekening yang kosong boleh
 * bila sudah tersimpan (nilainya tidak pernah ditampilkan ulang). Mengembalikan pesan per kolom, kosong bila lolos.
 */
export function PeriksaLangkah(
    langkah: number,
    data: IsianAktivasi,
    pendaftaran: PendaftaranMerchantTenant | null,
): Record<string, string> {
    const galat: Record<string, string> = {};

    if (langkah === 0) {
        if (data.NamaPemilik.trim() === '') galat.NamaPemilik = 'Nama pemilik wajib diisi.';
        if (HanyaAngka(data.Nik).length !== 16 && !(HanyaAngka(data.Nik) === '' && pendaftaran?.NikTersamar))
            galat.Nik = 'NIK harus 16 angka.';
        if (!/^\S+@\S+\.\S+$/.test(data.Email.trim())) galat.Email = 'Isi email yang benar.';
        if (!/^(\+?62|0)8\d{7,12}$/.test(data.NomorHp.replace(/[\s\-().]+/g, '')))
            galat.NomorHp = 'Nomor HP tidak valid. Contoh: 0812 3456 7890.';
        if (data.NamaUsaha.trim() === '') galat.NamaUsaha = 'Nama usaha wajib diisi.';
        if (data.AlamatUsaha.trim().length < 10) galat.AlamatUsaha = 'Tulis alamat usaha selengkapnya.';
    }

    if (langkah === 1) {
        if (data.IdReferensiBank === '') galat.IdReferensiBank = 'Pilih bank rekening toko.';
        if (data.NamaPemilikRekening.trim() === '') galat.NamaPemilikRekening = 'Nama pemilik rekening wajib diisi.';
        const rekening = HanyaAngka(data.NomorRekening);
        if ((rekening.length < 5 || rekening.length > 20) && !(rekening === '' && pendaftaran?.RekeningTersamar))
            galat.NomorRekening = 'Nomor rekening harus 5 sampai 20 angka.';
    }

    if (langkah === 2) {
        if (data.FotoKtp === null && !pendaftaran?.FotoTersimpan.Ktp) galat.FotoKtp = 'Ambil atau pilih foto KTP.';
        if (data.FotoSwafoto === null && !pendaftaran?.FotoTersimpan.Swafoto)
            galat.FotoSwafoto = 'Ambil atau pilih foto selfie.';
        if (data.FotoBuktiUsaha === null && !pendaftaran?.FotoTersimpan.BuktiUsaha)
            galat.FotoBuktiUsaha = 'Ambil atau pilih foto tempat usaha.';
    }

    return galat;
}

/** Penjelasan status untuk tenant dalam bahasa sehari-hari. */
export function PenjelasanStatus(status: StatusPendaftaranMerchant): string {
    switch (status) {
        case 'Draf':
            return 'Data Anda tersimpan tetapi belum dikirim.';
        case 'Dikirim':
            return 'Pendaftaran sedang dikirim ke DOKU. Biasanya selesai dalam beberapa menit.';
        case 'Ditinjau':
            return 'DOKU sedang meninjau data Anda. Biasanya 1 sampai 2 hari kerja. Anda tidak perlu melakukan apa-apa.';
        case 'Aktif':
            return 'Pendaftaran Anda disetujui DOKU. Tim Payoung akan menyiapkan langkah berikutnya agar QRIS toko Anda bisa dipakai di kasir.';
        case 'Ditolak':
            return 'DOKU tidak menyetujui pendaftaran ini. Perbaiki data sesuai alasan di bawah, lalu kirim ulang.';
        case 'Gagal':
            return 'Pendaftaran belum bisa dikirim. Perbaiki data sesuai pesan di bawah, lalu kirim ulang.';
    }
}

function RingkasanData({ pendaftaran }: { pendaftaran: PendaftaranMerchantTenant }) {
    const baris: [string, string | null][] = [
        ['Nama pemilik', pendaftaran.NamaPemilik],
        ['NIK', pendaftaran.NikTersamar],
        ['Nama usaha', pendaftaran.NamaUsaha],
        ['Bank', pendaftaran.NamaBank],
        ['Nama pemilik rekening', pendaftaran.NamaPemilikRekening],
        ['Nomor rekening', pendaftaran.RekeningTersamar],
        ['Dikirim', pendaftaran.DikirimPada ? FormatTanggalWaktu(pendaftaran.DikirimPada) : null],
        ['Disetujui', pendaftaran.DisetujuiPada ? FormatTanggalWaktu(pendaftaran.DisetujuiPada) : null],
    ];

    return (
        <dl className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-2 text-isi">
            {baris
                .filter(([, nilai]) => nilai !== null && nilai !== '')
                .map(([label, nilai]) => (
                    <div key={label} className="contents">
                        <dt className="text-teks-sekunder">{label}</dt>
                        <dd className="break-words text-teks-utama">{nilai}</dd>
                    </div>
                ))}
        </dl>
    );
}

/**
 * Aktivasi QRIS otomatis (DOKU Partner API): tenant cukup memberi foto KTP, selfie, foto tempat usaha, dan rekening toko.
 * Wizard tiga langkah; foto dihapus dari server Payoung begitu terunggah ke DOKU. NIK dan nomor rekening tidak pernah
 * ditampilkan utuh. Status ditampilkan sebagai linimasa dengan penjelasan per tahap.
 */
export default function AktivasiQris({
    LayananTersedia,
    BatasFotoMb,
    DaftarBank,
    Awal,
    Pendaftaran,
}: PropsAktivasiQris) {
    const { props } = usePage();
    const galatUmum = (props.errors as Record<string, string | undefined>).Umum;
    const bisaMengisi = LayananTersedia && (Pendaftaran === null || Pendaftaran.BisaDiubah);
    const formulir = useForm<IsianAktivasi>({
        NamaPemilik: Pendaftaran?.NamaPemilik ?? Awal.NamaPemilik,
        Nik: '',
        Email: Pendaftaran?.Email ?? Awal.Email,
        NomorHp: Pendaftaran?.NomorHp ?? '',
        NamaUsaha: Pendaftaran?.NamaUsaha ?? Awal.NamaUsaha,
        AlamatUsaha: Pendaftaran?.AlamatUsaha ?? '',
        IdReferensiBank: Pendaftaran?.IdReferensiBank ? String(Pendaftaran.IdReferensiBank) : '',
        NamaPemilikRekening: Pendaftaran?.NamaPemilikRekening ?? '',
        NomorRekening: '',
        FotoKtp: null,
        FotoSwafoto: null,
        FotoBuktiUsaha: null,
    });
    const [langkah, AturLangkah] = useState(0);
    const [galatLokal, AturGalatLokal] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState<'kirim' | 'draf' | 'batal' | 'segarkan' | null>(null);
    const [konfirmasiBatal, AturKonfirmasiBatal] = useState(false);
    const galat = { ...(formulir.errors as Record<string, string | undefined>), ...galatLokal };

    const Isi = <K extends keyof IsianAktivasi>(kunci: K, nilai: IsianAktivasi[K]) => {
        formulir.setData((lama) => ({ ...lama, [kunci]: nilai }));
        AturGalatLokal((lama) => {
            return Object.fromEntries(Object.entries(lama).filter(([nama]) => nama !== kunci));
        });
    };

    const Lanjut = () => {
        const hasil = PeriksaLangkah(langkah, formulir.data, Pendaftaran);
        AturGalatLokal(hasil);

        if (Object.keys(hasil).length === 0) {
            AturLangkah((lama) => Math.min(lama + 1, JUDUL_LANGKAH.length - 1));
        }
    };

    const LangkahDenganGalat = (galatServer: Record<string, string>) => {
        const tiapLangkah = [0, 1, 2].find((indeks) =>
            Object.keys(galatServer).some((kunci) => Object.keys(PeriksaLangkah(indeks, KOSONG, null)).includes(kunci)),
        );

        if (tiapLangkah !== undefined) {
            AturLangkah(tiapLangkah);
        }
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const hasil = PeriksaLangkah(2, formulir.data, Pendaftaran);
        AturGalatLokal(hasil);

        if (Object.keys(hasil).length > 0) {
            return;
        }

        AturMemproses('kirim');
        formulir.post(`${alamat}/kirim`, {
            forceFormData: true,
            preserveScroll: true,
            onError: (galatServer) => LangkahDenganGalat(galatServer),
            onFinish: () => AturMemproses(null),
        });
    };

    const SimpanDraf = () => {
        AturMemproses('draf');
        formulir.post(`${alamat}/draf`, {
            forceFormData: true,
            preserveScroll: true,
            onError: (galatServer) => LangkahDenganGalat(galatServer),
            onFinish: () => AturMemproses(null),
        });
    };

    const Batalkan = () => {
        router.post(
            `${alamat}/batal`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses('batal'),
                onFinish: () => {
                    AturMemproses(null);
                    AturKonfirmasiBatal(false);
                },
            },
        );
    };

    const Segarkan = () => {
        router.reload({
            only: ['Pendaftaran'],
            onStart: () => AturMemproses('segarkan'),
            onFinish: () => AturMemproses(null),
        });
    };

    const terakhir = langkah === JUDUL_LANGKAH.length - 1;
    const tersimpan = Pendaftaran?.FotoTersimpan;

    return (
        <TataLetakAplikasi judul="Aktivasi QRIS">
            <div className="grid gap-4">
                <Pemberitahuan jenis="info" judul="Aktifkan QRIS toko dengan KTP dan rekening">
                    Siapkan foto KTP, foto selfie sambil memegang KTP, foto tempat usaha, dan nomor rekening toko.
                    Setelah disetujui DOKU, uang pembayaran QRIS masuk ke rekening toko Anda. Foto hanya dipakai untuk
                    mendaftar ke DOKU dan dihapus dari server Payoung begitu terkirim.
                </Pemberitahuan>

                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}

                {!LayananTersedia ? (
                    <Pemberitahuan jenis="peringatan">
                        Aktivasi QRIS otomatis belum tersedia. Hubungi dukungan Payoung.
                    </Pemberitahuan>
                ) : null}

                {Pendaftaran ? (
                    <Panel
                        judul="Status pendaftaran"
                        aksi={<LabelStatus jenis={jenisStatus[Pendaftaran.Status]} teks={Pendaftaran.LabelStatus} />}
                    >
                        <p className="text-isi text-teks-utama">{PenjelasanStatus(Pendaftaran.Status)}</p>
                        {Pendaftaran.AlasanPenolakan ? (
                            <Pemberitahuan jenis="bahaya" judul="Alasan dari DOKU">
                                {Pendaftaran.AlasanPenolakan}
                            </Pemberitahuan>
                        ) : null}
                        {Pendaftaran.PesanGalat ? (
                            <Pemberitahuan jenis={Pendaftaran.Status === 'Gagal' ? 'bahaya' : 'peringatan'}>
                                {Pendaftaran.PesanGalat}
                            </Pemberitahuan>
                        ) : null}
                        <LiniMasaPendaftaran status={Pendaftaran.Status} />
                        {!Pendaftaran.BisaDiubah ? <RingkasanData pendaftaran={Pendaftaran} /> : null}
                        {Pendaftaran.Status === 'Dikirim' || Pendaftaran.Status === 'Ditinjau' ? (
                            <div>
                                <Tombol varian="sekunder" memproses={memproses === 'segarkan'} onClick={Segarkan}>
                                    Perbarui status
                                </Tombol>
                            </div>
                        ) : null}
                    </Panel>
                ) : null}

                {bisaMengisi ? (
                    <Panel
                        judul={`Langkah ${langkah + 1} dari ${JUDUL_LANGKAH.length}: ${JUDUL_LANGKAH[langkah]}`}
                        keterangan={
                            Pendaftaran?.Status === 'Ditolak' || Pendaftaran?.Status === 'Gagal'
                                ? 'Foto lama sudah dihapus, jadi foto perlu diambil lagi.'
                                : undefined
                        }
                    >
                        <form onSubmit={Kirim} className="grid gap-3" noValidate>
                            {langkah === 0 ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <BidangTeks
                                        label="Nama pemilik (sesuai KTP)"
                                        nilai={formulir.data.NamaPemilik}
                                        saatBerubah={(nilai) => Isi('NamaPemilik', nilai)}
                                        galat={galat.NamaPemilik}
                                        autoComplete="name"
                                        required
                                    />
                                    <BidangTeks
                                        label="NIK (16 angka di KTP)"
                                        nilai={formulir.data.Nik}
                                        saatBerubah={(nilai) => Isi('Nik', nilai)}
                                        galat={galat.Nik}
                                        {...(Pendaftaran?.NikTersamar
                                            ? {
                                                  keterangan: `Tersimpan ${Pendaftaran.NikTersamar}. Kosongkan bila tidak diganti.`,
                                              }
                                            : {})}
                                        inputMode="numeric"
                                        maxLength={20}
                                        autoComplete="off"
                                        kode
                                        required={!Pendaftaran?.NikTersamar}
                                    />
                                    <BidangTeks
                                        label="Email"
                                        jenis="email"
                                        nilai={formulir.data.Email}
                                        saatBerubah={(nilai) => Isi('Email', nilai)}
                                        galat={galat.Email}
                                        autoComplete="email"
                                        required
                                    />
                                    <BidangTeks
                                        label="Nomor HP"
                                        nilai={formulir.data.NomorHp}
                                        saatBerubah={(nilai) => Isi('NomorHp', nilai)}
                                        galat={galat.NomorHp}
                                        inputMode="tel"
                                        autoComplete="tel"
                                        required
                                    />
                                    <BidangTeks
                                        label="Nama usaha"
                                        nilai={formulir.data.NamaUsaha}
                                        saatBerubah={(nilai) => Isi('NamaUsaha', nilai)}
                                        galat={galat.NamaUsaha}
                                        required
                                    />
                                    <div className="sm:col-span-2">
                                        <BidangTeksPanjang
                                            label="Alamat usaha"
                                            nilai={formulir.data.AlamatUsaha}
                                            saatBerubah={(nilai) => Isi('AlamatUsaha', nilai)}
                                            galat={galat.AlamatUsaha}
                                            baris={3}
                                            maksimal={300}
                                            required
                                        />
                                    </div>
                                </div>
                            ) : null}

                            {langkah === 1 ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <BidangPilihan
                                        label="Bank"
                                        nilai={formulir.data.IdReferensiBank}
                                        opsi={DaftarBank.map((bank) => ({ Nilai: String(bank.Id), Label: bank.Nama }))}
                                        saatBerubah={(nilai) => Isi('IdReferensiBank', nilai)}
                                        galat={galat.IdReferensiBank}
                                        required
                                    />
                                    <BidangTeks
                                        label="Nama pemilik rekening"
                                        nilai={formulir.data.NamaPemilikRekening}
                                        saatBerubah={(nilai) => Isi('NamaPemilikRekening', nilai)}
                                        galat={galat.NamaPemilikRekening}
                                        keterangan="Harus sama dengan nama di buku tabungan."
                                        required
                                    />
                                    <BidangTeks
                                        label="Nomor rekening"
                                        nilai={formulir.data.NomorRekening}
                                        saatBerubah={(nilai) => Isi('NomorRekening', nilai)}
                                        galat={galat.NomorRekening}
                                        keterangan={
                                            Pendaftaran?.RekeningTersamar
                                                ? `Tersimpan ${Pendaftaran.RekeningTersamar}. Kosongkan bila tidak diganti.`
                                                : 'Rekening yang akan menerima uang pembayaran QRIS.'
                                        }
                                        inputMode="numeric"
                                        autoComplete="off"
                                        kode
                                        required={!Pendaftaran?.RekeningTersamar}
                                    />
                                </div>
                            ) : null}

                            {langkah === 2 ? (
                                <div className="grid gap-4">
                                    <BidangFotoKyc
                                        label="Foto KTP"
                                        kamera="environment"
                                        berkas={formulir.data.FotoKtp}
                                        saatBerubah={(berkas) => Isi('FotoKtp', berkas)}
                                        ukuranMaksimalMb={BatasFotoMb}
                                        sudahTersimpan={tersimpan?.Ktp === true}
                                        keterangan="Pastikan seluruh KTP terlihat jelas dan tidak silau."
                                        galat={galat.FotoKtp}
                                    />
                                    <BidangFotoKyc
                                        label="Foto selfie sambil memegang KTP"
                                        kamera="user"
                                        berkas={formulir.data.FotoSwafoto}
                                        saatBerubah={(berkas) => Isi('FotoSwafoto', berkas)}
                                        ukuranMaksimalMb={BatasFotoMb}
                                        sudahTersimpan={tersimpan?.Swafoto === true}
                                        keterangan="Wajah dan KTP terlihat jelas dalam satu foto."
                                        galat={galat.FotoSwafoto}
                                    />
                                    <BidangFotoKyc
                                        label="Foto tempat usaha"
                                        kamera="environment"
                                        berkas={formulir.data.FotoBuktiUsaha}
                                        saatBerubah={(berkas) => Isi('FotoBuktiUsaha', berkas)}
                                        ukuranMaksimalMb={BatasFotoMb}
                                        sudahTersimpan={tersimpan?.BuktiUsaha === true}
                                        keterangan="Tampak depan toko atau tempat Anda berjualan."
                                        galat={galat.FotoBuktiUsaha}
                                    />
                                </div>
                            ) : null}

                            <BilahAksiForm>
                                {terakhir ? (
                                    <Tombol
                                        type="submit"
                                        memproses={memproses === 'kirim'}
                                        disabled={memproses !== null}
                                    >
                                        Kirim pendaftaran
                                    </Tombol>
                                ) : (
                                    <Tombol onClick={Lanjut} disabled={memproses !== null}>
                                        Lanjut
                                    </Tombol>
                                )}
                                {langkah > 0 ? (
                                    <Tombol
                                        varian="sekunder"
                                        disabled={memproses !== null}
                                        onClick={() => AturLangkah((lama) => Math.max(lama - 1, 0))}
                                    >
                                        Kembali
                                    </Tombol>
                                ) : null}
                                <Tombol
                                    varian="sekunder"
                                    memproses={memproses === 'draf'}
                                    disabled={memproses !== null}
                                    onClick={SimpanDraf}
                                >
                                    Simpan draf
                                </Tombol>
                            </BilahAksiForm>
                        </form>

                        {Pendaftaran ? (
                            <div className="flex flex-wrap items-center gap-2 border-t border-garis pt-3">
                                {konfirmasiBatal ? (
                                    <>
                                        <span className="text-keterangan text-teks-sekunder">
                                            Data dan foto sementara akan dihapus. Lanjutkan?
                                        </span>
                                        <Tombol varian="bahaya" memproses={memproses === 'batal'} onClick={Batalkan}>
                                            Ya, batalkan pendaftaran
                                        </Tombol>
                                        <Tombol varian="sekunder" onClick={() => AturKonfirmasiBatal(false)}>
                                            Tidak jadi
                                        </Tombol>
                                    </>
                                ) : (
                                    <Tombol
                                        varian="sekunder"
                                        disabled={memproses !== null}
                                        onClick={() => AturKonfirmasiBatal(true)}
                                    >
                                        Batalkan pendaftaran
                                    </Tombol>
                                )}
                            </div>
                        ) : null}
                    </Panel>
                ) : null}
            </div>
        </TataLetakAplikasi>
    );
}
