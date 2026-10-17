import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal, BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import { TerbilangRupiah } from '@/Pustaka/Terbilang';
import type { DokumenKuitansiInvoice } from '@/Tipe/Invoice';

/**
 * Badan kuitansi pembayaran invoice (F-21 bagian 5): dipakai halaman cetak A4 di back-office dan halaman publik, jadi
 * yang tercetak sama dengan yang dilihat pelanggan. Nomor kuitansi = nomor pelunasan (`BP/…`). Terbilang mengikuti uang
 * yang benar-benar diterima, bukan jumlah yang dilunasi (selisihnya PPh yang dipotong pembeli).
 */
export default function TampilanKuitansiInvoice({ dokumen }: { dokumen: DokumenKuitansiInvoice }) {
    const { Kuitansi: k, Invoice: i, Penjual, Pembeli } = dokumen;
    const batal = k.Status === 'Dibatalkan';
    const adaPph = AmbilTandaDesimal(k.PotonganPph) > 0;
    const lunas = AmbilTandaDesimal(k.SisaSetelah) <= 0;
    // Ambang bea meterai dokumen bernilai uang (Rp 5 juta); sistem hanya mengingatkan, tidak membubuhkan meterai.
    const perluMeterai = BandingkanDesimal(k.UangDiterima, '5000000') > 0;

    return (
        <article className="flex flex-col gap-6 text-isi text-teks-utama">
            {batal ? (
                <p className="border-2 border-bahaya p-3 text-center text-subjudul font-bold text-bahaya uppercase">
                    Pembayaran dibatalkan
                    {k.AlasanBatal ? (
                        <span className="block text-isi font-normal normal-case">{k.AlasanBatal}</span>
                    ) : null}
                </p>
            ) : null}

            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-garis pb-4">
                <div className="min-w-0">
                    <p className="text-subjudul font-semibold break-words">{Penjual.Nama}</p>
                    {Penjual.Alamat ? <p className="whitespace-pre-line text-teks-sekunder">{Penjual.Alamat}</p> : null}
                    {Penjual.Npwp ? <p className="text-teks-sekunder">NPWP {Penjual.Npwp}</p> : null}
                </div>
                <div className="text-right">
                    <p className="text-judul font-semibold uppercase">Kuitansi</p>
                    <p className="font-mono">{k.Nomor}</p>
                    <p>
                        Tanggal <span className="font-semibold">{FormatTanggal(k.Tanggal)}</span>
                    </p>
                </div>
            </header>

            <dl className="grid gap-3 sm:grid-cols-[12rem_1fr]">
                <dt className="text-teks-sekunder">Telah terima dari</dt>
                <dd className="font-semibold break-words">
                    {Pembeli.Nama}
                    {Pembeli.Alamat ? (
                        <span className="block font-normal whitespace-pre-line text-teks-sekunder">
                            {Pembeli.Alamat}
                        </span>
                    ) : null}
                </dd>
                <dt className="text-teks-sekunder">Uang sejumlah</dt>
                <dd>
                    <span className="font-semibold tabular-nums">{FormatRupiah(k.UangDiterima)}</span>
                    <span className="block text-teks-sekunder italic">{TerbilangRupiah(k.UangDiterima)}</span>
                </dd>
                <dt className="text-teks-sekunder">Untuk pembayaran</dt>
                <dd className="break-words">
                    Invoice <span className="font-mono">{i.Nomor ?? ''}</span> tanggal {FormatTanggal(i.Tanggal)}
                    {i.Perihal ? <span className="block text-teks-sekunder">{i.Perihal}</span> : null}
                </dd>
            </dl>

            <dl className="ml-auto flex w-full max-w-sm flex-col gap-1">
                <Baris label="Tagihan invoice" nilai={i.Tagihan} />
                <Baris label="Dilunasi dengan pembayaran ini" nilai={k.Jumlah} />
                {adaPph ? (
                    <Baris
                        label={`${k.KodePph ?? 'PPh'}${k.TarifPph === null ? '' : ` ${FormatPersen(k.TarifPph)}`} dipotong pembeli`}
                        nilai={k.PotonganPph}
                        negatif
                    />
                ) : null}
                <Baris label="Uang diterima" nilai={k.UangDiterima} tebal />
                <Baris label="Sisa tagihan setelah pembayaran ini" nilai={k.SisaSetelah} tebal />
            </dl>

            {adaPph ? (
                <p className="text-teks-sekunder">
                    PPh yang dipotong dibuktikan dengan bukti potong
                    {k.NomorBuktiPotong ? (
                        <>
                            {' '}
                            nomor <span className="font-mono text-teks-utama">{k.NomorBuktiPotong}</span>
                        </>
                    ) : (
                        ' yang akan diserahkan pembeli'
                    )}
                    .
                </p>
            ) : null}
            {lunas && !batal ? (
                <p className="border-2 border-sukses p-2 text-center text-subjudul font-bold text-sukses uppercase">
                    Lunas
                </p>
            ) : null}

            <footer className="mt-4 flex justify-end">
                <div className="flex w-56 flex-col gap-14 text-center">
                    <p>Penerima,</p>
                    <p className="border-t border-garis pt-1 font-semibold break-words">{Penjual.Nama}</p>
                </div>
            </footer>
            <p className="text-keterangan text-teks-sekunder">
                Kuitansi ini dibuat otomatis oleh sistem penagihan penjual.
                {perluMeterai
                    ? ' Dokumen yang menyebut uang di atas Rp 5.000.000 dapat dikenai bea meterai; bubuhkan meterai bila diperlukan.'
                    : ''}
            </p>
        </article>
    );
}

function Baris({
    label,
    nilai,
    tebal = false,
    negatif = false,
}: {
    label: string;
    nilai: string;
    tebal?: boolean;
    negatif?: boolean;
}) {
    return (
        <div
            className={`flex items-baseline justify-between gap-3 ${tebal ? 'border-t border-garis pt-1 font-semibold' : ''}`}
        >
            <dt className={tebal ? '' : 'text-teks-sekunder'}>{label}</dt>
            <dd className="text-right tabular-nums">
                {negatif ? '− ' : ''}
                {FormatRupiah(nilai)}
            </dd>
        </div>
    );
}
