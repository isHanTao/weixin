<?php


if ( ! function_exists('mylogger')) {
    function mylogger($type,$msg, $data=''){
        if(strlen($data)>1000){
            $data=substr($data,0,1000);
        }
        \Illuminate\Support\Facades\Log::info("【{$type}】/r/n【msg={$msg}】/r/n【data={$data}】/r/n");
    }
}

if ( ! function_exists('log_debug')) {
    function log_debug($msg, $data=''){
        $type='Debug';
        mylogger($type,$msg, $data);
    }
}
if ( ! function_exists('log_info')) {
    function log_info($msg, $data=''){
        $type='Info';
        mylogger($type,$msg, $data);
    }
}
if ( ! function_exists('log_warn')) {
    function log_warn($msg, $data=''){
        $type='Warn';
        mylogger($type,$msg, $data);
    }
}
if ( ! function_exists('log_error')) {
    function log_error($msg, $data=''){
        $type='Error';
        mylogger($type,$msg, $data);
    }
}
if ( ! function_exists('log_exception')) {
    function log_exception(\Exception $e, $data=''){
        $type='Exception';
        $file='';
        $line='';
        $url='';
        try{
            $file=$e->getFile();
            $line=$e->getLine();
            $url=request()->fullUrl();
        }catch (\Exception $e){}
        \Illuminate\Support\Facades\Log::error("【url={$url}】   【{$file}={$line}】 【{$e->getMessage()}】 【data={$data}】/r/n{$e->getTraceAsString()}");
    }
}