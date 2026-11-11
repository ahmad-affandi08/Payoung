@extends('Surel.TataLetak')

@section('Judul', 'Pembayaran Anda sudah kami terima')
@section('Pratinjau', 'Tagihan '.$NomorTagihan.' lunas. Paket '.$NamaPaket.' aktif sampai '.$PeriodeSelesai.'.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Pembayaran tagihan Anda sudah kami terima dan langganan Anda sudah diperpanjang.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor tagihan' => $NomorTagihan,
        'Jumlah dibayar' => $Total,
        'Paket' => $NamaPaket,
        'Aktif sampai' => $PeriodeSelesai,
    ]])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Lihat rincian langganan'])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Terima kasih sudah berlangganan.</p>
@endsection
