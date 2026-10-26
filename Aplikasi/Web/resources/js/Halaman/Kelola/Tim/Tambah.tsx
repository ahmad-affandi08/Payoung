import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import CakupanOutletPeran, { AmbilCakupan, TerapkanCakupanPeran } from '@/Komponen/Kelola/CakupanOutletPeran';
import CangkangWizard from '@/Komponen/Formulir/Wizard/CangkangWizard';
import { useAlurWizard } from '@/Komponen/Formulir/Wizard/useAlurWizard';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import RingkasanGalatFormulir from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import {
    AmbilGalatLangkahTim,
    HitungLangkahTimBergalat,
    PetakanGalatTimKeLangkah,
    PeriksaLangkahTim,
    SusunLangkahTim,
    urutanLangkahTim,
    type AksesTim,
    type KunciLangkahTim,
} from '@/Komponen/Tim/LangkahTim';
import RingkasanTim from '@/Komponen/Tim/RingkasanTim';
import { cn } from '@/Komponen/Ui/utils';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { CekBatasPenuh, FormatBatas } from '@/Tipe/Organisasi';
import type { PropsTambahTim } from '@/Tipe/Tim';

type Akses = AksesTim;

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
    IkutAbsensi: boolean;
    LevelStaf: string;
    SiklusGaji: 'Bulanan' | 'Mingguan';
    DasarGaji: 'Tetap' | 'PerHariMasuk';
    GajiPokok: string;
    TarifLemburPerJam: string;
    PotonganTerlambatPerMenit: string;
    PotonganTidakMasukPerHari: string;
};

const JENIS_AKSES: { nilai: Akses; judul: string; keterangan: string }[] = [
    {
        nilai: 'Kasir',
        judul: 'Kasir (cukup PIN)',
        keterangan:
            'Tanpa email. Masuk aplikasi kasir dengan PIN 6 angka. Tidak bisa absen dari HP (absen butuh email).',
    },
    {
        nilai: 'Dashboard',
        judul: 'Dashboard (pakai email)',
        keterangan:
            'Membuka dashboard sesuai peran dan bisa absen dari HP. Kata sandi awal wajib diganti saat pertama masuk.',
    },
    {
        nilai: 'Tanpa',
        judul: 'Tanpa akun masuk',
        keterangan: 'Hanya dijadwalkan dan digaji. Tidak memakai kursi akun dan tidak bisa absen.',
    },
];

/** Peran yang paling sering dipakai tampil sebagai pilihan cepat; peran lain lewat daftar di bawahnya. */
const PeranRingkas: Record<string, string> = {
    Kasir: 'Berjualan di aplikasi kasir.',
    Supervisor: 'Kasir + menyetujui diskon, void, retur, kas keluar.',
    'Manajer Outlet': 'Mengelola outlet: produk, stok, laporan.',
    'Staf Gudang': 'Terima & kirim barang antar-outlet, opname stok.',
    Admin: 'Semua menu kecuali kepemilikan & langganan.',
    Karyawan: 'Hanya absen masuk/pulang dari HP, tanpa akses kasir atau menu lain.',
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
        IkutAbsensi: true,
        LevelStaf: '',
        SiklusGaji: 'Bulanan',
        DasarGaji: 'Tetap',
        GajiPokok: '',
        TarifLemburPerJam: '',
        PotonganTerlambatPerMenit: '',
        PotonganTidakMasukPerHari: '',
    });
    const d = formulir.data;
    const galatServer = formulir.errors as Record<string, string | undefined>;
    const [percobaan, AturPercobaan] = useState<KunciLangkahTim[]>([]);
    const [aturGajiPilihan, AturGajiPilihan] = useState(false);
    const akun = d.Akses !== 'Tanpa';
    const kasir = d.Akses === 'Kasir';
    const punyaKaryawan = BolehKaryawan && (!akun || d.JugaKaryawan || KaryawanTertaut !== null);
    const peranTerpilih = peranTerlihat.find((baris) => baris.Uuid === d.Peran);
    const penuh = CekBatasPenuh(BatasPengguna) && akun;
    const aturGaji = punyaKaryawan && aturGajiPilihan;
    const cakupan = AmbilCakupan(peranTerpilih);
    const outletKurang = cakupan !== 'Semua' && !d.SemuaOutlet && d.Outlet.length === 0;
    const aksesTersedia = jenisTersedia.map((j) => j.nilai);
    const langkah = SusunLangkahTim({ akses: d.Akses, punyaKaryawan, aturGaji });
    const KonteksPeriksa = () => ({ isian: d, aksesTersedia, outletKurang });
    const galatKlien: Record<string, string> = Object.assign(
        {},
        ...percobaan.map((kunci) => PeriksaLangkahTim(kunci, KonteksPeriksa())),
    );
    const galat = { ...galatServer, ...galatKlien } as Partial<Record<keyof Isian | 'Umum', string>>;

    const PeriksaLangkah = (kunci: KunciLangkahTim): boolean => {
        AturPercobaan((lama) => (lama.includes(kunci) ? lama : [...lama, kunci]));
        const valid = Object.keys(PeriksaLangkahTim(kunci, KonteksPeriksa())).length === 0;

        if (valid) {
            // Langkah ini sudah diperbaiki pengguna: galat server lamanya tidak perlu terus tampil.
            const kunciServer = Object.keys(galatServer).filter(
                (isi) => PetakanGalatTimKeLangkah(isi, d.Akses) === kunci,
            );

            if (kunciServer.length > 0) {
                formulir.clearErrors(...(kunciServer as never[]));
            }
        }

        return valid;
    };
    const kontrol = useAlurWizard(langkah, PeriksaLangkah, { awal: 'Siapa', urutan: urutanLangkahTim });

    const PilihPeran = (uuid: string) => {
        const baris = peranTerlihat.find((item) => item.Uuid === uuid);
        // D-180: isian awal "Ikut absensi" mengikuti peran (manajemen tidak, staf lapangan ya); masih bisa diubah.
        formulir.setData({
            ...d,
            Peran: uuid,
            IkutAbsensi: baris?.IkutAbsensi ?? true,
            ...TerapkanCakupanPeran(baris, Outlet, d),
        });
    };

    /** Semua langkah diperiksa ulang, lalu kirim. Galat server membuka langkah pertama yang memuatnya. */
    const SimpanBaru = () => {
        const langkahSalah = langkah.find((item) => item.Kunci !== 'Tinjau' && !PeriksaLangkah(item.Kunci));

        if (langkahSalah) {
            kontrol.Paksa(langkahSalah.Kunci);

            return;
        }

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
                      ...(data.Akses === 'Dashboard' && punyaKaryawan ? { IkutAbsensi: data.IkutAbsensi } : {}),
                      UuidKaryawan: KaryawanTertaut?.Uuid ?? '',
                  }),
            ...(aturGaji
                ? {
                      LevelStaf: data.LevelStaf,
                      SiklusGaji: data.SiklusGaji,
                      DasarGaji: data.DasarGaji,
                      GajiPokok: data.GajiPokok,
                      TarifLemburPerJam: data.TarifLemburPerJam,
                      PotonganTerlambatPerMenit: data.PotonganTerlambatPerMenit,
                      PotonganTidakMasukPerHari: data.PotonganTidakMasukPerHari,
                  }
                : {}),
        }));
        formulir.post('/kelola/tim', {
            preserveScroll: true,
            onError: (galatBaru: Record<string, string | undefined>) => {
                const [pertama] = HitungLangkahTimBergalat(galatBaru, d.Akses);

                if (pertama) {
                    kontrol.Paksa(pertama);
                }
            },
        });
    };

    const isianForm = Object.keys(d);

    const panelSiapa = (
        <>
            {KaryawanTertaut !== null ? (
                <Pemberitahuan jenis="info" judul="Akses masuk untuk karyawan yang sudah ada">
                    Akun ini ditautkan ke karyawan {KaryawanTertaut.Nama}, jadi jadwal, absensi, komisi, dan gajinya
                    tetap utuh dan ia tidak dicatat dua kali.
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
                <div className={cn('grid gap-2', jenisTersedia.length > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
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
                {galat.Akses ? <p className="text-keterangan font-semibold text-bahaya">{galat.Akses}</p> : null}
            </fieldset>
        </>
    );
    const panelAkun = (
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
        </>
    );
    const kotakGaji = punyaKaryawan ? (
        <KotakCentang
            label="Atur gaji & komisi sekarang (opsional)"
            nilai={aturGajiPilihan}
            saatBerubah={AturGajiPilihan}
        />
    ) : null;
    const panelPeran = (
        <>
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
                                        d.Peran === p.Uuid ? 'border-2 border-brand bg-brand-lembut' : 'border-garis',
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
                                    <span className="text-keterangan text-teks-sekunder">{PeranRingkas[p.Nama]}</span>
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
            {d.Akses === 'Dashboard' && punyaKaryawan && KaryawanTertaut === null ? (
                <div className="flex flex-col gap-1">
                    <KotakCentang
                        label="Ikut absensi (absen masuk/pulang dari HP setelah login)"
                        nilai={d.IkutAbsensi}
                        saatBerubah={(nilai) => formulir.setData('IkutAbsensi', nilai)}
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Matikan untuk manajer, supervisor, atau pemilik yang tidak perlu absen. Yang tidak ikut absensi
                        tidak dianggap terlambat atau tidak masuk dan tidak kena potongan gaji.
                    </p>
                    {galat.IkutAbsensi ? (
                        <p className="text-keterangan font-semibold text-bahaya">{galat.IkutAbsensi}</p>
                    ) : null}
                </div>
            ) : null}
            {kotakGaji}
        </>
    );
    const panelOutlet = (
        <>
            <BidangPilihan
                label="Outlet utama (opsional)"
                nilai={d.UuidOutlet}
                kosong="Semua outlet"
                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                saatBerubah={(nilai) => formulir.setData('UuidOutlet', nilai)}
                galat={galat.UuidOutlet}
            />
            {kotakGaji}
        </>
    );
    const panelGaji = (
        <>
            <BidangTeks
                label="Level staf (opsional)"
                nilai={d.LevelStaf}
                saatBerubah={(nilai) => formulir.setData('LevelStaf', nilai)}
                galat={galat.LevelStaf}
                keterangan="Dipakai aturan komisi bertingkat, misal Senior atau Junior."
                maxLength={40}
            />
            <BidangPilihan
                label="Siklus gaji"
                nilai={d.SiklusGaji}
                opsi={[
                    { Nilai: 'Bulanan', Label: 'Bulanan' },
                    { Nilai: 'Mingguan', Label: 'Mingguan' },
                ]}
                saatBerubah={(nilai) => formulir.setData('SiklusGaji', nilai === 'Mingguan' ? 'Mingguan' : 'Bulanan')}
                galat={galat.SiklusGaji}
            />
            <BidangPilihan
                label="Cara menghitung gaji pokok"
                nilai={d.DasarGaji}
                opsi={[
                    { Nilai: 'Tetap', Label: `Gaji tetap per ${d.SiklusGaji === 'Mingguan' ? 'minggu' : 'bulan'}` },
                    { Nilai: 'PerHariMasuk', Label: 'Upah per hari masuk' },
                ]}
                saatBerubah={(nilai) =>
                    formulir.setData('DasarGaji', nilai === 'PerHariMasuk' ? 'PerHariMasuk' : 'Tetap')
                }
                galat={galat.DasarGaji}
            />
            <BidangUang
                label={
                    d.DasarGaji === 'PerHariMasuk'
                        ? 'Upah per hari masuk'
                        : d.SiklusGaji === 'Mingguan'
                          ? 'Gaji pokok per minggu (opsional)'
                          : 'Gaji pokok (opsional)'
                }
                nilai={d.GajiPokok}
                saatBerubah={(nilai) => formulir.setData('GajiPokok', nilai)}
                galat={galat.GajiPokok}
                {...(d.DasarGaji === 'PerHariMasuk'
                    ? {
                          required: true,
                          keterangan:
                              'Gaji pokok rekap = upah × hari masuk (dari absensi dan cuti berbayar). Hari masuk bisa disesuaikan di draf rekap.',
                      }
                    : {})}
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
        </>
    );

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
            <p className="max-w-3xl text-isi text-teks-sekunder">
                {`Jumlah akun masuk terpakai: ${FormatBatas(BatasPengguna, 'pengguna')}.`}
            </p>
            <CangkangWizard
                kontrol={kontrol}
                kunciTinjau="Tinjau"
                labelNavigasi="Langkah tambah anggota tim"
                teksSimpan="Tambah ke tim"
                bergalat={HitungLangkahTimBergalat(galat, d.Akses)}
                pesanGalat={<RingkasanGalatFormulir galat={AmbilGalatLangkahTim(galat, kontrol.aktif, d.Akses)} />}
                saatSimpan={SimpanBaru}
                memproses={formulir.processing}
                bolehSimpan
                simpanDitahan={penuh}
                tautanBatal="/kelola/tim"
                panel={{
                    Siapa: panelSiapa,
                    Akun: panelAkun,
                    Peran: panelPeran,
                    Outlet: panelOutlet,
                    Gaji: panelGaji,
                    Tinjau: (
                        <RingkasanTim
                            isian={d}
                            langkah={langkah}
                            labelAkses={JENIS_AKSES.find((j) => j.nilai === d.Akses)?.judul ?? d.Akses}
                            peran={peranTerpilih}
                            semuaOutlet={cakupan === 'Semua' || d.SemuaOutlet}
                            uuidOutlet={d.Outlet}
                            outlet={Outlet}
                            namaOutletUtama={OpsiOutlet.find((o) => o.Uuid === d.UuidOutlet)?.Nama ?? null}
                            dicatatKaryawan={punyaKaryawan}
                            saatUbah={(kunci) => kontrol.Lompat(kunci)}
                        />
                    ),
                }}
            />
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
                        <Link href="/kelola/pengguna/undangan/buat" className="font-semibold text-brand underline">
                            Undang lewat email
                        </Link>
                        .
                    </>
                ) : null}
            </p>
        </TataLetakAplikasi>
    );
}
