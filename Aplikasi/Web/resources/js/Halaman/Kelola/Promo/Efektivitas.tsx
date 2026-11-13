import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { AngkaPeriodePromo, PropsEfektivitasPromo } from '@/Tipe/Promo';

function Nilai({ label, children, keterangan }: { label: string; children: React.ReactNode; keterangan?: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">{children}</dd>
            {keterangan ? <dd className="text-label text-teks-sekunder">{keterangan}</dd> : null}
        </div>
    );
}

function KartuPeriode({ judul, rentang, angka }: { judul: string; rentang: string; angka: AngkaPeriodePromo }) {
    return (
        <Card className="gap-3 rounded-panel p-4 shadow-none">
            <div>
                <h3 className="text-isi font-semibold text-teks-utama">{judul}</h3>
                <p className="text-label text-teks-sekunder">{rentang}</p>
            </div>
            <dl className="grid gap-3 sm:grid-cols-2">
                <Nilai label="Penjualan bersih">{FormatRupiah(angka.Bersih)}</Nilai>
                <Nilai label="Rata-rata per hari">{FormatRupiah(angka.RataHarian)}</Nilai>
                <Nilai label="Transaksi">{angka.JumlahTransaksi.toLocaleString('id-ID')}</Nilai>
                {angka.Qty !== null ? (
                    <Nilai label="Barang terjual">{Number(angka.Qty).toLocaleString('id-ID')}</Nilai>
                ) : null}
            </dl>
        </Card>
    );
}

/**
 * Efektivitas promo (F-16c bagian 4c): jumlah pakai, total potongan, bagian pemasok, dan uplift penjualan barang promo
 * dibanding periode yang sama panjang tepat sebelum promo.
 */
export default function HalamanEfektivitasPromo({ Promo, Efektivitas: e }: PropsEfektivitasPromo) {
    const uplift = e.Berjalan ? e.UpliftPersen : null;
    const naik = uplift !== null && !uplift.startsWith('-');

    return (
        <TataLetakAplikasi
            judul={`Efektivitas promo ${Promo.Kode}`}
            jejak={[{ label: 'Promo', href: '/kelola/promo' }]}
        >
            <p className="text-isi text-teks-sekunder">{Promo.Nama}</p>

            {!e.Berjalan ? (
                <Pemberitahuan jenis="info" judul="Promo belum berjalan">
                    Promo mulai {FormatTanggal(e.Periode.Dari)}. Laporan tersedia setelah promo berjalan.
                </Pemberitahuan>
            ) : (
                <>
                    <Card className="gap-4 rounded-panel p-4 shadow-none">
                        <p className="text-isi text-teks-sekunder">
                            {e.Periode.Hari} hari ({FormatTanggal(e.Periode.Dari)} – {FormatTanggal(e.Periode.Sampai)}),{' '}
                            {e.Cakupan.toLowerCase()}, {e.SemuaOutlet ? 'semua outlet' : 'outlet promo'}. Transaksi yang
                            dibatalkan tidak dihitung.
                        </p>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Nilai label="Dipakai">{e.JumlahPakai.toLocaleString('id-ID')} transaksi</Nilai>
                            <Nilai label="Total potongan" keterangan={`Rata-rata ${FormatRupiah(e.RataPotongan)}`}>
                                {FormatRupiah(e.TotalPotongan)}
                            </Nilai>
                            <Nilai label="Ditanggung pemasok">{FormatRupiah(e.DitanggungPemasok)}</Nilai>
                            <Nilai label="Uplift penjualan" keterangan="Dibanding periode sebelumnya yang sama panjang">
                                {uplift === null ? (
                                    <span className="text-teks-sekunder">Belum bisa dihitung</span>
                                ) : (
                                    <span className={naik ? 'text-sukses' : 'text-bahaya'}>
                                        {naik ? '+' : ''}
                                        {uplift.replace('.', ',')}% {naik ? '(naik)' : '(turun)'}
                                    </span>
                                )}
                            </Nilai>
                        </dl>
                    </Card>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <KartuPeriode
                            judul="Selama promo"
                            rentang={`${FormatTanggal(e.Periode.Dari)} – ${FormatTanggal(e.Periode.Sampai)}`}
                            angka={e.Sekarang}
                        />
                        <KartuPeriode
                            judul="Sebelum promo"
                            rentang={`${FormatTanggal(e.Pembanding.Dari)} – ${FormatTanggal(e.Pembanding.Sampai)}`}
                            angka={e.Sebelum}
                        />
                    </div>
                    <p className="text-label text-teks-sekunder">
                        Uplift adalah perkiraan: perubahan penjualan juga dipengaruhi musim, hari libur, dan promo lain.
                    </p>
                </>
            )}
        </TataLetakAplikasi>
    );
}
