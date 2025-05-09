<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UsersModel;
use Illuminate\Http\Request;

class UsersController extends Controller
{

  // READ ALL
  public function users()
  {
    return UsersModel::all();
  }

  // READ SINGLE
  public function user_by_id($id)
  {
    return UsersModel::findOrFail($id);
  }
}
