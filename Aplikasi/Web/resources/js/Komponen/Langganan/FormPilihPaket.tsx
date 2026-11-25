import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import PilihLamaLangganan, {
    CariHargaLama,
    UraiLama,
    type AturanLamaLangganan,
    type HargaPerLama,
} from '@/Komponen/Langganan/PilihLamaLangganan';
import { FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { RadioGroup, RadioGroupItem } from '@/Komponen/Ui/radio-group';
import { cn } from '@/Komponen/Ui/utils';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import type { RingkasanLangganan } from '@/Tipe/Langganan';

export type PilihanPaket = {
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    HargaBulanan: string;
    HargaTahunan: string;
    /** D-249: harga server untuk setiap lama yang boleh dipilih (sudah termasuk tangga diskon). */
    HargaPerLama: HargaPerLama[];
    PaketBerjalan: boolean;
    /** D-98: pratinjau naik paket (selisih prorata sebelum PPN); null bila bukan naik paket. */
    NaikPaket: { Selisih: string; Subtotal: string; HariDitagih: number; HariPeriode: number } | null;
    BisaDipilih: boolean;
};

type PropsFormPilihPaket = {
    pilihan: PilihanPaket[];
    langganan: RingkasanLangganan;
    aturan: AturanLamaLangganan;
};

/** Bawaan paket terpilih: dari `?paket=KODE` (dialog fitur terkunci, D-23), lalu paket berjalan, lalu yang pertama. */
function PilihPaketBawaan(pilihan: PilihanPaket[], langganan: RingkasanLangganan): PilihanPaket | undefined {
    const bisaDipilih = pilihan.filter((paket) => paket.BisaDipilih);
    const diminta = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('paket');

    return (
        bisaDipilih.find((paket) => paket.Kode === diminta) ??
        bisaDipilih.find((paket) => paket.PaketBerjalan) ??
        bisaDipilih.find((paket) => paket.Kode === langganan.KodePaket) ??
        bisaDipilih[0]
    );
}

/**
 * Pilih paket dan lama langganan lalu buat tagihan (D-332, tab Paket & harga). Kiri: kartu paket, lama, dan kupon.
 * Kanan: ringkasan harga dari server (tidak dihitung di peramban). PPN dan total baru tampil di tagihan.
 */
export default function FormPilihPaket({ pilihan, langganan, aturan }: PropsFormPilihPaket) {
    const idLegenda = useId();
    const bawaan = PilihPaketBawaan(pilihan, langganan);
    // Bawaan lama = lama yang dipakai sekarang bila masih dalam rentang, selain itu batas minimal (dari aturan server).
    const lamaBawaan =
        langganan.JumlahBulanSiklus >= aturan.MinimalBulan && langganan.JumlahBulanSiklus <= aturan.MaksimalBulan
            ? langganan.JumlahBulanSiklus
            : aturan.MinimalBulan;
    const formulir = useForm<{ KodePaket: string; JumlahBulan: string; KodeKupon: string }>({
        KodePaket: bawaan?.Kode ?? '',
        JumlahBulan: String(lamaBawaan),
        KodeKupon: '',
    });
    const perpanjangan = langganan.Status === 'Aktif' || langganan.Status === 'Tertunggak';
    const paketTerpilih = pilihan.find((paket) => paket.Kode === formulir.data.KodePaket);
    const naikPaket = paketTerpilih?.NaikPaket ?? null;
    const lamaTerpilih = UraiLama(formulir.data.JumlahBulan, aturan);
    const hargaLama = CariHargaLama(paketTerpilih?.HargaPerLama ?? [], lamaTerpilih);
    const adaNaikPaket = pilihan.some((paket) => paket.NaikPaket !== null);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/langganan/tagihan', { preserveScroll: true });
    };

    if (pilihan.length === 0) {
        return (
            <Pemberitahuan jenis="info" judul="Belum ada paket yang bisa dibeli">
                Harga paket sedang disiapkan. Coba lagi nanti atau hubungi tim kami.
            </Pemberitahuan>
        );
    }

    return (
        <form onSubmit={Kirim} noValidate className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <Panel
                judul={
                    adaNaikPaket
                        ? 'Perpanjang atau naik paket'
                        : perpanjangan
                          ? 'Perpanjang langganan'
                          : 'Pilih paket berbayar'
                }
                keterangan="Pilih paket dan lama langganan. Tagihan baru dibuat setelah Anda menekan Buat tagihan."
            >
                <FieldSet className="gap-2">
                    <FieldLegend id={idLegenda} className="mb-0 text-label font-semibold text-teks-utama">
                        Paket
                    </FieldLegend>
                    <RadioGroup
                        name="KodePaket"
                        value={formulir.data.KodePaket}
                        onValueChange={(nilai) => formulir.setData('KodePaket', nilai)}
                        aria-labelledby={idLegenda}
                        aria-invalid={formulir.errors.KodePaket ? true : undefined}
                        className="flex flex-col gap-2"
                    >
                        {pilihan.map((paket) => {
                            const terpilih = formulir.data.KodePaket === paket.Kode;
                            const hargaPaket = CariHargaLama(paket.HargaPerLama, lamaTerpilih);

                            return (
                                <label
                                    key={paket.Kode}
                                    htmlFor={`${idLegenda}-${paket.Kode}`}
                                    data-state={terpilih ? 'selected' : undefined}
                                    className={cn(
                                        'flex flex-wrap items-start gap-3 rounded-panel border p-4 sm:flex-nowrap',
                                        terpilih
                                            ? 'border-brand bg-brand-lembut ring-1 ring-brand'
                                            : 'border-garis bg-permukaan',
                                        paket.BisaDipilih ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={`${idLegenda}-${paket.Kode}`}
                                        value={paket.Kode}
                                        disabled={!paket.BisaDipilih}
                                        className="mt-1"
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="text-subjudul font-semibold text-teks-utama">
                                                {paket.Nama}
                                            </span>
                                            {paket.PaketBerjalan ? (
                                                <LabelStatus jenis="netral" teks="Paket Anda saat ini" />
                                            ) : null}
                                        </span>
                                        {paket.Keterangan ? (
                                            <span className="text-keterangan text-teks-sekunder">
                                                {paket.Keterangan}
                                            </span>
                                        ) : null}
                                        {paket.NaikPaket ? (
                                            <span className="text-keterangan text-teks-sekunder">
                                                Naik paket: bayar selisih {FormatRupiah(paket.NaikPaket.Subtotal)} untuk{' '}
                                                {paket.NaikPaket.HariDitagih} dari {paket.NaikPaket.HariPeriode} hari
                                                periode ini (belum termasuk PPN). Paket langsung berganti setelah lunas.
                                            </span>
                                        ) : null}
                                        {!paket.BisaDipilih ? (
                                            <span className="text-keterangan text-teks-sekunder">
                                                Turun paket berlaku mulai periode berikutnya. Hubungi tim kami untuk
                                                mengaturnya.
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="ml-auto flex flex-col items-end text-right tabular-nums">
                                        <span className="text-subjudul font-semibold text-teks-utama">
                                            {FormatRupiah(paket.HargaBulanan)}
                                            <span className="text-isi font-normal text-teks-sekunder"> /bulan</span>
                                        </span>
                                        {lamaTerpilih !== null && hargaPaket !== null ? (
                                            <span className="text-keterangan text-teks-sekunder">
                                                {FormatRupiah(hargaPaket.Subtotal)} untuk {lamaTerpilih} bulan
                                            </span>
                                        ) : null}
                                    </span>
                                </label>
                            );
                        })}
                    </RadioGroup>
                    {formulir.errors.KodePaket ? (
                        <FieldError className="text-keterangan font-semibold">{formulir.errors.KodePaket}</FieldError>
                    ) : null}
                </FieldSet>
                {naikPaket ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Lama langganan tetap {langganan.JumlahBulanSiklus} bulan dan masa berlangganan tidak berubah.
                        Perpanjangan berikutnya memakai harga paket baru.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <PilihLamaLangganan
                            aturan={aturan}
                            nilai={formulir.data.JumlahBulan}
                            saatBerubah={(nilai) => formulir.setData('JumlahBulan', nilai)}
                            harga={paketTerpilih?.HargaPerLama ?? []}
                            galat={formulir.errors.JumlahBulan}
                        />
                        <BidangTeks
                            label="Kode kupon (opsional)"
                            kode
                            maxLength={30}
                            nilai={formulir.data.KodeKupon}
                            saatBerubah={(nilai) => formulir.setData('KodeKupon', nilai.toUpperCase())}
                            galat={formulir.errors.KodeKupon}
                        />
                    </div>
                )}
            </Panel>
            <Panel judul="Ringkasan" tingkat="h3">
                <dl className="flex flex-col gap-2 text-isi">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-teks-sekunder">Paket</dt>
                        <dd className="font-semibold text-teks-utama">{paketTerpilih?.Nama ?? '—'}</dd>
                    </div>
                    {naikPaket ? (
                        <>
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-teks-sekunder">Selisih prorata</dt>
                                <dd className="font-semibold tabular-nums text-teks-utama">
                                    {FormatRupiah(naikPaket.Subtotal)}
                                </dd>
                            </div>
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-teks-sekunder">Hari ditagih</dt>
                                <dd className="tabular-nums">
                                    {naikPaket.HariDitagih} dari {naikPaket.HariPeriode}
                                </dd>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-teks-sekunder">Lama</dt>
                                <dd className="tabular-nums">
                                    {lamaTerpilih === null ? '—' : `${lamaTerpilih} bulan`}
                                </dd>
                            </div>
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-teks-sekunder">Subtotal</dt>
                                <dd className="text-subjudul font-semibold tabular-nums text-teks-utama">
                                    {hargaLama === null ? '—' : FormatRupiah(hargaLama.Subtotal)}
                                </dd>
                            </div>
                            {hargaLama !== null && Number(hargaLama.DiskonPersen) > 0 ? (
                                <p className="text-keterangan text-sukses">
                                    Hemat {Number(hargaLama.DiskonPersen)}% dari harga penuh{' '}
                                    {FormatRupiah(hargaLama.HargaPenuh)}.
                                </p>
                            ) : null}
                        </>
                    )}
                </dl>
                <p className="text-keterangan text-teks-sekunder">
                    Harga belum termasuk PPN. Rincian PPN dan total tampil di tagihan sebelum Anda membayar.
                </p>
                <Tombol
                    type="submit"
                    memproses={formulir.processing}
                    disabled={formulir.data.KodePaket === '' || (!naikPaket && lamaTerpilih === null)}
                >
                    {naikPaket ? 'Buat tagihan naik paket' : 'Buat tagihan'}
                </Tombol>
            </Panel>
        </form>
    );
}
