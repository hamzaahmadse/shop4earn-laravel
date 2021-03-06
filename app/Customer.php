<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    const POSITION_LEFT = 1;
    const POSITION_RIGHT = 2;
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 0;
    const NOT_PAIRED = 0;
    const PAIRED = 1;

    protected $fillable = [
        'user_id',
        'parent_id',
        'sponsor_id',
        'rank_id',
        'position',
        'is_paired',
        'temp_sponsor_id',
        'status',
    ];

    public function sponsor() {
        return $this->belongsTo(Customer::class,    'sponsor_id', 'user_id');
    }

    public function childs() {
        return $this->hasMany(Customer::class,    'parent_id', 'user_id');
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function criteria() {
        return $this->belongsTo(BVCriteria::class, 'rank_id', 'rank_id' );
    }

    public function rank(){
        return $this->belongsTo(Rank::class);
    }

    public function sponsors(){
        return $this->hasMany(Customer::class, 'sponsor_id', 'user_id');
    }

    public function salesDetail(){
        return $this->hasMany(SaleDetail::class, 'user_id', 'user_id');
    }
    public function earning(){
        return $this->hasOne(Earning::class, 'user_id', 'user_id');
    }
}
