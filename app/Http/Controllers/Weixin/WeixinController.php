<?php


namespace App\Http\Controllers\Weixin;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WeixinController extends Controller
{
    public function receive(Request $request)
    {
        $input = $request->all();
        return $input;
    }
}
