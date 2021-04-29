<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Barang;
use App\Models\Kontak;
use App\Models\TransaksiPembelian;
use App\Models\DetailPembelian;
use App\Models\KartuPersediaan;
use App\Models\User;

class TransaksiPembelianController extends Controller
{
    public function index2(){
        return response()->json($data, 200);
    }
    
    public function index($dd,$ddd){
        
        $output=[];

        $dateawal = date("Y-m-d 00:00:00", strtotime($dd));
        $dateakhir = date("Y-m-d 23:59:59", strtotime($ddd));
        if($dd == "null" || $ddd == "null"){
            
            $dateawal = date("Y-01-01 00:00:00");
            $dateakhir = date("Y-12-31 23:59:59");
        }
        $master = DB::table('master_pembelian')
        ->where('created_at','>',$dateawal)    
        ->where('created_at','<',$dateakhir)  
        ->where('deleted_at')   
        ->get();

        $output = $this->getDataPembelian($master);
        return response()->json($output, 200);
    }

    public function getDataPembelian($master){
        foreach ($master as $key => $value) {
            $invoice = [
                'diskon'=>$value->diskon,
                'grandTotal'=>$value->grand_total,
                'ongkir'=>$value->ongkir,
                'pajak'=>$value->pajak_masukan,
                'total'=>$value->total,
            ];

            $user = $barang = User::find($value->user_id);

            $orders = DB::table('detail_pembelian')
            ->select('detail_pembelian.*', 'barang.nama as nama_barang')
            ->join('barang','detail_pembelian.kode_barang_id','=','barang.kode_barang')    
            ->where('master_pembelian_id','=',$value->id)    
            ->get();

            $supplier = DB::table('master_kontak')
            ->where('id','=',$value->kontak_id)
            ->first();


            $pembayaran = [
                'downPayment'=>$value->down_payment,
                'sisaPembayaran'=>$value->sisa_pembayaran,
                'jenisPembayaran' => caraPembayaran($value->cara_pembayaran),
                'kredit'=>$value->kredit,
                'statusPembayaran'=> metodePembayaran($value->metode_pembayaran),
                'tanggalJatuhTempo'=>$value->tanggal_jatuh_tempo,
            ];

            $data = [
                'id'=>$value->id,
                'nomorTransaksi'=>$value->nomor_transaksi,
                'tanggalTransaksi'=>$value->created_at,
                'invoice'=> $invoice,
                'orders'=>$orders,
                'supplier'=>$supplier,
                'pembayaran'=>$pembayaran,
                'user'=> $user

            ];

            $output[] = $data;
        }
        return $output;
    }

        
        public function store(Request $request){
    
            if($request->pembayaran['statusPembayaran']['value'] == 2){
                $sisa_pembayaran = $request->invoice['grandTotal'];
            }else if($request->pembayaran['statusPembayaran']['value'] == 1){
                $sisa_pembayaran = (float)$request->invoice['grandTotal'] - (float)$request->pembayaran['downPayment'];
            }else{
                $sisa_pembayaran = 0;
            }
    
            $data = TransaksiPembelian::create([
                'nomor_transaksi'=> $request->nomorTransaksi,
                'created_at'=> date("Y-m-d h:i:s", strtotime($request->tanggalTransaksi)),
                'kontak_id' => $this->cekSupplier($request->supplier),
                'total' => $request->invoice['total'],
                'diskon' => $request->invoice['diskon'],
                'ongkir' => $request->invoice['ongkir'],
                'pajak_masukan' => $request->invoice['pajak'],
                'grand_total' => $request->invoice['grandTotal'],
                'metode_pembayaran' => $request->pembayaran['statusPembayaran']['title'],
                'kredit' => $request->pembayaran['kredit'],
                'down_payment' => $request->pembayaran['downPayment'],
                'sisa_pembayaran' => $sisa_pembayaran,
                'cara_pembayaran' => $request->pembayaran['jenisPembayaran']['title'],
                'tanggal_jatuh_tempo' => $request->pembayaran['tanggalJatuhTempo'],
                'retur' => 2,
                'user_id' => 1,
            ]);
            $id = $data->id;
            if($id){
                foreach ($request->orders as $key => $value) {
                    $detail = DetailPembelian::create([
                        'master_pembelian_id'=> $id,
                        'kode_barang_id' => $value['kode_barang'],
                        'jumlah' => $value['jumlah'],
                        'harga' => $value['harga'],
                        'diskon' => $value['diskon'],
                        'total' => ($value['jumlah'] * $value['harga']) - $value['diskon'],
                        'created_at'=> date("Y-m-d h:i:s", strtotime($request->tanggalTransaksi)),
                    ]);

                    $debit = $this->debitPersediaan($request->nomorTransaksi, $value, 'Pembelian Transaksi #'); 
                }
                $dd = $request->pembayaran['statusPembayaran']['value']; // CEK STATUS PEMBAYARANNYA KREDIT / LUNAS / COD
    
                $jurnal = $this->postJurnal(
                    'PEMBELIAN_ID_'.$id,
                    'PEMBELIAN NOMOR INVOICE #'.$request->nomorTransaksi,
                    $request->invoice['total'],
                    $request->invoice['pajak'],
                    $request->invoice['ongkir'],
                    $request->invoice['diskon'],
                    $dd,
                    $request->pembayaran['downPayment'],
                    $sisa_pembayaran,
                ); 

            }
    
            return response()->json($jurnal, 200);
        }

        public function cekSupplier($data){
            if($data['id'] == null || $data['id'] == '' ){
                $kontak = Kontak::create([
                    'nama'=> $data['nama'],
                    'tipe'=> 'SUPPLIER',
                    'alamat'=> $data['alamat'],
                    'telepon'=> $data['nomorTelepon'],
                    'wic'=> 1,
                ]);
                return $kontak->id;
            }
            return $data['id'];
        }

        public function debitPersediaan($nomor_transaksi, $data, $catatan){
            $detail = KartuPersediaan::create([
                'nomor_transaksi'=> $nomor_transaksi,
                'master_barang_id' => $data['id_barang'],
                'debit' => $data['jumlah'],
                'harga_beli' => $data['harga'],
                'kredit' => 0,
                'harga_jual' => 0,
                'catatan' => $catatan.'#'.$nomor_transaksi,
            ]);
        }

        public function postJurnal(
            $reff,
            $keterangan, 
            $pembelian, 
            $pajak = 0, 
            $ongkir = 0, 
            $diskon = 0, 
            $metodePembayaran = 0, 
            $dp=0, 
            $sisa_pembayaran=0
        ){
            $base_url = keuangan_base_url();

            $reqJurnal = Http::get($base_url.'reqnomorjurnal');
            $nomorJurnal = $reqJurnal->json();

            $kas = $pembelian + $pajak + $ongkir;

            if($metodePembayaran == 0){
                $kas = Http::post($base_url.'store/', [
                    'reff'=>$reff,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'3', // KAS BESAR
                    'nominal'=> $kas,
                    'jenis'=>'KREDIT',
                    'keterangan'=>'PENGELUARAN KAS '. $keterangan,
                ]);
                $response['kas'] = $kas->json();
            }else if ($metodePembayaran == 1){
                if($dp !== 0){
                    $kas = Http::post($base_url.'store/', [
                        'reff'=>$reff,
                        'nomor_jurnal'=>$nomorJurnal,
                        'master_akun_id'=>'3', // KAS
                        'nominal'=>$dp,
                        'jenis'=>'KREDIT',
                        'keterangan'=>'PENGELUARAN KAS DOWN PAYMENT '. $keterangan,
                    ]);
                    $response['kas'] = $kas->json();
                }
                $utang = Http::post($base_url.'store/', [
                    'reff'=>$reff,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'21', // UTANG DAGANG
                    'nominal'=>$sisa_pembayaran,
                    'jenis'=>'KREDIT',
                    'keterangan'=>'UTANG '. $keterangan,
                ]);
                $response['utang'] = $utang->json();
            }
            
            $persediaan = Http::post($base_url.'store/', [
                'reff'=>$reff,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'6', // PEMBELIAN
                'nominal'=>$pembelian,
                'jenis'=>'DEBIT',
                'keterangan'=>$keterangan,
            ]);
            $response['persediaan'] = $persediaan->json();
            
            if($pajak !== 0){
                $pajak = Http::post($base_url.'store/', [
                    'reff'=>$reff,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'9', // PAJAK MASUKAN
                    'nominal'=>$pajak,
                    'jenis'=>'DEBIT',
                    'keterangan'=>'PAJAK MASUKAN '. $keterangan,
                ]);
                $response['pajak'] = $pajak->json();
            }
            if($ongkir !== 0){
                $ongkir = Http::post($base_url.'store/', [
                    'reff'=>$reff,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'43', // AKUN BIAYA LAIN LAIN
                    'nominal'=>$ongkir,
                    'jenis'=>'DEBIT',
                    'keterangan'=>'ONGKIR '. $keterangan,
                ]);
                $response['ongkir'] = $ongkir->json();
            }
            if($diskon !== 0){
                $diskon = Http::post($base_url.'store/', [
                    'reff'=>$reff,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'39', // AKUN DISKON PEMBELIAN
                    'nominal'=>$diskon,
                    'jenis'=>'KREDIT',
                    'keterangan'=>'DISKON '. $keterangan,
                ]);
                $response['diskon'] = $diskon->json();
            }
            return response()->json($response, 200);
        }

        public function getDetailTransaksiByBarang($kode_barang){

            $output = [];
            $batch = [];
    
            $master = DB::table('detail_pembelian')
            ->select('detail_pembelian.*', 'master_pembelian.nomor_transaksi as nomor_transaksi','master_pembelian.sisa_pembayaran','master_kontak.nama as nama_supplier')
            ->where('kode_barang_id','=',$kode_barang)    
            ->join('master_pembelian','detail_pembelian.master_pembelian_id','=','master_pembelian.id')    
            ->join('master_kontak','master_pembelian.kontak_id','=','master_kontak.id')    
            ->get();
            
            foreach ($master as $key => $value) {
                $data = DB::table('master_pembelian')
                ->where('id','=',$value->master_pembelian_id)    
                ->first();
                $batch[] = $data;
            }
    
            $output = $this->getDataPembelian($batch);
    
            return response()->json($output, 200);
        }

}
