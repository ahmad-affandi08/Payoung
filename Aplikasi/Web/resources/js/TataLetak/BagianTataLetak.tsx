import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRightIcon,
    Building2Icon,
    CheckIcon,
    ChevronDownIcon,
    ClockIcon,
    CopyIcon,
    CreditCardIcon,
    LogOutIcon,
    ShieldCheckIcon,
    SparklesIcon,
} from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import { Avatar, AvatarFallback } from '@/Komponen/Ui/avatar';
import { Badge } from '@/Komponen/Ui/badge';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { StatusLanggananTenant, TenantAktif } from '@/Tipe/Aplikasi';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Komponen/Ui/breadcrumb';
import { Button } from '@/Komponen/Ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Komponen/Ui/dropdown-menu';
import { Separator } from '@/Komponen/Ui/separator';
import { SidebarTrigger } from '@/Komponen/Ui/sidebar';
import { Toaster } from '@/Komponen/Ui/sonner';
import { cn } from '@/Komponen/Ui/utils';

/**
 * Gaya menu sidebar gelap merek (D-15), dipakai tata letak tenant & pengelola: menu aktif berlatar Brand dengan
 * teks putih tebal, hover memakai sorotan BrandGelap. Warna datang dari token --sidebar-* di Gaya/Aplikasi.css.
 */
export const kelasTombolMenuSidebar =
    'h-9 rounded-lg px-3 text-label transition-[width,height,padding,background-color,color] duration-200 ease-out group-data-[collapsible=icon]:rounded-md group-data-[collapsible=icon]:p-1! data-[active=true]:bg-sidebar-primary data-[active=true]:font-semibold data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground';

/** Tombol grup menu (pembuka sub-menu): tetap Brand saat aktif walau terbuka & disorot. */
export const kelasTombolGrupSidebar =
    'group/grup cursor-pointer data-[active=true]:data-[state=open]:hover:bg-sidebar-primary data-[active=true]:data-[state=open]:hover:text-sidebar-primary-foreground';

/** Chevron di ujung kanan tombol grup: memutar 90° saat sub-menu terbuka, disembunyikan saat sidebar jadi ikon. */
export const kelasChevronGrupSidebar =
    'ml-auto transition-transform duration-200 ease-out group-data-[collapsible=icon]:hidden group-data-[state=open]/grup:rotate-90';

/** Sub-menu: teks redup, item aktif disorot BrandGelap dengan teks putih tebal. */
export const kelasTombolSubMenuSidebar =
    'h-8 rounded-md text-label transition-[background-color,color] duration-200 ease-out data-[active=true]:font-semibold';

/** Cookie bawaan SidebarProvider shadcn/ui; dibaca agar bilah samping tetap diciutkan setelah pindah halaman. */
export function BacaSidebarTerbuka(): boolean {
    if (typeof document === 'undefined') {
        return true;
    }

    return !document.cookie.split('; ').includes('sidebar_state=false');
}

/** Inisial untuk avatar menu akun ("Rina Wulandari" → "RW"). */
export function AmbilInisial(nama: string | undefined): string {
    const kata = (nama ?? '').trim().split(/\s+/).filter(Boolean);

    return kata
        .slice(0, 2)
        .map((bagian) => bagian.charAt(0).toUpperCase())
        .join('');
}

/** Toast (sonner) dengan label Bahasa Indonesia; dipasang sekali di setiap tata letak. */
export function PemberitahuanMelayang() {
    return (
        <Toaster
            position="top-right"
            closeButton
            containerAriaLabel="Notifikasi"
            toastOptions={{ closeButtonAriaLabel: 'Tutup' }}
        />
    );
}

type PropsKepala = {
    /** Rantai remah roti: induk (nama usaha/platform) lalu halaman ini. */
    induk: string;
    judul: string;
    /** false = remah roti tidak di kepala, tetapi di atas judul halaman sebagai `JejakHalaman` (D-27). */
    remah?: boolean;
    /** Kepala gelap untuk Platform Pengelola (PRD §13.8). */
    gelap?: boolean;
    /** false bila pembungkusnya sudah sticky (misal bersama penanda lingkungan Pengelola). */
    lengket?: boolean;
    /** Tujuan tautan identitas di bilah atas: beranda back-office tenant (bawaan) atau beranda konsol Pengelola. */
    tautanBeranda?: string;
    /** Identitas usaha: logo dan nama usaha di bilah atas (di HP hanya logo). */
    identitasUsaha?: {
        nama: string;
        tautanLogo?: string | null | undefined;
    } | null;
    children?: ReactNode;
};

/** Bilah atas di samping bilah menu: tombol buka/tutup menu, logo & nama usaha, remah roti, lalu menu akun. */
export function KepalaTataLetak({
    induk,
    judul,
    gelap = false,
    lengket = true,
    remah = true,
    tautanBeranda = '/kelola',
    identitasUsaha,
    children,
}: PropsKepala) {
    return (
        <header
            className={cn(
                'flex min-h-14 items-center gap-1.5 border-b px-3 py-2 sm:gap-2 sm:px-4',
                lengket && 'sticky top-0 z-10',
                gelap ? 'border-teks-utama bg-teks-utama text-permukaan' : 'border-garis bg-permukaan',
            )}
        >
            <SidebarTrigger
                aria-label="Buka atau tutup menu samping"
                data-tur="tombol-menu"
                title="Buka atau tutup menu samping (Ctrl+B)"
                className={cn(
                    '-ml-1 size-10 shrink-0 pointer-coarse:size-10 lg:size-9 lg:pointer-coarse:size-11',
                    gelap && 'hover:bg-permukaan/15 hover:text-permukaan',
                )}
            />
            <Separator
                orientation="vertical"
                className={cn('mr-1 data-[orientation=vertical]:h-5 shrink-0', gelap ? 'bg-permukaan/40' : 'bg-garis')}
            />

            {identitasUsaha ? (
                <Link
                    href={tautanBeranda}
                    className="flex min-w-0 shrink-0 items-center gap-2.5 rounded-lg transition-opacity hover:opacity-85 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brand max-w-[45%] sm:max-w-xs"
                    title={identitasUsaha.nama}
                >
                    <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-garis bg-permukaan sm:size-8">
                        {identitasUsaha.tautanLogo ? (
                            <img
                                src={identitasUsaha.tautanLogo}
                                alt={`Logo ${identitasUsaha.nama}`}
                                className="size-full object-contain p-0.5"
                            />
                        ) : (
                            <span className="flex size-full items-center justify-center rounded-lg bg-brand-lembut text-brand font-bold text-keterangan uppercase">
                                {AmbilInisial(identitasUsaha.nama)}
                            </span>
                        )}
                    </span>
                    <span className="hidden sm:inline-block truncate font-semibold text-label text-teks-utama">
                        {identitasUsaha.nama}
                    </span>
                </Link>
            ) : null}

            {remah ? (
                <Breadcrumb aria-label="Remah roti" className="min-w-0 flex-1">
                    <BreadcrumbList className={cn('text-label', gelap ? 'text-permukaan/80' : 'text-teks-sekunder')}>
                        <BreadcrumbItem className="min-w-0">
                            <span className="truncate font-semibold">{induk}</span>
                        </BreadcrumbItem>
                        <BreadcrumbSeparator />
                        <BreadcrumbItem className="min-w-0">
                            {/* shadcn memberi role="link" + aria-disabled; halaman saat ini cukup aria-current. */}
                            <BreadcrumbPage
                                role={undefined}
                                aria-disabled={undefined}
                                className={cn('truncate', gelap ? 'text-permukaan' : 'text-teks-utama')}
                            >
                                {judul}
                            </BreadcrumbPage>
                        </BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            ) : (
                <span className="min-w-0 flex-1" />
            )}
            {children}
        </header>
    );
}

export type ButirJejak = { label: string; href?: string };

/**
 * Jejak halaman di atas judul (D-27), bukan di kepala halaman.
 *
 * Menunjukkan induk halaman ini: nama usaha, lalu grup menunya — dan untuk halaman yang rumahnya di Pengaturan,
 * tautan kembali ke Pengaturan beserta nama grupnya. Tanpa ini halaman yang keluar dari menu samping (Satuan,
 * Outlet, Pengguna & peran, dan seterusnya) tidak punya satu pun petunjuk letaknya.
 *
 * Halaman saat ini tidak diulang di sini karena sudah menjadi `<h1>` di bawahnya.
 */
export function JejakHalaman({ jejak }: { jejak: ButirJejak[] }) {
    if (jejak.length === 0) {
        return null;
    }

    // D-228: jejak tanpa satu pun tautan (mis. "Nama usaha › Produk") tidak memberi jalan ke mana-mana dan hanya
    // memakan satu baris di HP. Jejak yang punya jalan kembali tetap tampil.
    const adaTautan = jejak.some((butir) => butir.href !== undefined);

    return (
        <Breadcrumb aria-label="Jejak halaman" className={cn('-mb-1', !adaTautan && 'max-sm:hidden')}>
            <BreadcrumbList className="text-keterangan text-teks-sekunder">
                {jejak.map((butir, urutan) => (
                    <Fragment key={`${butir.label}-${String(urutan)}`}>
                        {urutan > 0 ? <BreadcrumbSeparator /> : null}
                        <BreadcrumbItem className="min-w-0">
                            {butir.href === undefined ? (
                                <span className="truncate">{butir.label}</span>
                            ) : (
                                <Link
                                    href={butir.href}
                                    className="truncate font-semibold text-brand underline-offset-2 hover:underline"
                                >
                                    {butir.label}
                                </Link>
                            )}
                        </BreadcrumbItem>
                    </Fragment>
                ))}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

function KelasStatusLangganan(status: StatusLanggananTenant | null): string {
    switch (status) {
        case 'Aktif':
            return 'bg-sukses-lembut text-sukses border-sukses/30';
        case 'Trial':
            return 'bg-info-lembut text-info border-info/30';
        case 'Tertunggak':
            return 'bg-peringatan-lembut text-peringatan border-peringatan/30';
        case 'Ditangguhkan':
            return 'bg-bahaya-lembut text-bahaya border-bahaya/30';
        case 'Gratis':
            return 'bg-brand-lembut text-brand border-brand/30';
        default:
            return 'bg-latar text-teks-sekunder border-garis';
    }
}

function TeksMasaAktif(tenant: TenantAktif): string {
    if (tenant.StatusLangganan === 'Trial') {
        return tenant.PeriodeSelesai ? `Trial s/d ${FormatTanggal(tenant.PeriodeSelesai)}` : 'Masa percobaan';
    }
    if (tenant.PeriodeSelesai) {
        return `Berlaku s/d ${FormatTanggal(tenant.PeriodeSelesai)}`;
    }
    return 'Langganan aktif';
}

type PropsMenuAkun = {
    nama: string | undefined;
    email?: string | undefined;
    gelap?: boolean;
    tenant?: TenantAktif | null;
    bolehKelolaLangganan?: boolean;
    /** Konsol pengelola memakai `/keamanan`; back-office tenant `/kelola/keamanan`. */
    tautanKeamanan?: string;
    /** Akun ini wajib absen (D-180): tampilkan pintasan ke halaman Absen dari halaman mana pun. */
    bisaAbsen?: boolean;
};

/**
 * Menu akun (DropdownMenu): profil usaha, kartu paket & langganan aktif (perpanjang, masa aktif,
 * ID tenant copy, notifikasi tagihan pending), Keamanan akun (D-27), lalu Keluar.
 */
export function MenuAkun({
    nama,
    email,
    gelap = false,
    tenant,
    bolehKelolaLangganan = true,
    tautanKeamanan = '/kelola/keamanan',
    bisaAbsen = false,
}: PropsMenuAkun) {
    const [sudahSalin, AturSudahSalin] = useState(false);

    const SalinId = (teks: string, e: React.MouseEvent) => {
        e.stopPropagation();
        e.preventDefault();
        void navigator.clipboard.writeText(teks);
        AturSudahSalin(true);
        toast.success('ID Pelanggan berhasil disalin');
        setTimeout(() => AturSudahSalin(false), 2000);
    };

    const adaTagihan = tenant?.TagihanTertunda !== null && tenant?.TagihanTertunda !== undefined;
    // Dipersempit sekali di sini: penyempitan `tenant?.KodePelanggan` di JSX tidak terbawa ke dalam closure
    // onClick, dan menambal itu dengan `!` menyembunyikan kemungkinan null alih-alih menghilangkannya.
    const kodePelanggan = tenant?.KodePelanggan ?? null;

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    aria-label={`Menu akun ${nama ?? ''}`.trim()}
                    className={cn(
                        'h-10 gap-1.5 px-1.5 pointer-coarse:h-10 text-label font-semibold sm:gap-2 sm:px-2 lg:h-9 lg:pointer-coarse:h-11',
                        gelap && 'text-permukaan hover:bg-permukaan/15 hover:text-permukaan',
                    )}
                >
                    <div className="relative shrink-0">
                        <Avatar size="sm" aria-hidden="true">
                            <AvatarFallback className="bg-brand-lembut text-keterangan font-semibold text-brand">
                                {AmbilInisial(tenant?.Nama ?? nama)}
                            </AvatarFallback>
                        </Avatar>
                        {adaTagihan ? (
                            <span
                                aria-label="Ada tagihan tertunda"
                                className="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-bahaya ring-2 ring-permukaan animate-pulse"
                            />
                        ) : null}
                    </div>
                    <div className="hidden flex-col items-start text-left lg:flex min-w-0">
                        <span className="max-w-40 truncate text-label font-semibold text-teks-utama leading-tight">
                            {tenant?.Nama ?? nama}
                        </span>
                        {tenant?.Nama ? (
                            <span className="max-w-40 truncate text-keterangan font-normal text-teks-sekunder leading-tight">
                                {nama}
                            </span>
                        ) : null}
                    </div>
                    {tenant?.NamaPaket ? (
                        <Badge
                            variant="outline"
                            className="hidden xl:inline-flex text-keterangan px-1.5 py-0 h-4 border-brand/30 bg-brand/5 text-brand font-semibold shrink-0"
                        >
                            {tenant.NamaPaket}
                        </Badge>
                    ) : null}
                    <ChevronDownIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-2">
                <DropdownMenuLabel className="p-2">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2.5 min-w-0">
                            <span className="size-9 shrink-0 rounded-lg overflow-hidden border border-garis bg-permukaan flex items-center justify-center">
                                {tenant?.TautanLogo ? (
                                    <img
                                        src={tenant.TautanLogo}
                                        alt={`Logo ${tenant.Nama}`}
                                        className="size-full object-contain p-0.5"
                                    />
                                ) : (
                                    <span className="flex size-full items-center justify-center rounded-lg bg-brand-lembut text-label font-bold text-brand">
                                        {AmbilInisial(tenant?.Nama ?? nama)}
                                    </span>
                                )}
                            </span>
                            <div className="flex flex-col min-w-0">
                                <span className="truncate text-label font-bold text-teks-utama">
                                    {tenant?.Nama ?? nama}
                                </span>
                                <span className="truncate text-keterangan text-teks-sekunder">{email ?? nama}</span>
                            </div>
                        </div>
                        {kodePelanggan !== null ? (
                            <button
                                type="button"
                                onClick={(e) => SalinId(kodePelanggan, e)}
                                title="Salin ID Pelanggan"
                                className="flex items-center gap-1 rounded bg-latar px-2 py-1 text-keterangan font-mono font-medium text-teks-sekunder hover:text-teks-utama hover:bg-garis transition cursor-pointer shrink-0"
                            >
                                <span>{kodePelanggan}</span>
                                {sudahSalin ? (
                                    <CheckIcon className="size-3 text-sukses shrink-0" />
                                ) : (
                                    <CopyIcon className="size-3 shrink-0" />
                                )}
                            </button>
                        ) : null}
                    </div>
                </DropdownMenuLabel>

                {tenant ? (
                    <div className="mx-1 mb-2 rounded-lg border border-garis bg-permukaan-redup/70 p-3 flex flex-col gap-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-1.5 min-w-0">
                                <SparklesIcon className="size-4 shrink-0 text-brand" />
                                <span className="truncate text-label font-bold text-teks-utama">
                                    {tenant.NamaPaket ?? 'Paket Langganan'}
                                </span>
                                {tenant.StatusLangganan ? (
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'text-keterangan px-1.5 py-0 font-medium',
                                            KelasStatusLangganan(tenant.StatusLangganan),
                                        )}
                                    >
                                        {tenant.StatusLangganan}
                                    </Badge>
                                ) : null}
                            </div>
                            {bolehKelolaLangganan ? (
                                <Link
                                    href="/kelola/langganan"
                                    className="inline-flex items-center gap-0.5 text-keterangan font-semibold text-brand hover:underline shrink-0"
                                >
                                    Perpanjang
                                    <ArrowUpRightIcon className="size-3" />
                                </Link>
                            ) : null}
                        </div>
                        <p className="text-keterangan text-teks-sekunder">{TeksMasaAktif(tenant)}</p>

                        {tenant.TagihanTertunda ? (
                            <div className="flex items-center justify-between gap-2 rounded-md border border-bahaya/30 bg-bahaya-lembut/70 p-2 text-keterangan">
                                <div className="min-w-0">
                                    <p className="font-semibold text-bahaya">Tagihan {tenant.TagihanTertunda.Nomor}</p>
                                    <p className="text-teks-utama tabular-nums font-medium">
                                        {FormatRupiah(tenant.TagihanTertunda.Total)}
                                    </p>
                                </div>
                                <Button
                                    asChild
                                    size="sm"
                                    variant="destructive"
                                    className="h-8 pointer-coarse:h-11 px-3 text-label font-semibold shrink-0"
                                >
                                    <Link href={`/kelola/langganan/tagihan/${tenant.TagihanTertunda.Uuid}`}>Bayar</Link>
                                </Button>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                <DropdownMenuSeparator />

                {bolehKelolaLangganan && tenant ? (
                    <DropdownMenuItem asChild className="text-label cursor-pointer">
                        <Link href="/kelola/langganan">
                            <CreditCardIcon aria-hidden="true" />
                            Langganan & tagihan
                        </Link>
                    </DropdownMenuItem>
                ) : null}
                {bisaAbsen ? (
                    <DropdownMenuItem asChild className="text-label cursor-pointer">
                        <Link href="/kelola/absen">
                            <ClockIcon aria-hidden="true" />
                            Absen
                        </Link>
                    </DropdownMenuItem>
                ) : null}
                {tenant ? (
                    <DropdownMenuItem asChild className="text-label cursor-pointer">
                        <Link href="/kelola/pengaturan/profil-usaha">
                            <Building2Icon aria-hidden="true" />
                            Profil usaha
                        </Link>
                    </DropdownMenuItem>
                ) : null}
                <DropdownMenuItem asChild className="text-label cursor-pointer">
                    <Link href={tautanKeamanan}>
                        <ShieldCheckIcon aria-hidden="true" />
                        Keamanan akun
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="text-label text-destructive focus:text-destructive cursor-pointer"
                    onSelect={() => router.post('/keluar')}
                >
                    <LogOutIcon aria-hidden="true" />
                    Keluar
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
