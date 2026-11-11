@extends('Surel.TataLetak')

@section('Judul', $Judul)
@section('Pratinjau', 'Tagihan '.$NomorTagihan.' sebesar '.$Total.', jatuh tempo '.$JatuhTempo.'.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">{{ $Kalimat }}</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor tagihan' => $NomorTagihan,
        'Jumlah' => $Total,
        'Paket' => $NamaPaket,
        'Jatuh tempo' => $JatuhTempo,
    ]])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Bayar tagihan'])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Abaikan email ini bila Anda sudah membayar.</p>
@endsection
