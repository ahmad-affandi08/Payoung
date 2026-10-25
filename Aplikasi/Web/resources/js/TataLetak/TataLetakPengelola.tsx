import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
} from '@/Komponen/Ui/sidebar';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import IkonNavigasi, { type NamaIkonNavigasi } from '@/Komponen/Navigasi/IkonNavigasi';
import PenandaLingkungan from '@/Komponen/Umpan/PenandaLingkungan';
import DialogHasil from '@/Komponen/Umpan/DialogHasil';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { IzinPengelola, PunyaIzin, type KunciIzinPengelola, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

import {
    BacaSidebarTerbuka,
    JejakHalaman,
    kelasTombolMenuSidebar,
    KepalaTataLetak,
    MenuAkun,
    PemberitahuanMelayang,
    type ButirJejak,
} from './BagianTataLetak';
import gambarIkon from '@/Aset/Merek/IkonMerek.webp';
import KepalaSidebarMerek from './KepalaSidebarMerek';
import PencarianCepat, { type HalamanPencarian, type SumberPencarian } from './PencarianCepat';

type PropsTataLetak = {
    judul: string;
    /**
     * Langkah tambahan di jejak halaman, untuk halaman rincian yang butuh jalan kembali ke daftarnya
     * (misal `[{ label: 'Semua tenant', href: '/tenant' }]`). Sama dengan `TataLetakAplikasi`: sebelum D-28 hanya
     * tenant yang punya jejak, sedangkan konsol memakai tautan "Kembali ke …" yang ditulis ulang per halaman.
     */
    jejak?: ButirJejak[];
    /**
     * Aksi di kepala halaman. **Hanya untuk halaman rincian/formulir.** Halaman daftar memakai
     * `Komponen/Kelola/AksiHalaman` — baris sendiri di atas tabel (D-27), sama seperti back-office tenant.
     * Dijaga `Komponen/Kelola/AksiHalamanTes.tsx`.
     */
    aksi?: ReactNode;
    children: ReactNode;
};

type ItemMenu = {
    label: string;
    href: string;
    izin: KunciIzinPengelola | null;
    ikon: NamaIkonNavigasi;
    /**
     * Alamat lain yang dimiliki entri ini, untuk halaman sekeluarga yang alamatnya belum seragam
     * (`/flag-fitur`, `/kompatibilitas-perangkat` di bawah Rilis aplikasi). Tanpa ini menu tidak ikut menyala.
     */
    alamatLain?: string[];
};

type GrupMenu = { grup: string; item: ItemMenu[] };

/**
 * Menu samping Platform Pengelola, dikelompokkan menurut pekerjaan dan diurutkan menurut seberapa sering dipakai
 * (D-30). Sebelumnya 16 entri datar berurut nomor flow, sehingga `Tenant` — subjek yang paling sering dibuka —
 * justru paling bawah, dan tiga halaman P-10 yang satu subjek tampil sebagai tiga entri terpisah.
 *
 * Aturannya dijaga `AnggaranNavigasiPengelolaTes`. Menambah halaman berarti memilih grupnya, bukan menambah entri
 * di ujung daftar.
 */
export const daftarMenuPengelola: GrupMenu[] = [
    {
        grup: 'Pekerjaan harian',
        item: [
            { label: 'Beranda', href: '/', izin: null, ikon: 'Beranda' },
            // P-07 Siklus hidup tenant: subjek yang paling sering dibuka, jadi paling atas setelah Beranda.
            {
                label: 'Tenant',
                href: '/tenant',
                izin: IzinPengelola.TenantLihat,
                ikon: 'Toko',
                alamatLain: ['/tugas-tenant'],
            },
            // P-08 Tagihan langganan & verifikasi transfer.
            {
                label: 'Tagihan',
                href: '/tagihan',
                izin: IzinPengelola.TagihanLihat,
                ikon: 'Tagihan',
                alamatLain: ['/laporan-langganan'],
            },
            // P-09
            { label: 'Dukungan', href: '/dukungan/tiket', izin: IzinPengelola.DukunganTiketLihat, ikon: 'Dukungan' },
            // P-11
            {
                label: 'Operasional',
                href: '/operasional',
                izin: IzinPengelola.OperasionalLihat,
                ikon: 'DaftarPeriksa',
            },
        ],
    },
    {
        grup: 'Produk & pemasaran',
        item: [
            { label: 'Katalog', href: '/katalog/paket', izin: IzinPengelola.KatalogLihat, ikon: 'Produk' },
            {
                label: 'Template sektor',
                href: '/template-sektor',
                izin: IzinPengelola.TemplateLihat,
                ikon: 'Lapisan',
            },
            // P-10: rilis, flag fitur, HCL, dan pengumuman adalah satu subjek, jadi satu entri dengan tab halaman (TabRilis).
            {
                label: 'Rilis aplikasi',
                href: '/rilis',
                izin: IzinPengelola.RilisLihat,
                ikon: 'Retur',
                alamatLain: ['/flag-fitur', '/kompatibilitas-perangkat', '/pengumuman'],
            },
            // D-21 Situs pemasaran (payoung.id).
            { label: 'Situs pemasaran', href: '/situs/halaman', izin: IzinPengelola.SitusLihat, ikon: 'Lokasi' },
            // P-12 Mitra, reseller & referral: kanal akuisisi tenant, jadi serumpun dengan pemasaran.
            { label: 'Mitra', href: '/mitra', izin: IzinPengelola.MitraLihat, ikon: 'Loyalitas' },
            // P-07 PGL-24: funnel pendaftaran, adopsi fitur, retensi kohort (agregat, tanpa data pribadi).
            { label: 'Analitik', href: '/analitik', izin: IzinPengelola.AnalitikLihat, ikon: 'Grafik' },
        ],
    },
    {
        grup: 'Data platform',
        item: [
            { label: 'Integrasi', href: '/integrasi', izin: IzinPengelola.IntegrasiLihat, ikon: 'KodeQr' },
            {
                label: 'Referensi',
                href: '/referensi/tarif-pajak',
                izin: IzinPengelola.ReferensiLihat,
                ikon: 'Akuntansi',
            },
            {
                label: 'Legal',
                href: '/legal',
                izin: IzinPengelola.LegalLihat,
                ikon: 'Keamanan',
                alamatLain: ['/template-komunikasi'],
            },
        ],
    },
    {
        grup: 'Internal',
        item: [
            { label: 'Tim internal', href: '/tim-internal', izin: IzinPengelola.TimAnggotaLihat, ikon: 'Pelanggan' },
            { label: 'Log audit', href: '/log-audit', izin: IzinPengelola.AuditLihat, ikon: 'DaftarPeriksa' },
        ],
    },
];

/**
 * Sumber data pencarian cepat konsol. Aktif hanya bila halaman daftarnya ada di menu yang boleh dilihat pelaku; server
 * tetap memeriksa izin pada endpoint JSON `TabelData` yang sama.
 */
const sumberPencarianPengelola: SumberPencarian[] = [
    {
        id: 'tenant',
        label: 'Tenant',
        alamat: '/tenant',
        ikon: 'Pelanggan',
        AmbilHasil: (b) => ({
            judul: String(b.Nama),
            keterangan: typeof b.EmailPemilik === 'string' ? b.EmailPemilik : null,
            href: `/tenant/${String(b.Uuid)}`,
        }),
    },
    {
        id: 'tiket',
        label: 'Tiket dukungan',
        alamat: '/dukungan/tiket',
        ikon: 'Dukungan',
        AmbilHasil: (b) => ({
            judul: `${String(b.Nomor)} ${String(b.Judul)}`,
            keterangan: typeof b.NamaTenant === 'string' ? b.NamaTenant : null,
            href: `/dukungan/tiket/${String(b.Uuid)}`,
        }),
    },
    {
        id: 'tagihan',
        label: 'Tagihan langganan',
        alamat: '/tagihan',
        ikon: 'Akuntansi',
        AmbilHasil: (b) => ({
            judul: String(b.Nomor),
            keterangan: typeof b.NamaTenant === 'string' ? b.NamaTenant : null,
            href: `/tagihan/${String(b.Uuid)}`,
        }),
    },
];

/**
 * Menu aktif: Beranda hanya untuk "/", lainnya menurut segmen pertama URL (/katalog/addon → Katalog), ditambah
 * alamat lain yang dimiliki entri yang sama (/flag-fitur → Rilis aplikasi).
 */
export function CekMenuPengelolaAktif(href: string, url: string, alamatLain: string[] = []): boolean {
    const Cocok = (alamat: string) => url.startsWith(alamat.split('/').slice(0, 2).join('/'));

    return href === '/' ? url === '/' : Cocok(href) || alamatLain.some(Cocok);
}

/**
 * Tata letak Platform Pengelola (PRD §13.8): kepala sama dengan back-office tenant (D-205), penanda lingkungan
 * selalu terlihat, menu samping shadcn/ui sesuai izin.
 */
export default function TataLetakPengelola({ judul, jejak = [], aksi, children }: PropsTataLetak) {
    const { props, url } = usePage<PropsBersamaPengelola>();
    const pengguna = props.Pengguna;
    const grupTerlihat = daftarMenuPengelola
        .map((grup) => ({
            ...grup,
            item: grup.item.filter((menu) => menu.izin === null || PunyaIzin(pengguna, menu.izin)),
        }))
        .filter((grup) => grup.item.length > 0);
    const namaPlatform = `${props.NamaAplikasi} | Pengelola`;
    // Ctrl+K: halaman dari menu yang terlihat + pencarian tenant, tiket, dan tagihan lewat endpoint JSON daftarnya.
    const halamanPencarian: HalamanPencarian[] = grupTerlihat.flatMap((grup) =>
        grup.item.map((menu) => ({ label: menu.label, href: menu.href, grup: grup.grup, ikon: menu.ikon })),
    );
    const alamatTerlihat = new Set(halamanPencarian.map((h) => h.href));
    const sumberTerlihat = sumberPencarianPengelola.filter((sumber) => alamatTerlihat.has(sumber.alamat));

    return (
        <SidebarProvider defaultOpen={BacaSidebarTerbuka()}>
            <Head title={judul} />
            <Sidebar collapsible="icon" className="border-sidebar-border">
                <KepalaSidebarMerek nama={props.NamaAplikasi} />
                <SidebarContent>
                    <nav aria-label="Menu utama">
                        {grupTerlihat.map((grup) => (
                            <SidebarGroup key={grup.grup} className="px-3 py-2">
                                <SidebarGroupLabel>{grup.grup}</SidebarGroupLabel>
                                <SidebarGroupContent>
                                    <SidebarMenu>
                                        {grup.item.map((menu) => {
                                            const aktif = CekMenuPengelolaAktif(menu.href, url, menu.alamatLain);
                                            return (
                                                <SidebarMenuItem key={menu.href}>
                                                    <SidebarMenuButton
                                                        asChild
                                                        isActive={aktif}
                                                        tooltip={menu.label}
                                                        className={kelasTombolMenuSidebar}
                                                    >
                                                        <Link
                                                            href={menu.href}
                                                            aria-current={aktif ? 'page' : undefined}
                                                        >
                                                            <IkonNavigasi nama={menu.ikon} />
                                                            <span>{menu.label}</span>
                                                        </Link>
                                                    </SidebarMenuButton>
                                                </SidebarMenuItem>
                                            );
                                        })}
                                    </SidebarMenu>
                                </SidebarGroupContent>
                            </SidebarGroup>
                        ))}
                    </nav>
                </SidebarContent>
                {/* Rel hanya pintasan tetikus (tabIndex -1); tombol di bilah atas adalah kontrol yang diumumkan. */}
                <SidebarRail aria-hidden="true" aria-label={undefined} title="Buka atau tutup menu samping" />
            </Sidebar>
            <div data-slot="sidebar-inset" className="relative flex w-full min-w-0 flex-1 flex-col bg-latar">
                <div className="sticky top-0 z-20">
                    <PenandaLingkungan lingkungan={props.Lingkungan} />
                    {/* Kepala sama dengan dashboard tenant: terang, identitas di kiri, jejak di atas judul (bukan di kepala). */}
                    <KepalaTataLetak
                        induk={namaPlatform}
                        judul={judul}
                        remah={false}
                        lengket={false}
                        tautanBeranda="/"
                        identitasUsaha={{ nama: namaPlatform, tautanLogo: gambarIkon }}
                    >
                        <PencarianCepat halaman={halamanPencarian} sumber={sumberTerlihat} />
                        <MenuAkun nama={pengguna?.Nama} email={pengguna?.Email} tautanKeamanan="/keamanan" />
                    </KepalaTataLetak>
                </div>
                <main className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-4 sm:gap-4 sm:py-6">
                    <JejakHalaman jejak={jejak} />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <JudulHalaman>{judul}</JudulHalaman>
                        {aksi}
                    </div>
                    {props.PeringatanSuperAdmin ? (
                        <Pemberitahuan jenis="peringatan" judul="Super Admin aktif kurang dari 2">
                            Undang minimal satu Super Admin lagi agar Platform Pengelola tetap bisa dikelola bila satu
                            akun terkunci.
                        </Pemberitahuan>
                    ) : null}
                    {props.PeringatanIntegrasi.length > 0 ? (
                        <Pemberitahuan jenis="peringatan" judul="Status integrasi">
                            <ul className="list-disc pl-5">
                                {props.PeringatanIntegrasi.map((pesan) => (
                                    <li key={pesan}>{pesan}</li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}
                    {/* P-11 BR-P11.1: banner kondisi operasional. */}
                    {props.PeringatanOperasional.length > 0 ? (
                        <Pemberitahuan jenis="bahaya" judul="Masalah operasional">
                            <ul className="list-disc pl-5">
                                {props.PeringatanOperasional.map((pesan) => (
                                    <li key={pesan}>{pesan}</li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}
                    {children}
                </main>
            </div>
            <DialogHasil berhasil={props.Kilat} gagal={props.errors.Umum} penanda={props} galatBidang={props.errors} />
            <PemberitahuanMelayang />
        </SidebarProvider>
    );
}
