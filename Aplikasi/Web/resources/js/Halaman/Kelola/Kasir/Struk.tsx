import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangGambar from '@/Komponen/Formulir/BidangGambar';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PratinjauStruk, { PENUTUP_BAWAAN, type LebarKertas } from '@/Komponen/Struk/PratinjauStruk';
import { ToggleGroup, ToggleGroupItem } from '@/Komponen/Ui/toggle-group';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PengaturanStruk, PropsPengaturanStruk } from '@/Tipe/Kasir';

const alamat = '/kelola/kasir/struk';

/** Batas sama dengan server (`DataPengaturanStruk`). */
const PANJANG_BARIS = 48;
const PANJANG_CATATAN = 200;
const JUMLAH_KEPALA = 3;

/** Isian formulir: teks selalu string ('' = bawaan aplikasi), kepala selalu 3 slot. */
type IsianStruk = Omit<PengaturanStruk, 'NamaDicetak' | 'TeksKepala' | 'CatatanKaki' | 'TeksPenutup'> & {
    NamaDicetak: string;
    TeksKepala: string[];
    CatatanKaki: string;
    TeksPenutup: string;
};

export function KeIsian(p: PengaturanStruk): IsianStruk {
    return {
        ...p,
        NamaDicetak: p.NamaDicetak ?? '',
        TeksKepala: Array.from({ length: JUMLAH_KEPALA }, (_, i) => p.TeksKepala[i] ?? ''),
        CatatanKaki: p.CatatanKaki ?? '',
        TeksPenutup: p.TeksPenutup ?? '',
    };
}

/** Isian → pengaturan: teks kosong menjadi null, baris kepala kosong dibuang. */
export function KePengaturan(isian: IsianStruk): PengaturanStruk {
    const AtauNull = (teks: string) => (teks.trim() === '' ? null : teks.trim());

    return {
        ...isian,
        NamaDicetak: AtauNull(isian.NamaDicetak),
        TeksKepala: isian.TeksKepala.map((t) => t.trim()).filter((t) => t !== ''),
        CatatanKaki: AtauNull(isian.CatatanKaki),
        TeksPenutup: AtauNull(isian.TeksPenutup),
    };
}

const saklar: { kunci: keyof PengaturanStruk & `Tampilkan${string}`; label: string; bagian: 'kepala' | 'isi' }[] = [
    { kunci: 'TampilkanLogo', label: 'Cetak logo usaha', bagian: 'kepala' },
    { kunci: 'TampilkanAlamat', label: 'Alamat outlet', bagian: 'kepala' },
    { kunci: 'TampilkanTelepon', label: 'Telepon outlet', bagian: 'kepala' },
    { kunci: 'TampilkanNpwp', label: 'NPWP (hanya untuk outlet PKP)', bagian: 'kepala' },
    { kunci: 'TampilkanKasir', label: 'Nama kasir', bagian: 'isi' },
    { kunci: 'TampilkanPelanggan', label: 'Nama pelanggan', bagian: 'isi' },
    { kunci: 'TampilkanHemat', label: 'Total hemat dari diskon dan promo', bagian: 'isi' },
    { kunci: 'TampilkanStrukDigital', label: 'QR struk digital untuk pelanggan', bagian: 'isi' },
    { kunci: 'TampilkanUmpanBalik', label: 'Minta penilaian pelanggan di struk digital', bagian: 'isi' },
];

type KunciSaklar = (typeof saklar)[number]['kunci'];

function DaftarSaklar({
    bagian,
    isian,
    saatBerubah,
}: {
    bagian: 'kepala' | 'isi';
    isian: IsianStruk;
    saatBerubah: (kunci: KunciSaklar, nilai: boolean) => void;
}) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {saklar
                .filter((s) => s.bagian === bagian)
                .map((s) => (
                    <KotakCentang
                        key={s.kunci}
                        label={s.label}
                        nilai={isian[s.kunci]}
                        saatBerubah={(nilai) => saatBerubah(s.kunci, nilai)}
                    />
                ))}
        </div>
    );
}

/**
 * Pengaturan struk (PLT-06, PRD v1.79): satu pengaturan untuk semua outlet, dengan pratinjau kertas thermal 58/80 mm.
 * Berlaku di aplikasi kasir setelah data perangkat diperbarui. Nama usaha, NPWP, dan logo diubah di profil usaha.
 */
function IsiPengaturanStruk({
    Pengaturan,
    Profil,
    Outlet = [],
    UuidOutlet = null,
    LogoOutletKhusus = false,
}: PropsPengaturanStruk) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState(() => KeIsian(Pengaturan));
    const [lebar, AturLebar] = useState<LebarKertas>('58');
    const [memproses, AturMemproses] = useState(false);
    const [logoBaru, AturLogoBaru] = useState<File | null>(null);
    const [hapusLogo, AturHapusLogo] = useState(false);
    const adaPilihanOutlet = Outlet.length > 1 && UuidOutlet !== null;
    const pengaturan = KePengaturan(isian);
    const berubah =
        JSON.stringify(pengaturan) !== JSON.stringify(KePengaturan(KeIsian(Pengaturan))) ||
        logoBaru !== null ||
        hapusLogo;
    const Ubah = <K extends keyof IsianStruk>(kunci: K, nilai: IsianStruk[K]) =>
        AturIsian((lama) => ({ ...lama, [kunci]: nilai }));

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const pilihan = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => {
                AturLogoBaru(null);
                AturHapusLogo(false);
            },
        };

        if (!adaPilihanOutlet) {
            router.put(alamat, pengaturan, pilihan);

            return;
        }

        // D-76: dengan outlet terpilih, logo ikut terkirim sebagai berkas (POST + _method=put).
        router.post(
            alamat,
            { ...pengaturan, _method: 'put', UuidOutlet, Logo: logoBaru, HapusLogo: hapusLogo ? 1 : 0 },
            { ...pilihan, forceFormData: true },
        );
    };

    return (
        <TataLetakAplikasi judul="Pengaturan struk">
            <DaftarGalatServer
                galat={galat}
                kecuali={[
                    'NamaDicetak',
                    'TeksKepala',
                    'TeksKepala.0',
                    'TeksKepala.1',
                    'TeksKepala.2',
                    'CatatanKaki',
                    'TeksPenutup',
                ]}
            />
            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
                <form
                    onSubmit={Simpan}
                    aria-label="Pengaturan struk"
                    className="flex min-w-0 flex-col gap-4"
                    noValidate
                >
                    {adaPilihanOutlet ? (
                        <Panel
                            judul="Outlet"
                            idJudul="judul-outlet-struk"
                            keterangan="Saklar tampil dan logo di bawah berlaku untuk outlet yang dipilih. Isian teks (nama, kepala, catatan kaki, penutup) dipakai bersama."
                        >
                            <BidangPilihan
                                label="Atur struk untuk outlet"
                                nilai={UuidOutlet}
                                opsi={Outlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                                saatBerubah={(uuid) => router.get(alamat, { Outlet: uuid })}
                            />
                            <BidangGambar
                                label="Logo struk outlet ini"
                                berkas={logoBaru}
                                saatBerubah={(berkas) => {
                                    AturLogoBaru(berkas);
                                    AturHapusLogo(false);
                                }}
                                tautanSaatIni={hapusLogo ? null : (Profil.TautanLogo ?? null)}
                                {...(LogoOutletKhusus
                                    ? { saatHapusSaatIni: () => AturHapusLogo(true), labelHapus: 'Pakai logo usaha' }
                                    : {})}
                                ukuranMaksimalKb={1024}
                                ekstensi={['png', 'jpg', 'jpeg', 'webp']}
                                keterangan={
                                    LogoOutletKhusus
                                        ? 'Logo khusus outlet ini.'
                                        : 'Belum ada logo khusus. Memakai logo usaha. Unggah untuk menggantinya.'
                                }
                                galat={galat.Logo}
                            />
                        </Panel>
                    ) : null}
                    <Panel
                        judul="Kepala struk"
                        idJudul="judul-kepala-struk"
                        keterangan={
                            <>
                                Berlaku untuk semua outlet. Nama usaha, NPWP, dan logo diubah di{' '}
                                <Link
                                    href="/kelola/panduan-awal/profil-usaha"
                                    className="font-semibold text-brand underline"
                                >
                                    profil usaha
                                </Link>
                                .
                            </>
                        }
                    >
                        <BidangTeks
                            label="Nama di struk"
                            nilai={isian.NamaDicetak}
                            saatBerubah={(teks) => Ubah('NamaDicetak', teks)}
                            keterangan="Kosongkan agar struk memakai nama outlet masing-masing. Nama usaha atau akun pemilik tidak pernah dicetak. Isi hanya bila semua outlet harus memakai satu nama yang sama."
                            maxLength={PANJANG_BARIS}
                            galat={galat.NamaDicetak}
                        />
                        {isian.TeksKepala.map((teks, i) => (
                            <BidangTeks
                                key={i}
                                label={`Baris kepala ${i + 1}`}
                                nilai={teks}
                                saatBerubah={(baru) =>
                                    Ubah(
                                        'TeksKepala',
                                        isian.TeksKepala.map((lama, j) => (j === i ? baru : lama)),
                                    )
                                }
                                {...(i === 0
                                    ? { keterangan: 'Misal jam buka, akun Instagram, atau nomor WhatsApp.' }
                                    : {})}
                                maxLength={PANJANG_BARIS}
                                galat={galat[`TeksKepala.${i}`] ?? (i === 0 ? galat.TeksKepala : undefined)}
                            />
                        ))}
                        <DaftarSaklar bagian="kepala" isian={isian} saatBerubah={Ubah} />
                        <CatatanDataOutlet profil={Profil} isian={isian} />
                    </Panel>
                    <Panel judul="Isi struk" idJudul="judul-isi-struk">
                        <DaftarSaklar bagian="isi" isian={isian} saatBerubah={Ubah} />
                    </Panel>
                    <Panel judul="Kaki struk" idJudul="judul-kaki-struk">
                        <BidangTeksPanjang
                            label="Catatan kaki"
                            nilai={isian.CatatanKaki}
                            saatBerubah={(teks) => Ubah('CatatanKaki', teks)}
                            keterangan="Misal kebijakan tukar barang atau info Wi-Fi."
                            maksimal={PANJANG_CATATAN}
                            baris={3}
                            galat={galat.CatatanKaki}
                        />
                        <BidangTeks
                            label="Kalimat penutup"
                            nilai={isian.TeksPenutup}
                            saatBerubah={(teks) => Ubah('TeksPenutup', teks)}
                            keterangan={`Kosongkan untuk memakai "${PENUTUP_BAWAAN}".`}
                            maxLength={PANJANG_BARIS}
                            galat={galat.TeksPenutup}
                        />
                        {Profil.TandaAir ? (
                            <p className="text-keterangan text-teks-sekunder">
                                Paket Anda mencetak baris "Dibuat dengan Payoung" di akhir struk. Upgrade paket untuk
                                menghapusnya.
                            </p>
                        ) : null}
                    </Panel>
                    <div className="flex flex-wrap items-center gap-3">
                        <Tombol type="submit" memproses={memproses} disabled={!berubah}>
                            Simpan pengaturan struk
                        </Tombol>
                        {!berubah ? (
                            <span className="text-keterangan text-teks-sekunder">Belum ada perubahan.</span>
                        ) : null}
                    </div>
                </form>
                <section aria-labelledby="judul-pratinjau-struk" className="flex flex-col gap-3 lg:sticky lg:top-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="judul-pratinjau-struk" className="text-subjudul font-semibold">
                            Pratinjau
                        </h2>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={lebar}
                            onValueChange={(nilai) => (nilai === '58' || nilai === '80' ? AturLebar(nilai) : undefined)}
                            aria-label="Lebar kertas"
                        >
                            <ToggleGroupItem value="58">58 mm</ToggleGroupItem>
                            <ToggleGroupItem value="80">80 mm</ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                    <PratinjauStruk pengaturan={pengaturan} profil={Profil} lebar={lebar} />
                    <p className="max-w-xs text-keterangan text-teks-sekunder">
                        Transaksi contoh. Alamat dan telepon di atas diambil dari data outlet, sama dengan yang
                        tercetak.
                    </p>
                </section>
            </div>
        </TataLetakAplikasi>
    );
}

/**
 * D-177: alamat & telepon struk tidak diketik di sini, melainkan dari data outlet. Catatan ini menunjukkan jalannya,
 * dan memperingatkan bila saklarnya menyala tetapi datanya belum diisi (jadi tidak ada yang tercetak).
 */
function CatatanDataOutlet({ profil, isian }: { profil: PropsPengaturanStruk['Profil']; isian: IsianStruk }) {
    const tautan = profil.UuidOutletProfil === null ? '/kelola/outlet' : `/kelola/outlet/${profil.UuidOutletProfil}`;
    const kosong = [
        isian.TampilkanAlamat && !profil.AlamatOutlet ? 'alamat' : null,
        isian.TampilkanTelepon && !profil.TeleponOutlet ? 'telepon' : null,
    ].filter((nama): nama is string => nama !== null);

    return (
        <p className="text-keterangan text-teks-sekunder">
            {kosong.length > 0 ? (
                <>
                    <span className="font-semibold text-teks-utama">
                        Belum tercetak: {kosong.join(' dan ')} outlet belum diisi.{' '}
                    </span>
                </>
            ) : null}
            Alamat dan telepon diambil dari data outlet, bukan diketik di sini.{' '}
            <Link href={tautan} className="font-semibold text-brand underline">
                Ubah di data outlet
            </Link>
            .
        </p>
    );
}

/** Berganti outlet memuat ulang isian dari server, jadi isian lokal dibuat baru per outlet (D-76). */
export default function HalamanPengaturanStruk(props: PropsPengaturanStruk) {
    return <IsiPengaturanStruk key={props.UuidOutlet ?? 'tenant'} {...props} />;
}
