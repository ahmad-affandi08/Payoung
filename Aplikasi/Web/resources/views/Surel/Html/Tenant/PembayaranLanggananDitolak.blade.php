@extends('Surel.TataLetak')

@section('Judul', 'Bukti transfer perlu diperbaiki')
@section('Pratinjau', 'Tagihan '.$NomorTagihan.' masih bisa dibayar. Unggah bukti transfer yang benar.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Bukti transfer yang Anda unggah belum bisa kami terima, jadi tagihan ini masih terbuka.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor tagihan' => $NomorTagihan,
        'Jumlah tagihan' => $Total,
        'Alasan' => $Alasan,
    ]])

    <p style="margin:0 0 4px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Tagihan masih bisa dibayar. Unggah bukti transfer yang benar di halaman langganan:</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Unggah bukti transfer'])
@endsection

@section('CatatanKaki', 'Bila Anda merasa sudah membayar dengan benar, balas email ini agar tim kami memeriksa ulang.')
