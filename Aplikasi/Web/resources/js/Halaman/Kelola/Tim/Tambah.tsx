import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import CakupanOutletPeran, { TerapkanCakupanPeran } from '@/Komponen/Kelola/CakupanOutletPeran';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { CekBatasPenuh, FormatBatas } from '@/Tipe/Organisasi';
import type { PropsTambahTim } from '@/Tipe/Tim';

type Akses = 'Kasir' | 'Dashboard' | 'Tanpa';

type Isian = {
    Akses: Akses;
    Nama: string;
    Jabatan: string;
    Email: string;
    NoHp: string;
    KataSandi: string;
    Pin: string;
    Peran: string;
    SemuaOutlet: boolean;
    Outlet: string[];
    UuidOutlet: string;
    JugaKaryawan: boolean;
    LevelStaf: string;
    GajiPokok: string;
    TarifLemburPerJam: string;
    PotonganTerlambatPerMenit: string;
    PotonganTidakMasukPerHari: string;
};

const JENIS_AKSES: { nilai: Akses; judul: string; keterangan: string }[] = [
    { nilai: 'Kasir', judul: 'Kasir (cukup PIN)', keterangan: 'Tanpa email. Masuk aplikasi kasir dengan PIN 6 angka.' },
    {
        nilai: 'Dashboard',
        judul: 'Dashboard (pakai email)',
        keterangan: 'Membuka dashboard sesuai peran. Kata sandi awal wajib diganti saat pertama masuk.',
    },
    {
        nilai: 'Tanpa',
        judul: 'Tanpa akun masuk',
        keterangan: 'Hanya dijadwalkan, absen lewat HP, dan digaji. Tidak memakai kursi akun.',
    },
];

/** Peran yang paling sering dipakai tampil sebagai pilihan cepat; peran lain lewat daftar di bawahnya. */
const PeranRingkas: Record<string, string> = {
    Kasir: 'Berjualan di aplikasi kasir.',
    Supervisor: 'Kasir + menyetujui diskon, void, retur, kas keluar.',
    'Manajer Outlet': 'Mengelola outlet: produk, stok, laporan.',
    'Staf Gudang': 'Terima & kirim barang antar-outlet, opname stok.',
    Admin: 'Semua menu kecuali kepemilikan & langganan.',
};

/**
 * Tambah anggota tim (D-101): satu formulir untuk tiga hal. Pertama pilih bagaimana orang itu masuk — kasir dengan PIN,
 * dashboard dengan email, atau tanpa akun — baru isian yang relevan muncul. Gaji & komisi dilipat (opsional).
 */
export default function HalamanTambahTim({
    KaryawanTertaut,
    BolehAkun,
    BolehKaryawan,
    Peran,
    Outlet,
    OpsiOutlet,
    BatasPengguna,
}: PropsTambahTim) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const sayaPemilik = props.Akses?.Pemilik ?? false;
    const peranTerlihat = Peran.filter((peran) => sayaPemilik || !peran.Pemilik);
    const jenisTersedia = JENIS_AKSES.filter((j) =>
        j.nilai === 'Tanpa' ? BolehKaryawan && KaryawanTertaut === null : BolehAkun,
    );
    const formulir = useForm<Isian>({
        Akses: (jenisTersedia[0]?.nilai ?? 'Tanpa') as Akses,
        Nama: KaryawanTertaut?.Nama ?? '',
        Jabatan: KaryawanTertaut?.Jabatan ?? '',
        Email: '',
        NoHp: '',
        KataSandi: '',
        Pin: '',
        Peran: '',
        SemuaOutlet: false,
        Outlet: [],
        UuidOutlet: '',
        JugaKaryawan: true,
        LevelStaf: '',
        GajiPokok: '',
        TarifLemburPerJam: '',
        PotonganTerlambatPerMenit: '',
        PotonganTidakMasukPerHari: '',
    });
    const d = formulir.data;
    const galat = formulir.errors as Partial<Record<keyof Isian | 'Umum', string>>;
    const akun = d.Akses !== 'Tanpa';
    const kasir = d.Akses === 'Kasir';
    const punyaKaryawan = BolehKaryawan && (!akun || d.JugaKaryawan || KaryawanTertaut !== null);
    const peranTerpilih = peranTerlihat.find((baris) => baris.Uuid === d.Peran);
    const penuh = CekBatasPenuh(BatasPengguna) && akun;

    const PilihPeran = (uuid: string) => {
        const baris = peranTerlihat.find((item) => item.Uuid === uuid);
        formulir.setData({ ...d, Peran: uuid, ...TerapkanCakupanPeran(baris, Outlet, d) });
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((data) => ({
            Akses: data.Akses,
            Nama: data.Nama,
            Jabatan: data.Jabatan,
            ...(data.Akses === 'Tanpa'
                ? { UuidOutlet: data.UuidOutlet }
                : {
                      Email: data.Akses === 'Dashboard' ? data.Email : '',
                      KataSandi: data.Akses === 'Dashboard' ? data.KataSandi : '',
                      NoHp: data.NoHp,
                      Pin: data.Pin,
                      Peran: data.Peran,
                      SemuaOutlet: data.SemuaOutlet,
                      Outlet: data.Outlet,
                      JugaKaryawan: BolehKaryawan && KaryawanTertaut === null && data.JugaKaryawan,
                      UuidKaryawan: KaryawanTertaut?.Uuid ?? '',
                  }),
            ...(punyaKaryawan
                ? {
                      LevelStaf: data.LevelStaf,
                      GajiPokok: data.GajiPokok,
                      TarifLemburPerJam: data.TarifLemburPerJam,
                      PotonganTerlambatPerMenit: data.PotonganTerlambatPerMenit,
                      PotonganTidakMasukPerHari: data.PotonganTidakMasukPerHari,
                  }
                : {}),
        }));
        formulir.post('/kelola/tim', { preserveScroll: true, onError: () => formulir.reset('KataSandi', 'Pin') });
    };

    const isianForm = Object.keys(d);

    return (
        <TataLetakAplikasi judul="Tambah anggota tim">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            {penuh ? (
                <Pemberitahuan jenis="info" judul="Batas akun masuk paket sudah tercapai">
                    Pilih &ldquo;Tanpa akun masuk&rdquo;, nonaktifkan akun yang tidak dipakai, atau{' '}
                    <AjakanTambahBatas>
                        tingkatkan paket di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    .
                </Pemberitahuan>
            ) : null}
            <KartuFormulir keterangan={`Kursi akun masuk terpakai: ${FormatBatas(BatasPengguna, 'pengguna')}.`}>
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    {KaryawanTertaut !== null ? (
                        <Pemberitahuan jenis="info" judul="Akses masuk untuk karyawan yang sudah ada">
                            Akun ini ditautkan ke karyawan {KaryawanTertaut.Nama}, jadi jadwal, absensi, komisi, dan
                            gajinya tetap utuh dan ia tidak dicatat dua kali.
                        </Pemberitahuan>
                    ) : null}
                    <BidangTeks
                        label="Nama"
                        nilai={d.Nama}
                        saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                        galat={galat.Nama}
                        maxLength={150}
                        disabled={KaryawanTertaut !== null}
                        autoFocus={KaryawanTertaut === null}
                        required
                    />
                    <BidangTeks
                        label="Jabatan (opsional)"
                        nilai={d.Jabatan}
                        saatBerubah={(nilai) => formulir.setData('Jabatan', nilai)}
                        galat={galat.Jabatan}
                        keterangan="Misal Barista, Terapis, Mekanik."
                        maxLength={80}
                        disabled={KaryawanTertaut !== null}
                    />

                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1 text-label font-semibold text-teks-utama">Bagaimana ia masuk?</legend>
                        <div
                            className={cn('grid gap-2', jenisTersedia.length > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}
                        >
                            {jenisTersedia.map((j) => (
                                <label
                                    key={j.nilai}
                                    className={cn(
                                        'flex cursor-pointer flex-col gap-1 rounded-panel border p-3',
                                        d.Akses === j.nilai ? 'border-2 border-brand bg-brand-lembut' : 'border-garis',
                                    )}
                                >
                                    <span className="flex items-center gap-2 text-isi font-semibold text-teks-utama">
                                        <input
                                            type="radio"
                                            name="Akses"
                                            value={j.nilai}
                                            checked={d.Akses === j.nilai}
                                            onChange={() => formulir.setData('Akses', j.nilai)}
                                            className="size-4 accent-brand"
                                        />
                                        {j.judul}
                                    </span>
                                    <span className="text-keterangan text-teks-sekunder">{j.keterangan}</span>
                                </label>
                            ))}
                        </div>
                        {galat.Akses ? (
                            <p className="text-keterangan font-semibold text-bahaya">{galat.Akses}</p>
                        ) : null}
                    </fieldset>

                    {akun ? (
                        <>
                            {kasir ? null : (
                                <>
                                    <BidangTeks
                                        label="Email (untuk masuk dashboard)"
                                        jenis="email"
                                        nilai={d.Email}
                                        saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                                        galat={galat.Email}
                                        maxLength={191}
                                        required
                                    />
                                    <BidangTeks
                                        label="Kata sandi awal"
                                        jenis="password"
                                        keterangan="Minimal 8 karakter, berisi huruf dan angka. Berikan ke orangnya; ia wajib menggantinya saat pertama masuk."
                                        nilai={d.KataSandi}
                                        saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                                        galat={galat.KataSandi}
                                        autoComplete="new-password"
                                        required
                                    />
                                </>
                            )}
                            <BidangTeks
                                label={kasir ? 'PIN kasir' : 'PIN kasir (opsional)'}
                                jenis="password"
                                inputMode="numeric"
                                keterangan="6 angka, bukan angka sama semua atau berurutan (misal 123456). Dipakai masuk aplikasi kasir."
                                nilai={d.Pin}
                                saatBerubah={(nilai) => formulir.setData('Pin', nilai.replace(/\D/g, '').slice(0, 6))}
                                galat={galat.Pin}
                                maxLength={6}
                                autoComplete="off"
                                required={kasir}
                            />
                            <BidangTeks
                                label="Nomor WhatsApp (opsional)"
                                inputMode="tel"
                                nilai={d.NoHp}
                                saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                                galat={galat.NoHp}
                                maxLength={20}
                            />
                            {peranTerlihat.some((p) => p.Nama in PeranRingkas) ? (
                                <fieldset className="flex flex-col gap-2">
                                    <legend className="mb-1 text-label font-semibold text-teks-utama">Tugasnya</legend>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {peranTerlihat
                                            .filter((p) => p.Nama in PeranRingkas)
                                            .map((p) => (
                                                <label
                                                    key={p.Uuid}
                                                    className={cn(
                                                        'flex cursor-pointer flex-col gap-1 rounded-panel border p-3',
                                                        d.Peran === p.Uuid
                                                            ? 'border-2 border-brand bg-brand-lembut'
                                                            : 'border-garis',
                                                    )}
                                                >
                                                    <span className="flex items-center gap-2 text-isi font-semibold text-teks-utama">
                                                        <input
                                                            type="radio"
                                                            name="PeranRingkas"
                                                            value={p.Uuid}
                                                            checked={d.Peran === p.Uuid}
                                                            onChange={() => PilihPeran(p.Uuid)}
                                                            className="size-4 accent-brand"
                                                        />
                                                        {p.Nama}
                                                    </span>
                                                    <span className="text-keterangan text-teks-sekunder">
                                                        {PeranRingkas[p.Nama]}
                                                    </span>
                                                </label>
                                            ))}
                                    </div>
                                </fieldset>
                            ) : null}
                            <BidangPilihan
                                label="Peran (atau pilih peran lain)"
                                nilai={d.Peran}
                                opsi={peranTerlihat.map((baris) => ({ Nilai: baris.Uuid, Label: baris.Nama }))}
                                saatBerubah={PilihPeran}
                                galat={galat.Peran}
                                required
                                kosong="Pilih peran"
                            />
                            <CakupanOutletPeran
                                peran={peranTerpilih}
                                outlet={Outlet}
                                nilai={{ SemuaOutlet: d.SemuaOutlet, Outlet: d.Outlet }}
                                saatBerubah={(isian) => formulir.setData({ ...d, ...isian })}
                                galatSemuaOutlet={galat.SemuaOutlet}
                                galatOutlet={galat.Outlet}
                            />
                            {BolehKaryawan && KaryawanTertaut === null ? (
                                <KotakCentang
                                    label="Bukan karyawan (hanya akun akses, misal akuntan luar)"
                                    nilai={!d.JugaKaryawan}
                                    saatBerubah={(nilai) => formulir.setData('JugaKaryawan', !nilai)}
                                />
                            ) : null}
                        </>
                    ) : (
                        <BidangPilihan
                            label="Outlet utama (opsional)"
                            nilai={d.UuidOutlet}
                            kosong="Semua outlet"
                            opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                            saatBerubah={(nilai) => formulir.setData('UuidOutlet', nilai)}
                            galat={galat.UuidOutlet}
                        />
                    )}

                    {punyaKaryawan ? (
                        <details className="rounded-panel border border-garis p-3">
                            <summary className="cursor-pointer text-isi font-semibold text-teks-utama">
                                Gaji & komisi (opsional)
                            </summary>
                            <div className="mt-3 flex flex-col gap-4">
                                <BidangTeks
                                    label="Level staf (opsional)"
                                    nilai={d.LevelStaf}
                                    saatBerubah={(nilai) => formulir.setData('LevelStaf', nilai)}
                                    galat={galat.LevelStaf}
                                    keterangan="Dipakai aturan komisi bertingkat, misal Senior atau Junior."
                                    maxLength={40}
                                />
                                <BidangUang
                                    label="Gaji pokok (opsional)"
                                    nilai={d.GajiPokok}
                                    saatBerubah={(nilai) => formulir.setData('GajiPokok', nilai)}
                                    galat={galat.GajiPokok}
                                />
                                <BidangUang
                                    label="Tarif lembur per jam (opsional)"
                                    nilai={d.TarifLemburPerJam}
                                    saatBerubah={(nilai) => formulir.setData('TarifLemburPerJam', nilai)}
                                    galat={galat.TarifLemburPerJam}
                                    keterangan="Kosong = lembur tidak dibayar otomatis."
                                />
                                <BidangUang
                                    label="Potongan per menit terlambat (opsional)"
                                    nilai={d.PotonganTerlambatPerMenit}
                                    saatBerubah={(nilai) => formulir.setData('PotonganTerlambatPerMenit', nilai)}
                                    galat={galat.PotonganTerlambatPerMenit}
                                    keterangan="Kosong = keterlambatan tidak memotong gaji."
                                />
                                <BidangUang
                                    label="Potongan per hari tidak masuk (opsional)"
                                    nilai={d.PotonganTidakMasukPerHari}
                                    saatBerubah={(nilai) => formulir.setData('PotonganTidakMasukPerHari', nilai)}
                                    galat={galat.PotonganTidakMasukPerHari}
                                    keterangan="Untuk hari berjadwal tanpa absensi. Kosong = tidak memotong gaji."
                                />
                            </div>
                        </details>
                    ) : null}

                    <p className="text-keterangan text-teks-sekunder">
                        Izin rinci tiap peran bisa disesuaikan di{' '}
                        <Link href="/kelola/peran" className="font-semibold text-brand underline">
                            Peran & izin
                        </Link>
                        .
                        {BolehAkun ? (
                            <>
                                {' '}
                                Lebih suka mengirim undangan?{' '}
                                <Link
                                    href="/kelola/pengguna/undangan/buat"
                                    className="font-semibold text-brand underline"
                                >
                                    Undang lewat email
                                </Link>
                                .
                            </>
                        ) : null}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" memproses={formulir.processing} disabled={penuh}>
                            Tambah ke tim
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => router.visit('/kelola/tim')}>
                            Batal
                        </Tombol>
                    </div>
                </form>
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
